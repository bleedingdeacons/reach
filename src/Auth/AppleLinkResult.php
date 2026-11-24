<?php

declare(strict_types=1);

namespace Reach\Auth;

if (!defined('ABSPATH')) {
    exit;
}

use Unity\Members\Interfaces\Member;

/**
 * What {@see AppleAccountLink::resolve()} decided.
 *
 * Exactly one of `member` and `refusal` is set. `refusal` is the slug the
 * sign-in page keys its notice on, without the `reach_` prefix. `shouldLink`
 * is true only when the member was found by email and does not yet carry the
 * Apple id, so the caller stores it once the eligibility gate has passed.
 */
final class AppleLinkResult
{
    private function __construct(
        public readonly ?Member $member,
        public readonly bool $shouldLink,
        public readonly ?string $refusal,
    ) {
    }

    public static function linked(Member $member): self
    {
        return new self($member, false, null);
    }

    public static function toLink(Member $member): self
    {
        return new self($member, true, null);
    }

    public static function refused(string $slug): self
    {
        return new self(null, false, $slug);
    }
}
