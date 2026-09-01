<?php

declare(strict_types=1);

namespace Reach\Tests\Fixtures;

use Reach\Alerts\Alert;
use Reach\Alerts\AlertRepository;
use Reach\Alerts\AlertRequest;

/**
 * In-memory {@see AlertRepository} for tests.
 *
 * Reproduces the contract points the delivery paths lean on: the poll
 * returns only live, unacknowledged alerts addressed to the caller — by
 * device where one is named, by responder otherwise — never one
 * withheld from the asking handset, and acknowledgement is idempotent.
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
            messageUuid: $request->messageUuid,
            excludeDeviceId: $request->excludeDeviceId,
            level: $request->level,
            response: $request->response,
            senderEmail: $request->senderEmail,
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

    public function findByMessageUuid(string $messageUuid, int $limit = 100): array
    {
        if ($messageUuid === '') {
            return [];
        }

        $out = [];
        foreach ($this->alerts as $alert) {
            if ($alert->messageUuid === $messageUuid) {
                $out[] = $alert;
            }
        }

        return array_slice($out, 0, $limit);
    }

    public function pendingFor(string $memberEmail, int $deviceId, int $now, int $limit): array
    {
        $pending = [];

        foreach ($this->alerts as $alert) {
            if ($alert->isExpired($now)) {
                continue;
            }

            // Withheld from this handset, whatever it is addressed to.
            // Mirrors the AND in WpdbAlertRepository::pendingFor(), which
            // sits outside the target branch for the same reason.
            if ($alert->excludes($deviceId)) {
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

            // An answered message is over, for everybody. Mirrors the
            // NOT EXISTS in WpdbAlertRepository::pendingFor(), exemptions
            // and all — see there for why the notice and the empty uuid
            // are excused.
            if ($this->messageAnswered($alert)) {
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

    /**
     * Whether any handset has already answered this alert's message.
     *
     * Anything informational is exempt: nobody was taking it on, so one
     * handset closing its own copy must not take it off the others. So
     * is the empty uuid, which every row written before the column
     * existed shares and which is therefore not a message.
     */
    private function messageAnswered(Alert $alert): bool
    {
        if (!$alert->isFirstToRespond() || $alert->messageUuid === '') {
            return false;
        }

        foreach ($this->alerts as $sibling) {
            if ($sibling->messageUuid !== $alert->messageUuid) {
                continue;
            }

            foreach ($this->acks as $ack) {
                if ($ack['alert_id'] === $sibling->id) {
                    return true;
                }
            }
        }

        return false;
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
