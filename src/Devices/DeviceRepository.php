<?php

declare(strict_types=1);

namespace Reach\Devices;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Storage contract for enrolled Hand handsets.
 *
 * Bound by interface so the dispatcher and the auth controller can be
 * unit-tested against an in-memory fake, in the same shape as
 * {@see \Reach\CallRequests\CallRequestRepository}.
 */
interface DeviceRepository
{
    /**
     * Enrol a handset and return the stored row.
     *
     * $tokenHash is the SHA-256 of the bearer token; the raw token is
     * the caller's to hand to the app and is never passed here.
     */
    public function create(
        string $tokenHash,
        string $memberEmail,
        int $memberId,
        string $label,
        string $platform,
        string $pushProvider,
        string $pushToken,
        int $now,
    ): Device;

    /**
     * The live device holding this token hash, or null when there is
     * none — unknown, or revoked. Revoked rows are deliberately
     * indistinguishable from absent ones to the caller: both mean "this
     * token authenticates nothing".
     */
    public function findByTokenHash(string $tokenHash): ?Device;

    public function findById(int $id): ?Device;

    /**
     * Live devices enrolled to one responder. Used when an alert names a
     * target, and to cap how many handsets one responder may enrol.
     *
     * @return array<int, Device>
     */
    public function findByMemberEmail(string $memberEmail): array;

    /**
     * Every live device, for a broadcast alert.
     *
     * @return array<int, Device>
     */
    public function findAllLive(): array;

    /**
     * Newest-first page of devices for the admin list, revoked rows
     * included — the admin screen shows history, unlike the delivery
     * paths which only ever see live rows.
     *
     * @return array<int, Device>
     */
    public function list(int $limit, int $offset): array;

    public function countAll(): int;

    /**
     * Record that a device just authenticated. Cheap and frequent — the
     * poll path calls it on every request — so implementations should
     * keep it to a single indexed UPDATE.
     */
    public function touch(int $id, int $now): bool;

    /**
     * Replace a device's push registration. Called when Firebase rotates
     * a token, which it does without warning; a stale token is the usual
     * reason a handset goes quiet.
     */
    public function updatePushToken(int $id, string $pushProvider, string $pushToken): bool;

    /**
     * Revoke a device. Idempotent: re-revoking an already-revoked row
     * reports false rather than moving its timestamp, so the admin list
     * keeps the moment it was actually cut off.
     */
    public function revoke(int $id, int $now): bool;

    /**
     * Revoke every live device belonging to one responder. Used when a
     * member is deleted or loses eligibility, so their handsets stop
     * without an admin hunting for each one.
     *
     * @return int Number of devices revoked.
     */
    public function revokeAllForMember(string $memberEmail, int $now): int;
}
