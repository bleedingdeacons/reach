<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
use Reach\Tests\ReachTestCase;
use Reach\Auth\DeviceCodeStore;
use Reach\Auth\DeviceRedirectValidator;
use Reach\Auth\Providers\OAuthProvider;
use Reach\Auth\ProviderRegistry;
use Reach\Auth\StateStore;
use Reach\Auth\VerifiedIdentity;
use Reach\Devices\ResponderGate;
use Reach\Rest\OAuthController;
use Reach\Session\CurrentSession;
use Reach\Session\SessionCookie;
use Reach\Session\SessionCsrf;
use Reach\Session\SessionRevocationList;
use Unity\Members\Interfaces\Member;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use Reach\Tests\Fixtures\MemberStub;
use Unity\Testing\Doubles\InMemoryMemberRepository;

require_once __DIR__ . '/PasswordAuthenticatorTest.php'; // MemberStub(Repository)

/**
 * Tests for {@see OAuthController} — the authentication surface itself.
 *
 * The controller has no permission gate (these routes *are* the sign-in), so
 * the security rests on: single-use CSRF state, refusing anonymised relay
 * emails, the member-eligibility gate, and clamping the post-sign-in
 * return_to to this site. Each of those is exercised here, for both the
 * server-side redirect flow and Apple's client-side POST. A configurable
 * fake provider stands in for a real OAuth provider so the controller logic
 * is tested in isolation from JWT verification (covered separately).
 */
final class OAuthControllerTest extends ReachTestCase
{
    private StateStore $state;

    protected function setUp(): void
    {
        parent::setUp();

        WpState::$transients = [];
        $this->state = new StateStore();
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        parent::tearDown();
    }

    // --- start ------------------------------------------------------------

    public function testStartRejectsUnknownProvider(): void
    {
        $controller = $this->controller(new ProviderRegistry());
        $result = $controller->start(new WP_REST_Request(['provider' => 'nope']));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_unknown_provider', $result->get_error_code());
        $this->assertSame(400, $result->get_error_data()['status'] ?? null);
    }

    public function testStartRejectsClientSideProvider(): void
    {
        $registry = new ProviderRegistry();
        $registry->register($this->provider('apple', serverSide: false));
        $result = $this->controller($registry)->start(new WP_REST_Request(['provider' => 'apple']));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_unknown_provider', $result->get_error_code());
    }

    public function testStartRedirectsToProviderAuthorisationUrl(): void
    {
        $registry = new ProviderRegistry();
        $registry->register($this->provider('google', serverSide: true));

        $result = $this->controller($registry)->start(new WP_REST_Request(['provider' => 'google']));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame(302, $result->get_status());
        $this->assertSame('https://provider.test/auth', $result->get_headers()['Location'] ?? null);
    }

    // --- callback (server-side flow) --------------------------------------

    public function testCallbackWithUnknownStateRedirectsToSigninFailed(): void
    {
        $result = $this->controller($this->registryWith('google'))
            ->callback(new WP_REST_Request(['state' => 'never-issued', 'code' => 'x']));

        $this->assertRedirectsToSigninError($result, 'signin_failed');
    }

    public function testCallbackWithFailedExchangeRedirectsToSigninFailed(): void
    {
        $registry = $this->registryWith('google', identity: null); // handleCallback → null
        $state = $this->state->issue('google', 'https://example.test/reach/home')['state'];

        $result = $this->controller($registry)
            ->callback(new WP_REST_Request(['state' => $state, 'code' => 'bad']));

        $this->assertRedirectsToSigninError($result, 'signin_failed');
    }

    public function testCallbackRefusesAnonymisedRelayEmail(): void
    {
        $identity = new VerifiedIdentity('x@privaterelay.facebook.com', 'facebook', 'sub-1');
        $registry = $this->registryWith('facebook', identity: $identity);
        $state = $this->state->issue('facebook', 'https://example.test/reach/home')['state'];

        $result = $this->controller($registry, $this->membersWith($identity->email))
            ->callback(new WP_REST_Request(['state' => $state, 'code' => 'ok']));

        $this->assertRedirectsToSigninError($result, 'email_required');
    }

