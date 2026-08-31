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
 * $wpdb-backed {@see AlertReplyRepository}.
 *
 * One table, `…reach_alert_replies`, one row per reply. Unlike the
 * contacts table beside it the body is stored in clear: a reply is
 * dispatched onward to a handset and read on a lock screen, so
 * encrypting it here would protect nothing the alert itself has not
 * already exposed while making the admin view unreadable after a salt
 * rotation. The rule keeping personal data out is the protection, not a
 * second layer behind encryption. See {@see AlertReply}.
 */
final class WpdbAlertReplyRepository implements AlertReplyRepository
{
    public const TABLE_SUFFIX = 'reach_alert_replies';

    /**
     * Cap on a stored reply, matching the alert body it answers — a
     * reply that would not fit in an alert cannot be dispatched as one.
     */
    private const BODY_MAX = 1000;

    /** Cap on the denormalised name. Matches the alert title column. */
    private const RESPONDER_MAX = 200;

    public function __construct(private readonly wpdb $wpdb)
    {
    }

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

    /**
     * Idempotent: safe to call on every activation.
     *
     * Indexed on alert_id because that is how every read reaches it, and
     * on message_uuid so replies to a message delivered as several rows
     * can be gathered as answers to one thing.
     *
     * No comments in the SQL — dbDelta parses these with regular
     * expressions and does not expect to find any among the columns.
     */
    public static function install(wpdb $wpdb): void
    {
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $table   = self::tableName($wpdb);
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            alert_id BIGINT UNSIGNED NOT NULL,
            message_uuid CHAR(36) NOT NULL DEFAULT '',
            device_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            member_email VARCHAR(254) NOT NULL DEFAULT '',
            responder VARCHAR(200) NOT NULL DEFAULT '',
            body TEXT NOT NULL,
            created_at BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (id),
            KEY alert_id (alert_id),
            KEY message_uuid (message_uuid)
        ) {$charset};";

        dbDelta($sql);
    }

    public function create(
        int $alertId,
        string $messageUuid,
        int $deviceId,
        string $memberEmail,
        string $responder,
        string $body,
        int $now
    ): AlertReply {
        $body      = $this->clip($body, self::BODY_MAX);
        $responder = $this->clip($responder, self::RESPONDER_MAX);

        $this->wpdb->insert(
            self::tableName($this->wpdb),
            [
                'alert_id'     => $alertId,
                'message_uuid' => $messageUuid,
                'device_id'    => $deviceId,
                'member_email' => $memberEmail,
                'responder'    => $responder,
                'body'         => $body,
                'created_at'   => $now,
            ],
            // Counted, not eyeballed — see WpdbAlertRepository::create()
            // on what a format array out of step with its data does.
            ['%d', '%s', '%d', '%s', '%s', '%s', '%d'],
        );

        return new AlertReply(
            (int) $this->wpdb->insert_id,
            $alertId,
            $messageUuid,
            $deviceId,
            $memberEmail,
            $responder,
            $body,
            $now,
        );
    }

    public function findForAlert(int $alertId): array
    {
        $table = self::tableName($this->wpdb);

        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT {$this->columns()}
               FROM {$table}
              WHERE alert_id = %d
              ORDER BY created_at ASC, id ASC",
            $alertId,
        ), ARRAY_A);

        return $this->hydrateAll($rows);
    }

    public function findForAlerts(array $alertIds): array
    {
        $ids = [];
        foreach ($alertIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $table = self::tableName($this->wpdb);

        // Placeholders built from the count and the values passed
        // separately, so prepare() still does the quoting. The ids are
        // already cast to int above, which is what makes the interpolated
        // fragment a literal string as far as the analyser is concerned.
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));

        $sql = $this->wpdb->prepare(
            "SELECT {$this->columns()}
               FROM {$table}
              WHERE alert_id IN ({$placeholders})
              ORDER BY created_at ASC, id ASC",
            array_values($ids),
        );

        if ($sql === null) {
            throw new LogicException('Failed to prepare the alert-replies query.');
        }

        $out = [];
        foreach ($this->hydrateAll($this->wpdb->get_results($sql, ARRAY_A)) as $reply) {
            $out[$reply->alertId][] = $reply;
        }

        return $out;
    }

    public function purgeForAlertsExpiredBefore(int $before): int
    {
        $table  = self::tableName($this->wpdb);
        $alerts = WpdbAlertRepository::tableName($this->wpdb);

        $sql = $this->wpdb->prepare(
            "DELETE r FROM {$table} r
               INNER JOIN {$alerts} a ON a.id = r.alert_id
              WHERE a.expires_at < %d",
            $before,
        );

        if ($sql === null) {
            throw new LogicException('Failed to prepare the alert-reply purge query.');
        }

        $deleted = $this->wpdb->query($sql);

        return is_int($deleted) ? $deleted : 0;
    }

    /** @return literal-string */
    private function columns(): string
    {
        return 'id, alert_id, message_uuid, device_id, member_email, responder, body, created_at';
    }

    /**
     * @param mixed $rows
     * @return array<int, AlertReply>
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
    private function hydrate(array $row): AlertReply
    {
        return new AlertReply(
            (int) $row['id'],
            (int) $row['alert_id'],
            (string) ($row['message_uuid'] ?? ''),
            (int) ($row['device_id'] ?? 0),
            (string) ($row['member_email'] ?? ''),
            (string) ($row['responder'] ?? ''),
            (string) $row['body'],
            (int) $row['created_at'],
        );
    }

    /** Trim, strip markup and cap without splitting a UTF-8 sequence. */
    private function clip(string $value, int $max): string
    {
        $value = trim(wp_strip_all_tags($value));

        return strlen($value) > $max
            ? trim((string) mb_strcut($value, 0, $max, 'UTF-8'))
            : $value;
    }
}
