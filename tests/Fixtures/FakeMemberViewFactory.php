<?php

declare(strict_types=1);

namespace Reach\Tests\Fixtures;

use Unity\Members\Interfaces\MemberView;
use Unity\Members\Interfaces\MemberViewFactory;

/**
 * MemberViewFactory fake.
 *
 * Lives here rather than at the bottom of ContainerWiringTest, which
 * ControllerRoutesTest used to reach by require_once'ing that whole test file.
 * A fixture under Reach\Tests\ autoloads, so the coupling is gone.
 *
 * Not one of Unity\Testing\Doubles: Reach is the only plugin that fakes this
 * contract, so there is nothing yet to share.
 *
 * Constructed empty it returns nothing, which is all the wiring tests need —
 * they only check that the container hands the right object over. Given views,
 * it behaves like the real thing for the admin pages: hand it the views it
 * knows about and createFromSource() answers with those whose ids were asked
 * for, in the order asked, silently dropping ids it has never heard of (a
 * deleted member), which is exactly the case CallAttemptsPage::memberCell()
 * renders as "(member not found)".
 */
final class FakeMemberViewFactory implements MemberViewFactory
{
    /** @var array<int, MemberView> Keyed by member id. */
    private array $views = [];

    /**
     * @param array<int, MemberView> $views     Answered when their id is asked for.
     * @param array<int, MemberView> $unasked   Returned on every call whether asked for or not.
     *
     * $unasked models the one thing a real factory can do that the map above
     * cannot: hand back a view whose id was never in the source list. Source
     * ids and member ids are not the same thing, so callers that pair the
     * returned views back up with what they asked for carry a "no matching
     * row" branch — MemberSearchPage's distance cell is one — and this is how
     * that branch gets driven.
     */
    public function __construct(array $views = [], private array $unasked = [])
    {
        foreach ($views as $view) {
            $this->views[$view->getId()] = $view;
        }
    }

    /**
     * Ids passed to each call, newest last — lets a test assert that the
     * page batched its lookups instead of resolving one member at a time.
     *
     * @var array<int, array<int, int>>
     */
    public array $calls = [];

    /**
     * @param array<int, int> $sourceIds
     * @return array<int, MemberView>
     */
    public function createFromSource(array $sourceIds): array
    {
        $this->calls[] = array_map('intval', $sourceIds);

        $out = [];
        foreach ($sourceIds as $id) {
            $view = $this->views[(int) $id] ?? null;
            if ($view !== null) {
                $out[] = $view;
            }
        }

        return array_merge($out, $this->unasked);
    }
}
