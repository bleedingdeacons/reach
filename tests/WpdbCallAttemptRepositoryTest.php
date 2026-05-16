<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\CallAttempts\WpdbCallAttemptRepository;
use wpdb;

if (!class_exists('wpdb')) {
    class_alias(WpdbStub::class, 'wpdb');
}

/**
 * Minimal wpdb stub. Records each SQL string after prepare() has
 * substituted bound values so tests can assert on the actual query
 * shape rather than the placeholder template. None of the assertions
 * here exercise a real database — we trust MySQL to execute SQL; what
 * we want to lock down is the SQL the repository chooses to emit.
 */
class WpdbStub
{
    public string $prefix = 'wp_';
    /** @var array<int, string> */
    public array $queries = [];
    public array $nextResults = [];
    public ?array $nextRow = null;
    public int|string|null $nextVar = 0;

    public function get_charset_collate(): string
    {
        return '';
    }

    public function esc_like(string $s): string
    {
        return addcslashes($s, '_%\\');
    }

    public function prepare($query, ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $out = $query;
        foreach ($args as $a) {
            $repl = is_int($a) || (is_string($a) && ctype_digit($a))
                ? (string) (int) $a
                : "'" . str_replace("'", "''", (string) $a) . "'";
            $out = (string) preg_replace('/%[ds]/', $repl, $out, 1);
        }
        return $out;
    }

    public function get_results($sql, $mode = 'ARRAY_A'): array
    {
        $this->queries[] = (string) $sql;
        return $this->nextResults;
    }

    public function get_row($sql, $mode = 'ARRAY_A'): ?array
    {
        $this->queries[] = (string) $sql;
        return $this->nextRow;
    }

    public function get_var($sql)
    {
        $this->queries[] = (string) $sql;
        return $this->nextVar;
    }
}

final class WpdbCallAttemptRepositoryTest extends TestCase
{
    public function testCountWithoutFiltersHasNoWhereClause(): void
    {
        $db = $this->db();
        $db->nextVar = 12;
        $repo = new WpdbCallAttemptRepository($db);

        $this->assertSame(12, $repo->countWhere([]));
        $this->assertStringNotContainsString('WHERE', $db->queries[0]);
    }

    public function testCountWithAllFiltersBuildsExpectedWhere(): void
    {
        $db = $this->db();
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
    }

    public function testListOrdersByCreatedAtDescThenIdDesc(): void
    {
        // Stable secondary order matters: pagination across rows
        // sharing a timestamp must not skip or duplicate. The
        // assertion exists to flag a regression if someone "tidies
        // up" the ORDER BY.
        $db = $this->db();
        $repo = new WpdbCallAttemptRepository($db);
        $repo->list([], 50, 0);

        $this->assertStringContainsString(
            'ORDER BY created_at DESC, id DESC',
            $db->queries[0],
        );
    }

    public function testListClampsLimitAndOffset(): void
    {
        $db = $this->db();
        $repo = new WpdbCallAttemptRepository($db);
        $repo->list([], 99_999, -5);

        $this->assertStringContainsString('LIMIT 500 OFFSET 0', $db->queries[0]);
    }

    public function testListHydratesRows(): void
    {
        $db = $this->db();
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
    }

    public function testFindByIdReturnsNullOnMiss(): void
    {
        $db = $this->db();
        $db->nextRow = null;
        $repo = new WpdbCallAttemptRepository($db);

        $this->assertNull($repo->findById(99));
    }

    public function testFindByIdHydratesNoteWhenPresent(): void
    {
        $db = $this->db();
        $db->nextRow = [
            'id' => 3, 'member_id' => 7, 'viewer_email' => 'a@x',
            'viewer_provider' => 'apple', 'outcome' => 'no_answer',
            'note' => 'tried twice', 'created_at' => 1_234_567,
        ];
        $repo = new WpdbCallAttemptRepository($db);
        $found = $repo->findById(3);

        $this->assertNotNull($found);
        $this->assertSame('tried twice', $found->note);
    }

    public function testLikeWildcardsAreEscapedInEmailFilter(): void
    {
        // Without esc_like(), a viewer_email of "wild%card_user"
        // would match swathes of unrelated rows. The assertion
        // ensures the helper is in the substitution path.
        $db = $this->db();
        $repo = new WpdbCallAttemptRepository($db);
        $repo->countWhere(['viewer_email' => 'wild%card_user']);

        $this->assertStringContainsString('wild\\%card\\_user', $db->queries[0]);
    }

    private function db(): WpdbStub
    {
        return new WpdbStub();
    }
}
