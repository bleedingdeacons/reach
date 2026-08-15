<?php

declare(strict_types=1);

namespace Reach\Alerts;

if (!defined('ABSPATH')) {
    exit;
}

use LogicException;
use wpdb;

use function dbDelta;

/**
 * $wpdb-backed implementation of {@see AlertRepository}.
 *
 * Two tables, created via dbDelta on activation:
 *
 *   `…reach_alerts`      — one row per alert raised.
 *   `…reach_alert_acks`  — one row per (alert, device) that has alarmed.
 *
 * The split is what lets one alert ring several handsets and be
 * answered independently by each, and it is what the admin view reads
 * to answer "did anybody pick this up".
 *
 * Neither table holds personal data beyond the responder's own email on
 * an acknowledgement — see {@see Alert} for why that rule is strict.
 */
final class WpdbAlertRepository implements AlertRepository
{
    public const TABLE_SUFFIX = 'reach_alerts';
    public const ACKS_TABLE_SUFFIX = 'reach_alert_acks';

    /**
     * @return literal-string
     *
     * See WpdbPasswordCredentialRepository::tableName() on why the assertion
     * is needed and why it holds.
     */
    public static function tableName(wpdb $wpdb): string
    {
        /** @var literal-string $prefix */
        $prefix = $wpdb->prefix;

        return $prefix . self::TABLE_SUFFIX;
    }

    /** @return literal-string */
    public static function acksTableName(wpdb $wpdb): string
    {
        /** @var literal-string $prefix */
        $prefix = $wpdb->prefix;

        return $prefix . self::ACKS_TABLE_SUFFIX;
    }

    public function __construct(private readonly wpdb $wpdb)
    {
    }

    /**
     * Idempotent: safe to call on every activation.
     *
     * The (target_email, expires_at) index covers the poll query, which
     * is the hottest thing in this feature — every handset runs it every
     * few seconds while on duty.
     */
    public static function install(wpdb $wpdb): void
    {
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $charset = $wpdb->get_charset_collate();
        $table   = self::tableName($wpdb);
        $acks    = self::acksTableName($wpdb);

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            kind VARCHAR(32) NOT NULL,
            source VARCHAR(64) NOT NULL DEFAULT '',
            priority VARCHAR(16) NOT NULL DEFAULT 'normal',
            title VARCHAR(200) NOT NULL,
            body TEXT NOT NULL,
            reference VARCHAR(64) NOT NULL DEFAULT '',
            payload TEXT NULL,
            target_email VARCHAR(254) NOT NULL DEFAULT '',
            created_at BIGINT UNSIGNED NOT NULL,
            expires_at BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (id),
            KEY target_expiry (target_email, expires_at),
            KEY created_at (created_at)
        ) {$charset};";

        dbDelta($sql);

