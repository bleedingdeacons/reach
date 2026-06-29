<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\CallRequests\WpdbCallRequestRepository;

// Reuse the wpdb stub (and the `wpdb` class alias) defined by the
// call-attempts repository test, so both repositories' tests agree on
// what `wpdb` resolves to regardless of file load order. require_once is
// idempotent — PHPUnit loading the same file later is a no-op.
require_once __DIR__ . '/WpdbCallAttemptRepositoryTest.php';

/**
 * Extends the shared {@see WpdbStub} with the write methods the
 * call-requests repository needs (insert / delete / query) plus the
 * insert_id property. The base stub already records prepared SQL so we
 * can assert on the query shape the repository emits.
 */
final class CallRequestWpdbStub extends WpdbStub
{
    public int $insert_id = 0;
    public int $nextInsertId = 0;
    public int $deleteReturn = 1;
    public int $queryReturn = 0;

    /** @var array<int, array{table: string, data: array<string, mixed>}> */
    public array $inserted = [];
    /** @var array{table: string, where: array<string, mixed>}|null */
    public ?array $lastDelete = null;

    public function insert($table, $data, $format = null): int
    {
        $this->inserted[] = ['table' => (string) $table, 'data' => $data];
        $this->insert_id = $this->nextInsertId;
        return 1;
    }

    public function delete($table, $where, $where_format = null): int
    {
        $this->lastDelete = ['table' => (string) $table, 'where' => $where];
        return $this->deleteReturn;
    }

    public function query($sql): int
    {
        $this->queries[] = (string) $sql;
        return $this->queryReturn;
    }
}

final class WpdbCallRequestRepositoryTest extends TestCase
{
    public function testCreateStoresOnlyTrackingDataAndReturnsModel(): void
    {
        $db = new CallRequestWpdbStub();
        $db->nextInsertId = 77;
        $repo = new WpdbCallRequestRepository($db);

        $req = $repo->create('Resp One', 'BS5 / Easton', 'a@x', 'google', 1000);

        $this->assertSame(77, $req->id);
        $this->assertSame('Resp One', $req->responderName);
        $this->assertSame('BS5 / Easton', $req->area);
        $this->assertSame('a@x', $req->viewerEmail);
        $this->assertSame('google', $req->viewerProvider);
        $this->assertSame(1000, $req->createdAt);
        $this->assertFalse($req->isCompleted());
        $this->assertSame('CR-000077', $req->serial());

        // Caller PII must never be written to the table — it is emailed.
        $this->assertCount(1, $db->inserted);
        $this->assertSame('wp_reach_call_requests', $db->inserted[0]['table']);
        $this->assertSame('Resp One', $db->inserted[0]['data']['responder_name']);
        $this->assertSame('BS5 / Easton', $db->inserted[0]['data']['area']);
        $this->assertArrayNotHasKey('caller_name', $db->inserted[0]['data']);
        $this->assertArrayNotHasKey('caller_phone', $db->inserted[0]['data']);
        $this->assertArrayNotHasKey('gender', $db->inserted[0]['data']);
        $this->assertArrayNotHasKey('note', $db->inserted[0]['data']);
        $this->assertArrayNotHasKey('member_id', $db->inserted[0]['data']);
    }

    public function testListOrdersPendingFirstThenNewest(): void
    {
        // Pending rows (completed_at IS NULL) sort first; within a group
        // newest first, with id DESC stabilising shared timestamps.
        $db = new CallRequestWpdbStub();
        $repo = new WpdbCallRequestRepository($db);
        $repo->list(50, 0);

        $this->assertStringContainsString(
            'ORDER BY (completed_at IS NULL) DESC, created_at DESC, id DESC',
            $db->queries[0],
        );
    }

    public function testListClampsLimitAndOffset(): void
    {
        $db = new CallRequestWpdbStub();
        $repo = new WpdbCallRequestRepository($db);
        $repo->list(99_999, -5);

        $this->assertStringContainsString('LIMIT 500 OFFSET 0', $db->queries[0]);
    }

