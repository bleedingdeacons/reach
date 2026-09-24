<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use Reach\Rest\DeviceAuthController;
use WP_Error;
use WP_REST_Request;

/**
 * The escape hatch in {@see \Reach\Rest\RequiresSecureTransport}:
 * REACH_ALLOW_INSECURE_TRANSPORT lets plain HTTP through.
 *
 * <para>Split out of SecureTransportTest when the suite moved to Pest, and
 * deliberately left a PHPUnit class. The test defines a constant, which
 * cannot be undone, so it runs in a separate process — and Pest refuses
 * process isolation outright (closures cannot be carried into a child
 * process). Pest still runs this class as it is. tests/bootstrap.php defines
 * PHPUNIT_COMPOSER_INSTALL so the child process has an autoloader.</para>
 */
#[CoversTrait(\Reach\Rest\RequiresSecureTransport::class)]
final class SecureTransportConstantTest extends ReachTestCase
{
    /**
     * Local development runs over http, and a check that cannot be turned
     * off is a check somebody works around by deleting it.
     *
     * <para>In its own process because define() is permanent: setting the
     * constant in this process would silently disable the guard for every
     * test that ran afterwards, and they would pass for the wrong
     * reason.</para>
     */
    #[PreserveGlobalState(false)]
    #[Test]
    #[RunInSeparateProcess]
    public function the_constant_lets_a_laptop_through(): void
    {
        WpState::$isSsl = false;
        define('REACH_ALLOW_INSECURE_TRANSPORT', true);

        // Getting past the guard is the whole assertion, and the proof is
        // that execution reaches a collaborator this test never built. A
        // guarded request returns before touching one, so the two outcomes
        // are unambiguous: a WP_Error naming the transport means the guard
        // held, and anything else means it did not.
        try {
            $result = $this->call(DeviceAuthController::class, 'password');

            $this->assertTrue(
                !$result instanceof WP_Error || $result->get_error_code() !== 'reach_insecure_transport',
                'the constant should have let this through',
            );
        } catch (\Error $e) {
            $this->assertStringContainsString(
                'must not be accessed before initialization',
                $e->getMessage(),
                'expected to reach an unbuilt collaborator, which only happens past the guard',
            );
        }
    }

    /**
     * Drive one route without building its whole world — the same helper
     * SecureTransportTest uses, for the same reason: the controller is
     * constructed through reflection without running its constructor.
     */
    private function call(string $controller, string $method): mixed
    {
        $instance = (new \ReflectionClass($controller))->newInstanceWithoutConstructor();

        return $instance->{$method}(new WP_REST_Request());
    }
}
