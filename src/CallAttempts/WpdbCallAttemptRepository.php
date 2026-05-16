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

        return array_map([$this, 'hydrate'], $rows);
    }

    public function list(array $filters, int $limit, int $offset): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $table = self::tableName($this->wpdb);

        [$where, $params] = $this->buildWhere($filters);

        // ORDER BY id DESC after created_at DESC stabilises pagination
        // when many rows share a timestamp (a real possibility right
        // after a clustered burst of activity). Without it, LIMIT/
        // OFFSET can skip or duplicate rows across pages.
        $sql = "SELECT id, member_id, viewer_email, viewer_provider, outcome, note, created_at
                  FROM {$table}
                 {$where}
                 ORDER BY created_at DESC, id DESC
                 LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        $rows = $params === []
            ? $this->wpdb->get_results($sql, ARRAY_A)
            : $this->wpdb->get_results($this->wpdb->prepare($sql, ...$params), ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }
        return array_map([$this, 'hydrate'], $rows);
    }

    public function countWhere(array $filters): int
    {
        $table = self::tableName($this->wpdb);
        [$where, $params] = $this->buildWhere($filters);

        $sql = "SELECT COUNT(*) FROM {$table} {$where}";
        $value = $params === []
            ? $this->wpdb->get_var($sql)
            : $this->wpdb->get_var($this->wpdb->prepare($sql, ...$params));

        return (int) $value;
    }

    public function findById(int $id): ?CallAttempt
    {
        $table = self::tableName($this->wpdb);
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT id, member_id, viewer_email, viewer_provider, outcome, note, created_at
               FROM {$table}
              WHERE id = %d
              LIMIT 1",
            $id,
        ), ARRAY_A);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * Translate the admin filter array into a WHERE clause plus its
     * bound parameters, suitable for splicing into a prepared query.
     * Returns ['', []] when no filters apply, so callers can branch
     * on whether prepare() is needed.
     *
     * Kept private and used by both list() and countWhere() so the
     * pager's "show me page N of results filtered like X" stays
     * arithmetically honest — the same predicate must drive both.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<int, scalar>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];

        if (isset($filters['member_id']) && (int) $filters['member_id'] > 0) {
            $clauses[] = 'member_id = %d';
            $params[] = (int) $filters['member_id'];
        }
        if (isset($filters['viewer_email']) && is_string($filters['viewer_email']) && $filters['viewer_email'] !== '') {
            // Substring match on email: admins often have only a
            // domain or partial address from a support ticket.
            $clauses[] = 'viewer_email LIKE %s';
            $params[] = '%' . $this->wpdb->esc_like($filters['viewer_email']) . '%';
        }
        if (isset($filters['outcome']) && is_string($filters['outcome']) && $filters['outcome'] !== '') {
            $clauses[] = 'outcome = %s';
            $params[] = $filters['outcome'];
        }
        if (isset($filters['since']) && (int) $filters['since'] > 0) {
            $clauses[] = 'created_at >= %d';
            $params[] = (int) $filters['since'];
        }
        if (isset($filters['until']) && (int) $filters['until'] > 0) {
            $clauses[] = 'created_at <= %d';
            $params[] = (int) $filters['until'];
        }

        if ($clauses === []) {
            return ['', []];
        }
        return ['WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): CallAttempt
    {
        return new CallAttempt(
            (int) $row['id'],
            (int) $row['member_id'],
            (string) $row['viewer_email'],
            (string) $row['viewer_provider'],
            (string) $row['outcome'],
            $row['note'] !== null ? (string) $row['note'] : null,
            (int) $row['created_at'],
        );
    }
}
