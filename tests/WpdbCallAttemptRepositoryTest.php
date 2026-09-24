<?php

declare(strict_types=1);

namespace Reach\Tests;

use Reach\CallAttempts\WpdbCallAttemptRepository;
use Reach\Tests\Fixtures\WpdbStub;

beforeEach(function () {
    $this->db = function (): WpdbStub {
        return new WpdbStub();
    };
});

test('count without filters has no where clause', function () {
    $db = ($this->db)();
    $db->nextVar = 12;
    $repo = new WpdbCallAttemptRepository($db);

    $this->assertSame(12, $repo->countWhere([]));
    $this->assertStringNotContainsString('WHERE', $db->queries[0]);
});

test('count with all filters builds expected where', function () {
    $db = ($this->db)();
    $repo = new WpdbCallAttemptRepository($db);
    $repo->countWhere([
        'member_id'    => 42,
        'viewer_email' => 'alice@example.com',
        'outcome'      => 'no_answer',
        'since'        => 1_700_000_000,
        'until'        => 1_700_500_000,
    ]);
    $q = $db->queries[0];

    $this->assertStringContainsString('WHERE', $q);
    $this->assertStringContainsString('member_id = 42', $q);
    $this->assertStringContainsString("LIKE '%alice@example.com%'", $q);
    $this->assertStringContainsString("outcome = 'no_answer'", $q);
    $this->assertStringContainsString('created_at >= 1700000000', $q);
    $this->assertStringContainsString('created_at <= 1700500000', $q);
    $this->assertSame(4, substr_count($q, ' AND '));
});

test('list orders by created at desc then id desc', function () {
    // Stable secondary order matters: pagination across rows
    // sharing a timestamp must not skip or duplicate. The
    // assertion exists to flag a regression if someone "tidies
    // up" the ORDER BY.
    $db = ($this->db)();
    $repo = new WpdbCallAttemptRepository($db);
    $repo->list([], 50, 0);

    $this->assertStringContainsString(
        'ORDER BY created_at DESC, id DESC',
        $db->queries[0],
    );
});

test('list clamps limit and offset', function () {
    $db = ($this->db)();
    $repo = new WpdbCallAttemptRepository($db);
    $repo->list([], 99_999, -5);

    $this->assertStringContainsString('LIMIT 500 OFFSET 0', $db->queries[0]);
});

test('list hydrates rows', function () {
    $db = ($this->db)();
    $db->nextResults = [
        [
            'id' => 9, 'member_id' => 1, 'viewer_email' => 'a@x',
            'viewer_provider' => 'google', 'outcome' => 'reached',
            'note' => null, 'created_at' => 100,
        ],
    ];
    $repo = new WpdbCallAttemptRepository($db);
    $rows = $repo->list([], 50, 0);

    $this->assertCount(1, $rows);
    $this->assertSame(9, $rows[0]->id);
    $this->assertSame('reached', $rows[0]->outcome);
    $this->assertNull($rows[0]->note);
});

test('find by id returns null on miss', function () {
    $db = ($this->db)();
    $db->nextRow = null;
    $repo = new WpdbCallAttemptRepository($db);

    $this->assertNull($repo->findById(99));
});

test('find by id hydrates note when present', function () {
    $db = ($this->db)();
    $db->nextRow = [
        'id' => 3, 'member_id' => 7, 'viewer_email' => 'a@x',
        'viewer_provider' => 'apple', 'outcome' => 'no_answer',
        'note' => 'tried twice', 'created_at' => 1_234_567,
    ];
    $repo = new WpdbCallAttemptRepository($db);
    $found = $repo->findById(3);

    $this->assertNotNull($found);
    $this->assertSame('tried twice', $found->note);
});

test('like wildcards are escaped in email filter', function () {
    // Without esc_like(), a viewer_email of "wild%card_user"
    // would match swathes of unrelated rows. The assertion
    // ensures the helper is in the substitution path.
    $db = ($this->db)();
    $repo = new WpdbCallAttemptRepository($db);
    $repo->countWhere(['viewer_email' => 'wild%card_user']);

    $this->assertStringContainsString('wild\\%card\\_user', $db->queries[0]);
});
