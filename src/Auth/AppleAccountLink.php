<?php

declare(strict_types=1);

namespace Reach\Auth;

if (!defined('ABSPATH')) {
    exit;
}

use Guardian\AppleSignIn;
use Unity\Members\Interfaces\MemberRepository;

/**
 * Who a Sign in with Apple belongs to.
 *
 * <b>Apple sends the email reliably only on the first authorisation.</b> A
 * returning user's token may have no email at all, so Reach cannot find the
 * member by address every time. Apple's `sub` (a stable id for this person
 * and this app) is on every token, so the first sign-in whose email matches a
 * member stores it on that member as a Unity extended value, {@see self::KEY}.
 * Every later sign-in finds the member by `sub` and needs no email.
 *
 * In order:
 *
 *   1. A member already carrying this `sub` is the member. The email, if
 *      any, is not consulted: the link outlives a change of address on
 *      either side.
 *   2. No link and no email: refused, `apple_no_email`. Nothing to match on.
 *   3. No link, an email matching a member: that member, to be linked once
 *      the eligibility gate has passed.
 *   4. No link, an email matching nobody: refused. `apple_hidden_email` when
 *      it is a Hide My Email relay address, which can never match a member's
 *      record, and `not_eligible` otherwise, the same as every other
 *      provider.
 *
 * This decides only who; whether they may use Reach is
 * {@see OutreachEligibility}, applied by the caller.
 */
final class AppleAccountLink
{
    /** The extended value key Apple's `sub` is stored under. */
    public const KEY = 'AppleUserId';

    public function __construct(private readonly MemberRepository $members)
    {
    }

    public function resolve(AppleSignIn $apple): AppleLinkResult
    {
        $linked = $this->members->findByValue(self::KEY, $apple->sub);
        if ($linked !== null) {
            return AppleLinkResult::linked($linked);
        }

        if ($apple->email === null) {
            return AppleLinkResult::refused('apple_no_email');
        }

        $member = $this->members->findByEmail($apple->email);
        if ($member !== null) {
            return AppleLinkResult::toLink($member);
        }

        return AppleLinkResult::refused($apple->isPrivateRelay ? 'apple_hidden_email' : 'not_eligible');
    }
}
