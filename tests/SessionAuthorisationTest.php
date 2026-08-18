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
final class SessionAuthorisationTest extends ReachTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        WpState::$transients = [];
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        parent::tearDown();
    }

    // --- CurrentSession: a signature is not an authorisation ----------------

    public function testAcceptsSessionForAnEligibleTwelfthStepper(): void
    {
        $current = $this->currentSessionFor('user@example.com');

        $session = $current->get();
        $this->assertNotNull($session);
        $this->assertSame('user@example.com', $session->email);
        $this->assertNotNull($current->member());
    }

    public function testAcceptsSessionForACertifiedTelephoneResponder(): void
    {
        $responder = new MemberStub(
            'responder@example.com',
            twelfthStepper: false,
            telephoneResponder: true,
            responderCertification: ResponderCertification::Certified,
        );

        $current = $this->currentSessionWith($this->sessionFor('responder@example.com'), $responder);

        $this->assertNotNull($current->get());
    }

    public function testRefusesSessionWhoseEmailMatchesNoMember(): void
    {
        $current = $this->currentSessionWith($this->sessionFor('ghost@example.com'), null);

        $this->assertNull($current->get());
        $this->assertNull($current->member());
    }

    public function testRefusesMemberWithNeitherOutreachRole(): void
    {
        $ineligible = new MemberStub(
            'lapsed@example.com',
            twelfthStepper: false,
            telephoneResponder: false,
        );

        $current = $this->currentSessionWith($this->sessionFor('lapsed@example.com'), $ineligible);

        $this->assertNull($current->get());
    }

    /**
     * An uncertified responder is refused. This is the distinction the
     * eligibility rule exists to draw: someone who has Applied or is In
     * Training has not been cleared to take helpline calls.
     */
    public function testRefusesUncertifiedTelephoneResponder(): void
    {
        $uncertified = new MemberStub(
            'training@example.com',
            twelfthStepper: false,
            telephoneResponder: true,
            responderCertification: ResponderCertification::InTraining,
        );

        $current = $this->currentSessionWith($this->sessionFor('training@example.com'), $uncertified);

        $this->assertNull($current->get());
    }

    /**
     * The whole point of re-checking per request: the cookie was minted
     * while the member was eligible and is still perfectly valid, but
     * the role behind it has gone.
     */
    public function testRoleWithdrawnAfterSignInClosesAccessImmediately(): void
    {
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
    }

    public function testRawExposesTheCookiesClaimEvenWhenUnauthorised(): void
    {
        // Sign-out needs this: a session that may no longer act is
        // exactly one worth revoking, and refusing to hand it over
        // would make it unrevocable.
        $current = $this->currentSessionWith($this->sessionFor('ghost@example.com'), null);

        $this->assertNull($current->get());
        $this->assertNotNull($current->raw());
        $this->assertSame('ghost@example.com', $current->raw()->email);
    }

    public function testRawIsNullWhenThereIsNoCookieAtAll(): void
    {
        $current = $this->currentSessionSignedOut();

        $this->assertNull($current->raw());
        $this->assertNull($current->get());
        $this->assertFalse($current->isAuthenticated());
    }

    // --- SessionRevocationList ---------------------------------------------

    public function testRevokedSessionIsRefusedDespiteAValidSignature(): void
    {
        $session = $this->sessionFor('user@example.com');
        $_COOKIE[SessionCookie::COOKIE_NAME] = (new SessionCookie())->sign($session);

        $revocations = new SessionRevocationList();
        $members = new InMemoryMemberRepository([new MemberStub('user@example.com')]);

        $this->assertNotNull((new CurrentSession(new SessionCookie(), $members, $revocations))->get());

        $revocations->revoke($session->id, $session->expiresAt, time());

        $this->assertNull((new CurrentSession(new SessionCookie(), $members, $revocations))->get());
    }

    public function testRevokingOneSessionLeavesAnotherAlone(): void
    {
        $revocations = new SessionRevocationList();
        $mine  = $this->sessionFor('user@example.com');
        $yours = $this->sessionFor('user@example.com');

        $revocations->revoke($mine->id, $mine->expiresAt, time());

        $this->assertTrue($revocations->isRevoked($mine->id));
        $this->assertFalse($revocations->isRevoked($yours->id));
    }

    public function testAnAlreadyExpiredSessionIsNotRecorded(): void
    {
        // Nothing to revoke: it is refused on expiry anyway, and an
        // entry outliving the token it revokes would grow the list for
        // no benefit.
        $revocations = new SessionRevocationList();
        $now = time();

        $revocations->revoke('spent-session', $now - 1, $now);

        $this->assertFalse($revocations->isRevoked('spent-session'));
    }

    public function testSessionsWithoutAnIdAreNeverTreatedAsRevoked(): void
    {
        // Cookies issued before sessions had ids carry none. They must
        // keep working until they expire rather than all being refused
        // at once by an upgrade.
        $revocations = new SessionRevocationList();

        $this->assertFalse($revocations->isRevoked(''));
    }

    public function testALegacySessionWithoutAnIdIsStillAccepted(): void
    {
        $legacy = new Session('user@example.com', 'google', 'sub', time(), time() + 3600);
        $this->assertSame('', $legacy->id);

        $current = $this->currentSessionWith($legacy, new MemberStub('user@example.com'));

        $this->assertNotNull($current->get());
    }

    public function testTheStoredKeyIsNotTheSessionIdItself(): void
    {
        // Option names are not secret; a live session id sitting in one
        // would be a credential in the clear.
        $revocations = new SessionRevocationList();
        $revocations->revoke('a-session-id', time() + 3600, time());

        foreach (array_keys(WpState::$transients) as $key) {
            $this->assertStringNotContainsString('a-session-id', (string) $key);
        }
    }

    // --- SessionCsrf --------------------------------------------------------

    public function testAcceptsTheTokenMintedForThisSession(): void
    {
        $csrf = new SessionCsrf();
        $session = $this->sessionFor('user@example.com');

        $request = new WP_REST_Request();
        $request->set_header(SessionCsrf::HEADER, $csrf->mint($session));

        $this->assertTrue($csrf->verify($request, $session));
    }

    public function testRefusesARequestCarryingNoToken(): void
    {
        $csrf = new SessionCsrf();

        $this->assertFalse($csrf->verify(new WP_REST_Request(), $this->sessionFor('user@example.com')));
    }

    public function testRefusesATokenMintedForAnotherSession(): void
    {
        $csrf = new SessionCsrf();
        $mine  = $this->sessionFor('user@example.com');
        $yours = $this->sessionFor('user@example.com');

        $request = new WP_REST_Request();
        $request->set_header(SessionCsrf::HEADER, $csrf->mint($yours));

        $this->assertFalse($csrf->verify($request, $mine));
    }

    public function testTokenChangesWhenTheSigningSaltRotates(): void
    {
        $csrf = new SessionCsrf();
        $session = $this->sessionFor('user@example.com');

        $before = $csrf->mint($session);
        $this->salts['nonce'] = 'rotated-' . str_repeat('z', 48);
        $after = $csrf->mint($session);

        $this->assertNotSame($before, $after);
    }

    /**
     * Sessions predating ids still get a usable token, bound to what
     * does distinguish them — otherwise an upgrade would lock every
     * open tab out of writing until its cookie expired.
     */
    public function testLegacySessionsGetATokenBoundToTheirIdentity(): void
    {
        $csrf = new SessionCsrf();
        $legacy = new Session('user@example.com', 'google', 'sub', 1_000, 5_000);
        $other  = new Session('user@example.com', 'google', 'sub', 2_000, 6_000);

        $request = new WP_REST_Request();
        $request->set_header(SessionCsrf::HEADER, $csrf->mint($legacy));

        $this->assertTrue($csrf->verify($request, $legacy));
        $this->assertFalse($csrf->verify($request, $other));
    }
}
