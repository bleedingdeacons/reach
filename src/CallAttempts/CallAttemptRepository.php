<?php

declare(strict_types=1);

namespace Reach\CallAttempts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persistence for {@see CallAttempt}.
 *
 * Kept as an interface so the scorer and REST controller depend on an
 * abstraction, and tests can hand in an in-memory implementation
 * without touching $wpdb.
 */
interface CallAttemptRepository
{
    /**
     * Insert (or update, if a recent attempt by the same viewer for the
     * same member exists) and return the persisted attempt.
     *
     * "Recent" is defined by the implementation; the production impl
     * collapses repeats within 30 minutes onto the latest row rather
     * than appending. This is how the UI "user taps wrong button, taps
     * again" case is handled without piling up duplicate rows.
     */
    public function record(
        int $memberId,
        string $viewerEmail,
        string $viewerProvider,
        string $outcome,
        ?string $note,
        int $now,
    ): CallAttempt;

    /**
     * Return all attempts for the given members made within the last
     * $sinceSeconds seconds, oldest-first. Empty array if none.
     *
     * @param array<int, int> $memberIds
     * @return array<int, CallAttempt>
     */
    public function forMembersSince(array $memberIds, int $sinceSeconds, int $now): array;
}
