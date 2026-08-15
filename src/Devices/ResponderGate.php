<?php

declare(strict_types=1);

namespace Reach\Devices;

if (!defined('ABSPATH')) {
    exit;
}

use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;

/**
 * The single answer to "may this person use Hand?".
 *
 * Hand is stricter than the Reach website. The site admits a
 * 12th-stepper *or* a certified telephone responder, because both have
 * reason to look up members. Hand is the helpline handset: it receives
 * alerts raised for the duty rota, so it admits certified telephone
 * responders and nobody else. A 12th-stepper with no responder role can
 * sign in to the website and will be refused by Hand, which is the
 * intended difference rather than an oversight.
 *
 * <b>Why this is its own class.</b> Reach already carries the cost of
 * duplicating an eligibility rule: the same check is written out three
 * times across OAuthController, PasswordAuthenticator and
 * PasswordAuthController, and the three have drifted — the last of
 * those omits the certification requirement the other two enforce, so
 * an uncertified responder can currently obtain a session by password
 * but not by OAuth. Hand's gate is consulted from four places (both
 * enrolment paths, every authenticated call, and every alert
 * dispatch), so it is written once here and called, never copied.
 *
 * Certification matters and is not a formality: a responder who has
 * Applied, is In Training, or is Pending has not been cleared to take
 * helpline calls. Sending them a caller's details would put an
 * untrained volunteer on a 12th-step call.
 */
final class ResponderGate
{
    public function __construct(private readonly MemberRepository $members)
    {
    }

    /**
     * The member behind an email if they may use Hand, else null.
     *
     * Null covers every refusal — no such member, not a responder,
     * responder but not yet certified — because the caller returns one
     * indistinguishable error for all of them. Nothing about which
     * emails correspond to members should be inferable from a sign-in
     * response.
     */
    public function authorisedMember(string $email): ?Member
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        $member = $this->members->findByEmail($email);
        if ($member === null) {
            return null;
        }

        return $this->isAuthorised($member) ? $member : null;
    }

    /**
     * Whether a member holds a current certification as a telephone
     * responder. Kept separate from {@see authorisedMember()} so callers
     * that already hold a Member — the dispatcher, deciding who an
     * alert may go to — can ask without a second repository round trip.
     */
    public function isAuthorised(?Member $member): bool
    {
        return $member !== null
            && $member->isTelephoneResponder()
            && $member->getResponderCertification()->isCertified();
    }
}