    public function testCallbackRejectsIneligibleMember(): void
    {
        $identity = new VerifiedIdentity('nobody@example.com', 'google', 'sub-1');
        $registry = $this->registryWith('google', identity: $identity);
        // Member with neither outreach role.
        $members = new InMemoryMemberRepository([new MemberStub('nobody@example.com', false, false)]);
        $state = $this->state->issue('google', 'https://example.test/reach/home')['state'];

        $result = $this->controller($registry, $members)
            ->callback(new WP_REST_Request(['state' => $state, 'code' => 'ok']));

        $this->assertRedirectsToSigninError($result, 'not_eligible');
    }

    public function testCallbackHappyPathIssuesSessionAndRedirectsToReturnTo(): void
    {
        $identity = new VerifiedIdentity('member@example.com', 'google', 'sub-9');
        $registry = $this->registryWith('google', identity: $identity);
        $members = $this->membersWith('member@example.com');
        $state = $this->state->issue('google', 'https://example.test/reach/find')['state'];

        $result = $this->controller($registry, $members)
            ->callback(new WP_REST_Request(['state' => $state, 'code' => 'ok']));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame(302, $result->get_status());
        // return_to is honoured (and, being same-host, survives the clamp).
        $this->assertSame('https://example.test/reach/find', $result->get_headers()['Location'] ?? null);
    }

    public function testCallbackIsSingleUseState(): void
    {
        $identity = new VerifiedIdentity('member@example.com', 'google', 'sub-9');
        $registry = $this->registryWith('google', identity: $identity);
        $members = $this->membersWith('member@example.com');
        $state = $this->state->issue('google', 'https://example.test/reach/home')['state'];
        $controller = $this->controller($registry, $members);

        $controller->callback(new WP_REST_Request(['state' => $state, 'code' => 'ok']));
        // Replaying the same state must now fail — the transient was consumed.
        $replay = $controller->callback(new WP_REST_Request(['state' => $state, 'code' => 'ok']));
        $this->assertRedirectsToSigninError($replay, 'signin_failed');
    }

    // --- apple (client-side POST) -----------------------------------------

    public function testAppleRejectsInvalidState(): void
    {
        $result = $this->controller($this->registryWith('apple'))
            ->apple(new WP_REST_Request(['id_token' => 't', 'state' => 'nope']));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_invalid_state', $result->get_error_code());
    }

