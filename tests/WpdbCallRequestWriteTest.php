<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\CallRequests\CallRequest;
use Reach\CallRequests\WpdbCallRequestRepository;

require_once __DIR__ . '/WpdbCallAttemptRepositoryTest.php'; // WpdbStub (aliased to wpdb)

/**
 * Cover the write paths of {@see WpdbCallRequestRepository}: create()'s
 * insert, markCompleted()'s pending-only update (both the updated and the
 * already-completed outcomes), delete(), and the install() routine —
 * including the legacy-PII column drop that removes caller personal data
 * left over from the pre-email schema on upgrade.
 */
final class WpdbCallRequestWriteTest extends TestCase
{
    private WpdbStub $wpdb;
    private WpdbCallRequestRepository $repo;

    protected function setUp(): void
    {
        $this->wpdb = new WpdbStub();
        $this->repo = new WpdbCallRequestRepository($this->wpdb);
        $GLOBALS['__reach_dbdelta'] = [];
    }

    public function testCreateInsertsTrackingRowAndReturnsRecord(): void
    {
        $request = $this->repo->create('Responder', 'BS5 / Easton', 'r@example.com', 'google', 1_700_000_000);

        $this->assertInstanceOf(CallRequest::class, $request);
        $this->assertSame(1, $request->id);
        $this->assertSame('CR-000001', $request->serial());
        $this->assertCount(1, $this->wpdb->inserted);
        $data = $this->wpdb->inserted[0]['data'];
        // Only non-identifying tracking data is stored.
        $this->assertSame('Responder', $data['responder_name']);
        $this->assertSame('BS5 / Easton', $data['area']);
        $this->assertArrayNotHasKey('caller_name', $data);
        $this->assertArrayNotHasKey('caller_phone', $data);
    }

    public function testMarkCompletedReturnsTrueWhenAPendingRowIsUpdated(): void
    {
        $this->wpdb->nextQueryResult = 1; // one row updated
        $this->assertTrue($this->repo->markCompleted(5, 42, 'Volunteer', 1_700_000_500));
    }

    public function testMarkCompletedReturnsFalseWhenAlreadyCompleted(): void
    {
        $this->wpdb->nextQueryResult = 0; // WHERE completed_at IS NULL matched nothing
        $this->assertFalse($this->repo->markCompleted(5, 42, 'Volunteer', 1_700_000_500));
    }

    public function testDeleteReturnsTrueWhenARowIsRemoved(): void
    {
        $this->wpdb->nextDeleteResult = 1;
        $this->assertTrue($this->repo->delete(9));
        $this->assertSame(['id' => 9], $this->wpdb->deletes[0]['where']);
    }

    public function testDeleteReturnsFalseWhenNothingRemoved(): void
    {
        $this->wpdb->nextDeleteResult = 0;
        $this->assertFalse($this->repo->delete(9));
    }

    public function testInstallCreatesTableAndDropsLegacyPiiColumns(): void
    {
        // nextVar > 0 makes the legacy index and every legacy PII column
        // "exist", so install() issues the ALTER TABLE ... DROP statements —
        // exercising the upgrade path that purges old caller data.
        $this->wpdb->nextVar = 1;

        WpdbCallRequestRepository::install($this->wpdb);

        $this->assertCount(1, $GLOBALS['__reach_dbdelta']);
        $this->assertStringContainsString('CREATE TABLE wp_reach_call_requests', $GLOBALS['__reach_dbdelta'][0]);

        $altered = implode("\n", $this->wpdb->queries);
        $this->assertStringContainsString('DROP INDEX member_created', $altered);
        $this->assertStringContainsString('DROP COLUMN `caller_name`', $altered);
        $this->assertStringContainsString('DROP COLUMN `caller_phone`', $altered);
        $this->assertStringContainsString('DROP COLUMN `note`', $altered);
    }

    public function testInstallOnFreshSchemaDropsNothing(): void
    {
        // nextVar = 0 → no legacy index/columns exist → no ALTER statements.
        $this->wpdb->nextVar = 0;

        WpdbCallRequestRepository::install($this->wpdb);

        $altered = implode("\n", $this->wpdb->queries);
        $this->assertStringNotContainsString('DROP COLUMN', $altered);
        $this->assertStringNotContainsString('DROP INDEX', $altered);
    }
}
