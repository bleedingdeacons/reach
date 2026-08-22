<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
use Reach\Auth\DeviceCodeStore;
use Reach\Auth\DeviceRedirectValidator;
use Reach\Auth\DeviceTokenMinter;
use Reach\Auth\PasswordAuthenticator;
use Reach\Auth\PasswordPolicy;
use Reach\Auth\PasswordResetMailer;
use Reach\Auth\ProviderRegistry;
use Reach\Auth\StateStore;
use Reach\Auth\VerifiedIdentity;
use Reach\Core\RateLimiter;
use Reach\Devices\CurrentDevice;
use Reach\Devices\Device;
use Reach\Devices\ResponderGate;
use Reach\Rest\DeviceAuthController;
use Reach\Tests\Fixtures\InMemoryDeviceRepository;
use Reach\Tests\Fixtures\MemberStub;
use Scrutiny\Testing\Doubles\SpyAuditLogger;
use Unity\Members\ResponderCertification;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

// InMemoryPasswordCredentialRepository, and ConfigurableProvider from the
// OAuth controller test. require_once is idempotent.
require_once __DIR__ . '/PasswordAuthenticatorTest.php';
require_once __DIR__ . '/OAuthControllerTest.php';

/**
 * The parts of {@see DeviceAuthController} that
 * {@see DeviceAuthControllerTest} leaves alone: the route declarations,
 * the SSO start hand-off, the whole password enrolment path, the push
 * and session guards, and the rate limiter in front of both enrolment
 * endpoints.
 *
 * The distinction worth keeping in view throughout: a handset holds a
 * long-lived token, so every one of these paths is either handing one
 * out or checking one, and the failure modes have to stay separable —
 * "wrong password", "not certified", "not signed in" and "slow down" are
 * four different answers and the app acts differently on each.
 */
final class DeviceAuthControllerFlowTest extends ReachTestCase
{
    private InMemoryDeviceRepository $devices;
    private DeviceTokenMinter $minter;
    private DeviceCodeStore $codes;
    private InMemoryPasswordCredentialRepository $credentials;
    private ProviderRegistry $providers;
    private SpyAuditLogger $audit;

    protected function setUp(): void
    {
        parent::setUp();

        WpState::$transients = [];
        WpState::$options = [];
        WpState::$restRoutes = [];

        $this->devices = new InMemoryDeviceRepository();
        $this->minter = new DeviceTokenMinter();
        $this->codes = new DeviceCodeStore();
        $this->credentials = new InMemoryPasswordCredentialRepository();
        $this->providers = new ProviderRegistry();
        $this->audit = new SpyAuditLogger();

        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR']);

        parent::tearDown();
    }

    // ── route declarations ────────────────────────────────────────────

    public function testRegisterHangsRouteRegistrationOnRestApiInit(): void
    {
        $this->captureAction('rest_api_init');

        $this->controller()->register();

        $this->assertCount(1, $this->actionCallbacks('rest_api_init'));
    }

    public function testEverySixDeviceRoutesAreDeclared(): void
    {
        $this->controller()->registerRoutes();

        $routes = array_column(WpState::$restRoutes, 'route');
        foreach (['start', 'exchange', 'password', 'push', 'session', 'signout'] as $endpoint) {
            $this->assertContains('/auth/device/' . $endpoint, $routes);
        }
        foreach (WpState::$restRoutes as $route) {
            $this->assertSame(DeviceAuthController::NAMESPACE, $route['namespace']);
        }
    }

    public function testEveryDeviceRouteIsPubliclyReachableAndGuardsItself(): void
    {
        // permission_callback is __return_true throughout because the
        // bearer token is checked inside each callback — there is no
        // WordPress user to gate on. That is deliberate, so it is worth
        // an assertion rather than a review comment.
        $this->controller()->registerRoutes();

        foreach (WpState::$restRoutes as $route) {
            $this->assertSame('__return_true', $route['args']['permission_callback']);
        }
    }

    public function testTheRedirectUriIsNotSanitisedAsText(): void
    {
        // sanitize_text_field strips characters a URI legitimately
        // contains. The value is validated whole against the allow-list
        // instead, which is the stronger check — so a sanitize_callback
        // appearing here would be a regression.
        $this->controller()->registerRoutes();

        $args = $this->routeArgs('/auth/device/start');
        $this->assertArrayNotHasKey('sanitize_callback', $args['redirect_uri']);
        $this->assertTrue($args['redirect_uri']['required']);
    }

