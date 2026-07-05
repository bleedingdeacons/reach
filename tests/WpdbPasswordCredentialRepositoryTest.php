<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\Auth\WpdbPasswordCredentialRepository;

// Reuse the shared wpdb stub (and the `wpdb` class alias) from the
// call-attempts repository test so all repository tests agree on what
// `wpdb` resolves to regardless of load order. require_once is idempotent.
require_once __DIR__ . '/WpdbCallAttemptRepositoryTest.php';

/**
 * Extends the shared {@see WpdbStub} with the write path the credentials
 * repository needs (query), recording each prepared statement so tests can
 * assert on the SQL shape the repository emits.
 */
final class CredentialWpdbStub extends WpdbStub
{
    public int $queryReturn = 0;
    public int $deleteReturn = 1;
    /** @var array{table: string, where: array<string, mixed>}|null */
    public ?array $lastDelete = null;

    public function query($sql): int
    {
        $this->queries[] = (string) $sql;
        return $this->queryReturn;
    }

    public function delete($table, $where, $where_format = null): int
    {
        $this->lastDelete = ['table' => (string) $table, 'where' => $where];
        return $this->deleteReturn;
    }
}

final class WpdbPasswordCredentialRepositoryTest extends TestCase
{
    public function testFindReturnsNullOnMiss(): void
    {
        $db = new CredentialWpdbStub();
        $db->nextRow = null;
        $repo = new WpdbPasswordCredentialRepository($db);

        $this->assertNull($repo->find('nobody@example.com'));
        $this->assertStringContainsString('FROM wp_reach_credentials', $db->queries[0]);
        $this->assertStringContainsString("WHERE email = 'nobody@example.com'", $db->queries[0]);
    }

    public function testFindHydratesRow(): void
    {
        $db = new CredentialWpdbStub();
        $db->nextRow = [
            'email' => 'user@example.com',
            'password_hash' => 'HASH',
            'reset_token_hash' => 'TOKENHASH',
            'reset_expires_at' => 4600,
            'failed_attempts' => 2,
            'locked_until' => 0,
            'updated_at' => 1234,
        ];
        $repo = new WpdbPasswordCredentialRepository($db);
        $cred = $repo->find('user@example.com');

        $this->assertNotNull($cred);
        $this->assertSame('user@example.com', $cred->email);
        $this->assertSame('HASH', $cred->passwordHash);
        $this->assertSame('TOKENHASH', $cred->resetTokenHash);
        $this->assertSame(4600, $cred->resetExpiresAt);
        $this->assertSame(2, $cred->failedAttempts);
        $this->assertTrue($cred->hasPassword());
    }

    public function testFindByResetTokenHashRefusesEmptyWithoutQuerying(): void
    {
        $db = new CredentialWpdbStub();
        $repo = new WpdbPasswordCredentialRepository($db);

        $this->assertNull($repo->findByResetTokenHash(''));
        // A blank hash must never hit the database (it would match rows with
        // no pending token).
        $this->assertCount(0, $db->queries);
    }

    public function testUpsertPasswordHashClearsTokenAndUnlocks(): void
    {
        $db = new CredentialWpdbStub();
        $repo = new WpdbPasswordCredentialRepository($db);

        $repo->upsertPasswordHash('user@example.com', 'NEWHASH', 5000);

        $q = $db->queries[0];
        $this->assertStringContainsString('INSERT INTO wp_reach_credentials', $q);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $q);
        // Setting a password wipes any pending token and lockout.
        $this->assertStringContainsString("reset_token_hash = ''", $q);
        $this->assertStringContainsString('reset_expires_at = 0', $q);
        $this->assertStringContainsString('failed_attempts = 0', $q);
        $this->assertStringContainsString('locked_until = 0', $q);
        $this->assertStringContainsString("'NEWHASH'", $q);
    }

    public function testStoreResetTokenDoesNotTouchPasswordHash(): void
    {
        $db = new CredentialWpdbStub();
        $repo = new WpdbPasswordCredentialRepository($db);

        $repo->storeResetToken('user@example.com', 'THASH', 6600, 3000);

        $q = $db->queries[0];
        $this->assertStringContainsString('INSERT INTO wp_reach_credentials', $q);
        $this->assertStringContainsString('reset_token_hash = VALUES(reset_token_hash)', $q);
        // The password column must be left alone on a reset-token write.
        $this->assertStringNotContainsString('password_hash =', $q);
    }

    public function testRecordFailedAttemptIsUpdateOnly(): void
    {
        $db = new CredentialWpdbStub();
        $repo = new WpdbPasswordCredentialRepository($db);

        $repo->recordFailedAttempt('user@example.com', 3, 9000, 4000);

        $q = $db->queries[0];
        // UPDATE only — never INSERT — so an unknown email is never seeded.
        $this->assertStringStartsWith('UPDATE wp_reach_credentials', trim($q));
        $this->assertStringNotContainsString('INSERT', $q);
        $this->assertStringContainsString('failed_attempts = 3', $q);
        $this->assertStringContainsString('locked_until = 9000', $q);
        $this->assertStringContainsString("WHERE email = 'user@example.com'", $q);
    }

    public function testResetFailedAttemptsZeroesCounters(): void
    {
        $db = new CredentialWpdbStub();
        $repo = new WpdbPasswordCredentialRepository($db);

        $repo->resetFailedAttempts('user@example.com', 4000);

        $q = $db->queries[0];
        $this->assertStringStartsWith('UPDATE wp_reach_credentials', trim($q));
        $this->assertStringContainsString('failed_attempts = 0', $q);
        $this->assertStringContainsString('locked_until = 0', $q);
    }

    public function testDeleteRemovesRowByEmail(): void
    {
        $db = new CredentialWpdbStub();
        $repo = new WpdbPasswordCredentialRepository($db);

        $repo->delete('user@example.com');

        $this->assertSame('wp_reach_credentials', $db->lastDelete['table']);
        $this->assertSame('user@example.com', $db->lastDelete['where']['email']);
    }
}
