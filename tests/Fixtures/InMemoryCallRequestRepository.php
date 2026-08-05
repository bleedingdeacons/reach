<?php

declare(strict_types=1);

namespace Reach\Tests\Fixtures;

use LogicException;
use Reach\CallRequests\CallRequest;
use Reach\CallRequests\CallRequestRepository;

/**
 * A CallRequestRepository backed by an array.
 *
 * markCompleted() is implemented rather than recorded because the admin
 * screen's decision to audit hangs off its return value: it returns true only
 * when a still-pending row was updated, so "already completed" and "unknown
 * id" both have to answer false for the completion path to be tested at all.
 * The arguments are kept in {@see $completions} so a test can also assert who
 * was recorded as having actioned it.
 *
 * {@see $total} exists so the pager's boundaries can be driven without
 * building fifty-odd rows: countAll() reports across every page while list()
 * returns one.
 *
 * create() and delete() throw — neither is reachable from the admin screen
 * (requests are raised on the find page and kept as history), so reaching one
 * from a render or a Completed POST is a bug worth failing on.
 */
final class InMemoryCallRequestRepository implements CallRequestRepository
{
    /** @var array<int, array{id: int, memberId: int, memberName: string, completedAt: int}> */
    public array $completions = [];

    /** @var array<int, array{limit: int, offset: int}> Paging passed to list(), in order. */
    public array $paging = [];

    private int $total;

    /**
     * @param array<int, CallRequest> $rows  What list() hands back.
     * @param int|null                $total What countAll() reports; defaults to the row count.
     */
    public function __construct(private array $rows = [], ?int $total = null)
    {
        $this->total = $total ?? count($rows);
    }

    public function create(
        string $responderName,
        string $area,
        string $viewerEmail,
        string $viewerProvider,
        int $now,
    ): CallRequest {
        throw new LogicException('Call requests are raised on the find page; create() must never be reached from admin');
    }

    /** @return array<int, CallRequest> */
    public function list(int $limit, int $offset): array
    {
        $this->paging[] = ['limit' => $limit, 'offset' => $offset];

        return $this->rows;
    }

    public function countAll(): int
    {
        return $this->total;
    }

    public function countPending(): int
    {
        $pending = 0;
        foreach ($this->rows as $row) {
            if (!$row->isCompleted()) {
                $pending++;
            }
        }

        return $pending;
    }

    public function findById(int $id): ?CallRequest
    {
        foreach ($this->rows as $row) {
            if ($row->id === $id) {
                return $row;
            }
        }

        return null;
    }

    public function markCompleted(int $id, int $memberId, string $memberName, int $completedAt): bool
    {
        foreach ($this->rows as $index => $row) {
            if ($row->id !== $id || $row->isCompleted()) {
                continue;
            }

            $this->completions[] = compact('id', 'memberId', 'memberName', 'completedAt');
            $this->rows[$index] = new CallRequest(
                id: $row->id,
                responderName: $row->responderName,
                area: $row->area,
                viewerEmail: $row->viewerEmail,
                viewerProvider: $row->viewerProvider,
                createdAt: $row->createdAt,
                completedAt: $completedAt,
                completedByMemberId: $memberId,
                completedByName: $memberName,
            );

            return true;
        }

        return false;
    }

    public function delete(int $id): bool
    {
        throw new LogicException('Call requests are kept as history; delete() must never be reached from admin');
    }
}