    public function testThePasswordIsNotSanitisedEither(): void
    {
        // Sanitising would silently alter a chosen password, so the
        // account would be unenterable by the person who set it.
        $this->controller()->registerRoutes();

        $args = $this->routeArgs('/auth/device/password');
        $this->assertArrayNotHasKey('sanitize_callback', $args['password']);
        $this->assertArrayHasKey('sanitize_callback', $args['email']);
    }

    public function testBothEnrolmentRoutesShareTheSameDeviceArguments(): void
    {
        $this->controller()->registerRoutes();

        foreach (['/auth/device/exchange', '/auth/device/password'] as $route) {
            $args = $this->routeArgs($route);
            $this->assertTrue($args['platform']['required'], "{$route} must require a platform");
            // Optional, and defaulted, so a handset can enrol before
            // Firebase has handed it a registration token.
            $this->assertFalse($args['push_provider']['required']);
            $this->assertSame('', $args['push_token']['default']);
            $this->assertSame('', $args['label']['default']);
        }
    }

    // ── the SSO start hand-off ────────────────────────────────────────

    public function testStartRedirectsToTheProviderForAnAllowedAppScheme(): void
    {
        $this->providers->register(new ConfigurableProvider('google', true, null));
        $controller = $this->controller();

        $result = $controller->start($this->request([
            'provider'     => 'google',
            'redirect_uri' => 'hand://auth',
        ]));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame(302, $result->get_status());
        $this->assertSame('https://provider.test/auth', $result->get_headers()['Location']);
    }

    public function testStartStashesTheAppRedirectWithTheState(): void
    {
        // This is what tells the shared OAuth callback to end in an
        // exchange code rather than in a browser cookie — the only thing
        // separating the two flows.
        $this->providers->register(new ConfigurableProvider('google', true, null));
        $controller = $this->build(new InMemoryMemberRepository([]), new StateStore());

        $controller->start($this->request([
            'provider'     => 'google',
            'redirect_uri' => 'hand://auth',
        ]));

        $this->assertCount(1, WpState::$transients);
        $stashed = (array) reset(WpState::$transients);
        $this->assertSame('hand://auth', $stashed['device_redirect']);
        // A PKCE verifier is minted for the handset flow too, not only
        // for the browser one.
        $this->assertNotSame('', (string) $stashed['code_verifier']);
    }

