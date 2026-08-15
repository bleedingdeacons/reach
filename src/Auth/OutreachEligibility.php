<?php

declare(strict_types=1);

namespace Reach\Auth;

if (!defined('ABSPATH')) {
    exit;
}

use Unity\Members\Interfaces\Member;

/**
 * The single answer to "may this member sign in to Reach?".
 *
 * Reach is for members who handle outreach calls: 12th-step volunteers,
 * and certified telephone responders on the helpline. A responder who
 * has only Applied, is In Training, or is Pending has not been cleared
 * to take helpline calls and is turned away — that distinction is the
 * substance of this rule, not a formality.
 *
 * <b>Why this class exists.</b> The rule used to be written out three
 * times — in {@see \Reach\Rest\OAuthController::assertMemberAllowed()},
 * in {@see PasswordAuthenticator::isEligibleMember()}, and in
 * {@see \Reach\Rest\PasswordAuthController::eligibleMember()} — with
 * each copy carrying a docblock claiming to be "kept in lockstep" with
 * the others. They were not. The third omitted the certification check
 * entirely, so an uncertified telephone responder who had set a password
 * could obtain a session through `/auth/login` while the same person was
 * refused through OAuth and could not even be sent a reset link.
 *
 * Three copies of a security rule will drift again given the chance, so
 * there is one now and the call sites ask it rather than restating it.
 *
 * <b>Deliberately static, and deliberately taking a Member.</b> Nothing
 * here needs a repository — each caller already holds the member, or has
 * its own reason for how it looks one up — so making this injectable
 * would have meant changing three constructors and every test that
 * builds them, to no benefit. Resolving an email to a member stays the
 * caller's business; deciding whether that member may sign in does not.
 *
 * Not to be confused with {@see \Reach\Devices\ResponderGate}, which is
 * the Hand app's gate and is stricter: the website admits 12th-steppers,
 * the helpline handset does not.
 */
final class OutreachEligibility
{
    /**
     * Whether a member may hold a Reach session. Null — no member
     * matched the verified email — is refused, so callers can pass a
     * lookup result straight in.
     */
    public static function permits(?Member $member): bool
    {
        if ($member === null) {
            return false;
        }

        if ($member->isTwelfthStepper()) {
            return true;
        }

        return $member->isTelephoneResponder()
            && $member->getResponderCertification()->isCertified();
    }
}