        $acksSql = "CREATE TABLE {$acks} (
            alert_id BIGINT UNSIGNED NOT NULL,
            device_id BIGINT UNSIGNED NOT NULL,
            member_email VARCHAR(254) NOT NULL,
            acked_at BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (alert_id, device_id),
            KEY device_id (device_id)
        ) {$charset};";

        dbDelta($acksSql);
    }

    public function create(AlertRequest $request, int $now): Alert
    {
        $table = self::tableName($this->wpdb);

        $payloadJson = $request->payload === []
            ? null
            : (string) wp_json_encode($request->payload);

        $this->wpdb->insert(
            $table,
            [
                'kind'         => $request->kind,
                'source'       => $request->source,
                'priority'     => $request->priority,
                'title'        => $request->title,
                'body'         => $request->body,
                'reference'    => $request->reference,
                'payload'      => $payloadJson,
                'target_email' => $request->targetEmail,
                'created_at'   => $now,
                'expires_at'   => $request->expiresAt($now),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d'],
        );

        return new Alert(
            (int) $this->wpdb->insert_id,
            $request->kind,
            $request->source,
            $request->priority,
            $request->title,
            $request->body,
            $request->reference,
            $request->payload,
            $request->targetEmail,
            $now,
            $request->expiresAt($now),
        );
    }

    public function findById(int $id): ?Alert
    {
        $table = self::tableName($this->wpdb);
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT {$this->columns()} FROM {$table} WHERE id = %d LIMIT 1",
            $id,
        ), ARRAY_A);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function pendingFor(string $memberEmail, int $deviceId, int $now, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $table = self::tableName($this->wpdb);
        $acks  = self::acksTableName($this->wpdb);

        // LEFT JOIN … IS NULL rather than NOT IN (SELECT …): the
        // anti-join uses the acks primary key directly, where the
        // subquery form has to materialise every ack this device has
        // ever made.
        //
        // Oldest first, so a handset that has been out of signal alarms
        // in the order things actually happened.
        $contacts = WpdbAlertContactRepository::tableName($this->wpdb);

        // The second LEFT JOIN reads only whether a contact row exists —
        // never the encrypted column itself. Personal data must not travel
        // on the path every handset runs every few seconds; the app is told
        // there is a contact and fetches it separately, once, audited.
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT a.id, a.kind, a.source, a.priority, a.title, a.body, a.reference,
                    a.payload, a.target_email, a.created_at, a.expires_at,
                    (c.alert_id IS NOT NULL) AS has_contact
               FROM {$table} a
               LEFT JOIN {$acks} k ON k.alert_id = a.id AND k.device_id = %d
               LEFT JOIN {$contacts} c ON c.alert_id = a.id
              WHERE k.alert_id IS NULL
                AND a.expires_at > %d
                AND (a.target_email = '' OR a.target_email = %s)
              ORDER BY a.id ASC
              LIMIT %d",
            $deviceId,
            $now,
            $memberEmail,
            $limit,
        ), ARRAY_A);

        return $this->hydrateAll($rows);
    }

    public function acknowledge(int $alertId, int $deviceId, string $memberEmail, int $now): bool
    {
        $acks = self::acksTableName($this->wpdb);

        // INSERT IGNORE makes the repeat ack a no-op at the storage
        // layer, which is where the idempotence belongs: two handsets
        // racing, or one retrying after a dropped response, must not
        // overwrite the first acknowledgement's timestamp.
        $sql = $this->wpdb->prepare(
            "INSERT IGNORE INTO {$acks} (alert_id, device_id, member_email, acked_at)
             VALUES (%d, %d, %s, %d)",
            $alertId,
            $deviceId,
            $memberEmail,
            $now,
        );

        if ($sql === null) {
            throw new LogicException('Failed to prepare the alert acknowledgement query.');
        }

        $inserted = $this->wpdb->query($sql);

        return is_int($inserted) && $inserted > 0;
    }

    public function acknowledgementsFor(int $alertId): array
    {
        $acks = self::acksTableName($this->wpdb);
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT device_id, member_email, acked_at
               FROM {$acks}
              WHERE alert_id = %d
              ORDER BY acked_at ASC, device_id ASC",
            $alertId,
        ), ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'device_id'    => (int) $row['device_id'],
                'member_email' => (string) $row['member_email'],
                'acked_at'     => (int) $row['acked_at'],
            ];
        }

        return $out;
    }

    public function list(int $limit, int $offset): array
    {
        $limit  = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $table  = self::tableName($this->wpdb);

        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT {$this->columns()}
               FROM {$table}
              ORDER BY created_at DESC, id DESC
              LIMIT %d OFFSET %d",
            $limit,
            $offset,
        ), ARRAY_A);

        return $this->hydrateAll($rows);
    }

    public function countAll(): int
    {
        $table = self::tableName($this->wpdb);
        return (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    public function purgeExpiredBefore(int $before): int
    {
        $table = self::tableName($this->wpdb);
        $acks  = self::acksTableName($this->wpdb);

        // Acknowledgements go first. There is no foreign key here (WP
        // core tables carry none and dbDelta cannot express one), so
        // deleting the alerts first would strand every ack row against
        // an id that no longer exists.
        $acksSql = $this->wpdb->prepare(
            "DELETE k FROM {$acks} k
               INNER JOIN {$table} a ON a.id = k.alert_id
              WHERE a.expires_at < %d",
            $before,
        );

        if ($acksSql === null) {
            throw new LogicException('Failed to prepare the alert-acknowledgement purge query.');
        }

        $this->wpdb->query($acksSql);

        $sql = $this->wpdb->prepare("DELETE FROM {$table} WHERE expires_at < %d", $before);
        if ($sql === null) {
            throw new LogicException('Failed to prepare the alert purge query.');
        }

        $deleted = $this->wpdb->query($sql);

        return is_int($deleted) ? $deleted : 0;
    }

    /** @return literal-string */
    private function columns(): string
    {
        return 'id, kind, source, priority, title, body, reference, payload, '
            . 'target_email, created_at, expires_at';
    }

    /**
     * @param mixed $rows
     * @return array<int, Alert>
     */
    private function hydrateAll($rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $this->hydrate($row);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Alert
    {
        return new Alert(
            (int) $row['id'],
            (string) $row['kind'],
            (string) $row['source'],
            (string) $row['priority'],
            (string) $row['title'],
            (string) $row['body'],
            (string) $row['reference'],
            $this->decodePayload($row['payload'] ?? null),
            (string) $row['target_email'],
            (int) $row['created_at'],
            (int) $row['expires_at'],
            // Absent on the queries that do not join the contacts table
            // (the admin list, findById); those callers do not use it.
            (bool) ($row['has_contact'] ?? false),
        );
    }

    /**
     * @return array<string, string>
     */
    private function decodePayload(mixed $stored): array
    {
        if (!is_string($stored) || $stored === '') {
            return [];
        }

        $decoded = json_decode($stored, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key) && (is_string($value) || is_int($value) || is_float($value))) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }
}
