<?php

declare(strict_types=1);

namespace Reach\CallRequests;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persistence for {@see CallRequest}.
 *
 * Kept as an interface so the REST controller and admin view depend on
 * an abstraction, and tests can hand in an in-memory implementation
 * without touching $wpdb.
 *
 * Unlike the call-attempts repository this one is fully mutable: rows
 * are short-lived operational data, deletable individually from the
 * admin page and purged wholesale once they age past the retention
 * window.
 */
interface CallRequestRepository
{
    /**
     * Insert a request and return the persisted record.
     */
    public function create(
        int $memberId,
        string $callerName,
        string $callerPhone,
        ?string $note,
        string $viewerEmail,
        string $viewerProvider,
        int $now,
    ): CallRequest;

    /**
     * Paginated list, newest first.
     *
     * @return array<int, CallRequest>
     */
    public function list(int $limit, int $offset): array;

    /**
     * Total number of stored requests, for the admin pager.
     */
    public function countAll(): int;

    /**
     * Single-row fetch by primary key.
     */
    public function findById(int $id): ?CallRequest;

    /**
     * Delete one request by id. Returns true when a row was removed.
     */
    public function delete(int $id): bool;

    /**
     * Delete every request older than $olderThanSeconds relative to
     * $now. Returns the number of rows removed. Used by the retention
     * cron and as an on-load backstop on the admin page.
     */
    public function purgeOlderThan(int $olderThanSeconds, int $now): int;
}
