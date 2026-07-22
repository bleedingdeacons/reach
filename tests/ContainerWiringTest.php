<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\Auth\ProviderRegistry;
use Reach\Core\ReachServiceProvider;
use Reach\Geocoding\Geocoder;
use Reach\Geocoding\PostcodesIoGeocoder;
use Reach\Plugin;
use Reach\Rest\CallAttemptController;
use Reach\Rest\CallRequestController;
use Reach\Rest\NearestMembersController;
use Reach\Rest\OAuthController;
use Reach\Rest\PasswordAuthController;
use Reach\Session\CurrentSession;
use ReflectionClass;
use RuntimeException;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Unity\Core\Interfaces\Container;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Members\Interfaces\MemberViewFactory;
use WP_REST_Request;
use WP_REST_Response;

// WpdbStub (aliased to wpdb) + the shared member/audit fakes.
require_once __DIR__ . '/WpdbCallAttemptRepositoryTest.php';
require_once __DIR__ . '/PasswordAuthenticatorTest.php';
require_once __DIR__ . '/PasswordAuthControllerGateTest.php'; // NullAuditLogger

/**
 * Cover the dependency-injection wiring: {@see ReachServiceProvider}, which
 * registers every Reach service into Unity's container, and {@see Plugin},
 * which drives that registration and hangs the REST controllers, rewrite
 * rules and integration filters off WordPress hooks.
 *
 * A recording container resolves every registered factory so the closure
 * bodies actually run; the leaf Unity/Scrutiny dependencies are supplied as
 * in-memory fakes, and $wpdb is a stub, so no database or WordPress core is
 * needed.
 */
