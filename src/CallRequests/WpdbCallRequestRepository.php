<?php

declare(strict_types=1);

namespace Reach\CallRequests;

if (!defined('ABSPATH')) {
    exit;
}

use wpdb;

use function dbDelta;

/**
 * $wpdb-backed implementation of {@see CallRequestRepository}.
 *
 * Schema is created via dbDelta on plugin activation (see install()).
 * The table is small and short-lived: rows are deleted from the admin
 * page individually and purged wholesale once they age past
 * {@see self::RETENTION_DAYS} days. The created_at index covers both
 * the newest-first list query and the age-based purge.
 */
final class WpdbCallRequestRepository implements CallRequestRepository
{
    public const TABLE_SUFFIX = 'reach_call_requests';

    /**
     * How long a request is kept before it is automatically removed.
     * Requests are operational, not an audit trail — once a callback
     * has (or hasn't) happened a few days on, the caller's personal
     * data should not linger in the database.
     */
    public const RETENTION_DAYS = 5;

    public function __construct(private readonly wpdb $wpdb)
    {
    }

    public static function tableName(wpdb $wpdb): string
    {
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /**
     * Idempotent: safe to call on every activation. dbDelta diffs
     * against the live schema and only applies changes.
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
            member_id BIGINT UNSIGNED NOT NULL,
            caller_name VARCHAR(200) NOT NULL,
            caller_phone VARCHAR(50) NOT NULL,
            note TEXT NULL,
            viewer_email VARCHAR(254) NOT NULL,
            viewer_provider VARCHAR(32) NOT NULL,
            created_at BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY member_created (member_id, created_at)
        ) {$charset};";

        dbDelta($sql);
    }

    public function create(
        int $memberId,
        string $callerName,
        string $callerPhone,
        ?string $note,
        string $viewerEmail,
        string $viewerProvider,
        int $now,
    ): CallRequest {
        $table = self::tableName($this->wpdb);

        $this->wpdb->insert(
            $table,
            [
                'member_id'       => $memberId,
                'caller_name'     => $callerName,
                'caller_phone'    => $callerPhone,
                'note'            => $note,
                'viewer_email'    => $viewerEmail,
                'viewer_provider' => $viewerProvider,
                'created_at'      => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%d'],
        );

        return new CallRequest(
            (int) $this->wpdb->insert_id,
            $memberId,
            $callerName,
            $callerPhone,
            $note,
            $viewerEmail,
            $viewerProvider,
            $now,
        );
    }

    public function list(int $limit, int $offset): array
    {
        $limit  = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $table  = self::tableName($this->wpdb);

        // ORDER BY id DESC after created_at DESC stabilises pagination
        // when several rows share a timestamp — same reasoning as the
        // call-attempts list.
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, member_id, caller_name, caller_phone, note, viewer_email, viewer_provider, created_at
               FROM {$table}
              ORDER BY created_at DESC, id DESC
              LIMIT %d OFFSET %d",
            $limit,
            $offset,
        ), ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }
        return array_map([$this, 'hydrate'], $rows);
    }

    public function countAll(): int
    {
        $table = self::tableName($this->wpdb);
        return (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    public function findById(int $id): ?CallRequest
    {
        $table = self::tableName($this->wpdb);
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT id, member_id, caller_name, caller_phone, note, viewer_email, viewer_provider, created_at
               FROM {$table}
              WHERE id = %d
              LIMIT 1",
            $id,
        ), ARRAY_A);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function delete(int $id): bool
    {
        $table = self::tableName($this->wpdb);
        $deleted = $this->wpdb->delete($table, ['id' => $id], ['%d']);
        return is_int($deleted) && $deleted > 0;
    }

    public function purgeOlderThan(int $olderThanSeconds, int $now): int
    {
        $table  = self::tableName($this->wpdb);
        $cutoff = $now - max(0, $olderThanSeconds);

        $deleted = $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %d",
            $cutoff,
        ));

        return is_int($deleted) ? $deleted : 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): CallRequest
    {
        return new CallRequest(
            (int) $row['id'],
            (int) $row['member_id'],
            (string) $row['caller_name'],
            (string) $row['caller_phone'],
            $row['note'] !== null ? (string) $row['note'] : null,
            (string) $row['viewer_email'],
            (string) $row['viewer_provider'],
            (int) $row['created_at'],
        );
    }
}
