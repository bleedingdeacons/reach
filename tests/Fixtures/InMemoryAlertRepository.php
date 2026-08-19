<?php

declare(strict_types=1);

namespace Reach\Tests\Fixtures;

use Reach\Alerts\Alert;
use Reach\Alerts\AlertRepository;
use Reach\Alerts\AlertRequest;

/**
 * In-memory {@see AlertRepository} for tests.
 *
 * Reproduces the two contract points the delivery paths lean on: the
 * poll returns only live, unacknowledged alerts addressed to the caller
 * — by device where one is named, by responder otherwise — and
 * acknowledgement is idempotent.
 *
 * <b>"By device where one is named" is a precedence, not a second
 * condition.</b> This fake had it right while the SQL had it wrong, which
 * is exactly why the mismatch survived a green suite: every test that
 * exercised targeting ran against this class. A change to the rule here
 * is a change to {@see \Reach\Alerts\WpdbAlertRepository::pendingFor()}
 * too, and {@see \Reach\Tests\WpdbAlertRepositoryTest} is where the SQL
 * side of it is pinned.
 */
final class InMemoryAlertRepository implements AlertRepository
{
    /** @var array<int, Alert> */
    public array $alerts = [];

    /** @var array<int, array{alert_id: int, device_id: int, member_email: string, acked_at: int}> */
    public array $acks = [];

    private int $nextId = 1;

    public function create(AlertRequest $request, int $now): Alert
    {
        $alert = new Alert(
            $this->nextId++,
            $request->kind,
            $request->source,
            $request->priority,
            $request->title,
            $request->body,
            $request->reference,
            $request->payload,
            $request->targetEmail,
            $now,
            $request->expiresAt($now),
            targetDeviceId: $request->targetDeviceId,
        );

        $this->alerts[] = $alert;

        return $alert;
    }

    public function findById(int $id): ?Alert
    {
        foreach ($this->alerts as $alert) {
            if ($alert->id === $id) {
                return $alert;
            }
        }

        return null;
    }

    public function pendingFor(string $memberEmail, int $deviceId, int $now, int $limit): array
    {
        $pending = [];

        foreach ($this->alerts as $alert) {
            if ($alert->isExpired($now)) {
                continue;
            }

            if ($alert->isDeviceTargeted() && $alert->targetDeviceId !== $deviceId) {
                continue;
            }

            if (
                !$alert->isDeviceTargeted()
                && !$alert->isBroadcast()
                && $alert->targetEmail !== $memberEmail
            ) {
                continue;
            }

            if ($this->hasAck($alert->id, $deviceId)) {
                continue;
            }

            $pending[] = $alert;
        }

        return array_slice($pending, 0, $limit);
    }

    public function acknowledge(int $alertId, int $deviceId, string $memberEmail, int $now): bool
    {
        if ($this->hasAck($alertId, $deviceId)) {
            return false;
        }

        $this->acks[] = [
            'alert_id'     => $alertId,
            'device_id'    => $deviceId,
            'member_email' => $memberEmail,
            'acked_at'     => $now,
        ];

        return true;
    }

    public function acknowledgementsFor(int $alertId): array
    {
        $out = [];
        foreach ($this->acks as $ack) {
            if ($ack['alert_id'] === $alertId) {
                $out[] = [
                    'device_id'    => $ack['device_id'],
                    'member_email' => $ack['member_email'],
                    'acked_at'     => $ack['acked_at'],
                ];
            }
        }

        return $out;
    }

    public function list(int $limit, int $offset): array
    {
        return array_slice(array_reverse($this->alerts), $offset, $limit);
    }

    public function countAll(): int
    {
        return count($this->alerts);
    }

    public function purgeExpiredBefore(int $before): int
    {
        $kept = [];
        $removed = 0;

        foreach ($this->alerts as $alert) {
            if ($alert->expiresAt < $before) {
                $removed++;
                continue;
            }
            $kept[] = $alert;
        }

        $this->alerts = $kept;

        return $removed;
    }

    private function hasAck(int $alertId, int $deviceId): bool
    {
        foreach ($this->acks as $ack) {
            if ($ack['alert_id'] === $alertId && $ack['device_id'] === $deviceId) {
                return true;
            }
        }

        return false;
    }
}
