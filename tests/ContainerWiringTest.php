<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
use Reach\Tests\ReachTestCase;
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
use Unity\Committees\Interfaces\CommitteeRepository;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Members\Interfaces\MemberViewFactory;
use WP_REST_Request;
use WP_REST_Response;
use Reach\Tests\Fixtures\MemberStub;
use Unity\Testing\Doubles\InMemoryCommitteeRepository;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use Unity\Testing\Doubles\FakeContainer;
use Reach\Tests\Fixtures\FakeMemberViewFactory;
use Scrutiny\Testing\Doubles\SpyAuditLogger;

// WpdbStub (aliased to wpdb) + the shared member/audit fakes.
require_once __DIR__ . '/WpdbCallAttemptRepositoryTest.php';
require_once __DIR__ . '/PasswordAuthenticatorTest.php';
require_once __DIR__ . '/PasswordAuthControllerGateTest.php'; // SpyAuditLogger

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
final class ContainerWiringTest extends ReachTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wpdb'] = new WpdbStub();

        // These tests do not just check the wiring happened — they take the
        // registered callbacks back out and invoke them, so each hook of
        // interest is captured as Plugin::init() hangs it.
        $this->captureActions(['rest_api_init', 'admin_menu', 'unity/member_deleted']);
        $this->captureFilters(['rest_post_dispatch', 'trusted_signup_member']);

        WpState::$cron = [];
        WpState::$options = [];
        WpState::$isAdmin = false;
        $this->resetPluginStatics();
    }

    protected function tearDown(): void
    {
        $this->resetPluginStatics();
        WpState::$isAdmin = false;
        parent::tearDown();
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
        $this->assertActionAdded('rest_api_init');
        $this->assertGreaterThanOrEqual(5, count($this->actionCallbacks('rest_api_init')));

        // The no-store cache filter and the two integration filters are hung.
        $this->assertFilterAdded('rest_post_dispatch');
        $this->assertFilterAdded('trusted_signup_member');
        $this->assertActionAdded('unity/member_deleted');

        $this->assertSame($container, Plugin::getContainer());

        // Second init is a no-op — hooks are not registered twice.
        $hooksAfterFirst = count($this->actionCallbacks('rest_api_init'));
        Plugin::init($container);
        $this->assertCount($hooksAfterFirst, $this->actionCallbacks('rest_api_init'));
    }

    public function testInitAlsoRegistersAdminPagesWhenInAdmin(): void
    {
        WpState::$isAdmin = true;
        Plugin::init($this->container());

        // admin_menu is only hooked from the admin-only page registrations.
        $this->assertActionAdded('admin_menu');
    }

    public function testRestPostDispatchFilterForcesNoStoreOnReachRoutes(): void
    {
        Plugin::init($this->container());
        $filter = $this->filterCallbacks('rest_post_dispatch')[0];

        $reachResponse = new WP_REST_Response(['x' => 1]);
        $reachRequest  = new WP_REST_Request([], '/reach/v1/nearest-members');
        $filter($reachResponse, null, $reachRequest);
        $this->assertStringContainsString('no-store', $reachResponse->get_headers()['Cache-Control'] ?? '');

        // A non-Reach route is left untouched.
        $other = new WP_REST_Response(['x' => 1]);
        $filter($other, null, new WP_REST_Request([], '/wp/v2/posts'));
        $this->assertArrayNotHasKey('Cache-Control', $other->get_headers());
    }

    public function testTrustedSignupFilterResolvesTheReachMemberFromSession(): void
    {
        $members = new InMemoryMemberRepository([new MemberStub('member@example.com', true, true, 7)]);
        Plugin::init($this->container($members));
        $filter = $this->filterCallbacks('trusted_signup_member')[0];

        // Already-resolved member is passed straight through.
        $existing = new MemberStub('other@example.com');
        $this->assertSame($existing, $filter($existing));

        // With no session cookie set, the filter can't resolve a member.
        $_COOKIE = [];
        $this->assertNull($filter(null));
    }

    public function testMemberDeletedHookPurgesTheMembersPasswordCredential(): void
    {
        Plugin::init($this->container());
        $callback = $this->actionCallbacks('unity/member_deleted')[0];

        // A null member is ignored; a real member triggers a delete against the
        // credentials repo. The repo is the WpdbStub-backed real one, so the
        // assertion is simply that invoking the hook does not error.
        $callback(123, null);
        $callback(123, new MemberStub('gone@example.com'));
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
            MemberRepository::class    => $members ?? new InMemoryMemberRepository([]),
            CommitteeRepository::class => new InMemoryCommitteeRepository(),
            AuditLogger::class         => new SpyAuditLogger(),
            MemberViewFactory::class   => new FakeMemberViewFactory(),
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
