<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\Core\ReachServiceProvider;
use Reach\Rest\CallAttemptController;
use Reach\Rest\CallRequestController;
use Reach\Rest\NearestMembersController;
use Reach\Rest\OAuthController;
use Reach\Rest\PasswordAuthController;
use Reach\Session\CurrentSession;
use Reach\Session\Session;
use ReflectionClass;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Unity\Core\Interfaces\Container;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Members\Interfaces\MemberViewFactory;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

require_once __DIR__ . '/WpdbCallAttemptRepositoryTest.php';   // WpdbStub
require_once __DIR__ . '/PasswordAuthenticatorTest.php';       // PwTestMember(Repository)
require_once __DIR__ . '/PasswordAuthControllerGateTest.php';  // NullAuditLogger
require_once __DIR__ . '/ContainerWiringTest.php';             // FakeContainer, FakeMemberViewFactory

/**
 * Exercise the REST route wiring for every controller: register() hangs the
 * rest_api_init hook, registerRoutes() declares the routes, and the inline
 * sanitize_callback / validate_callback closures on each argument enforce the
 * request contract. Driving all five controllers through the container and
 * then invoking each declared callback covers that (otherwise untested) input
 * layer in one place, which is where a validation regression would hide.
 */
final class ControllerRoutesTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new WpdbStub();
        $GLOBALS['__reach_hooks'] = [];
        $GLOBALS['__reach_routes'] = [];
        $GLOBALS['__reach_options'] = [];
        $_COOKIE = [];

        $this->container = new FakeContainer([
            MemberRepository::class  => new PwTestMemberRepository([]),
            AuditLogger::class       => new NullAuditLogger(),
            MemberViewFactory::class => new FakeMemberViewFactory(),
        ]);
        (new ReachServiceProvider())->register($this->container);
    }

    public function testEveryControllerRegistersRoutesAndArgumentCallbacksHold(): void
    {
        foreach ([
            OAuthController::class,
            PasswordAuthController::class,
            NearestMembersController::class,
            CallAttemptController::class,
            CallRequestController::class,
        ] as $class) {
            $this->container->get($class)->register();
        }

        // Each register() hung a rest_api_init callback; fire them to run the
        // registerRoutes() bodies and populate the captured route table.
        $this->assertArrayHasKey('rest_api_init', $GLOBALS['__reach_hooks']);
        foreach ($GLOBALS['__reach_hooks']['rest_api_init'] as $callback) {
            $callback();
        }

        $routes = $GLOBALS['__reach_routes'];
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
    }

    // --- NearestMembersController introspection + gate --------------------

    public function testNearestMembersPermissionCallbackDependsOnSession(): void
    {
        /** @var NearestMembersController $controller */
        $controller = $this->container->get(NearestMembersController::class);

        // No cookie ⇒ no session ⇒ 401.
        $denied = $controller->permissionCallback();
        $this->assertInstanceOf(WP_Error::class, $denied);
        $this->assertSame(401, $denied->data['status'] ?? null);

        // Seed a session into the container's CurrentSession and re-check.
        $this->seedSession('user@example.com');
        $this->assertTrue($controller->permissionCallback());
    }

    public function testGetSessionReportsAuthenticationState(): void
    {
        /** @var NearestMembersController $controller */
        $controller = $this->container->get(NearestMembersController::class);

        $anon = $controller->getSession();
        $this->assertInstanceOf(WP_REST_Response::class, $anon);
        $this->assertFalse($anon->get_data()['authenticated']);

        $this->seedSession('user@example.com', 'google');
        $authed = $controller->getSession()->get_data();
        $this->assertTrue($authed['authenticated']);
        $this->assertSame('user@example.com', $authed['email']);
        $this->assertSame('google', $authed['provider']);
    }

    /**
     * Force the container's shared CurrentSession to report a session for the
     * given email, bypassing cookie HMAC verification (its own unit test's
     * job), so the controller gate/introspection branches can be exercised.
     */
    private function seedSession(string $email, string $provider = 'google'): void
    {
        $current = $this->container->get(CurrentSession::class);
        $session = new Session($email, $provider, 'sub', time(), time() + 3600);

        $ref = new ReflectionClass($current);
        $cached = $ref->getProperty('cached');
        $cached->setAccessible(true);
        $cached->setValue($current, $session);
        $resolved = $ref->getProperty('resolved');
        $resolved->setAccessible(true);
        $resolved->setValue($current, true);
    }
}
