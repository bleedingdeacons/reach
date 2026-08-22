<?php

declare(strict_types=1);

namespace Reach\Tests\Fixtures;

use Reach\Devices\Device;
use Reach\Devices\DeviceRepository;

/**
 * In-memory {@see DeviceRepository} for tests.
 *
 * Mirrors the Wpdb implementation's contract rather than its storage —
 * most importantly that lookups by token hash refuse revoked rows, which
 * is the behaviour every authentication path depends on.
 */
final class InMemoryDeviceRepository implements DeviceRepository
{
    /** @var array<int, Device> */
    public array $devices = [];

    /**
     * Device id => the token hash it authenticates with.
     *
     * The real table stores the hash on the row; Device deliberately
     * does not carry it, so the double keeps the mapping alongside.
     * {@see create()} records it automatically; {@see rememberHash()} is
     * for devices seeded straight into the constructor.
     *
     * @var array<int, string>
     */
    public array $hashes = [];

    /**
     * Device id => the secret alert payloads are encrypted to.
     *
     * Held beside the hashes for the same reason: the real table stores
     * it on the row, and Device deliberately does not carry it.
     *
     * @var array<int, string>
     */
    public array $payloadKeys = [];

    private int $nextId = 1;

    /** @param array<int, Device> $devices */
    public function __construct(array $devices = [])
    {
        foreach ($devices as $device) {
            $this->devices[] = $device;
            $this->nextId = max($this->nextId, $device->id + 1);
        }
    }

    /**
     * When true, {@see create()} throws as the Wpdb implementation does
     * when its INSERT fails - a missing table, most usefully.
     *
     * Worth being able to simulate: the real thing used to swallow that
     * failure and hand back a Device with id 0, so enrolment answered 201
     * with a token for a row that did not exist.
     */
    public bool $failOnCreate = false;

    public function create(
        string $tokenHash,
        string $memberEmail,
        int $memberId,
        string $label,
        string $platform,
        string $pushProvider,
        string $pushToken,
        int $now,
        string $payloadKey = '',
    ): Device {
        if ($this->failOnCreate) {
            throw new \RuntimeException('The device could not be enrolled: the write failed.');
        }

        $device = new Device(
            $this->nextId++,
            $memberEmail,
            $memberId,
            $label,
            $platform,
            $pushProvider,
            $pushToken,
            $now,
            $now,
        );

        $this->devices[] = $device;
        $this->hashes[$device->id] = $tokenHash;
        $this->payloadKeys[$device->id] = $payloadKey;

        return $device;
    }

    public function findByTokenHash(string $tokenHash): ?Device
    {
        foreach ($this->devices as $device) {
            if (($this->hashes[$device->id] ?? '') !== $tokenHash) {
                continue;
            }

            // Revoked rows are invisible here, exactly as they are in
            // the Wpdb implementation: every caller must be unable to
            // tell a revoked token from an unknown one.
            return $device->isRevoked() ? null : $device;
        }

        return null;
    }

    public function rememberHash(int $deviceId, string $tokenHash): void
    {
        $this->hashes[$deviceId] = $tokenHash;
    }

    public function findById(int $id): ?Device
    {
        foreach ($this->devices as $device) {
            if ($device->id === $id) {
                return $device;
            }
        }

        return null;
    }

    public function findByMemberEmail(string $memberEmail): array
    {
        return array_values(array_filter(
            $this->devices,
            static fn(Device $d): bool => $d->memberEmail === $memberEmail && !$d->isRevoked(),
        ));
    }

    public function findAllLive(): array
    {
        return array_values(array_filter(
            $this->devices,
            static fn(Device $d): bool => !$d->isRevoked(),
        ));
    }

    public function list(int $limit, int $offset, string $orderBy = '', string $order = 'desc'): array
    {
        $compare = match (strtolower($orderBy)) {
            'member_email'  => static fn(Device $a, Device $b): int => strcmp($a->memberEmail, $b->memberEmail),
            'label'         => static fn(Device $a, Device $b): int => strcmp($a->label, $b->label),
            'platform'      => static fn(Device $a, Device $b): int => strcmp($a->platform, $b->platform),
            'push_provider' => static fn(Device $a, Device $b): int => strcmp($a->pushProvider, $b->pushProvider),
            'created_at'    => static fn(Device $a, Device $b): int => $a->createdAt <=> $b->createdAt,
            'last_seen_at'  => static fn(Device $a, Device $b): int => $a->lastSeenAt <=> $b->lastSeenAt,
            'revoked_at'    => static fn(Device $a, Device $b): int => ($a->revokedAt ?? 0) <=> ($b->revokedAt ?? 0),
            default         => null,
        };

        $devices = $this->devices;

        if ($compare !== null) {
            $descending = strtolower($order) !== 'asc';

            usort($devices, static function (Device $a, Device $b) use ($compare, $descending): int {
                $result = $compare($a, $b);

                return $result !== 0
                    ? ($descending ? -$result : $result)
                    : $b->id <=> $a->id;
            });
        }

        return array_slice($devices, $offset, $limit);
    }

    public function countAll(): int
    {
        return count($this->devices);
    }

    public function payloadKeyFor(int $id): string
    {
        return $this->payloadKeys[$id] ?? '';
    }

    public function touch(int $id, int $now): bool
    {
        return $this->replace($id, static fn(Device $d): Device => new Device(
            $d->id,
            $d->memberEmail,
            $d->memberId,
            $d->label,
            $d->platform,
            $d->pushProvider,
            $d->pushToken,
            $d->createdAt,
            $now,
            $d->revokedAt,
        ));
    }

    public function updatePushToken(int $id, string $pushProvider, string $pushToken): bool
    {
        return $this->replace($id, static fn(Device $d): Device => new Device(
            $d->id,
            $d->memberEmail,
            $d->memberId,
            $d->label,
            $d->platform,
            $pushProvider,
            $pushToken,
            $d->createdAt,
            $d->lastSeenAt,
            $d->revokedAt,
        ));
    }

    public function revoke(int $id, int $now): bool
    {
        $device = $this->findById($id);
        if ($device === null || $device->isRevoked()) {
            return false;
        }

        return $this->replace($id, static fn(Device $d): Device => new Device(
            $d->id,
            $d->memberEmail,
            $d->memberId,
            $d->label,
            $d->platform,
            $d->pushProvider,
            $d->pushToken,
            $d->createdAt,
            $d->lastSeenAt,
            $now,
        ));
    }

    public function delete(int $id): bool
    {
        foreach ($this->devices as $index => $device) {
            if ($device->id !== $id) {
                continue;
            }

            // The payload key goes with the row, as it does in the real
            // table where it is a column on it. Leaving it behind would
            // let payloadKeyFor() answer with a secret for a handset that
            // no longer exists.
            unset($this->devices[$index], $this->hashes[$id], $this->payloadKeys[$id]);
            $this->devices = array_values($this->devices);

            return true;
        }

        return false;
    }

    public function revokeAllForMember(string $memberEmail, int $now): int
    {
        $count = 0;
        foreach ($this->findByMemberEmail($memberEmail) as $device) {
            if ($this->revoke($device->id, $now)) {
                $count++;
            }
        }

        return $count;
    }

    /** @param callable(Device): Device $mutate */
    private function replace(int $id, callable $mutate): bool
    {
        foreach ($this->devices as $index => $device) {
            if ($device->id === $id) {
                $this->devices[$index] = $mutate($device);
                return true;
            }
        }

        return false;
    }
}
