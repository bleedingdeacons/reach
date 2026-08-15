<?php

declare(strict_types=1);

namespace Reach\Tests\Fixtures;

use Reach\Alerts\AlertContactRepository;

/**
 * In-memory {@see AlertContactRepository} for tests.
 *
 * Stores plaintext — the encryption is the Wpdb implementation's
 * concern and is tested there. What this double preserves is the
 * contract the callers depend on: saving an empty contact removes it,
 * and a missing contact reads back as '' rather than throwing.
 */
final class InMemoryAlertContactRepository implements AlertContactRepository
{
    /** @var array<int, string> Alert id => contact. */
    public array $contacts = [];

    public function save(int $alertId, string $contact, int $now): bool
    {
        $contact = trim($contact);
        if ($contact === '') {
            return $this->delete($alertId);
        }

        $this->contacts[$alertId] = $contact;

        return true;
    }

    public function find(int $alertId): string
    {
        return $this->contacts[$alertId] ?? '';
    }

    public function has(int $alertId): bool
    {
        return isset($this->contacts[$alertId]);
    }

    public function delete(int $alertId): bool
    {
        if (!isset($this->contacts[$alertId])) {
            return false;
        }

        unset($this->contacts[$alertId]);

        return true;
    }

    public function purgeForExpiredAlertsBefore(int $before): int
    {
        // The real implementation joins against the alerts table. Tests
        // that care drive the repositories directly, so this double only
        // needs to satisfy the interface.
        $count = count($this->contacts);
        $this->contacts = [];

        return $count;
    }
}