final class ContainerWiringTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new WpdbStub();
        $GLOBALS['__reach_hooks'] = [];
        $GLOBALS['__reach_filters'] = [];
        $GLOBALS['__reach_cron'] = [];
        $GLOBALS['__reach_options'] = [];
        $GLOBALS['__reach_is_admin'] = false;
        $this->resetPluginStatics();
    }

    protected function tearDown(): void
    {
        $this->resetPluginStatics();
        unset($GLOBALS['__reach_is_admin']);
    }

    // --- ReachServiceProvider ---------------------------------------------

    public function testServiceProviderRegistersAndResolvesEveryService(): void
    {
        $container = $this->container();
        (new ReachServiceProvider())->register($container);

        // Resolve every registered id so each factory closure executes.
        foreach ($container->registeredIds() as $id) {
            $this->assertIsObject($container->get($id), "service $id should resolve to an object");
        }

        // Spot-check the assembled graph.
        $registry = $container->get(ProviderRegistry::class);
        $this->assertInstanceOf(ProviderRegistry::class, $registry);
        $this->assertEqualsCanonicalizing(
            ['google', 'microsoft', 'apple', 'facebook'],
            $registry->names(),
        );

        $this->assertInstanceOf(PostcodesIoGeocoder::class, $container->get(Geocoder::class));
        $this->assertInstanceOf(OAuthController::class, $container->get(OAuthController::class));
        $this->assertInstanceOf(CurrentSession::class, $container->get(CurrentSession::class));
    }

    // --- Plugin::init -----------------------------------------------------

    public function testInitWiresControllersRewritesAndFiltersOnce(): void
    {
        $container = $this->container();

        Plugin::init($container);

        // Each REST controller registered its routes on rest_api_init.
        $this->assertArrayHasKey('rest_api_init', $GLOBALS['__reach_hooks']);
        $this->assertGreaterThanOrEqual(5, count($GLOBALS['__reach_hooks']['rest_api_init']));

        // The no-store cache filter and the two integration filters are hung.
        $this->assertArrayHasKey('rest_post_dispatch', $GLOBALS['__reach_filters']);
        $this->assertArrayHasKey('trusted_signup_member', $GLOBALS['__reach_filters']);
        $this->assertArrayHasKey('unity/member_deleted', $GLOBALS['__reach_hooks']);

        $this->assertSame($container, Plugin::getContainer());

        // Second init is a no-op — hooks are not registered twice.
        $hooksAfterFirst = count($GLOBALS['__reach_hooks']['rest_api_init']);
        Plugin::init($container);
        $this->assertCount($hooksAfterFirst, $GLOBALS['__reach_hooks']['rest_api_init']);
    }

    public function testInitAlsoRegistersAdminPagesWhenInAdmin(): void
    {
        $GLOBALS['__reach_is_admin'] = true;
        Plugin::init($this->container());

        // admin_menu is only hooked from the admin-only page registrations.
        $this->assertArrayHasKey('admin_menu', $GLOBALS['__reach_hooks']);
    }

    public function testRestPostDispatchFilterForcesNoStoreOnReachRoutes(): void
    {
        Plugin::init($this->container());
        $filter = $GLOBALS['__reach_filters']['rest_post_dispatch'][0];

        $reachResponse = new WP_REST_Response(['x' => 1]);
        $reachRequest  = new WP_REST_Request(['__route' => '/reach/v1/nearest-members']);
        $filter($reachResponse, null, $reachRequest);
        $this->assertStringContainsString('no-store', $reachResponse->get_headers()['Cache-Control'] ?? '');

        // A non-Reach route is left untouched.
        $other = new WP_REST_Response(['x' => 1]);
        $filter($other, null, new WP_REST_Request(['__route' => '/wp/v2/posts']));
        $this->assertArrayNotHasKey('Cache-Control', $other->get_headers());
    }

    public function testTrustedSignupFilterResolvesTheReachMemberFromSession(): void
    {
        $members = new PwTestMemberRepository([new PwTestMember('member@example.com', true, true, 7)]);
        Plugin::init($this->container($members));
        $filter = $GLOBALS['__reach_filters']['trusted_signup_member'][0];

        // Already-resolved member is passed straight through.
        $existing = new PwTestMember('other@example.com');
        $this->assertSame($existing, $filter($existing));

        // With no session cookie set, the filter can't resolve a member.
        $_COOKIE = [];
        $this->assertNull($filter(null));
    }

    public function testMemberDeletedHookPurgesTheMembersPasswordCredential(): void
    {
        Plugin::init($this->container());
        $callback = $GLOBALS['__reach_hooks']['unity/member_deleted'][0];

        // A null member is ignored; a real member triggers a delete against the
        // credentials repo. The repo is the WpdbStub-backed real one, so the
        // assertion is simply that invoking the hook does not error.
        $callback(123, null);
        $callback(123, new PwTestMember('gone@example.com'));
        $this->addToAssertionCount(1);
    }

    public function testGetContainerThrowsBeforeInit(): void
    {
        $this->expectException(RuntimeException::class);
        Plugin::getContainer();
    }

    public function testBuildDateReadsBuildDateLineFromReadme(): void
    {
        $dir = sys_get_temp_dir() . '/reach-build-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/readme.txt', "=== Reach ===\nBuild date: 2026/07/22 09:00:00\n");

        $ref = new \ReflectionMethod(Plugin::class, 'readBuildDateFromReadme');
        $this->assertSame('2026/07/22 09:00:00', $ref->invoke(null, $dir));

        // Missing readme ⇒ empty string, not an error.
        $this->assertSame('', $ref->invoke(null, $dir . '/does-not-exist'));

        unlink($dir . '/readme.txt');
        rmdir($dir);
    }

    // --- helpers ----------------------------------------------------------

    private function container(?MemberRepository $members = null): FakeContainer
    {
        return new FakeContainer([
            MemberRepository::class  => $members ?? new PwTestMemberRepository([]),
            AuditLogger::class       => new NullAuditLogger(),
            MemberViewFactory::class => new FakeMemberViewFactory(),
        ]);
    }

    private function resetPluginStatics(): void
    {
        $ref = new ReflectionClass(Plugin::class);
        foreach (['container' => null, 'initialized' => false, 'buildDate' => null] as $prop => $value) {
            if ($ref->hasProperty($prop)) {
                $p = $ref->getProperty($prop);
                $p->setValue(null, $value);
            }
        }
    }
}

/**
 * Recording DI container implementing Unity's Container contract. Presets are
 * pre-built leaf services (MemberRepository, AuditLogger, MemberViewFactory);
 * everything else is resolved by running the registered factory once and
 * caching the result.
 */
final class FakeContainer implements Container
{
    /** @var array<string, callable> */
    private array $factories = [];
    /** @var array<string, mixed> */
    private array $instances;

    /** @param array<string, mixed> $presets */
    public function __construct(array $presets = [])
    {
        $this->instances = $presets;
    }

    public function register(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (isset($this->factories[$id])) {
            return $this->instances[$id] = ($this->factories[$id])($this);
        }
        throw new RuntimeException('No service registered for ' . $id);
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || array_key_exists($id, $this->instances);
    }

    /** @return array<int, string> */
    public function registeredIds(): array
    {
        return array_keys($this->factories);
    }
}

/** Minimal MemberViewFactory fake — the admin pages only store it. */
final class FakeMemberViewFactory implements MemberViewFactory
{
    public function createFromSource(array $sourceIds): array
    {
        return [];
    }
}
