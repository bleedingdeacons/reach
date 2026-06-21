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
    public function testCreateInsertsRowAndReturnsModel(): void
    {
        $db = new CallRequestWpdbStub();
        $db->nextInsertId = 77;
        $repo = new WpdbCallRequestRepository($db);

        $req = $repo->create(42, 'Sam', '07700 900123', 'call after 6', 'a@x', 'google', 1000);

        $this->assertSame(77, $req->id);
        $this->assertSame(42, $req->memberId);
        $this->assertSame('Sam', $req->callerName);
        $this->assertSame('07700 900123', $req->callerPhone);
        $this->assertSame('call after 6', $req->note);
        $this->assertSame('a@x', $req->viewerEmail);
        $this->assertSame('google', $req->viewerProvider);
        $this->assertSame(1000, $req->createdAt);

        $this->assertCount(1, $db->inserted);
        $this->assertSame('wp_reach_call_requests', $db->inserted[0]['table']);
        $this->assertSame('Sam', $db->inserted[0]['data']['caller_name']);
        $this->assertSame('07700 900123', $db->inserted[0]['data']['caller_phone']);
        $this->assertSame(42, $db->inserted[0]['data']['member_id']);
    }

    public function testCreateStoresNullNote(): void
    {
        $db = new CallRequestWpdbStub();
        $repo = new WpdbCallRequestRepository($db);

        $req = $repo->create(1, 'A', '07', null, 'a@x', 'apple', 5);

        $this->assertNull($req->note);
        $this->assertNull($db->inserted[0]['data']['note']);
    }

    public function testListOrdersByCreatedAtDescThenIdDesc(): void
    {
        // Stable secondary order matters for pagination across rows
        // sharing a timestamp — same reasoning as the call-attempts list.
        $db = new CallRequestWpdbStub();
        $repo = new WpdbCallRequestRepository($db);
        $repo->list(50, 0);

        $this->assertStringContainsString('ORDER BY created_at DESC, id DESC', $db->queries[0]);
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
                'id' => 9, 'member_id' => 1, 'caller_name' => 'Sam',
                'caller_phone' => '07', 'note' => null,
                'viewer_email' => 'a@x', 'viewer_provider' => 'google',
                'created_at' => 100,
            ],
        ];
        $repo = new WpdbCallRequestRepository($db);
        $rows = $repo->list(50, 0);

        $this->assertCount(1, $rows);
        $this->assertSame(9, $rows[0]->id);
        $this->assertSame('Sam', $rows[0]->callerName);
        $this->assertNull($rows[0]->note);
    }

    public function testCountAllReturnsVar(): void
    {
        $db = new CallRequestWpdbStub();
        $db->nextVar = 12;
        $repo = new WpdbCallRequestRepository($db);

        $this->assertSame(12, $repo->countAll());
        $this->assertStringContainsString('COUNT(*)', $db->queries[0]);
    }

    public function testFindByIdReturnsNullOnMiss(): void
    {
        $db = new CallRequestWpdbStub();
        $db->nextRow = null;
        $repo = new WpdbCallRequestRepository($db);

        $this->assertNull($repo->findById(99));
    }

    public function testFindByIdHydratesNoteWhenPresent(): void
    {
        $db = new CallRequestWpdbStub();
        $db->nextRow = [
            'id' => 3, 'member_id' => 7, 'caller_name' => 'Jo',
            'caller_phone' => '0789', 'note' => 'leave a message',
            'viewer_email' => 'a@x', 'viewer_provider' => 'apple',
            'created_at' => 1_234_567,
        ];
        $repo = new WpdbCallRequestRepository($db);
        $found = $repo->findById(3);

        $this->assertNotNull($found);
        $this->assertSame('leave a message', $found->note);
        $this->assertSame('Jo', $found->callerName);
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

    public function testPurgeDeletesOlderThanCutoffAndReturnsCount(): void
    {
        $db = new CallRequestWpdbStub();
        $db->queryReturn = 3;
        $repo = new WpdbCallRequestRepository($db);

        $now    = 1_000_000;
        $window = WpdbCallRequestRepository::RETENTION_DAYS * 86400;
        $removed = $repo->purgeOlderThan($window, $now);

        $this->assertSame(3, $removed);
        $q = $db->queries[0];
        $this->assertStringContainsString('DELETE FROM wp_reach_call_requests', $q);
        $this->assertStringContainsString('created_at < ' . ($now - $window), $q);
    }
}
