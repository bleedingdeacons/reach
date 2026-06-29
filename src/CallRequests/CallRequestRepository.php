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
 * Rows are durable history: a request is created when raised, marked
 * completed once a 12th-stepper has called the caller back, and kept
 * thereafter. The only destructive operation is {@see delete()}, used
 * to roll back a row whose notification email failed to send (so no
 * orphan tracking row is left behind with the caller details lost).
 */
interface CallRequestRepository
{
    /**
     * Insert a request and return the persisted record.
     *
     * Only the non-identifying tracking data is stored — the caller's
     * name, phone, preferred gender and note are emailed instead (see
     * {@see CallRequestMailer}), never written here.
     */
    public function create(
        string $responderName,
        string $area,
        string $viewerEmail,
        string $viewerProvider,
        int $now,
    ): CallRequest;

    /**
     * Paginated list, pending first then newest completed.
     *
     * @return array<int, CallRequest>
     */
    public function list(int $limit, int $offset): array;

    /**
     * Total number of stored requests (pending + completed), for the
     * admin pager.
     */
    public function countAll(): int;

    /**
     * Number of requests still awaiting a callback.
     */
    public function countPending(): int;

    /**
     * Single-row fetch by primary key.
     */
    public function findById(int $id): ?CallRequest;

    /**
     * Mark one pending request as completed, recording the member who
     * actioned it. Returns true when a still-pending row was updated;
     * false if the id is unknown or already completed.
     */
    public function markCompleted(int $id, int $memberId, string $memberName, int $completedAt): bool;

    /**
     * Delete one request by id. Returns true when a row was removed.
     *
     * Not exposed in the admin UI — used only to roll back a freshly
     * created row when its notification email could not be sent.
     */
    public function delete(int $id): bool;
}
