<?php

declare(strict_types=1);

namespace Reach\Tests;

use Reach\Alerts\AlertRequest;
use Reach\Auth\DeviceTokenMinter;
use Reach\Devices\CurrentDevice;
use Reach\Devices\Device;
use Reach\Devices\ResponderGate;
use Reach\Rest\AlertController;
use Reach\Tests\Fixtures\InMemoryAlertContactRepository;
use Reach\Tests\Fixtures\InMemoryAlertRepository;
use Reach\Tests\Fixtures\InMemoryDeviceRepository;
use Reach\Tests\Fixtures\MemberStub;
use Scrutiny\Testing\Doubles\SpyAuditLogger;
use Unity\Members\ResponderCertification;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The handset's side of the alert loop: collecting alerts and saying it
 * has rung for them.
 *
 * The poll is what makes the whole feature dependable — push is the fast
 * path, not the reliable one — so the rules it enforces matter: a
 * handset sees broadcasts and its own targeted alerts, never another
 * responder's, never an expired one, and never one it has already
 * alarmed for.
 */
final class AlertControllerTest extends ReachTestCase
{
    private InMemoryDeviceRepository $devices;
    private InMemoryAlertRepository $alerts;
    private InMemoryAlertContactRepository $contacts;
    private DeviceTokenMinter $minter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->devices = new InMemoryDeviceRepository();
        $this->alerts = new InMemoryAlertRepository();
        $this->contacts = new InMemoryAlertContactRepository();
        $this->minter = new DeviceTokenMinter();
    }

    public function testPollRequiresAuthentication(): void
    {
        $result = $this->controller()->pending(new WP_REST_Request());

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(401, $result->get_error_data()['status'] ?? null);
    }

    public function testPollReturnsBroadcastAlerts(): void
    {
        $token = $this->enrol('responder@example.com');
        $this->raise(['kind' => 'test', 'title' => 'Everybody']);

        $result = $this->controller()->pending($this->authed($token));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertCount(1, $result->get_data()['alerts']);
        $this->assertSame('Everybody', $result->get_data()['alerts'][0]['title']);
    }

    public function testPollDoesNotReturnAnotherRespondersTargetedAlert(): void
    {
        $token = $this->enrol('responder@example.com');
        $this->raise([
            'kind'         => 'test',
            'title'        => 'For somebody else',
            'target_email' => 'other@example.com',
        ]);

        $result = $this->controller()->pending($this->authed($token));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame([], $result->get_data()['alerts']);
    }

    public function testPollReturnsAlertsTargetedAtThisResponder(): void
    {
        $token = $this->enrol('responder@example.com');
        $this->raise([
            'kind'         => 'test',
            'title'        => 'Just for you',
            'target_email' => 'responder@example.com',
        ]);

        $result = $this->controller()->pending($this->authed($token));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertCount(1, $result->get_data()['alerts']);
    }

    public function testExpiredAlertsAreNotReturned(): void
    {
        $token = $this->enrol('responder@example.com');
        // Raised far enough in the past that its default hour is spent.
        $this->alerts->create($this->request(['kind' => 'test', 'title' => 'Stale']), time() - 7200);

        $result = $this->controller()->pending($this->authed($token));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame([], $result->get_data()['alerts']);
    }

    public function testAcknowledgingStopsAnAlertComingBack(): void
    {
        // This is what prevents the same alert ringing a handset twice.
        $token = $this->enrol('responder@example.com');
        $alert = $this->raise(['kind' => 'test', 'title' => 'Ring once']);
        $controller = $this->controller();

        $ack = $controller->acknowledge($this->authed($token, ['id' => $alert->id]));
        $this->assertInstanceOf(WP_REST_Response::class, $ack);
        $this->assertTrue($ack->get_data()['acknowledged']);

        $result = $controller->pending($this->authed($token));
        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame([], $result->get_data()['alerts']);
    }

    public function testAcknowledgingIsIdempotent(): void
    {
        // A handset retrying after a dropped response has achieved what
        // it asked for; that is not an error.
        $token = $this->enrol('responder@example.com');
        $alert = $this->raise(['kind' => 'test', 'title' => 'Ring once']);
        $controller = $this->controller();

        $controller->acknowledge($this->authed($token, ['id' => $alert->id]));
        $second = $controller->acknowledge($this->authed($token, ['id' => $alert->id]));

        $this->assertInstanceOf(WP_REST_Response::class, $second);
        $this->assertCount(1, $this->alerts->acknowledgementsFor($alert->id));
    }

    public function testOneHandsetAcknowledgingDoesNotSilenceAnother(): void
    {
        // Alerts go to the whole rota; each handset rings and answers
        // for itself.
        $first = $this->enrol('one@example.com');
        $second = $this->enrol('two@example.com');
        $alert = $this->raise(['kind' => 'test', 'title' => 'Everybody']);
        $controller = $this->controller();

        $controller->acknowledge($this->authed($first, ['id' => $alert->id]));

        $result = $controller->pending($this->authed($second));
        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertCount(1, $result->get_data()['alerts']);
    }

    public function testCannotAcknowledgeAnotherRespondersTargetedAlert(): void
    {
        // Otherwise the admin view would show an alert answered by
        // someone who never saw it.
        $token = $this->enrol('responder@example.com');
        $alert = $this->raise([
            'kind'         => 'test',
            'title'        => 'For somebody else',
            'target_email' => 'other@example.com',
        ]);

        $result = $this->controller()->acknowledge($this->authed($token, ['id' => $alert->id]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(404, $result->get_error_data()['status'] ?? null);
        $this->assertSame([], $this->alerts->acknowledgementsFor($alert->id));
    }

    public function testAcknowledgingAnUnknownAlertIs404(): void
    {
        $token = $this->enrol('responder@example.com');

        $result = $this->controller()->acknowledge($this->authed($token, ['id' => 9999]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(404, $result->get_error_data()['status'] ?? null);
    }

    // --- helpers ----------------------------------------------------------

    private function controller(): AlertController
    {
        $members = [];
        foreach ($this->devices->devices as $device) {
            $members[] = new MemberStub(
                personalEmail: $device->memberEmail,
                twelfthStepper: false,
                telephoneResponder: true,
                id: $device->id,
                responderCertification: ResponderCertification::Certified,
            );
        }

        $gate = new ResponderGate(new InMemoryMemberRepository($members));

        return new AlertController(
            $this->alerts,
            $this->contacts,
            new CurrentDevice($this->devices, $this->minter, $gate),
            new SpyAuditLogger(),
        );
    }

    /** Enrol a handset directly and return its plaintext token. */
    private function enrol(string $email): string
    {
        $token = $this->minter->mint();
        $this->devices->create(
            $this->minter->hash($token),
            $email,
            count($this->devices->devices) + 1,
            'Phone',
            'android',
            Device::PUSH_FCM,
            'fcm-' . $email,
            time(),
        );

        return $token;
    }

    /** @param array<string, mixed> $args */
    private function raise(array $args): \Reach\Alerts\Alert
    {
        return $this->alerts->create($this->request($args), time());
    }

    /** @param array<string, mixed> $args */
    private function request(array $args): AlertRequest
    {
        $request = AlertRequest::fromArray($args);
        $this->assertInstanceOf(AlertRequest::class, $request);

        return $request;
    }

    /** @param array<string, mixed> $params */
    private function authed(string $token, array $params = []): WP_REST_Request
    {
        $request = new WP_REST_Request($params);
        $request->set_header('authorization', 'Bearer ' . $token);

        return $request;
    }
}
