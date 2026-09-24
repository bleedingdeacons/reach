<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
use Reach\Session\CurrentSession;
use Reach\Session\Session;
use Reach\Session\SessionCookie;
use Reach\Session\SessionCsrf;
use Reach\Session\SessionRevocationList;
use Reach\Tests\Fixtures\MemberStub;
use Unity\Members\ResponderCertification;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use WP_REST_Request;

/**
 * Tests for the three classes that decide whether a signed cookie may
 * actually act: {@see CurrentSession}, {@see SessionRevocationList} and
 * {@see SessionCsrf}.
 *
 * The behaviour they exist for is that a valid signature is not an
 * authorisation. Reach's session cookie is a stateless bearer token, so
 * on its own it says only who signed in and when — it cannot know that
 * a certification has since lapsed, that a member has been removed, or
 * that somebody pressed Sign out on another device. Each of those is a
 * case below.
 */

beforeEach(function () {
    WpState::$transients = [];
    WpState::$options = [];
    $_COOKIE = [];
});

afterEach(function () {
    $_COOKIE = [];
});

// --- CurrentSession: a signature is not an authorisation ----------------
test('accepts session for an eligible twelfth stepper', function () {
    $current = $this->currentSessionFor('user@example.com');

    $session = $current->get();
    $this->assertNotNull($session);
    $this->assertSame('user@example.com', $session->email);
    $this->assertNotNull($current->member());
});

test('accepts session for a certified telephone responder', function () {
    $responder = new MemberStub(
        'responder@example.com',
        twelfthStepper: false,
        telephoneResponder: true,
        responderCertification: ResponderCertification::Certified,
    );

    $current = $this->currentSessionWith($this->sessionFor('responder@example.com'), $responder);

    $this->assertNotNull($current->get());
});

test('refuses session whose email matches no member', function () {
    $current = $this->currentSessionWith($this->sessionFor('ghost@example.com'), null);

    $this->assertNull($current->get());
    $this->assertNull($current->member());
});

test('refuses member with neither outreach role', function () {
    $ineligible = new MemberStub(
        'lapsed@example.com',
        twelfthStepper: false,
        telephoneResponder: false,
    );

    $current = $this->currentSessionWith($this->sessionFor('lapsed@example.com'), $ineligible);

    $this->assertNull($current->get());
});

/**
 * An uncertified responder is refused. This is the distinction the
 * eligibility rule exists to draw: someone who has Applied or is In
 * Training has not been cleared to take helpline calls.
 */
test('refuses uncertified telephone responder', function () {
    $uncertified = new MemberStub(
        'training@example.com',
        twelfthStepper: false,
        telephoneResponder: true,
        responderCertification: ResponderCertification::InTraining,
    );

    $current = $this->currentSessionWith($this->sessionFor('training@example.com'), $uncertified);

    $this->assertNull($current->get());
});

/**
 * The whole point of re-checking per request: the cookie was minted
 * while the member was eligible and is still perfectly valid, but
 * the role behind it has gone.
 */
test('role withdrawn after sign in closes access immediately', function () {
    $session = $this->sessionFor('user@example.com');
    $_COOKIE[SessionCookie::COOKIE_NAME] = (new SessionCookie())->sign($session);

    $eligible = new InMemoryMemberRepository([new MemberStub('user@example.com')]);
    $this->assertNotNull(
        (new CurrentSession(new SessionCookie(), $eligible, new SessionRevocationList()))->get(),
        'sanity: the same cookie is accepted while the role stands',
    );

    $withdrawn = new InMemoryMemberRepository([
        new MemberStub('user@example.com', twelfthStepper: false, telephoneResponder: false),
    ]);

    $this->assertNull(
        (new CurrentSession(new SessionCookie(), $withdrawn, new SessionRevocationList()))->get(),
        'the cookie still verifies; the member may no longer use Reach',
    );
});

test('raw exposes the cookies claim even when unauthorised', function () {
    // Sign-out needs this: a session that may no longer act is
    // exactly one worth revoking, and refusing to hand it over
    // would make it unrevocable.
    $current = $this->currentSessionWith($this->sessionFor('ghost@example.com'), null);

    $this->assertNull($current->get());
    $this->assertNotNull($current->raw());
    $this->assertSame('ghost@example.com', $current->raw()->email);
});

