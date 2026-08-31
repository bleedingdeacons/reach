<?php

declare(strict_types=1);

namespace Reach\Tests\Fixtures;

use Unity\Testing\Doubles\CommitteeStub as UnityCommitteeStub;

/**
 * A Unity committee, defaulted the way Reach's tests want one.
 *
 * The six accessors of Unity\Committees\Interfaces\Committee come from the stub
 * Unity ships, so a change to that contract surfaces in Unity's build rather
 * than as silent drift here. This class carries only what is specific to Reach,
 * the same arrangement as {@see MemberStub}.
 *
 * What is specific is the argument order. Unity's stub leads with the term id,
 * which is right for a general-purpose double; Reach addresses committees by
 * slug and nothing else — it is what the Send Message form posts back and what
 * CommitteeRepository resolves, precisely because term ids differ between
 * sites. So the slug leads here and the id trails as the bookkeeping these
 * tests almost never care about.
 *
 *     new CommitteeStub('telephones', 'Telephones')
 *     new CommitteeStub('pi-health', 'Health', parentId: 2)
 */
final class CommitteeStub extends UnityCommitteeStub
{
    public function __construct(
        string $slug = 'telephones',
        string $name = 'Telephones',
        int $id = 1,
        int $parentId = 0,
        string $description = '',
    ) {
        parent::__construct(
            id: $id,
            slug: $slug,
            name: $name,
            parentId: $parentId,
            description: $description,
        );
    }
}
