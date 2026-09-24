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
 * <para>The one test that turns the guard off lives in
 * SecureTransportConstantTest, still a PHPUnit class: it defines a
 * constant, so it has to run in a separate process, and Pest refuses
 * process isolation.</para>
 */

covers(\Reach\Rest\RequiresSecureTransport::class);

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
function callRoute(string $controller, string $method): mixed
{
    $instance = (new \ReflectionClass($controller))->newInstanceWithoutConstructor();

    return $instance->{$method}(new WP_REST_Request());
}

test('a handset route refuses plain http', function (string $controller, string $method) {
    WpState::$isSsl = false;

    $result = callRoute($controller, $method);

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('reach_insecure_transport', $result->get_error_code());
    $this->assertSame(403, $result->get_error_data()['status']);
})->with('deviceRoutes');

test('the refusal happens before anything else', function (string $controller, string $method) {
    // The point of guarding first: a request that should not have been
    // made must not be answered with anything that distinguishes a
    // valid token from an invalid one, or a known alert from an
    // unknown one. Over http both answers are readable.
    WpState::$isSsl = false;

    $result = callRoute($controller, $method);

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertNotSame('reach_device_not_authenticated', $result->get_error_code());
})->with('deviceRoutes');

/** @return array<string, array{0: string, 1: string}> */
dataset('deviceRoutes', function (): array {
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
});