test('raw is null when there is no cookie at all', function () {
    $current = $this->currentSessionSignedOut();

    $this->assertNull($current->raw());
    $this->assertNull($current->get());
    $this->assertFalse($current->isAuthenticated());
});

// --- SessionRevocationList ---------------------------------------------
test('revoked session is refused despite a valid signature', function () {
    $session = $this->sessionFor('user@example.com');
    $_COOKIE[SessionCookie::COOKIE_NAME] = (new SessionCookie())->sign($session);

    $revocations = new SessionRevocationList();
    $members = new InMemoryMemberRepository([new MemberStub('user@example.com')]);

    $this->assertNotNull((new CurrentSession(new SessionCookie(), $members, $revocations))->get());

    $revocations->revoke($session->id, $session->expiresAt, time());

    $this->assertNull((new CurrentSession(new SessionCookie(), $members, $revocations))->get());
});

test('revoking one session leaves another alone', function () {
    $revocations = new SessionRevocationList();
    $mine  = $this->sessionFor('user@example.com');
    $yours = $this->sessionFor('user@example.com');

    $revocations->revoke($mine->id, $mine->expiresAt, time());

    $this->assertTrue($revocations->isRevoked($mine->id));
    $this->assertFalse($revocations->isRevoked($yours->id));
});

test('an already expired session is not recorded', function () {
    // Nothing to revoke: it is refused on expiry anyway, and an
    // entry outliving the token it revokes would grow the list for
    // no benefit.
    $revocations = new SessionRevocationList();
    $now = time();

    $revocations->revoke('spent-session', $now - 1, $now);

    $this->assertFalse($revocations->isRevoked('spent-session'));
});

test('sessions without an id are never treated as revoked', function () {
    // Cookies issued before sessions had ids carry none. They must
    // keep working until they expire rather than all being refused
    // at once by an upgrade.
    $revocations = new SessionRevocationList();

    $this->assertFalse($revocations->isRevoked(''));
});

test('a legacy session without an id is still accepted', function () {
    $legacy = new Session('user@example.com', 'google', 'sub', time(), time() + 3600);
    $this->assertSame('', $legacy->id);

    $current = $this->currentSessionWith($legacy, new MemberStub('user@example.com'));

    $this->assertNotNull($current->get());
});

test('the stored id is hashed not stored in the clear', function () {
    // Option contents are not secret - they show up in a database
    // dump and in any admin tool that lists options - so a live
    // session id sitting in one would be a credential in the clear.
    $revocations = new SessionRevocationList();
    $revocations->revoke('a-session-id', time() + 3600, time());

    $stored = WpState::$options;

    $this->assertNotSame([], $stored, 'sanity: something was stored');
    // Neither the option names nor their contents may carry it.
    $this->assertStringNotContainsString('a-session-id', (string) json_encode($stored));
    $this->assertStringNotContainsString('a-session-id', implode('|', array_keys($stored)));
});

/**
 * Revocation is an INSERT per session rather than a rewrite of one
 * shared array, so two sign-outs landing together cannot drop each
 * other's entry. A read-modify-write could, and would fail open.
 */
test('concurrent revocations do not lose each other', function () {
    $now = time();

    // Two instances, as two requests would have, interleaved so that
    // each reads before the other writes.
    $a = new SessionRevocationList();
    $b = new SessionRevocationList();

    $a->revoke('session-a', $now + 3600, $now);
    $b->revoke('session-b', $now + 3600, $now);

    $this->assertTrue($a->isRevoked('session-a'));
    $this->assertTrue($a->isRevoked('session-b'));
});

test('revoking the same session twice is harmless', function () {
    $revocations = new SessionRevocationList();
    $now = time();

    $revocations->revoke('a-session', $now + 3600, $now);
    $revocations->revoke('a-session', $now + 3600, $now);

    $this->assertTrue($revocations->isRevoked('a-session'));
});

