<?php

declare(strict_types=1);

namespace Reach\Devices;

if (!defined('ABSPATH')) {
    exit;
}

use LogicException;
use wpdb;

use function dbDelta;

/**
 * $wpdb-backed implementation of {@see DeviceRepository}.
 *
 * Schema is created via dbDelta on plugin activation (see install()).
 *
 * The table holds no personal data beyond the responder's own email —
 * which is the identity the token was minted for and the key the
 * eligibility gate is re-checked against — plus a device label the
 * handset chooses for itself. No caller data ever reaches this table;
 * that stays out of the database entirely, exactly as it does for call
 * requests.
 *
 * Indexes follow the three questions actually asked of it: authenticate
 * a bearer token (unique on token_hash), find one responder's handsets
 * (member_email), and list every live handset for a broadcast
 * (revoked_at).
 */
final class WpdbDeviceRepository implements DeviceRepository
{
    public const TABLE_SUFFIX = 'reach_devices';

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

    public function __construct(private readonly wpdb $wpdb)
    {
    }

    /**
     * Idempotent: safe to call on every activation. dbDelta diffs
     * against the live schema and only applies changes.
     *
     * token_hash is CHAR(64) because it is always a hex SHA-256 — fixed
     * width, so the unique index over it stays compact. push_token is
     * generously sized: FCM registration tokens have no documented
     * maximum and have grown twice, and a truncated one is a handset
     * that silently never rings.
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
            token_hash CHAR(64) NOT NULL,
            member_email VARCHAR(254) NOT NULL,
            member_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            label VARCHAR(200) NOT NULL DEFAULT '',
            platform VARCHAR(32) NOT NULL DEFAULT '',
            push_provider VARCHAR(16) NOT NULL DEFAULT '',
            push_token VARCHAR(512) NOT NULL DEFAULT '',
            created_at BIGINT UNSIGNED NOT NULL,
            last_seen_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            revoked_at BIGINT UNSIGNED NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY member_email (member_email),
            KEY revoked_at (revoked_at)
        ) {$charset};";

        dbDelta($sql);
    }

    public function create(
        string $tokenHash,
        string $memberEmail,
        int $memberId,
        string $label,
        string $platform,
        string $pushProvider,
        string $pushToken,
        int $now,
    ): Device {
        $table = self::tableName($this->wpdb);

        $this->wpdb->insert(
            $table,
            [
                'token_hash'    => $tokenHash,
                'member_email'  => $memberEmail,
                'member_id'     => $memberId,
                'label'         => $label,
                'platform'      => $platform,
                'push_provider' => $pushProvider,
                'push_token'    => $pushToken,
                'created_at'    => $now,
                'last_seen_at'  => $now,
            ],
            ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d'],
        );

        return new Device(
            (int) $this->wpdb->insert_id,
            $memberEmail,
            $memberId,
            $label,
            $platform,
            $pushProvider,
            $pushToken,
            $now,
            $now,
        );
    }

    public function findByTokenHash(string $tokenHash): ?Device
    {
        $table = self::tableName($this->wpdb);

        // revoked_at IS NULL is part of the lookup rather than a check
        // the caller makes afterwards: a revoked token must be
        // indistinguishable from an unknown one at every call site, and
        // the surest way to guarantee that is to never return the row.
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT {$this->columns()}
               FROM {$table}
              WHERE token_hash = %s AND revoked_at IS NULL
              LIMIT 1",
            $tokenHash,
        ), ARRAY_A);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findById(int $id): ?Device
    {
        $table = self::tableName($this->wpdb);
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT {$this->columns()} FROM {$table} WHERE id = %d LIMIT 1",
            $id,
        ), ARRAY_A);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findByMemberEmail(string $memberEmail): array
    {
        $table = self::tableName($this->wpdb);
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT {$this->columns()}
               FROM {$table}
              WHERE member_email = %s AND revoked_at IS NULL
              ORDER BY id ASC",
            $memberEmail,
        ), ARRAY_A);

        return $this->hydrateAll($rows);
    }

    public function findAllLive(): array
    {
        $table = self::tableName($this->wpdb);
        $rows = $this->wpdb->get_results(
            "SELECT {$this->columns()} FROM {$table} WHERE revoked_at IS NULL ORDER BY id ASC",
            ARRAY_A,
        );

        return $this->hydrateAll($rows);
    }

    public function list(int $limit, int $offset): array
    {
        $limit  = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $table  = self::tableName($this->wpdb);

        // Live handsets first, then newest-first within each group, so
        // the admin page opens on what is currently enrolled rather than
        // on a wall of history. id DESC stabilises pagination when rows
        // share a timestamp.
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT {$this->columns()}
               FROM {$table}
              ORDER BY (revoked_at IS NULL) DESC, created_at DESC, id DESC
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

    public function touch(int $id, int $now): bool
    {
        $table = self::tableName($this->wpdb);
        $updated = $this->wpdb->update(
            $table,
            ['last_seen_at' => $now],
            ['id' => $id],
            ['%d'],
            ['%d'],
        );

        return is_int($updated) && $updated > 0;
    }

    public function updatePushToken(int $id, string $pushProvider, string $pushToken): bool
    {
        $table = self::tableName($this->wpdb);
        $updated = $this->wpdb->update(
            $table,
            ['push_provider' => $pushProvider, 'push_token' => $pushToken],
            ['id' => $id],
            ['%s', '%s'],
            ['%d'],
        );

        return is_int($updated) && $updated > 0;
    }

    public function revoke(int $id, int $now): bool
    {
        $table = self::tableName($this->wpdb);

        // Only a still-live row is updated, which makes revocation
        // idempotent and preserves the moment a handset was actually cut
        // off. prepare() returns null only on a placeholder/argument
        // mismatch — a coding error, not a runtime condition — and here
        // false would read as "already revoked" and hide the bug.
        $sql = $this->wpdb->prepare(
            "UPDATE {$table} SET revoked_at = %d WHERE id = %d AND revoked_at IS NULL",
            $now,
            $id,
        );

        if ($sql === null) {
            throw new LogicException('Failed to prepare the device revocation query.');
        }

        $updated = $this->wpdb->query($sql);

        return is_int($updated) && $updated > 0;
    }

    public function delete(int $id): bool
    {
        $table = self::tableName($this->wpdb);

        $deleted = $this->wpdb->delete($table, ['id' => $id], ['%d']);

        return is_int($deleted) && $deleted > 0;
    }

    public function revokeAllForMember(string $memberEmail, int $now): int
    {
        $table = self::tableName($this->wpdb);

        $sql = $this->wpdb->prepare(
            "UPDATE {$table} SET revoked_at = %d WHERE member_email = %s AND revoked_at IS NULL",
            $now,
            $memberEmail,
        );

        if ($sql === null) {
            throw new LogicException('Failed to prepare the bulk device revocation query.');
        }

        $updated = $this->wpdb->query($sql);

        return is_int($updated) ? $updated : 0;
    }

    /** @return literal-string */
    private function columns(): string
    {
        return 'id, token_hash, member_email, member_id, label, platform, '
            . 'push_provider, push_token, created_at, last_seen_at, revoked_at';
    }

    /**
     * @param mixed $rows
     * @return array<int, Device>
     */
    private function hydrateAll($rows): array
    {
        if (!is_array($rows)) {
            return [];
        }
        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Device
    {
        return new Device(
            (int) $row['id'],
            (string) $row['member_email'],
            (int) $row['member_id'],
            (string) $row['label'],
            (string) $row['platform'],
            (string) $row['push_provider'],
            (string) $row['push_token'],
            (int) $row['created_at'],
            (int) $row['last_seen_at'],
            $row['revoked_at'] !== null ? (int) $row['revoked_at'] : null,
        );
    }
}
