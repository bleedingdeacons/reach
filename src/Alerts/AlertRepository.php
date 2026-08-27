<?php

declare(strict_types=1);

namespace Reach\Alerts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Storage contract for alerts and their acknowledgements.
 *
 * Bound by interface so {@see AlertDispatcher} can be unit-tested
 * against an in-memory fake, in the same shape as the call-request and
 * call-attempt repositories.
 */
interface AlertRepository
{
    public function create(AlertRequest $request, int $now): Alert;

    public function findById(int $id): ?Alert;

    /**
     * Every alert raised by one send, oldest first.
     *
     * A message is usually one row, but not always — see
     * {@see MessageUuid}. This is what {@see AcknowledgementNotifier}
     * asks in order to work out who a message actually went to, which
     * cannot be read off the acknowledged row alone: an administrator's
     * message to a responder with two handsets is two device-targeted
     * rows, and the one that was answered names only its own handset.
     *
     * Returns nothing for the empty uuid. Rows written before the column
     * existed carry one, and they are not all the same message.
     *
     * @return array<int, Alert>
     */
    public function findByMessageUuid(string $messageUuid, int $limit = 100): array;

    /**
     * Alerts a handset should be ringing about right now.
     *
     * Returns live (unexpired) alerts addressed to this responder or
     * broadcast to everyone, which *this device* has not yet
     * acknowledged and which are not deliberately withheld from it,
     * oldest first. See {@see Alert::$excludeDeviceId} for the second:
     * a handset must not be handed the notice announcing its own
     * acknowledgement.
     *
     * <b>Why acknowledgements rather than a client-held cursor.</b> A
     * "give me everything after id N" cursor lives on the handset, and
     * a handset that is reinstalled, restored from a backup, or simply
     * cleared loses it — and then either re-alarms for every alert in
     * the table or, if it guesses forward, misses live ones. The server
     * knowing what each device has already handled is the only version
     * of this that survives a handset being replaced mid-shift.
     *
     * @return array<int, Alert>
     */
    public function pendingFor(string $memberEmail, int $deviceId, int $now, int $limit): array;

    /**
     * Record that a device has alarmed for an alert. Idempotent — a
     * repeat ack from the same device is not an error, and must not
     * move the original timestamp, which is the evidence of how quickly
     * the rota responded.
     */
    public function acknowledge(int $alertId, int $deviceId, string $memberEmail, int $now): bool;

    /**
     * Acknowledgements against one alert, oldest first, for the admin
     * view: it is what answers "did anyone pick this up, and when".
     *
     * @return array<int, array{device_id: int, member_email: string, acked_at: int}>
     */
    public function acknowledgementsFor(int $alertId): array;

    /**
     * Newest-first page of alerts for the admin list.
     *
     * @return array<int, Alert>
     */
    public function list(int $limit, int $offset): array;

    public function countAll(): int;

    /**
     * Delete alerts that expired before $before, and their
     * acknowledgements. Alerts are operational, not history — an hour
     * after nobody needed to know, nobody needs to know — so unlike call
     * requests these are purged rather than kept.
     *
     * @return int Number of alerts deleted.
     */
    public function purgeExpiredBefore(int $before): int;
}