/**
 * Revocations must survive what a transient would not. WordPress
 * treats a transient's expiry as a maximum, so an object-cache
 * eviction or a flush can drop one early - and a revocation that
 * disappears fails open, handing a signed-out session back its
 * access.
 */
test('revocations survive a transient flush', function () {
    $revocations = new SessionRevocationList();
    $session = $this->sessionFor('user@example.com');

    $revocations->revoke($session->id, $session->expiresAt, time());

    // Everything a transient store would lose.
    WpState::$transients = [];

    $this->assertTrue($revocations->isRevoked($session->id));
});

test('an entry past its own expiry is treated as absent', function () {
    $revocations = new SessionRevocationList();
    $now = time();

    $revocations->revoke('short-lived', $now + 10, $now);

    $this->assertTrue($revocations->isRevoked('short-lived', $now));
    $this->assertFalse($revocations->isRevoked('short-lived', $now + 11));
});

test('writing prunes entries that have expired', function () {
    // The list is bounded by sign-outs within one session lifetime,
    // which only holds if spent entries actually go.
    $revocations = new SessionRevocationList();
    $now = time();

    $revocations->revoke('old', $now + 10, $now);
    $revocations->revoke('new', $now + 3600, $now + 11);

    $this->assertCount(1, WpState::$options[SessionRevocationList::INDEX_OPTION] ?? []);
    $this->assertTrue($revocations->isRevoked('new', $now + 11));
    $this->assertFalse($revocations->isRevoked('old', $now + 11));
});

test('forget drops a revocation', function () {
    $revocations = new SessionRevocationList();
    $revocations->revoke('a-session', time() + 3600, time());
    $this->assertTrue($revocations->isRevoked('a-session'));

    $revocations->forget('a-session');

    $this->assertFalse($revocations->isRevoked('a-session'));
});

test('a corrupt option means nothing is revoked', function () {
    // It is an option row, so it can be hand-edited or corrupted.
    // That must degrade to "nothing is revoked" rather than fatal
    // on every authenticated request.
    WpState::$options[SessionRevocationList::INDEX_OPTION] = 'not-an-array';

    $this->assertFalse((new SessionRevocationList())->isRevoked('a-session'));
});

// --- SessionCsrf --------------------------------------------------------
test('accepts the token minted for this session', function () {
    $csrf = new SessionCsrf();
    $session = $this->sessionFor('user@example.com');

    $request = new WP_REST_Request();
    $request->set_header(SessionCsrf::HEADER, $csrf->mint($session));

    $this->assertTrue($csrf->verify($request, $session));
});

test('refuses a request carrying no token', function () {
    $csrf = new SessionCsrf();

    $this->assertFalse($csrf->verify(new WP_REST_Request(), $this->sessionFor('user@example.com')));
});

test('refuses a token minted for another session', function () {
    $csrf = new SessionCsrf();
    $mine  = $this->sessionFor('user@example.com');
    $yours = $this->sessionFor('user@example.com');

    $request = new WP_REST_Request();
    $request->set_header(SessionCsrf::HEADER, $csrf->mint($yours));

    $this->assertFalse($csrf->verify($request, $mine));
});

test('token changes when the signing salt rotates', function () {
    $csrf = new SessionCsrf();
    $session = $this->sessionFor('user@example.com');

    $before = $csrf->mint($session);
    $this->salts['nonce'] = 'rotated-' . str_repeat('z', 48);
    $after = $csrf->mint($session);

    $this->assertNotSame($before, $after);
});

/**
 * Sessions predating ids still get a usable token, bound to what
 * does distinguish them — otherwise an upgrade would lock every
 * open tab out of writing until its cookie expired.
 */
test('legacy sessions get a token bound to their identity', function () {
    $csrf = new SessionCsrf();
    $legacy = new Session('user@example.com', 'google', 'sub', 1_000, 5_000);
    $other  = new Session('user@example.com', 'google', 'sub', 2_000, 6_000);

    $request = new WP_REST_Request();
    $request->set_header(SessionCsrf::HEADER, $csrf->mint($legacy));

    $this->assertTrue($csrf->verify($request, $legacy));
    $this->assertFalse($csrf->verify($request, $other));
});
