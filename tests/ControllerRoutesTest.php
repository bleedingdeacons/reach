<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
use Reach\Core\ReachServiceProvider;
use Reach\Rest\CallAttemptController;
use Reach\Rest\CallRequestController;
use Reach\Rest\NearestMembersController;
use Reach\Rest\OAuthController;
use Reach\Rest\PasswordAuthController;
use Reach\Session\CurrentSession;
use Reach\Session\Session;
use Reach\Session\SessionCookie;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Unity\Core\Interfaces\Container;
use Unity\Auth\Interfaces\PasswordCredentialRepository;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Members\Interfaces\MemberViewFactory;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use Reach\Tests\Fixtures\MemberStub;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use Unity\Testing\Doubles\InMemoryPasswordCredentialRepository;
use Unity\Testing\Doubles\FakeContainer;
use Reach\Tests\Fixtures\FakeMemberViewFactory;
use Scrutiny\Testing\Doubles\SpyAuditLogger;
use Reach\Tests\Fixtures\WpdbStub;

/**
 * Exercise the REST route wiring for every controller: register() hangs the
 * rest_api_init hook, registerRoutes() declares the routes, and the inline
 * sanitize_callback / validate_callback closures on each argument enforce the
 * request contract. Driving all five controllers through the container and
 * then invoking each declared callback covers that (otherwise untested) input
 * layer in one place, which is where a validation regression would hide.
 */

beforeEach(function () {
    /**
     * Sign in as $email for the container's shared CurrentSession.
     *
     * This used to reflect a Session into CurrentSession's private
     * cache, on the grounds that cookie verification was its own unit
     * test's business. That would now skip the eligibility check as
     * well, and these tests are precisely about whether the gate is
     * wired up — so the cookie is minted for real and invalidate()
     * drops whatever the earlier unauthenticated call cached.
     */
    $this->seedSession = function (string $email, string $provider = 'google'): void {
        $session = new Session($email, $provider, 'sub', time(), time() + 3600, null, Session::newId());

        $_COOKIE[SessionCookie::COOKIE_NAME] = (new SessionCookie())->sign($session);

        $this->container->get(CurrentSession::class)->invalidate();
    };

    $GLOBALS['wpdb'] = new WpdbStub();

    // The controllers' register() hangs its route registration on
    // rest_api_init; this test fires those callbacks by hand, so they have
    // to be captured as they are added.
    $this->captureAction('rest_api_init');

    WpState::$restRoutes = [];
    WpState::$options = [];
    $_COOKIE = [];

    $this->container = new FakeContainer([
        // Carries the member the session-gated tests sign in as: a
        // session whose email matches no member is refused, so an
        // empty repository would deny every one of them.
        MemberRepository::class  => new InMemoryMemberRepository([
            new MemberStub('user@example.com'),
        ]),
        AuditLogger::class       => new SpyAuditLogger(),
        MemberViewFactory::class => new FakeMemberViewFactory(),
        // Unity registers the password store into this same container
        // in production; Reach no longer binds one of its own.
        PasswordCredentialRepository::class => new InMemoryPasswordCredentialRepository(),
    ]);
    (new ReachServiceProvider())->register($this->container);
});

test('every controller registers routes and argument callbacks hold', function () {
    foreach (
        [
        OAuthController::class,
        PasswordAuthController::class,
        NearestMembersController::class,
        CallAttemptController::class,
        CallRequestController::class,
        ] as $class
    ) {
        $this->container->get($class)->register();
    }

    // Each register() hung a rest_api_init callback; fire them to run the
    // registerRoutes() bodies and populate the captured route table.
    $this->assertActionAdded('rest_api_init');
    foreach ($this->actionCallbacks('rest_api_init') as $callback) {
        $callback();
    }

    $routes = WpState::$restRoutes;
    $this->assertNotEmpty($routes);

    // Reach registers everything under the reach/v1 namespace.
    foreach ($routes as $route) {
        $this->assertSame('reach/v1', $route['namespace']);
    }

    // Exercise every declared sanitize/validate callback across a spread
    // of inputs so both branches of each closure run. The assertion is
    // simply that none of them error on hostile or empty input.
    $samples = ['a value', '', '  ', ['x', 123, ''], 123, '12.5', 'not-a-number', null];
    $invocations = 0;
    foreach ($routes as $route) {
        // register_rest_route's third argument (the route definition) is
        // captured under 'args'; the per-parameter specs live nested under
        // that definition's own 'args' key.
        $argSpecs = $route['args']['args'] ?? [];
        foreach ($argSpecs as $spec) {
            if (!is_array($spec)) {
                continue;
            }
            foreach (['sanitize_callback', 'validate_callback'] as $slot) {
                $cb = $spec[$slot] ?? null;
                if (!is_callable($cb)) {
                    continue;
                }
                foreach ($samples as $sample) {
                    $cb($sample);
                    $invocations++;
                }
            }
        }
    }
    $this->assertGreaterThan(0, $invocations);
});

// --- NearestMembersController introspection + gate --------------------
test('nearest members permission callback depends on session', function () {
    /** @var NearestMembersController $controller */
    $controller = $this->container->get(NearestMembersController::class);

    // No cookie ⇒ no session ⇒ 401.
    $denied = $controller->permissionCallback();
    $this->assertInstanceOf(WP_Error::class, $denied);
    $this->assertSame(401, $denied->get_error_data()['status'] ?? null);

    // Seed a session into the container's CurrentSession and re-check.
    ($this->seedSession)('user@example.com');
    $this->assertTrue($controller->permissionCallback());
});

test('get session reports authentication state', function () {
    /** @var NearestMembersController $controller */
    $controller = $this->container->get(NearestMembersController::class);

    $anon = $controller->getSession(new WP_REST_Request([], '/reach/v1/session'));
    $this->assertInstanceOf(WP_REST_Response::class, $anon);
    $this->assertFalse($anon->get_data()['authenticated']);

    ($this->seedSession)('user@example.com', 'google');
    $authed = $controller->getSession(new WP_REST_Request([], '/reach/v1/session'))->get_data();
    $this->assertTrue($authed['authenticated']);
    $this->assertSame('user@example.com', $authed['email']);
    $this->assertSame('google', $authed['provider']);
    $this->assertArrayHasKey('token', $authed);
});

test('get session withholds the token from a cross origin caller', function () {
    /** @var NearestMembersController $controller */
    $controller = $this->container->get(NearestMembersController::class);

    ($this->seedSession)('user@example.com', 'google');

    // A sibling subdomain is same-site as far as SameSite=Lax is
    // concerned, so the session cookie *is* attached — and core's
    // rest_send_cors_headers() would reflect this Origin back with
    // Access-Control-Allow-Credentials, letting it read the body. The
    // token it wants is the one guarding every cookie-authenticated
    // write in the plugin.
    $request = new WP_REST_Request([], '/reach/v1/session');
    $request->set_header('Origin', 'https://blog.example.test');

    $data = $controller->getSession($request)->get_data();

    $this->assertFalse($data['authenticated']);
    $this->assertArrayNotHasKey('token', $data);
});

test('get session still serves this sites own pages', function () {
    /** @var NearestMembersController $controller */
    $controller = $this->container->get(NearestMembersController::class);

    ($this->seedSession)('user@example.com', 'google');

    $request = new WP_REST_Request([], '/reach/v1/session');
    $request->set_header('Origin', 'https://example.test');

    $data = $controller->getSession($request)->get_data();

    $this->assertTrue($data['authenticated']);
    $this->assertArrayHasKey('token', $data);
});
