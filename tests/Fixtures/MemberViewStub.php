<?php

declare(strict_types=1);

namespace Reach\Tests\Fixtures;

use Unity\Members\Interfaces\MemberView;
use Unity\Members\ResponderCertification;

/**
 * An inert MemberView for tests.
 *
 * The admin pages render members through {@see MemberView} — the anonymous,
 * read-only projection — rather than through Member, so covering them needs a
 * double for that contract. Unity ships one for Member
 * ({@see \Unity\Testing\Doubles\MemberStub}) but not yet for MemberView, and
 * Reach is still the only plugin that fakes it, which is the same reasoning
 * {@see FakeMemberViewFactory} records for living here rather than in Unity.
 *
 * Every field is defaulted, so a test names only what it cares about:
 *
 *     new MemberViewStub(id: 7, anonymousName: 'Alice K.', area: 'Bedminster')
 *
 * Note the mobile number is blank by default. These screens are the personal-
 * data surface of a public finder, so a fixture only carries a number when the
 * test is specifically about how numbers are rendered or gated — and then it
 * is an obviously fake one.
 */
final class MemberViewStub implements MemberView
{
    /** @param array<int, string> $accepts */
    public function __construct(
        private int $id = 1,
        private string $anonymousName = 'Test',
        private string $personalEmail = '',
        private string $mobileNumber = '',
        private int $homeGroupId = 0,
        private string $homeGroupName = '',
        private bool $isGSR = false,
        private int $positionId = 0,
        private string $positionName = '',
        private string $rotationDate = '',
        private bool $twelfthStepper = true,
        private bool $telephoneResponder = false,
        private ResponderCertification $responderCertification = ResponderCertification::None,
        private string $area = '',
        private array $accepts = [],
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getAnonymousName(): string
    {
        return $this->anonymousName;
    }

    public function getPersonalEmail(): string
    {
        return $this->personalEmail;
    }

    public function getMobileNumber(): string
    {
        return $this->mobileNumber;
    }

    public function getHomeGroupId(): int
    {
        return $this->homeGroupId;
    }

    public function getHomeGroupName(): string
    {
        return $this->homeGroupName;
    }

    public function hasHomeGroup(): bool
    {
        return $this->homeGroupId > 0;
    }

    public function isGSR(): bool
    {
        return $this->isGSR;
    }

    public function getPositionId(): int
    {
        return $this->positionId;
    }

    public function getPositionName(): string
    {
        return $this->positionName;
    }

    public function hasPosition(): bool
    {
        return $this->positionId > 0;
    }

    public function getRotationDate(): string
    {
        return $this->rotationDate;
    }

    public function isTwelfthStepper(): bool
    {
        return $this->twelfthStepper;
    }

    public function isTelephoneResponder(): bool
    {
        return $this->telephoneResponder;
    }

    public function getResponderCertification(): ResponderCertification
    {
        return $this->responderCertification;
    }

    public function getArea(): string
    {
        return $this->area;
    }

    /** @return array<int, string> */
    public function getAccepts(): array
    {
        return $this->accepts;
    }
}
