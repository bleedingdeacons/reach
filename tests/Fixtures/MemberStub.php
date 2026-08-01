<?php

declare(strict_types=1);

namespace Reach\Tests\Fixtures;

use Unity\Members\ResponderCertification;
use Unity\Testing\Doubles\MemberStub as UnityMemberStub;

/**
 * A Unity member, defaulted the way Reach's tests want one.
 *
 * The 23 accessors of Unity\Members\Interfaces\Member come from the stub Unity
 * ships, so a change to that contract surfaces in Unity's build rather than as
 * silent drift here. This class carries only what is specific to Reach.
 *
 * There were five hand-written Member doubles in this suite before it — one
 * named class and four anonymous ones inlined in test files — and they agreed
 * on every one of these defaults while parameterising different subsets of
 * fields. Everything is defaulted here, so each test's helper names only what
 * it varies.
 *
 * Why these defaults: Reach is a public finder for 12th-step members, so a
 * member is a twelfth-stepper and is visible (showAnonymousName,
 * showMemberProfile, isGdprAccepted) unless a test says otherwise — the
 * visibility gates are what most of these tests are about, and starting them
 * closed would mean setting three flags on nearly every fixture.
 */
final class MemberStub extends UnityMemberStub
{
    /** @param array<int, string> $accepts */
    public function __construct(
        string $personalEmail = '',
        bool $twelfthStepper = true,
        bool $telephoneResponder = false,
        int $id = 1,
        ResponderCertification $responderCertification = ResponderCertification::None,
        string $anonymousName = 'Test',
        string $area = '',
        array $accepts = [],
    ) {
        parent::__construct(
            id: $id,
            anonymousName: $anonymousName,
            showAnonymousName: true,
            showMemberProfile: true,
            personalEmail: $personalEmail,
            twelfthStepper: $twelfthStepper,
            telephoneResponder: $telephoneResponder,
            responderCertification: $responderCertification,
            area: $area,
            accepts: $accepts,
            gdprAccepted: true,
        );
    }
}
