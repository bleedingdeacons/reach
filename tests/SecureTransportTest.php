<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
use Reach\Rest\AlertController;
use Reach\Rest\DeviceAuthController;
use WP_Error;
use WP_REST_Request;

/**
 * Every route a handset uses refuses plain HTTP.
 *
 * <para>These routes either hand out a credential or carry one.
 * Enrolment answers with a bearer token and the key alert payloads are
 * encrypted to, each emitted exactly once; the alert routes send that
 * token up on every poll, which on a duty handset is every few seconds
 * for hours. A stolen device token is a working impersonation of a
 * certified responder until somebody notices and revokes it.</para>
 *
 * <para>The tests are here rather than spread across the two controller
 * suites because the property belongs to the trait, not to either
 * controller, and one list is easier to keep complete than two. If a
 * route is added to either controller and not added here, that is the
 * omission this file exists to make visible.</para>
 *
 * @covers \Reach\Rest\RequiresSecureTransport
 */
final class SecureTransportTest extends ReachTestCase
{
    /**
     * @test
     * @dataProvider deviceRoutes
     */
    public function a_handset_route_refuses_plain_http(string $controller, string $method): void
    {
        WpState::$isSsl = false;

        $result = $this->call($controller, $method);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_insecure_transport', $result->get_error_code());
        $this->assertSame(403, $result->get_error_data()['status']);
    }

    /**
     * @test
     * @dataProvider deviceRoutes
     */
    public function the_refusal_happens_before_anything_else(string $controller, string $method): void
    {
        // The point of guarding first: a request that should not have been
        // made must not be answered with anything that distinguishes a
        // valid token from an invalid one, or a known alert from an
        // unknown one. Over http both answers are readable.
        WpState::$isSsl = false;

        $result = $this->call($controller, $method);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertNotSame('reach_device_not_authenticated', $result->get_error_code());
    }

    /**
     * Local development runs over http, and a check that cannot be turned
     * off is a check somebody works around by deleting it.
     *
     * <para>In its own process because define() is permanent: setting the
     * constant in this process would silently disable the guard for every
     * test that ran afterwards, and they would pass for the wrong
     * reason.</para>
     *
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
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

    /** @return array<string, array{0: string, 1: string}> */
    public static function deviceRoutes(): array
    {
        return [
            'enrol by password'   => [DeviceAuthController::class, 'password'],
            'begin oauth'         => [DeviceAuthController::class, 'start'],
            'exchange oauth code' => [DeviceAuthController::class, 'exchange'],
            'update push token'   => [DeviceAuthController::class, 'updatePush'],
            'read the session'    => [DeviceAuthController::class, 'session'],
            'poll for alerts'     => [AlertController::class, 'pending'],
            'read a contact'      => [AlertController::class, 'contact'],
            'acknowledge'         => [AlertController::class, 'acknowledge'],
        ];
    }

    /**
     * Drive one route without building its whole world.
     *
     * The guard runs before any collaborator is touched, which is the
     * behaviour under test, so the controllers are constructed through
     * reflection without running their constructors. That is deliberate:
     * a test that had to assemble a working repository, minter and audit
     * logger to prove a request is refused would be proving something
     * else as well.
     */
    private function call(string $controller, string $method): mixed
    {
        $instance = (new \ReflectionClass($controller))->newInstanceWithoutConstructor();

        return $instance->{$method}(new WP_REST_Request());
    }
}
