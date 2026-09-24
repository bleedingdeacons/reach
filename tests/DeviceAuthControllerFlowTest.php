<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
use Unity\Testing\Doubles\InMemoryPasswordCredentialRepository;
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
use Reach\Tests\Fixtures\ConfigurableProvider;

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

beforeEach(function () {
    // ── helpers ───────────────────────────────────────────────────────
    $this->certified = function (string $email): MemberStub {
        return new MemberStub(
            personalEmail: $email,
            twelfthStepper: false,
            telephoneResponder: true,
            responderCertification: ResponderCertification::Certified,
        );
    };

    $this->controller = function (MemberStub ...$members): DeviceAuthController {
        return ($this->build)(new InMemoryMemberRepository($members));
    };

    $this->build = function (
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
    };

    /** Enrol a handset through the SSO path and return its token. */
    $this->enrol = function (DeviceAuthController $controller, string $email): string {
        $code = $this->codes->issue(new VerifiedIdentity(email: $email, provider: 'google', sub: 'sub'));
        $result = $controller->exchange(($this->request)(['code' => $code, 'platform' => 'android']));

        $this->assertInstanceOf(WP_REST_Response::class, $result);

        return (string) $result->get_data()['token'];
    };

    /**
     * @param array<string, mixed> $params
     * @return array<string, array<string, mixed>>
     */
    $this->routeArgs = function (string $route): array {
        foreach (WpState::$restRoutes as $registered) {
            if ($registered['route'] === $route) {
                return $registered['args']['args'];
            }
        }

        $this->fail('route not registered: ' . $route);
    };

    /** @param array<string, mixed> $params */
    $this->request = function (array $params = []): WP_REST_Request {
        return new WP_REST_Request($params + [
            'label'         => '',
            'push_provider' => '',
            'push_token'    => '',
        ]);
    };

    /** @param array<string, mixed> $params */
    $this->authed = function (string $token, array $params = []): WP_REST_Request {
        $request = ($this->request)($params);
        $request->set_header('authorization', 'Bearer ' . $token);

        return $request;
    };

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
});

afterEach(function () {
    unset($_SERVER['REMOTE_ADDR']);
});

// ── route declarations ────────────────────────────────────────────
test('register hangs route registration on rest api init', function () {
    $this->captureAction('rest_api_init');

    ($this->controller)()->register();

    $this->assertCount(1, $this->actionCallbacks('rest_api_init'));
});

test('every six device routes are declared', function () {
    ($this->controller)()->registerRoutes();

    $routes = array_column(WpState::$restRoutes, 'route');
    foreach (['start', 'exchange', 'password', 'push', 'session', 'signout'] as $endpoint) {
        $this->assertContains('/auth/device/' . $endpoint, $routes);
    }
    foreach (WpState::$restRoutes as $route) {
        $this->assertSame(DeviceAuthController::NAMESPACE, $route['namespace']);
    }
});

test('every device route is publicly reachable and guards itself', function () {
    // permission_callback is __return_true throughout because the
    // bearer token is checked inside each callback — there is no
    // WordPress user to gate on. That is deliberate, so it is worth
    // an assertion rather than a review comment.
    ($this->controller)()->registerRoutes();

    foreach (WpState::$restRoutes as $route) {
        $this->assertSame('__return_true', $route['args']['permission_callback']);
    }
});

test('the redirect uri is not sanitised as text', function () {
    // sanitize_text_field strips characters a URI legitimately
    // contains. The value is validated whole against the allow-list
    // instead, which is the stronger check — so a sanitize_callback
    // appearing here would be a regression.
    ($this->controller)()->registerRoutes();

    $args = ($this->routeArgs)('/auth/device/start');
    $this->assertArrayNotHasKey('sanitize_callback', $args['redirect_uri']);
    $this->assertTrue($args['redirect_uri']['required']);
});

test('the password is not sanitised either', function () {
    // Sanitising would silently alter a chosen password, so the
    // account would be unenterable by the person who set it.
    ($this->controller)()->registerRoutes();

    $args = ($this->routeArgs)('/auth/device/password');
    $this->assertArrayNotHasKey('sanitize_callback', $args['password']);
    $this->assertArrayHasKey('sanitize_callback', $args['email']);
});

test('both enrolment routes share the same device arguments', function () {
    ($this->controller)()->registerRoutes();

    foreach (['/auth/device/exchange', '/auth/device/password'] as $route) {
        $args = ($this->routeArgs)($route);
        $this->assertTrue($args['platform']['required'], "{$route} must require a platform");
        // Optional, and defaulted, so a handset can enrol before
        // Firebase has handed it a registration token.
        $this->assertFalse($args['push_provider']['required']);
        $this->assertSame('', $args['push_token']['default']);
        $this->assertSame('', $args['label']['default']);
    }
});

