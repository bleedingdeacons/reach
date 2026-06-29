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
 * The table holds durable tracking history — no caller PII (that is
 * emailed at the time the request is raised), so rows are kept rather
 * than purged. The created_at index covers the newest-first list query.
 */
final class WpdbCallRequestRepository implements CallRequestRepository
{
    public const TABLE_SUFFIX = 'reach_call_requests';

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
            responder_name VARCHAR(200) NOT NULL,
            area VARCHAR(200) NOT NULL,
            viewer_email VARCHAR(254) NOT NULL,
            viewer_provider VARCHAR(32) NOT NULL,
            created_at BIGINT UNSIGNED NOT NULL,
            completed_at BIGINT UNSIGNED NULL,
            completed_by_member_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            completed_by_name VARCHAR(200) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY created_at (created_at)
        ) {$charset};";

        dbDelta($sql);

        self::dropLegacyPiiColumns($wpdb, $table);
    }

    /**
     * Drop caller personal-data columns left over from the old schema.
     *
     * Earlier versions stored the caller's name, phone, preferred gender
     * and note (and, before that, a target member_id). Those details are
     * now emailed at the time the request is raised and never persisted,
     * so on upgrade we remove the columns — and with them any PII still
     * sitting in old rows. dbDelta adds the new completion columns but
     * cannot drop columns, so we do it here. Guarded on column existence
     * so it is a no-op on fresh installs and on re-runs.
     */
    private static function dropLegacyPiiColumns(wpdb $wpdb, string $table): void
    {
        // The legacy member-targeted schema carried an index over
        // (member_id, created_at); drop it before its columns.
        $hasIndex = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'member_created'",
            $table,
        ));
        if ((int) $hasIndex > 0) {
            $wpdb->query("ALTER TABLE {$table} DROP INDEX member_created");
        }

        foreach (['member_id', 'gender', 'caller_name', 'caller_phone', 'note'] as $column) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                $table,
                $column,
            ));
            if ((int) $exists > 0) {
                $wpdb->query("ALTER TABLE {$table} DROP COLUMN `{$column}`");
            }
        }
    }

    public function create(
        string $responderName,
        string $area,
        string $viewerEmail,
        string $viewerProvider,
        int $now,
    ): CallRequest {
        $table = self::tableName($this->wpdb);

        $this->wpdb->insert(
            $table,
            [
                'responder_name'  => $responderName,
                'area'            => $area,
                'viewer_email'    => $viewerEmail,
                'viewer_provider' => $viewerProvider,
                'created_at'      => $now,
            ],
            ['%s', '%s', '%s', '%s', '%d'],
        );

        return new CallRequest(
            (int) $this->wpdb->insert_id,
            $responderName,
            $area,
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

        // Pending rows (completed_at IS NULL) sort first; within each
        // group newest first. ORDER BY id DESC after created_at DESC
        // stabilises pagination when rows share a timestamp.
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, responder_name, area, viewer_email, viewer_provider, created_at,
                    completed_at, completed_by_member_id, completed_by_name
               FROM {$table}
              ORDER BY (completed_at IS NULL) DESC, created_at DESC, id DESC
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

    public function countPending(): int
    {
        $table = self::tableName($this->wpdb);
        return (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE completed_at IS NULL");
    }

    public function findById(int $id): ?CallRequest
    {
        $table = self::tableName($this->wpdb);
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT id, responder_name, area, viewer_email, viewer_provider, created_at,
                    completed_at, completed_by_member_id, completed_by_name
               FROM {$table}
              WHERE id = %d
              LIMIT 1",
            $id,
        ), ARRAY_A);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function markCompleted(int $id, int $memberId, string $memberName, int $completedAt): bool
    {
        $table = self::tableName($this->wpdb);

        // Only a still-pending row is updated — the WHERE clause makes
        // completion idempotent and a double-click harmless.
        $updated = $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$table}
                SET completed_at = %d, completed_by_member_id = %d, completed_by_name = %s
              WHERE id = %d AND completed_at IS NULL",
            $completedAt,
            $memberId,
            $memberName,
            $id,
        ));

        return is_int($updated) && $updated > 0;
    }

    public function delete(int $id): bool
    {
        $table = self::tableName($this->wpdb);
        $deleted = $this->wpdb->delete($table, ['id' => $id], ['%d']);
        return is_int($deleted) && $deleted > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): CallRequest
    {
        return new CallRequest(
            (int) $row['id'],
            (string) $row['responder_name'],
            (string) $row['area'],
            (string) $row['viewer_email'],
            (string) $row['viewer_provider'],
            (int) $row['created_at'],
            $row['completed_at'] !== null ? (int) $row['completed_at'] : null,
            (int) $row['completed_by_member_id'],
            (string) $row['completed_by_name'],
        );
    }
}