    public function testAppleRejectsStateIssuedForAnotherProvider(): void
    {
        $state = $this->state->issue('google', 'https://example.test/reach/home')['state'];
        $result = $this->controller($this->registryWith('apple'))
            ->apple(new WP_REST_Request(['id_token' => 't', 'state' => $state]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_invalid_state', $result->get_error_code());
    }

    public function testAppleReturnsAuthErrorWhenTokenInvalid(): void
    {
        $registry = $this->registryWith('apple', identity: null, serverSide: false); // verifyIdToken → null
        $state = $this->state->issue('apple', 'https://example.test/reach/home')['state'];

        $result = $this->controller($registry)
            ->apple(new WP_REST_Request(['id_token' => 'bad', 'state' => $state]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_signin_failed', $result->get_error_code());
        $this->assertSame(401, $result->get_error_data()['status'] ?? null);
    }

    public function testAppleHappyPathIssuesSessionAndReturnsRedirectJson(): void
    {
        $identity = new VerifiedIdentity('apple-user@icloud.com', 'apple', 'sub-a');
        $registry = $this->registryWith('apple', identity: $identity, serverSide: false);
        $members = $this->membersWith('apple-user@icloud.com');
        $state = $this->state->issue('apple', 'https://example.test/reach/home')['state'];

        $result = $this->controller($registry, $members)
            ->apple(new WP_REST_Request(['id_token' => 'ok', 'state' => $state]));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame(200, $result->get_status());
        $this->assertArrayHasKey('redirect', $result->get_data());
    }

    public function testAppleRejectsIneligibleMember(): void
    {
        $identity = new VerifiedIdentity('apple-user@icloud.com', 'apple', 'sub-a');
        $registry = $this->registryWith('apple', identity: $identity, serverSide: false);
        $members = new InMemoryMemberRepository([new MemberStub('apple-user@icloud.com', false, false)]);
        $state = $this->state->issue('apple', 'https://example.test/reach/home')['state'];

        $result = $this->controller($registry, $members)
            ->apple(new WP_REST_Request(['id_token' => 'ok', 'state' => $state]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_not_eligible', $result->get_error_code());
        $this->assertSame(403, $result->get_error_data()['status'] ?? null);
    }

    // --- appleStart / signout ---------------------------------------------

    public function testAppleStartReturnsStateAndNonce(): void
    {
        $result = $this->controller($this->registryWith('apple'))->appleStart(new WP_REST_Request());
        $data = $result->get_data();
        $this->assertArrayHasKey('state', $data);
        $this->assertArrayHasKey('nonce', $data);
        $this->assertNotSame('', $data['state']);
    }

    public function testSignoutAcknowledgesWhenNobodyIsSignedIn(): void
    {
        // No cookie, so nothing to revoke and no token to demand. The
        // caller still gets the outcome it asked for: saying otherwise
        // would tell an unauthenticated prober whether a cookie was
        // valid.
        $result = $this->controller($this->registryWith('google'))->signout(new WP_REST_Request());

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertTrue($result->get_data()['signed_out']);
    }

    public function testSignoutRevokesTheSessionServerSide(): void
    {
        $members = new InMemoryMemberRepository([new MemberStub('user@example.com')]);
        $session = $this->sessionFor('user@example.com');
        $_COOKIE[SessionCookie::COOKIE_NAME] = (new SessionCookie())->sign($session);

        $revocations = new SessionRevocationList();
        $controller  = $this->controllerWithRevocations($members, $revocations);

        $result = $controller->signout($this->withSessionToken(new WP_REST_Request(), $session));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertTrue($result->get_data()['signed_out']);

        // The point of the whole exercise: the token is dead even
        // though it is still signed, unexpired, and would still verify.
        $this->assertTrue($revocations->isRevoked($session->id));
    }

    /**
     * A revoked session stops being accepted everywhere, not merely in
     * the browser that pressed Sign out — which is what makes sign-out
     * mean something for a stateless cookie.
     */
    public function testRevokedSessionIsNoLongerAccepted(): void
    {
        $members = new InMemoryMemberRepository([new MemberStub('user@example.com')]);
        $session = $this->sessionFor('user@example.com');
        $_COOKIE[SessionCookie::COOKIE_NAME] = (new SessionCookie())->sign($session);

        $revocations = new SessionRevocationList();
        $current = new CurrentSession(new SessionCookie(), $members, $revocations);

        $this->assertNotNull($current->get(), 'sanity: the session is good before revocation');

        $revocations->revoke($session->id, $session->expiresAt, time());

        $this->assertNull(
            (new CurrentSession(new SessionCookie(), $members, $revocations))->get(),
            'a revoked session must not be accepted, however valid its signature',
        );
    }

    public function testSignoutRefusesWithoutTheSessionToken(): void
    {
        $members = new InMemoryMemberRepository([new MemberStub('user@example.com')]);
        $session = $this->sessionFor('user@example.com');
        $_COOKIE[SessionCookie::COOKIE_NAME] = (new SessionCookie())->sign($session);

        $revocations = new SessionRevocationList();
        $controller  = $this->controllerWithRevocations($members, $revocations);

        // No X-Reach-Token header: a cross-site page must not be able to
        // sign a responder out mid-shift.
        $result = $controller->signout(new WP_REST_Request());

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_invalid_session_token', $result->get_error_code());
        $this->assertSame(403, $result->get_error_data()['status'] ?? null);
        $this->assertFalse($revocations->isRevoked($session->id));
    }

    // --- helpers ----------------------------------------------------------

    private function controller(ProviderRegistry $registry, ?InMemoryMemberRepository $members = null): OAuthController
    {
        $repository = $members ?? new InMemoryMemberRepository([]);

        return new OAuthController(
            $registry,
            $this->state,
            new SessionCookie(),
            $repository,
            // The device-flow collaborators. These tests exercise the
            // browser paths, where no device redirect is ever stashed,
            // so the three are present to satisfy the constructor and
            // are never reached. DeviceAuthControllerTest covers the
            // branch that does use them.
            new DeviceCodeStore(),
            new DeviceRedirectValidator(),
            new ResponderGate($repository),
            // Session-lifecycle collaborators. CurrentSession is what
            // sign-out revokes through, and the CSRF token is what a
            // cookie-authenticated write must present. Built over the
            // same member repository so an issued session resolves to
            // the same records the rest of the test sees.
            new CurrentSession(new SessionCookie(), $repository, new SessionRevocationList()),
            new SessionRevocationList(),
            new SessionCsrf(),
        );
    }

    /**
     * A controller sharing an explicit revocation list, so a test can
     * inspect what sign-out actually recorded.
     */
    private function controllerWithRevocations(
        InMemoryMemberRepository $members,
        SessionRevocationList $revocations
    ): OAuthController {
        return new OAuthController(
            $this->registryWith('google'),
            $this->state,
            new SessionCookie(),
            $members,
            new DeviceCodeStore(),
            new DeviceRedirectValidator(),
            new ResponderGate($members),
            new CurrentSession(new SessionCookie(), $members, $revocations),
            $revocations,
            new SessionCsrf(),
        );
    }

    private function registryWith(string $name, ?VerifiedIdentity $identity = null, bool $serverSide = true): ProviderRegistry
    {
        $registry = new ProviderRegistry();
        $registry->register($this->provider($name, $serverSide, $identity));
        return $registry;
    }

    private function membersWith(string $email): InMemoryMemberRepository
    {
        // Default MemberStub is a 12th-stepper, so it passes the gate.
        return new InMemoryMemberRepository([new MemberStub($email)]);
    }

    private function provider(string $name, bool $serverSide = true, ?VerifiedIdentity $identity = null): OAuthProvider
    {
        return new ConfigurableProvider($name, $serverSide, $identity);
    }

    private function assertRedirectsToSigninError(mixed $result, string $slug): void
    {
        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame(302, $result->get_status());
        $location = $result->get_headers()['Location'] ?? '';
        $this->assertStringContainsString('/reach/signin', $location);
        $this->assertStringContainsString('reach_error=' . $slug, $location);
    }
}

/**
 * Configurable OAuthProvider double: fixed authorisation URL, and a preset
 * identity (or null) returned from both handleCallback and verifyIdToken.
 */
final class ConfigurableProvider implements OAuthProvider
{
    public function __construct(
        private string $providerName,
        private bool $serverSide,
        private ?VerifiedIdentity $identity,
    ) {
    }

    public function name(): string
    {
        return $this->providerName;
    }

    public function isServerSide(): bool
    {
        return $this->serverSide;
    }

    public function getAuthorizationUrl(string $state, string $nonce, string $redirectUri, ?string $codeVerifier = null): string
    {
        return 'https://provider.test/auth';
    }

    public function handleCallback(string $code, string $nonce, string $redirectUri, ?string $codeVerifier = null): ?VerifiedIdentity
    {
        return $this->identity;
    }

    public function verifyIdToken(string $idToken, string $nonce): ?VerifiedIdentity
    {
        return $this->identity;
    }
}
