<?php

declare(strict_types=1);

namespace Reach\Alerts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Storage contract for replies to alerts.
 *
 * Bound by interface for the same reason {@see AlertRepository} is: so
 * the REST controller can be unit-tested against an in-memory fake
 * rather than a database.
 */
interface AlertReplyRepository
{
    /**
     * Record a reply. Returns the stored row, so the caller has its id
     * for the notice it then raises.
     */
    public function create(
        int $alertId,
        string $messageUuid,
        int $deviceId,
        string $memberEmail,
        string $responder,
        string $body,
        int $now
    ): AlertReply;

    /**
     * Replies to one alert, oldest first.
     *
     * @return array<int, AlertReply>
     */
    public function findForAlert(int $alertId): array;

    /**
     * Replies to every alert in a set, keyed by alert id.
     *
     * <b>Batched on purpose.</b> The admin list renders a page of alerts
     * and wants the replies against each; asking per row is the shape
     * that turns one screen into fifty queries. Alerts with no replies
     * are absent from the result rather than present and empty.
     *
     * @param array<int, int> $alertIds
     * @return array<int, array<int, AlertReply>>
     */
    public function findForAlerts(array $alertIds): array;

    /**
     * Delete replies to alerts that expired before $before.
     *
     * Runs before the alerts themselves are purged — there is no foreign
     * key to cascade, so the other order strands every reply against an
     * id that no longer exists. Same reasoning as the contacts purge.
     *
     * @return int Number of replies deleted.
     */
    public function purgeForAlertsExpiredBefore(int $before): int;
}
