<?php

declare(strict_types=1);

namespace Reach\CallAttempts;

if (!defined('ABSPATH')) {
    exit;
}

use wpdb;

use function dbDelta;

/**
 * $wpdb-backed implementation of {@see CallAttemptRepository}.
 *
 * Schema is created via dbDelta on plugin activation (see install()).
 * The table is small and only ever read in narrow ways: by a small
 * set of member ids for a recent time window, ordered by created_at.
 * The (member_id, created_at) index covers the only hot path.
 *
 * Repeat-attempt collapsing
 * -------------------------
 * record() looks for an existing row by (member_id, viewer_email) in
 * the last self::COLLAPSE_WINDOW_SECONDS and overwrites it if found.
 * Without this, a flustered caller tapping "No answer" twice would
 * inflate the no-answer count and skew the badge. 30 minutes is
 * comfortably longer than the back-to-back-tap scenario without
 * eating into "I called this morning, no answer, calling again now".
 */
final class WpdbCallAttemptRepository implements CallAttemptRepository
{
    public const TABLE_SUFFIX = 'reach_call_attempts';
    private const COLLAPSE_WINDOW_SECONDS = 1800; // 30 minutes

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

        // Outcome stored as a short string rather than ENUM so adding a
        // new outcome later is a code change, not a schema change. The
        // application layer validates against CallAttempt::OUTCOMES.
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id BIGINT UNSIGNED NOT NULL,
            viewer_email VARCHAR(254) NOT NULL,
            viewer_provider VARCHAR(32) NOT NULL,
            outcome VARCHAR(32) NOT NULL,
            note TEXT NULL,
            created_at BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (id),
            KEY member_created (member_id, created_at),
            KEY viewer_member (viewer_email, member_id, created_at)
        ) {$charset};";

        dbDelta($sql);
    }

    public function record(
        int $memberId,
        string $viewerEmail,
        string $viewerProvider,
        string $outcome,
        ?string $note,
        int $now,
    ): CallAttempt {
        $table = self::tableName($this->wpdb);
        $cutoff = $now - self::COLLAPSE_WINDOW_SECONDS;

        // Look for a recent attempt by this viewer against this member.
        $existingId = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$table}
              WHERE member_id = %d
                AND viewer_email = %s
                AND created_at >= %d
              ORDER BY created_at DESC
              LIMIT 1",
            $memberId,
            $viewerEmail,
            $cutoff,
        ));

        if ($existingId !== null) {
            $this->wpdb->update(
                $table,
                [
                    'outcome'    => $outcome,
                    'note'       => $note,
                    'created_at' => $now,
                ],
                ['id' => (int) $existingId],
                ['%s', '%s', '%d'],
                ['%d'],
            );
            $id = (int) $existingId;
        } else {
            $this->wpdb->insert(
                $table,
                [
                    'member_id'       => $memberId,
                    'viewer_email'    => $viewerEmail,
                    'viewer_provider' => $viewerProvider,
                    'outcome'         => $outcome,
                    'note'            => $note,
                    'created_at'      => $now,
                ],
                ['%d', '%s', '%s', '%s', '%s', '%d'],
            );
            $id = (int) $this->wpdb->insert_id;
        }

        return new CallAttempt(
            $id,
            $memberId,
            $viewerEmail,
            $viewerProvider,
            $outcome,
            $note,
            $now,
        );
    }

    public function forMembersSince(array $memberIds, int $sinceSeconds, int $now): array
    {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if ($memberIds === []) {
            return [];
        }

        $cutoff = $now - $sinceSeconds;
        $table = self::tableName($this->wpdb);

        // Build an IN clause with a placeholder per id. Manual because
        // wpdb::prepare() doesn't expand arrays. All values are coerced
        // to int above, so the splice is safe.
        $placeholders = implode(',', array_fill(0, count($memberIds), '%d'));
        $params = [...$memberIds, $cutoff];

        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, member_id, viewer_email, viewer_provider, outcome, note, created_at
               FROM {$table}
              WHERE member_id IN ({$placeholders})
                AND created_at >= %d
              ORDER BY created_at ASC",
            ...$params,
        ), ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        return array_map(
            static fn(array $row): CallAttempt => new CallAttempt(
                (int) $row['id'],
                (int) $row['member_id'],
                (string) $row['viewer_email'],
                (string) $row['viewer_provider'],
                (string) $row['outcome'],
                $row['note'] !== null ? (string) $row['note'] : null,
                (int) $row['created_at'],
            ),
            $rows,
        );
    }
}