    public function testStartRefusesAProviderThatIsNotServerSide(): void
    {
        // A client-side provider has no authorization URL for us to send
        // the handset to.
        $this->providers->register(new ConfigurableProvider('apple', false, null));

        $result = $this->controller()->start($this->request([
            'provider'     => 'apple',
            'redirect_uri' => 'hand://auth',
        ]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_unknown_provider', $result->get_error_code());
    }

    public function testStartRefusesAnUnregisteredProvider(): void
    {
        $result = $this->controller()->start($this->request([
            'provider'     => 'myspace',
            'redirect_uri' => 'hand://auth',
        ]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_unknown_provider', $result->get_error_code());
    }

    public function testTheRedirectIsCheckedBeforeTheProviderIs(): void
    {
        // Order matters: probing the allow-list must not be able to also
        // enumerate which providers are configured.
        $result = $this->controller()->start($this->request([
            'provider'     => 'myspace',
            'redirect_uri' => 'https://evil.test/steal',
        ]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_invalid_redirect', $result->get_error_code());
    }

    // ── password enrolment ────────────────────────────────────────────

    public function testPasswordEnrolsACertifiedResponder(): void
    {
        $this->credentials->seedPassword('responder@example.com', 'correct horse battery');
        $controller = $this->controller($this->certified('responder@example.com'));

        $result = $controller->password($this->request([
            'email'    => 'responder@example.com',
            'password' => 'correct horse battery',
            'platform' => 'ios',
            'label'    => 'iPhone 15',
        ]));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame(201, $result->get_status());

        $data = $result->get_data();
        $this->assertTrue($this->minter->looksLikeToken($data['token']));
        $this->assertSame('ios', $data['platform']);
        $this->assertCount(1, $this->devices->devices);
    }

    public function testEnrolmentIssuesAPayloadKeyBesideTheToken(): void
    {
        // The secret alert payloads will be encrypted to. Emitted once,
        // like the token: a handset that loses it enrols afresh, because
        // there is no way to ask for it again.
        $this->credentials->seedPassword('responder@example.com', 'correct horse battery');
        $controller = $this->controller($this->certified('responder@example.com'));

        $result = $controller->password($this->request([
            'email'    => 'responder@example.com',
            'password' => 'correct horse battery',
            'platform' => 'android',
            'label'    => 'Pixel 8',
        ]));

        $this->assertInstanceOf(WP_REST_Response::class, $result);

        $data = $result->get_data();
        $key = $data['payload_key'];

        $this->assertIsString($key);
        $this->assertNotSame('', $key);
        $this->assertNotSame($data['token'], $key, 'the two credentials must be independent');

        // 32 bytes, base64. Anything shorter is not an AES-256 key.
        $raw = base64_decode($key, true);
        $this->assertIsString($raw);
        $this->assertSame(32, strlen($raw));
    }

    public function testEveryEnrolmentGetsItsOwnPayloadKey(): void
    {
        // A key shared between handsets would mean one lost phone reads
        // every other responder's alerts.
        $this->credentials->seedPassword('responder@example.com', 'correct horse battery');
        $controller = $this->controller($this->certified('responder@example.com'));

        $first = $controller->password($this->request([
            'email'    => 'responder@example.com',
            'password' => 'correct horse battery',
            'platform' => 'android',
            'label'    => 'One',
        ]));
        $second = $controller->password($this->request([
            'email'    => 'responder@example.com',
            'password' => 'correct horse battery',
            'platform' => 'android',
            'label'    => 'Two',
        ]));

        $this->assertInstanceOf(WP_REST_Response::class, $first);
        $this->assertInstanceOf(WP_REST_Response::class, $second);
        $this->assertNotSame(
            $first->get_data()['payload_key'],
            $second->get_data()['payload_key'],
        );
    }

    public function testTheIssuedPayloadKeyIsTheOneStoredAgainstTheDevice(): void
    {
        $this->credentials->seedPassword('responder@example.com', 'correct horse battery');
        $controller = $this->controller($this->certified('responder@example.com'));

        $result = $controller->password($this->request([
            'email'    => 'responder@example.com',
            'password' => 'correct horse battery',
            'platform' => 'android',
            'label'    => 'Pixel 8',
        ]));

        $this->assertInstanceOf(WP_REST_Response::class, $result);

        $device = $this->devices->devices[0];

        $this->assertSame(
            $result->get_data()['payload_key'],
            $this->devices->payloadKeyFor($device->id),
        );
    }

    public function testPasswordEnrolmentIsAudited(): void
    {
        // A device token is a long-lived credential over personal data,
        // so handing one out is an auditable event under Scrutiny.
        $this->credentials->seedPassword('responder@example.com', 'correct horse battery');
        $controller = $this->controller($this->certified('responder@example.com'));

        $controller->password($this->request([
            'email'    => 'responder@example.com',
            'password' => 'correct horse battery',
            'platform' => 'android',
        ]));

        $this->assertCount(1, $this->audit->entries);
        $this->assertStringContainsString('Hand device enrolled via password', $this->audit->entries[0]['detail']);
    }

    public function testAWrongPasswordIsRefusedWithoutSayingWhy(): void
    {
        $this->credentials->seedPassword('responder@example.com', 'correct horse battery');
        $controller = $this->controller($this->certified('responder@example.com'));

        $result = $controller->password($this->request([
            'email'    => 'responder@example.com',
            'password' => 'wrong',
            'platform' => 'android',
        ]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_invalid_credentials', $result->get_error_code());
        $this->assertSame(401, $result->get_error_data()['status']);
        $this->assertSame([], $this->devices->devices);
    }

    public function testAnUnknownEmailIsRefusedIdenticallyToAWrongPassword(): void
    {
        // The two must be indistinguishable, or this endpoint becomes an
        // oracle for which addresses hold responder accounts.
        $controller = $this->controller($this->certified('responder@example.com'));

        $result = $controller->password($this->request([
            'email'    => 'nobody@example.com',
            'password' => 'anything',
            'platform' => 'android',
        ]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_invalid_credentials', $result->get_error_code());
    }

    public function testACorrectPasswordFromAnUncertifiedResponderSaysSo(): void
    {
        // Distinct from invalid credentials on purpose: the password was
        // right, so there is nothing left to hide about the account, and
        // "your certification has lapsed" is what the responder needs in
        // order to do something about it.
        $this->credentials->seedPassword('lapsed@example.com', 'correct horse battery');
        $controller = $this->controller(new MemberStub(
            personalEmail: 'lapsed@example.com',
            twelfthStepper: false,
            telephoneResponder: true,
            responderCertification: ResponderCertification::None,
        ));

        $result = $controller->password($this->request([
            'email'    => 'lapsed@example.com',
            'password' => 'correct horse battery',
            'platform' => 'android',
        ]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_not_eligible', $result->get_error_code());
        $this->assertSame(403, $result->get_error_data()['status']);
        $this->assertSame([], $this->devices->devices);
    }

    public function testPasswordEnrolmentStillRequiresAKnownPlatform(): void
    {
        $this->credentials->seedPassword('responder@example.com', 'correct horse battery');
        $controller = $this->controller($this->certified('responder@example.com'));

        $result = $controller->password($this->request([
            'email'    => 'responder@example.com',
            'password' => 'correct horse battery',
            'platform' => 'blackberry',
        ]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_unknown_platform', $result->get_error_code());
    }

    // ── rate limiting ─────────────────────────────────────────────────

    /**
     * @dataProvider enrolmentEndpoints
     */
    public function testEnrolmentIsRateLimitedPerIp(string $method): void
    {
        // 30 attempts per 15 minutes per IP. Generous, because behind a
        // CDN this may be an edge address shared by many responders —
        // but it still has to stop a token-guessing flood.
        $this->credentials->seedPassword('responder@example.com', 'correct horse battery');
        $controller = $this->controller($this->certified('responder@example.com'));
        $limiter = new RateLimiter();
        for ($i = 0; $i < 30; $i++) {
            $limiter->overLimit('device:198.51.100.7', 30, 15 * 60);
        }

        $result = $controller->{$method}($this->request([
            'code'     => 'anything',
            'email'    => 'responder@example.com',
            'password' => 'correct horse battery',
            'platform' => 'android',
        ]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_rate_limited', $result->get_error_code());
        $this->assertSame(429, $result->get_error_data()['status']);
        $this->assertSame([], $this->devices->devices);
    }

    /** @return array<string, array{0: string}> */
    public static function enrolmentEndpoints(): array
    {
        return [
            'sso exchange' => ['exchange'],
            'password'     => ['password'],
        ];
    }

    public function testTheLimitIsKeyedToTheClientIp(): void
    {
        // One responder's flood must not lock out another's enrolment.
        $this->credentials->seedPassword('responder@example.com', 'correct horse battery');
        $controller = $this->controller($this->certified('responder@example.com'));
        $limiter = new RateLimiter();
        for ($i = 0; $i < 30; $i++) {
            $limiter->overLimit('device:203.0.113.9', 30, 15 * 60);
        }

        $result = $controller->password($this->request([
            'email'    => 'responder@example.com',
            'password' => 'correct horse battery',
            'platform' => 'android',
        ]));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
    }

    // ── push registration ─────────────────────────────────────────────

    public function testUpdatePushRequiresALiveToken(): void
    {
        $result = $this->controller()->updatePush($this->request([
            'push_provider' => 'fcm',
            'push_token'    => 'new-token',
        ]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_device_not_authenticated', $result->get_error_code());
        $this->assertSame(401, $result->get_error_data()['status']);
    }

    public function testAPushProviderWithoutATokenIsRefused(): void
    {
        // The half-state — a transport claimed with nothing to send to —
        // is a handset that will never be pushed to and does not know it.
        $controller = $this->controller($this->certified('responder@example.com'));
        $token = $this->enrol($controller, 'responder@example.com');

        $result = $controller->updatePush($this->authed($token, [
            'push_provider' => 'fcm',
            'push_token'    => '   ',
        ]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_missing_push_token', $result->get_error_code());
        $this->assertSame(400, $result->get_error_data()['status']);
    }

    public function testDroppingPushEntirelyNeedsNoToken(): void
    {
        // Turning push off is the one case where an empty token is the
        // correct request rather than a broken one.
        $controller = $this->controller($this->certified('responder@example.com'));
        $token = $this->enrol($controller, 'responder@example.com');

        $result = $controller->updatePush($this->authed($token, [
            'push_provider' => '',
            'push_token'    => '',
        ]));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame(Device::PUSH_NONE, $result->get_data()['push_provider']);
    }

    public function testAnUnrecognisedTransportFallsBackToPollingRatherThanFailing(): void
    {
        // A newer app asking for a transport this server has never heard
        // of should still work, just by pulling.
        $controller = $this->controller($this->certified('responder@example.com'));
        $token = $this->enrol($controller, 'responder@example.com');

        $result = $controller->updatePush($this->authed($token, [
            'push_provider' => 'apns-direct',
            'push_token'    => 'some-token',
        ]));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame(Device::PUSH_NONE, $result->get_data()['push_provider']);
    }

    public function testAnOverlongPushTokenIsCappedRatherThanRefused(): void
    {
        // Firebase tokens have no documented maximum and have grown
        // twice; capping keeps the column safe without inventing a limit
        // the app would have to know about.
        $controller = $this->controller($this->certified('responder@example.com'));
        $token = $this->enrol($controller, 'responder@example.com');

        $result = $controller->updatePush($this->authed($token, [
            'push_provider' => 'fcm',
            'push_token'    => str_repeat('t', 2_000),
        ]));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $device = $this->devices->devices[0];
        $this->assertLessThan(2_000, strlen($device->pushToken));
        $this->assertNotSame('', $device->pushToken);
    }

    // ── session and sign-out ──────────────────────────────────────────

    public function testSessionDescribesTheHandsetAndItsResponder(): void
    {
        $controller = $this->controller($this->certified('responder@example.com'));
        $token = $this->enrol($controller, 'responder@example.com');

        $result = $controller->session($this->authed($token));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $data = $result->get_data();
        $this->assertTrue($data['authorised']);
        $this->assertSame('android', $data['platform']);
        $this->assertSame('Test', $data['responder']);
    }

    public function testSignoutRevokesTheHandset(): void
    {
        $controller = $this->controller($this->certified('responder@example.com'));
        $token = $this->enrol($controller, 'responder@example.com');

        $result = $controller->signout($this->authed($token));

        $this->assertSame(200, $result->get_status());
        $this->assertTrue($result->get_data()['signed_out']);
        $this->assertTrue($this->devices->devices[0]->isRevoked());
    }

    public function testSigningOutTwiceStillReportsSuccess(): void
    {
        // A caller signing out with a token that is already dead has got
        // the outcome it asked for; an error would be an app-side path
        // for a state it cannot act on.
        $controller = $this->controller($this->certified('responder@example.com'));
        $token = $this->enrol($controller, 'responder@example.com');
        $controller->signout($this->authed($token));

        $result = $controller->signout($this->authed($token));

        $this->assertSame(200, $result->get_status());
        $this->assertTrue($result->get_data()['signed_out']);
    }

    public function testSignoutWithNoTokenAtAllStillReportsSuccess(): void
    {
        $result = $this->controller()->signout($this->request());

        $this->assertSame(200, $result->get_status());
        $this->assertTrue($result->get_data()['signed_out']);
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function certified(string $email): MemberStub
    {
        return new MemberStub(
            personalEmail: $email,
            twelfthStepper: false,
            telephoneResponder: true,
            responderCertification: ResponderCertification::Certified,
        );
    }

    private function controller(MemberStub ...$members): DeviceAuthController
    {
        return $this->build(new InMemoryMemberRepository($members));
    }

    private function build(
        InMemoryMemberRepository $members,
        ?StateStore $stateStore = null,
    ): DeviceAuthController {
        $gate = new ResponderGate($members);

        return new DeviceAuthController(
            $this->devices,
            $this->minter,
            $this->codes,
            new DeviceRedirectValidator(),
            $gate,
            new CurrentDevice($this->devices, $this->minter, $gate),
            new PasswordAuthenticator(
                $this->credentials,
                $members,
                new PasswordResetMailer(),
                new PasswordPolicy(),
            ),
            $this->providers,
            $stateStore ?? new StateStore(),
            new RateLimiter(),
            $this->audit,
        );
    }

    /** Enrol a handset through the SSO path and return its token. */
    private function enrol(DeviceAuthController $controller, string $email): string
    {
        $code = $this->codes->issue(new VerifiedIdentity(email: $email, provider: 'google', sub: 'sub'));
        $result = $controller->exchange($this->request(['code' => $code, 'platform' => 'android']));

        $this->assertInstanceOf(WP_REST_Response::class, $result);

        return (string) $result->get_data()['token'];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, array<string, mixed>>
     */
    private function routeArgs(string $route): array
    {
        foreach (WpState::$restRoutes as $registered) {
            if ($registered['route'] === $route) {
                return $registered['args']['args'];
            }
        }

        $this->fail('route not registered: ' . $route);
    }

    /** @param array<string, mixed> $params */
    private function request(array $params = []): WP_REST_Request
    {
        return new WP_REST_Request($params + [
            'label'         => '',
            'push_provider' => '',
            'push_token'    => '',
        ]);
    }

    /** @param array<string, mixed> $params */
    private function authed(string $token, array $params = []): WP_REST_Request
    {
        $request = $this->request($params);
        $request->set_header('authorization', 'Bearer ' . $token);

        return $request;
    }
}
