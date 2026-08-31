<?php

declare(strict_types=1);

namespace Reach\Tests\Fixtures;

use Reach\Alerts\AlertReply;
use Reach\Alerts\AlertReplyRepository;

/**
 * In-memory {@see AlertReplyRepository} for tests.
 *
 * Keeps every reply in insertion order and hands out ids from a counter,
 * which is all the callers depend on: they store a reply and then use
 * its id in a response. The clipping the Wpdb implementation does on the
 * way in is that implementation's concern and is tested there — a double
 * that also truncated would make a test asserting on a long body pass
 * for the wrong reason.
 */
final class InMemoryAlertReplyRepository implements AlertReplyRepository
{
    /** @var array<int, AlertReply> */
    public array $replies = [];

    private int $nextId = 1;

    public function create(
        int $alertId,
        string $messageUuid,
        int $deviceId,
        string $memberEmail,
        string $responder,
        string $body,
        int $now
    ): AlertReply {
        $reply = new AlertReply(
            $this->nextId++,
            $alertId,
            $messageUuid,
            $deviceId,
            $memberEmail,
            $responder,
            $body,
            $now,
        );

        $this->replies[] = $reply;

        return $reply;
    }

    public function findForAlert(int $alertId): array
    {
        $out = [];
        foreach ($this->replies as $reply) {
            if ($reply->alertId === $alertId) {
                $out[] = $reply;
            }
        }

        return $out;
    }

    public function findForAlerts(array $alertIds): array
    {
        $wanted = [];
        foreach ($alertIds as $id) {
            $wanted[(int) $id] = true;
        }

        $out = [];
        foreach ($this->replies as $reply) {
            if (isset($wanted[$reply->alertId])) {
                $out[$reply->alertId][] = $reply;
            }
        }

        return $out;
    }

    public function purgeForAlertsExpiredBefore(int $before): int
    {
        // Nothing here knows when an alert expires — the join that
        // answers that lives in SQL. Tests needing the purge assert
        // against the Wpdb implementation.
        return 0;
    }
}
