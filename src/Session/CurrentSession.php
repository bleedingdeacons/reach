<?php

declare(strict_types=1);

namespace Reach\Session;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Auth\OutreachEligibility;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;

/**
 * Request-scoped accessor for the current session — and the point at
 * which a signed cookie becomes an authorisation to act.
 *
 * The underlying cookie is read once per request and cached, so
 * multiple callers (a REST permission_callback and a template
 * redirect, for example) don't each pay the HMAC verification cost.
 *
 * <b>A valid cookie is not the same as a permitted one.</b> The cookie
 * proves who signed in and when; whether that person may still use
 * Reach is a question about the member record, and the answer changes
 * while the cookie is in flight. Roles are withdrawn: a 12th-stepper
 * stands down, a responder's certification lapses, a member is removed
 * from Unity altogether. {@see OutreachEligibility} is therefore
 * re-asked on every request rather than only at sign-in, so that a
 * withdrawal takes effect at the member's next request instead of up to
 * {@see SessionCookie::TTL_SECONDS} later.
 *
 * This mirrors {@see \Reach\Devices\CurrentDevice}, which has always
 * re-run its gate per request and says why: a long-lived credential is
 * a poor place to have frozen an authorisation decision. A 12-hour
 * session cookie is exactly such a credential. The cost is one member
 * lookup per request, which lands on WordPress's object cache, and it
 * is also a saving — callers that used to resolve the viewer's member
 * record themselves now ask {@see member()} for the one already
 * resolved here.
 *
 * Revocation is checked in the same place and for the same reason: a
 * session that has been signed out must stop working everywhere at
 * once, not only where somebody remembered to look. See
 * {@see SessionRevocationList}.
 *
 * The distinction the two accessors draw matters. {@see raw()} is the
 * cookie's claim — who this browser says it is — and is what sign-out
 * needs, because a session being revoked is by definition one that may
 * no longer act. {@see get()} is the authorised session, and is what
 * every other caller wants.
 */
final class CurrentSession
{
    private bool $resolved = false;
    private ?Session $cached = null;

    private bool $authorised = false;
    private ?Member $member = null;

    public function __construct(
        private readonly SessionCookie $cookie,
        private readonly MemberRepository $members,
        private readonly SessionRevocationList $revocations,
    ) {
    }

    /**
     * The current session if it is valid, unrevoked, and belongs to a
     * member who may still use Reach — otherwise null.
     *
     * Null covers every refusal, as it does for devices: no cookie, a
     * tampered or expired one, a signed-out session, an email matching
     * no member, and a member whose role no longer permits access.
     * Callers turn all of them into the same 401.
     */
    public function get(): ?Session
    {
        $this->resolve();

        return $this->authorised ? $this->cached : null;
    }

    /**
     * The member behind the current session, or null when there is no
     * authorised session.
     *
     * Callers that need the viewer's member record — to name them in an
     * audit row, say — should take it from here rather than looking the
     * email up again: it is the same record the authorisation decision
     * was just made on, and asking twice invites the two answers to
     * differ.
     */
    public function member(): ?Member
    {
        $this->resolve();

        return $this->authorised ? $this->member : null;
    }

    /**
     * The session the cookie claims, before the eligibility and
     * revocation checks.
     *
     * Only sign-out should need this. Revoking a session that {@see get()}
     * already refuses is still worth doing — the cookie remains signed
     * and valid, so the copy of it that prompted the sign-out would
     * otherwise stay usable if the member's role were restored.
     */
    public function raw(): ?Session
    {
        $this->resolve();

        return $this->cached;
    }

    public function isAuthenticated(): bool
    {
        return $this->get() !== null;
    }

    /**
     * Force a re-read on the next call — used after the OAuth callback
     * sets a fresh cookie within the same request.
     */
    public function invalidate(): void
    {
        $this->resolved = false;
        $this->cached = null;
        $this->authorised = false;
        $this->member = null;
    }

    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }
        $this->resolved = true;

        $session = $this->cookie->read();
        $this->cached = $session;
        if ($session === null) {
            return;
        }

        if ($this->revocations->isRevoked($session->id)) {
            return;
        }

        $member = $this->members->findByEmail($session->email);
        if (!OutreachEligibility::permits($member)) {
            return;
        }

        $this->member = $member;
        $this->authorised = true;
    }
}