// ── the SSO start hand-off ────────────────────────────────────────
test('start redirects to the provider for an allowed app scheme', function () {
    $this->providers->register(new ConfigurableProvider('google', true, null));
    $controller = ($this->controller)();

    $result = $controller->start(($this->request)([
        'provider'     => 'google',
        'redirect_uri' => 'hand://auth',
    ]));

    $this->assertInstanceOf(WP_REST_Response::class, $result);
    $this->assertSame(302, $result->get_status());
    $this->assertSame('https://provider.test/auth', $result->get_headers()['Location']);
});

test('start stashes the app redirect with the state', function () {
    // This is what tells the shared OAuth callback to end in an
    // exchange code rather than in a browser cookie — the only thing
    // separating the two flows.
    $this->providers->register(new ConfigurableProvider('google', true, null));
    $controller = ($this->build)(new InMemoryMemberRepository([]), new StateStore());

    $controller->start(($this->request)([
        'provider'     => 'google',
        'redirect_uri' => 'hand://auth',
    ]));

    $this->assertCount(1, WpState::$transients);
    $stashed = (array) reset(WpState::$transients);
    $this->assertSame('hand://auth', $stashed['device_redirect']);
    // A PKCE verifier is minted for the handset flow too, not only
    // for the browser one.
    $this->assertNotSame('', (string) $stashed['code_verifier']);
});

test('start refuses a provider that is not server side', function () {
    // A client-side provider has no authorization URL for us to send
    // the handset to.
    $this->providers->register(new ConfigurableProvider('apple', false, null));

    $result = ($this->controller)()->start(($this->request)([
        'provider'     => 'apple',
        'redirect_uri' => 'hand://auth',
    ]));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('reach_unknown_provider', $result->get_error_code());
});

test('start refuses an unregistered provider', function () {
    $result = ($this->controller)()->start(($this->request)([
        'provider'     => 'myspace',
        'redirect_uri' => 'hand://auth',
    ]));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('reach_unknown_provider', $result->get_error_code());
});

test('the redirect is checked before the provider is', function () {
    // Order matters: probing the allow-list must not be able to also
    // enumerate which providers are configured.
    $result = ($this->controller)()->start(($this->request)([
        'provider'     => 'myspace',
        'redirect_uri' => 'https://evil.test/steal',
    ]));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('reach_invalid_redirect', $result->get_error_code());
});

