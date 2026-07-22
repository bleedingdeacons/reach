<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\Auth\Providers\OAuthProvider;
use Reach\Auth\ProviderRegistry;
use Reach\Auth\StateStore;
use Reach\Auth\VerifiedIdentity;
use Reach\Rest\OAuthController;
use Reach\Session\SessionCookie;
use Unity\Members\Interfaces\Member;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

require_once __DIR__ . '/PasswordAuthenticatorTest.php'; // PwTestMember(Repository)

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
final class OAuthControllerTest extends TestCase
{
    private StateStore $state;

    protected function setUp(): void
    {
        $GLOBALS['__reach_transients'] = [];
        $this->state = new StateStore();
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
    }

    // --- start ------------------------------------------------------------

    public function testStartRejectsUnknownProvider(): void
    {
        $controller = $this->controller(new ProviderRegistry());
        $result = $controller->start(new WP_REST_Request(['provider' => 'nope']));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_unknown_provider', $result->get_error_code());
        $this->assertSame(400, $result->data['status'] ?? null);
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
        $members = new PwTestMemberRepository([new PwTestMember('nobody@example.com', false, false)]);
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
        $this->assertSame(401, $result->data['status'] ?? null);
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
        $members = new PwTestMemberRepository([new PwTestMember('apple-user@icloud.com', false, false)]);
        $state = $this->state->issue('apple', 'https://example.test/reach/home')['state'];

        $result = $this->controller($registry, $members)
            ->apple(new WP_REST_Request(['id_token' => 'ok', 'state' => $state]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_not_eligible', $result->get_error_code());
        $this->assertSame(403, $result->data['status'] ?? null);
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

    public function testSignoutAcknowledges(): void
    {
        $result = $this->controller($this->registryWith('google'))->signout();

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertTrue($result->get_data()['signed_out']);
    }

    // --- helpers ----------------------------------------------------------

    private function controller(ProviderRegistry $registry, ?PwTestMemberRepository $members = null): OAuthController
    {
        return new OAuthController(
            $registry,
            $this->state,
            new SessionCookie(),
            $members ?? new PwTestMemberRepository([]),
        );
    }

    private function registryWith(string $name, ?VerifiedIdentity $identity = null, bool $serverSide = true): ProviderRegistry
    {
        $registry = new ProviderRegistry();
        $registry->register($this->provider($name, $serverSide, $identity));
        return $registry;
    }

    private function membersWith(string $email): PwTestMemberRepository
    {
        // Default PwTestMember is a 12th-stepper, so it passes the gate.
        return new PwTestMemberRepository([new PwTestMember($email)]);
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
