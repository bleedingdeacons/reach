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
 * <b>A member with an address and a home group, and that is the whole
 * rule.</b> Hand used to admit certified telephone responders and nobody
 * else; it now admits anybody Unity holds a usable record for. The
 * intergroup decided that the rota is not the only reason to carry the
 * handset — the app sends and receives messages between members, and
 * gating that on a helpline certification kept it from the people it was
 * useful to.
 *
 * <b>What that gave up, stated plainly.</b> The old gate meant a lapsed
 * certification stopped a handset at its next call without anybody
 * remembering to revoke it, and it meant the encrypted caller details an
 * alert can carry only ever reached somebody cleared to take a 12th-step
 * call. Neither is true any more. Alerts carrying contact details now
 * reach every enrolled handset the alert is addressed to, whoever holds
 * it, and a member removed from the rota keeps their handset until the
 * record itself changes. Scrutiny still audits every contact read, so
 * the question "who saw this caller's number" remains answerable — what
 * changed is the size of the set it can answer with.
 *
 * <b>Why an address and a home group rather than an address alone.</b>
 * The address is what a handset is matched on: it is the identity a
 * device token is minted for and the thing an alert is routed by, so a
 * member without one cannot be reached and must not be able to enrol.
 * The home group is the sign that a record is a real, current member
 * rather than a stub — Reconcile's imports and half-finished admin
 * entries leave records with a name and nothing else, and those should
 * not be able to put a handset on the rota.
 *
 * <b>Why this is still its own class.</b> Reach already carries the cost
 * of duplicating an eligibility rule: the same check is written out three
 * times across OAuthController, PasswordAuthenticator and
 * PasswordAuthController, and the three have drifted. Hand's gate is
 * consulted from four places (both enrolment paths, every authenticated
 * call, and every alert dispatch), so it is written once here and
 * called, never copied. That mattered more when the rule was strict; it
 * still matters, because a rule in four places is a rule that will
 * differ in four places.
 *
 * This is Hand's gate alone. {@see \Reach\Auth\OutreachEligibility} is
 * the website's and is untouched — the site still admits a 12th-stepper
 * or a certified responder, because who may look members up is a
 * different question from who may carry a handset.
 */
final class ResponderGate
{
    public function __construct(private readonly MemberRepository $members)
    {
    }

    /**
     * The member behind an email if they may use Hand, else null.
     *
     * Null covers every refusal — no such member, no usable address, no
     * home group — because the caller returns one indistinguishable
     * error for all of them. Nothing about which emails correspond to
     * members should be inferable from a sign-in response.
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
     * Whether a member may carry a handset: Unity knows them, they have
     * a usable address, and they have a home group.
     *
     * Kept separate from {@see authorisedMember()} so callers that
     * already hold a Member — the dispatcher, deciding who an alert may
     * go to — can ask without a second repository round trip.
     *
     * <b>The address is validated, not merely present.</b> A record
     * carrying "n/a" or a half-typed address is one an alert can never
     * be delivered to, and letting it enrol would put a handset on the
     * list that silently never rings.
     */
    public function isAuthorised(?Member $member): bool
    {
        if ($member === null) {
            return false;
        }

        $email = strtolower(trim($member->getPersonalEmail()));

        return $email !== ''
            && is_email($email) !== false
            && $member->getHomeGroup() > 0;
    }
}