// ── password enrolment ────────────────────────────────────────────
test('password enrols a certified responder', function () {
    $this->credentials->seedPassword('responder@example.com', 'correct horse battery');
    $controller = ($this->controller)(($this->certified)('responder@example.com'));

    $result = $controller->password(($this->request)([
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
});

test('enrolment issues a payload key beside the token', function () {
    // The secret alert payloads will be encrypted to. Emitted once,
    // like the token: a handset that loses it enrols afresh, because
    // there is no way to ask for it again.
    $this->credentials->seedPassword('responder@example.com', 'correct horse battery');
    $controller = ($this->controller)(($this->certified)('responder@example.com'));

    $result = $controller->password(($this->request)([
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
});

test('every enrolment gets its own payload key', function () {
    // A key shared between handsets would mean one lost phone reads
    // every other responder's alerts.
    $this->credentials->seedPassword('responder@example.com', 'correct horse battery');
    $controller = ($this->controller)(($this->certified)('responder@example.com'));

    $first = $controller->password(($this->request)([
        'email'    => 'responder@example.com',
        'password' => 'correct horse battery',
        'platform' => 'android',
        'label'    => 'One',
    ]));
    $second = $controller->password(($this->request)([
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
});

test('the issued payload key is the one stored against the device', function () {
    $this->credentials->seedPassword('responder@example.com', 'correct horse battery');
    $controller = ($this->controller)(($this->certified)('responder@example.com'));

    $result = $controller->password(($this->request)([
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
});

test('password enrolment is audited', function () {
    // A device token is a long-lived credential over personal data,
    // so handing one out is an auditable event under Scrutiny.
    $this->credentials->seedPassword('responder@example.com', 'correct horse battery');
    $controller = ($this->controller)(($this->certified)('responder@example.com'));

    $controller->password(($this->request)([
        'email'    => 'responder@example.com',
        'password' => 'correct horse battery',
        'platform' => 'android',
    ]));

    $this->assertCount(1, $this->audit->entries);
    $this->assertStringContainsString('Hand device enrolled via password', $this->audit->entries[0]['detail']);
});

test('a wrong password is refused without saying why', function () {
    $this->credentials->seedPassword('responder@example.com', 'correct horse battery');
    $controller = ($this->controller)(($this->certified)('responder@example.com'));

    $result = $controller->password(($this->request)([
        'email'    => 'responder@example.com',
        'password' => 'wrong',
        'platform' => 'android',
    ]));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('reach_invalid_credentials', $result->get_error_code());
    $this->assertSame(401, $result->get_error_data()['status']);
    $this->assertSame([], $this->devices->devices);
});

test('an unknown email is refused identically to a wrong password', function () {
    // The two must be indistinguishable, or this endpoint becomes an
    // oracle for which addresses hold responder accounts.
    $controller = ($this->controller)(($this->certified)('responder@example.com'));

    $result = $controller->password(($this->request)([
        'email'    => 'nobody@example.com',
        'password' => 'anything',
        'platform' => 'android',
    ]));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('reach_invalid_credentials', $result->get_error_code());
});

test('a correct password from an ineligible member says so', function () {
    // Distinct from invalid credentials on purpose: the password was
    // right, so there is nothing left to hide about the account, and
    // being told why is what lets somebody do something about it.
    //
    // The reason changed with the gate — it used to be a lapsed
    // certification, and is now a record without a home group — but
    // the shape of the answer did not, and that is what this pins.
    $this->credentials->seedPassword('lapsed@example.com', 'correct horse battery');
    $controller = ($this->controller)(new MemberStub(
        personalEmail: 'lapsed@example.com',
        twelfthStepper: false,
        telephoneResponder: true,
        responderCertification: ResponderCertification::Certified,
        homeGroup: 0,
    ));

    $result = $controller->password(($this->request)([
        'email'    => 'lapsed@example.com',
        'password' => 'correct horse battery',
        'platform' => 'android',
    ]));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('reach_not_eligible', $result->get_error_code());
    $this->assertSame(403, $result->get_error_data()['status']);
    $this->assertSame([], $this->devices->devices);
});

test('password enrolment still requires a known platform', function () {
    $this->credentials->seedPassword('responder@example.com', 'correct horse battery');
    $controller = ($this->controller)(($this->certified)('responder@example.com'));

    $result = $controller->password(($this->request)([
        'email'    => 'responder@example.com',
        'password' => 'correct horse battery',
        'platform' => 'blackberry',
    ]));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('reach_unknown_platform', $result->get_error_code());
});

// ── rate limiting ─────────────────────────────────────────────────
test('enrolment is rate limited per ip', function (string $method) {
    // 30 attempts per 15 minutes per IP. Generous, because behind a
    // CDN this may be an edge address shared by many responders —
    // but it still has to stop a token-guessing flood.
    $this->credentials->seedPassword('responder@example.com', 'correct horse battery');
    $controller = ($this->controller)(($this->certified)('responder@example.com'));
    $limiter = new RateLimiter();
    for ($i = 0; $i < 30; $i++) {
        $limiter->overLimit('device:198.51.100.7', 30, 15 * 60);
    }

    $result = $controller->{$method}(($this->request)([
        'code'     => 'anything',
        'email'    => 'responder@example.com',
        'password' => 'correct horse battery',
        'platform' => 'android',
    ]));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('reach_rate_limited', $result->get_error_code());
    $this->assertSame(429, $result->get_error_data()['status']);
    $this->assertSame([], $this->devices->devices);
})->with('enrolmentEndpoints');

/** @return array<string, array{0: string}> */
dataset('enrolmentEndpoints', function (): array {
    return [
        'sso exchange' => ['exchange'],
        'password'     => ['password'],
    ];
});

test('the limit is keyed to the client ip', function () {
    // One responder's flood must not lock out another's enrolment.
    $this->credentials->seedPassword('responder@example.com', 'correct horse battery');
    $controller = ($this->controller)(($this->certified)('responder@example.com'));
    $limiter = new RateLimiter();
    for ($i = 0; $i < 30; $i++) {
        $limiter->overLimit('device:203.0.113.9', 30, 15 * 60);
    }

    $result = $controller->password(($this->request)([
        'email'    => 'responder@example.com',
        'password' => 'correct horse battery',
        'platform' => 'android',
    ]));

    $this->assertInstanceOf(WP_REST_Response::class, $result);
});

// ── push registration ─────────────────────────────────────────────
test('update push requires a live token', function () {
    $result = ($this->controller)()->updatePush(($this->request)([
        'push_provider' => 'fcm',
        'push_token'    => 'new-token',
    ]));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('reach_device_not_authenticated', $result->get_error_code());
    $this->assertSame(401, $result->get_error_data()['status']);
});

test('a push provider without a token is refused', function () {
    // The half-state — a transport claimed with nothing to send to —
    // is a handset that will never be pushed to and does not know it.
    $controller = ($this->controller)(($this->certified)('responder@example.com'));
    $token = ($this->enrol)($controller, 'responder@example.com');

    $result = $controller->updatePush(($this->authed)($token, [
        'push_provider' => 'fcm',
        'push_token'    => '   ',
    ]));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('reach_missing_push_token', $result->get_error_code());
    $this->assertSame(400, $result->get_error_data()['status']);
});

test('dropping push entirely needs no token', function () {
    // Turning push off is the one case where an empty token is the
    // correct request rather than a broken one.
    $controller = ($this->controller)(($this->certified)('responder@example.com'));
    $token = ($this->enrol)($controller, 'responder@example.com');

    $result = $controller->updatePush(($this->authed)($token, [
        'push_provider' => '',
        'push_token'    => '',
    ]));

    $this->assertInstanceOf(WP_REST_Response::class, $result);
    $this->assertSame(Device::PUSH_NONE, $result->get_data()['push_provider']);
});

test('an unrecognised transport falls back to polling rather than failing', function () {
    // A newer app asking for a transport this server has never heard
    // of should still work, just by pulling.
    $controller = ($this->controller)(($this->certified)('responder@example.com'));
    $token = ($this->enrol)($controller, 'responder@example.com');

    $result = $controller->updatePush(($this->authed)($token, [
        'push_provider' => 'apns-direct',
        'push_token'    => 'some-token',
    ]));

    $this->assertInstanceOf(WP_REST_Response::class, $result);
    $this->assertSame(Device::PUSH_NONE, $result->get_data()['push_provider']);
});

test('an overlong push token is capped rather than refused', function () {
    // Firebase tokens have no documented maximum and have grown
    // twice; capping keeps the column safe without inventing a limit
    // the app would have to know about.
    $controller = ($this->controller)(($this->certified)('responder@example.com'));
    $token = ($this->enrol)($controller, 'responder@example.com');

    $result = $controller->updatePush(($this->authed)($token, [
        'push_provider' => 'fcm',
        'push_token'    => str_repeat('t', 2_000),
    ]));

    $this->assertInstanceOf(WP_REST_Response::class, $result);
    $device = $this->devices->devices[0];
    $this->assertLessThan(2_000, strlen($device->pushToken));
    $this->assertNotSame('', $device->pushToken);
});

// ── session and sign-out ──────────────────────────────────────────
test('session describes the handset and its responder', function () {
    $controller = ($this->controller)(($this->certified)('responder@example.com'));
    $token = ($this->enrol)($controller, 'responder@example.com');

    $result = $controller->session(($this->authed)($token));

    $this->assertInstanceOf(WP_REST_Response::class, $result);
    $data = $result->get_data();
    $this->assertTrue($data['authorised']);
    $this->assertSame('android', $data['platform']);
    $this->assertSame('Test', $data['responder']);
});

test('signout revokes the handset', function () {
    $controller = ($this->controller)(($this->certified)('responder@example.com'));
    $token = ($this->enrol)($controller, 'responder@example.com');

    $result = $controller->signout(($this->authed)($token));

    $this->assertSame(200, $result->get_status());
    $this->assertTrue($result->get_data()['signed_out']);
    $this->assertTrue($this->devices->devices[0]->isRevoked());
});

test('signing out twice still reports success', function () {
    // A caller signing out with a token that is already dead has got
    // the outcome it asked for; an error would be an app-side path
    // for a state it cannot act on.
    $controller = ($this->controller)(($this->certified)('responder@example.com'));
    $token = ($this->enrol)($controller, 'responder@example.com');
    $controller->signout(($this->authed)($token));

    $result = $controller->signout(($this->authed)($token));

    $this->assertSame(200, $result->get_status());
    $this->assertTrue($result->get_data()['signed_out']);
});

test('signout with no token at all still reports success', function () {
    $result = ($this->controller)()->signout(($this->request)());

    $this->assertSame(200, $result->get_status());
    $this->assertTrue($result->get_data()['signed_out']);
});
