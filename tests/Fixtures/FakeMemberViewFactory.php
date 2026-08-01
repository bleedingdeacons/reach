<?php

declare(strict_types=1);

namespace Reach\Tests\Fixtures;

use Unity\Members\Interfaces\MemberViewFactory;

/**
 * Minimal MemberViewFactory fake — the admin pages only store it.
 *
 * Lives here rather than at the bottom of ContainerWiringTest, which
 * ControllerRoutesTest used to reach by require_once'ing that whole test file.
 * A fixture under Reach\Tests\ autoloads, so the coupling is gone.
 *
 * Not one of Unity\Testing\Doubles: Reach is the only plugin that fakes this
 * contract, so there is nothing yet to share.
 */
final class FakeMemberViewFactory implements MemberViewFactory
{
    /**
     * @param array<int, int> $sourceIds
     * @return array<int, mixed>
     */
    public function createFromSource(array $sourceIds): array
    {
        return [];
    }
}
