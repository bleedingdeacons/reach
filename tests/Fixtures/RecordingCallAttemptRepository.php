<?php

declare(strict_types=1);

namespace Reach\Tests\Fixtures;

use LogicException;
use Reach\CallAttempts\CallAttempt;
use Reach\CallAttempts\CallAttemptRepository;

/**
 * A CallAttemptRepository holding fixed rows and recording what was asked of
 * it.
 *
 * The admin list page converts its query-string filters into the repository's
 * filter shape before querying, so the interesting assertion is not "which
 * rows came back" (the fixture decides that) but "which filters went in".
 * Both {@see $listFilters} and {@see $countFilters} keep every call, so a test
 * can assert the conversion without reimplementing the repository's matching.
 *
 * {@see $total} is separate from the row count on purpose: the pager is driven
 * by countWhere() across all pages while list() returns one page, and the
 * boundary cases only exist when the two differ.
 *
 * record() throws. The call-attempts screen is read-only by design — the audit
 * trail depends on the table being append-only — so a write from an admin
 * render path is a bug, and this makes it a loud one.
 */
final class RecordingCallAttemptRepository implements CallAttemptRepository
{
    /** @var array<int, array<string, mixed>> Filters passed to list(), in order. */
    public array $listFilters = [];

    /** @var array<int, array<string, mixed>> Filters passed to countWhere(), in order. */
    public array $countFilters = [];

    /** @var array<int, array{limit: int, offset: int}> Paging passed to list(), in order. */
    public array $paging = [];

    private int $total;

    /**
     * @param array<int, CallAttempt> $rows  What list() hands back.
     * @param int|null                $total What countWhere() reports; defaults to the row count.
     */
    public function __construct(private array $rows = [], ?int $total = null)
    {
        $this->total = $total ?? count($rows);
    }

    public function record(
        int $memberId,
        string $viewerEmail,
        string $viewerProvider,
        string $outcome,
        ?string $note,
        int $now,
    ): CallAttempt {
        throw new LogicException('The call-attempts admin screen is read-only; record() must never be reached');
    }

    /**
     * @param array<int, int> $memberIds
     * @return array<int, CallAttempt>
     */
    public function forMembersSince(array $memberIds, int $sinceSeconds, int $now): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, CallAttempt>
     */
    public function list(array $filters, int $limit, int $offset): array
    {
        $this->listFilters[] = $filters;
        $this->paging[] = ['limit' => $limit, 'offset' => $offset];

        return $this->rows;
    }

    /** @param array<string, mixed> $filters */
    public function countWhere(array $filters): int
    {
        $this->countFilters[] = $filters;

        return $this->total;
    }

    public function findById(int $id): ?CallAttempt
    {
        foreach ($this->rows as $row) {
            if ($row->id === $id) {
                return $row;
            }
        }

        return null;
    }
}
