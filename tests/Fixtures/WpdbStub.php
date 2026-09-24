<?php

declare(strict_types=1);

namespace Reach\Tests\Fixtures;

/**
 * Minimal wpdb stub. Records each SQL string after prepare() has
 * substituted bound values so tests can assert on the actual query
 * shape rather than the placeholder template. None of the assertions
 * here exercise a real database — we trust MySQL to execute SQL; what
 * we want to lock down is the SQL the repository chooses to emit.
 *
 * Aliased to `wpdb` in tests/bootstrap.php, so every repository test agrees
 * on what `wpdb` resolves to. It used to live in
 * WpdbCallAttemptRepositoryTest.php and be pulled into a dozen other test
 * files by require_once, which only works while every test file is a
 * class PHPUnit loads with include_once; Pest specs are not.
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

    /** @var array<int, array{table: string, where: array<string, mixed>}> */
    public array $deletes = [];
    public int|false $nextDeleteResult = 1;

    public function delete($table, $where, $whereFormat = null): int|false
    {
        $this->deletes[] = ['table' => (string) $table, 'where' => (array) $where];
        return $this->nextDeleteResult;
    }

    /** @var array<int, array{table: string, data: array<string, mixed>}> */
    public array $inserted = [];
    /** @var array<int, array{table: string, data: array<string, mixed>, where: array<string, mixed>}> */
    public array $updated = [];
    public int $insert_id = 0;
    private int $autoId = 0;

    public function insert($table, $data, $formats = null): int
    {
        $this->inserted[] = ['table' => (string) $table, 'data' => (array) $data];
        $this->insert_id = ++$this->autoId;
        return 1;
    }

    public function update($table, $data, $where, $dataFormats = null, $whereFormats = null): int
    {
        $this->updated[] = ['table' => (string) $table, 'data' => (array) $data, 'where' => (array) $where];
        return 1;
    }

    public int|bool $nextQueryResult = 1;

    public function query($sql): int|bool
    {
        $this->queries[] = (string) $sql;
        return $this->nextQueryResult;
    }
}