    public function testListHydratesRows(): void
    {
        $db = new CallRequestWpdbStub();
        $db->nextResults = [
            [
                'id' => 9, 'responder_name' => 'Resp', 'area' => 'BS5',
                'viewer_email' => 'a@x', 'viewer_provider' => 'google',
                'created_at' => 100, 'completed_at' => null,
                'completed_by_member_id' => 0, 'completed_by_name' => '',
            ],
        ];
        $repo = new WpdbCallRequestRepository($db);
        $rows = $repo->list(50, 0);

        $this->assertCount(1, $rows);
        $this->assertSame(9, $rows[0]->id);
        $this->assertSame('Resp', $rows[0]->responderName);
        $this->assertSame('BS5', $rows[0]->area);
        $this->assertFalse($rows[0]->isCompleted());
        $this->assertSame('CR-000009', $rows[0]->serial());
    }

    public function testCountAllReturnsVar(): void
    {
        $db = new CallRequestWpdbStub();
        $db->nextVar = 12;
        $repo = new WpdbCallRequestRepository($db);

        $this->assertSame(12, $repo->countAll());
        $this->assertStringContainsString('COUNT(*)', $db->queries[0]);
    }

    public function testCountPendingFiltersOnNullCompletedAt(): void
    {
        $db = new CallRequestWpdbStub();
        $db->nextVar = 4;
        $repo = new WpdbCallRequestRepository($db);

        $this->assertSame(4, $repo->countPending());
        $this->assertStringContainsString('WHERE completed_at IS NULL', $db->queries[0]);
    }

    public function testFindByIdReturnsNullOnMiss(): void
    {
        $db = new CallRequestWpdbStub();
        $db->nextRow = null;
        $repo = new WpdbCallRequestRepository($db);

        $this->assertNull($repo->findById(99));
    }

    public function testFindByIdHydratesCompletedRow(): void
    {
        $db = new CallRequestWpdbStub();
        $db->nextRow = [
            'id' => 3, 'responder_name' => 'Resp', 'area' => 'Bath',
            'viewer_email' => 'a@x', 'viewer_provider' => 'apple',
            'created_at' => 1_234_567, 'completed_at' => 1_234_999,
            'completed_by_member_id' => 42, 'completed_by_name' => 'Jo M',
        ];
        $repo = new WpdbCallRequestRepository($db);
        $found = $repo->findById(3);

        $this->assertNotNull($found);
        $this->assertSame('Bath', $found->area);
        $this->assertTrue($found->isCompleted());
        $this->assertSame(1_234_999, $found->completedAt);
        $this->assertSame(42, $found->completedByMemberId);
        $this->assertSame('Jo M', $found->completedByName);
    }

    public function testMarkCompletedUpdatesOnlyPendingRow(): void
    {
        $db = new CallRequestWpdbStub();
        $db->queryReturn = 1;
        $repo = new WpdbCallRequestRepository($db);

        $this->assertTrue($repo->markCompleted(5, 42, 'Jo M', 2000));

        $q = $db->queries[0];
        $this->assertStringContainsString('UPDATE wp_reach_call_requests', $q);
        $this->assertStringContainsString('completed_at = 2000', $q);
        $this->assertStringContainsString('completed_by_member_id = 42', $q);
        $this->assertStringContainsString("completed_by_name = 'Jo M'", $q);
        // Idempotent: only a still-open row is touched.
        $this->assertStringContainsString('WHERE id = 5 AND completed_at IS NULL', $q);
    }

    public function testMarkCompletedReturnsFalseWhenNothingUpdated(): void
    {
        $db = new CallRequestWpdbStub();
        $db->queryReturn = 0;
        $repo = new WpdbCallRequestRepository($db);

        $this->assertFalse($repo->markCompleted(5, 42, 'Jo M', 2000));
    }

    public function testDeleteReturnsTrueWhenRowRemoved(): void
    {
        $db = new CallRequestWpdbStub();
        $db->deleteReturn = 1;
        $repo = new WpdbCallRequestRepository($db);

        $this->assertTrue($repo->delete(5));
        $this->assertSame('wp_reach_call_requests', $db->lastDelete['table']);
        $this->assertSame(5, $db->lastDelete['where']['id']);
    }

    public function testDeleteReturnsFalseWhenNothingRemoved(): void
    {
        $db = new CallRequestWpdbStub();
        $db->deleteReturn = 0;
        $repo = new WpdbCallRequestRepository($db);

        $this->assertFalse($repo->delete(5));
    }
}
