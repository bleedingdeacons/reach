<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\Auth\WpdbPasswordCredentialRepository;

require_once __DIR__ . '/WpdbCallAttemptRepositoryTest.php'; // WpdbStub (aliased to wpdb)

/**
 * Cover the mutation methods of {@see WpdbPasswordCredentialRepository}: the
 * upsert/reset/lockout writes and delete, plus install(). These are the
 * statements that hold the security-sensitive invariants — a password set
 * clears the reset token and unlocks, failed-attempt recording is UPDATE-only
 * (never seeds a row for an unknown email), and delete removes the credential
 * on GDPR erasure. The SQL each emits is asserted against the WpdbStub.
 */
final class WpdbPasswordCredentialWriteTest extends TestCase
{
    private WpdbStub $wpdb;
    private WpdbPasswordCredentialRepository $repo;

    protected function setUp(): void
    {
        $this->wpdb = new WpdbStub();
        $this->repo = new WpdbPasswordCredentialRepository($this->wpdb);
        $GLOBALS['__reach_dbdelta'] = [];
    }

    public function testUpsertPasswordHashClearsTokenAndUnlocks(): void
    {
        $this->repo->upsertPasswordHash('user@example.com', 'hashed', 1_700_000_000);

        $sql = end($this->wpdb->queries);
        $this->assertStringContainsString('INSERT INTO wp_reach_credentials', $sql);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
        // A successful set is a clean slate.
        $this->assertStringContainsString("reset_token_hash = ''", $sql);
        $this->assertStringContainsString('failed_attempts = 0', $sql);
    }

    public function testStoreResetTokenUpserts(): void
    {
        $this->repo->storeResetToken('user@example.com', str_repeat('a', 64), 1_700_003_600, 1_700_000_000);

        $sql = end($this->wpdb->queries);
        $this->assertStringContainsString('INSERT INTO wp_reach_credentials', $sql);
        $this->assertStringContainsString('reset_token_hash = VALUES(reset_token_hash)', $sql);
    }

    public function testClearResetToken(): void
    {
        $this->repo->clearResetToken('user@example.com', 1_700_000_000);

        $sql = end($this->wpdb->queries);
        $this->assertStringContainsString('UPDATE wp_reach_credentials', $sql);
        $this->assertStringContainsString("reset_token_hash = ''", $sql);
    }

    public function testRecordFailedAttemptIsUpdateOnly(): void
    {
        $this->repo->recordFailedAttempt('user@example.com', 3, 1_700_010_000, 1_700_000_000);

        $sql = end($this->wpdb->queries);
        // Must be an UPDATE (never an INSERT) so an unknown email can't seed a
        // row and leak account existence.
        $this->assertStringStartsWith('UPDATE wp_reach_credentials', trim($sql));
        $this->assertStringContainsString('failed_attempts = 3', $sql);
    }

    public function testResetFailedAttempts(): void
    {
        $this->repo->resetFailedAttempts('user@example.com', 1_700_000_000);

        $sql = end($this->wpdb->queries);
        $this->assertStringContainsString('failed_attempts = 0', $sql);
        $this->assertStringContainsString('locked_until = 0', $sql);
    }

    public function testDeleteRemovesTheCredentialRow(): void
    {
        $this->repo->delete('gone@example.com');

        $this->assertCount(1, $this->wpdb->deletes);
        $this->assertSame('wp_reach_credentials', $this->wpdb->deletes[0]['table']);
        $this->assertSame(['email' => 'gone@example.com'], $this->wpdb->deletes[0]['where']);
    }

    public function testInstallRunsDbDelta(): void
    {
        WpdbPasswordCredentialRepository::install($this->wpdb);

        $this->assertCount(1, $GLOBALS['__reach_dbdelta']);
        $this->assertStringContainsString('CREATE TABLE wp_reach_credentials', $GLOBALS['__reach_dbdelta'][0]);
        $this->assertStringContainsString('PRIMARY KEY  (email)', $GLOBALS['__reach_dbdelta'][0]);
    }
}
