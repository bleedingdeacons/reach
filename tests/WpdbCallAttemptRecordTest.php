<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\CallAttempts\CallAttempt;
use Reach\CallAttempts\WpdbCallAttemptRepository;
use wpdb;

require_once __DIR__ . '/WpdbCallAttemptRepositoryTest.php'; // WpdbStub (aliased to wpdb)

/**
 * Cover the write paths of {@see WpdbCallAttemptRepository} that the
 * read-focused WpdbCallAttemptRepositoryTest leaves out: record()'s
 * insert-vs-collapse decision (a repeat attempt by the same viewer against
 * the same member within the window overwrites rather than appends), the
 * forMembersSince() query, and the idempotent install() schema call.
 */
final class WpdbCallAttemptRecordTest extends TestCase
{
    private WpdbStub $wpdb;
    private WpdbCallAttemptRepository $repo;

    protected function setUp(): void
    {
        $this->wpdb = new WpdbStub();
        $this->repo = new WpdbCallAttemptRepository($this->wpdb);
        $GLOBALS['__reach_dbdelta'] = [];
    }

    public function testRecordInsertsWhenNoRecentAttemptExists(): void
    {
        // get_var → null means "no recent attempt": take the INSERT branch.
        $this->wpdb->nextVar = null;

        $attempt = $this->repo->record(42, 'viewer@example.com', 'google', CallAttempt::OUTCOME_REACHED, 'a note', 1_700_000_000);

        $this->assertInstanceOf(CallAttempt::class, $attempt);
        $this->assertSame(1, $attempt->id); // insert_id from the stub
        $this->assertSame(42, $attempt->memberId);
        $this->assertCount(1, $this->wpdb->inserted);
        $this->assertCount(0, $this->wpdb->updated);
        $this->assertSame('reached', $this->wpdb->inserted[0]['data']['outcome']);
        $this->assertSame('a note', $this->wpdb->inserted[0]['data']['note']);
    }

    public function testRecordCollapsesOntoRecentAttempt(): void
    {
        // get_var → an existing id means a recent attempt: take the UPDATE
        // branch and reuse that id rather than inserting a duplicate row.
        $this->wpdb->nextVar = 77;

        $attempt = $this->repo->record(42, 'viewer@example.com', 'google', CallAttempt::OUTCOME_NO_ANSWER, null, 1_700_000_500);

        $this->assertSame(77, $attempt->id);
        $this->assertCount(0, $this->wpdb->inserted);
        $this->assertCount(1, $this->wpdb->updated);
        $this->assertSame(['id' => 77], $this->wpdb->updated[0]['where']);
        $this->assertSame('no_answer', $this->wpdb->updated[0]['data']['outcome']);
        $this->assertNull($this->wpdb->updated[0]['data']['note']);
    }

    public function testForMembersSinceReturnsEmptyForEmptyIdList(): void
    {
        $this->assertSame([], $this->repo->forMembersSince([], 3600, time()));
        // No query was issued for the trivial case.
        $this->assertCount(0, $this->wpdb->queries);
    }

    public function testForMembersSinceHydratesRows(): void
    {
        $this->wpdb->nextResults = [
            [
                'id' => 1, 'member_id' => 42, 'viewer_email' => 'v@example.com',
                'viewer_provider' => 'google', 'outcome' => 'reached', 'note' => 'hi', 'created_at' => 1_700_000_000,
            ],
            [
                'id' => 2, 'member_id' => 43, 'viewer_email' => 'v@example.com',
                'viewer_provider' => 'google', 'outcome' => 'no_answer', 'note' => null, 'created_at' => 1_700_000_100,
            ],
        ];

        $rows = $this->repo->forMembersSince([42, 43, 42], 7200, 1_700_003_600);

        $this->assertContainsOnlyInstancesOf(CallAttempt::class, $rows);
        $this->assertCount(2, $rows);
        $this->assertSame(42, $rows[0]->memberId);
        $this->assertNull($rows[1]->note);
        // Duplicate ids were collapsed to two before building the IN() list
        // (the stub's prepare() substitutes the two %d placeholders).
        $this->assertStringContainsString('member_id IN (42,43)', $this->lastQuery());
    }

    public function testInstallRunsDbDeltaWithTheExpectedTable(): void
    {
        WpdbCallAttemptRepository::install($this->wpdb);

        $this->assertCount(1, $GLOBALS['__reach_dbdelta']);
        $sql = $GLOBALS['__reach_dbdelta'][0];
        $this->assertStringContainsString('CREATE TABLE wp_reach_call_attempts', $sql);
        $this->assertStringContainsString('member_id', $sql);
    }

    public function testTableNameUsesWpdbPrefix(): void
    {
        $this->assertSame('wp_reach_call_attempts', WpdbCallAttemptRepository::tableName($this->wpdb));
    }

    /**
     * The stub's prepare() substitutes bound values, so the recorded query is
     * post-substitution. For asserting the IN() placeholder shape we only need
     * that two %d placeholders were emitted, which the stub leaves intact when
     * there are more placeholders than the single get_results arg it records.
     */
    private function lastQuery(): string
    {
        return end($this->wpdb->queries) ?: '';
    }
}
