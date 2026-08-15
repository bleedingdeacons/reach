<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
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
    private SpyAuditLogger $audit;

    protected function setUp(): void
    {
        parent::setUp();

        WpState::$restRoutes = [];

        $this->devices = new InMemoryDeviceRepository();
        $this->alerts = new InMemoryAlertRepository();
        $this->contacts = new InMemoryAlertContactRepository();
        $this->minter = new DeviceTokenMinter();
        // Held rather than built inside controller(), because the
        // contact endpoint's audit entry is the thing under test on that
        // path rather than a side effect of it.
        $this->audit = new SpyAuditLogger();
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

    // --- route wiring -----------------------------------------------------

    public function testRegisterHangsRouteRegistrationOnRestApiInit(): void
    {
        $this->captureAction('rest_api_init');

        $this->controller()->register();

        $this->assertCount(1, $this->actionCallbacks('rest_api_init'));
    }

    public function testAllThreeAlertRoutesAreDeclared(): void
    {
        $this->controller()->registerRoutes();

        $routes = array_column(WpState::$restRoutes, 'route');
        $this->assertContains('/alerts', $routes);
        $this->assertContains('/alerts/(?P<id>\d+)/contact', $routes);
        $this->assertContains('/alerts/(?P<id>\d+)/ack', $routes);
    }

    public function testTheAlertIdIsCoercedToAPositiveInteger(): void
    {
        // absint on both id-bearing routes: the pattern already restricts
        // it to digits, and this makes the callback's (int) cast a
        // formality rather than the only guard.
        $this->controller()->registerRoutes();

        foreach (WpState::$restRoutes as $route) {
            if (!str_contains((string) $route['route'], '<id>')) {
                continue;
            }
            $this->assertSame('absint', $route['args']['args']['id']['sanitize_callback']);
            $this->assertTrue($route['args']['args']['id']['required']);
        }
    }

    // --- the contact endpoint ---------------------------------------------

    public function testFetchingAContactRequiresAuthentication(): void
    {
        $alert = $this->raise(['kind' => 'call_request', 'title' => 'Callback wanted']);
        $this->contacts->save($alert->id, 'Sam, 07700 900123', time());

        $result = $this->controller()->contact(new WP_REST_Request(['id' => $alert->id]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(401, $result->get_error_data()['status'] ?? null);
    }

    public function testAResponderCanFetchTheContactForABroadcastAlert(): void
    {
        $token = $this->enrol('responder@example.com');
        $alert = $this->raise(['kind' => 'call_request', 'title' => 'Callback wanted']);
        $this->contacts->save($alert->id, 'Sam, 07700 900123', time());

        $result = $this->controller()->contact($this->authed($token, ['id' => $alert->id]));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame(200, $result->get_status());
        $this->assertSame('Sam, 07700 900123', $result->get_data()['contact']);
        $this->assertSame($alert->id, $result->get_data()['alert_id']);
    }

    public function testEveryContactReadIsAudited(): void
    {
        // The point of the endpoint's shape: a regulator can answer
        // "which user saw this personal data, and when". An alert contact
        // is personal data reaching a responder, so it is answerable the
        // same way as everything else Reach exposes.
        $token = $this->enrol('responder@example.com');
        $alert = $this->raise([
            'kind'      => 'call_request',
            'title'     => 'Callback wanted',
            'reference' => 'CR-000123',
        ]);
        $this->contacts->save($alert->id, 'Sam, 07700 900123', time());

        $this->controller()->contact($this->authed($token, ['id' => $alert->id]));

        $this->assertCount(1, $this->audit->entries);
        $entry = $this->audit->entries[0];
        $this->assertSame('alert_contact', $entry['fieldName']);
        $this->assertStringContainsString('Alert contact viewed', $entry['detail']);
        $this->assertStringContainsString('ref:CR-000123', $entry['detail']);
        $this->assertStringContainsString('alert:' . $alert->id, $entry['detail']);
    }

    public function testTheAuditEntryOmitsAnEmptyReference(): void
    {
        $token = $this->enrol('responder@example.com');
        $alert = $this->raise(['kind' => 'call_request', 'title' => 'Callback wanted']);
        $this->contacts->save($alert->id, 'Sam, 07700 900123', time());

        $this->controller()->contact($this->authed($token, ['id' => $alert->id]));

        $this->assertStringNotContainsString('ref:', $this->audit->entries[0]['detail']);
    }

    public function testTheAuditTrailNeverRecordsTheContactItself(): void
    {
        // Auditing the read must not become a second, unencrypted copy of
        // the thing being protected.
        $token = $this->enrol('responder@example.com');
        $alert = $this->raise(['kind' => 'call_request', 'title' => 'Callback wanted']);
        $this->contacts->save($alert->id, 'Sam, 07700 900123', time());

        $this->controller()->contact($this->authed($token, ['id' => $alert->id]));

        $this->assertStringNotContainsString('900123', $this->audit->entries[0]['detail']);
        $this->assertStringNotContainsString('Sam', $this->audit->entries[0]['detail']);
    }

    public function testAnAlertWithNoContactAnswersEmptyAndIsNotAudited(): void
    {
        // Nothing personal was disclosed, so there is nothing to audit —
        // and an audit trail padded with non-events is a worse answer to
        // "who saw this" than one without them.
        $token = $this->enrol('responder@example.com');
        $alert = $this->raise(['kind' => 'call_request', 'title' => 'Callback wanted']);

        $result = $this->controller()->contact($this->authed($token, ['id' => $alert->id]));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame('', $result->get_data()['contact']);
        $this->assertSame([], $this->audit->entries);
    }

    public function testAResponderCanFetchTheContactForTheirOwnTargetedAlert(): void
    {
        $token = $this->enrol('responder@example.com');
        $alert = $this->raise([
            'kind'         => 'call_request',
            'title'        => 'Callback wanted',
            'target_email' => 'responder@example.com',
        ]);
        $this->contacts->save($alert->id, 'Sam, 07700 900123', time());

        $result = $this->controller()->contact($this->authed($token, ['id' => $alert->id]));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame('Sam, 07700 900123', $result->get_data()['contact']);
    }

    public function testAnotherRespondersTargetedContactIsRefusedAsUnknown(): void
    {
        // Same 404 as "no such alert": which alerts exist is not
        // something one responder should learn about another's.
        $this->enrol('other@example.com');
        $token = $this->enrol('responder@example.com');
        $alert = $this->raise([
            'kind'         => 'call_request',
            'title'        => 'Callback wanted',
            'target_email' => 'other@example.com',
        ]);
        $this->contacts->save($alert->id, 'Sam, 07700 900123', time());

        $result = $this->controller()->contact($this->authed($token, ['id' => $alert->id]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_unknown_alert', $result->get_error_code());
        $this->assertSame(404, $result->get_error_data()['status'] ?? null);
        $this->assertSame([], $this->audit->entries, 'a refused read is not a read');
    }

    public function testFetchingAnUnknownAlertsContactIs404(): void
    {
        $token = $this->enrol('responder@example.com');

        $result = $this->controller()->contact($this->authed($token, ['id' => 9999]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(404, $result->get_error_data()['status'] ?? null);
    }

    public function testTheContactIsRefusedIdenticallyWhetherItIsMissingOrNotYours(): void
    {
        // The two 404s must be indistinguishable or the difference is
        // itself the disclosure.
        $this->enrol('other@example.com');
        $token = $this->enrol('responder@example.com');
        $theirs = $this->raise([
            'kind'         => 'call_request',
            'title'        => 'Callback wanted',
            'target_email' => 'other@example.com',
        ]);

        $notYours = $this->controller()->contact($this->authed($token, ['id' => $theirs->id]));
        $noSuchThing = $this->controller()->contact($this->authed($token, ['id' => 9999]));

        $this->assertInstanceOf(WP_Error::class, $notYours);
        $this->assertInstanceOf(WP_Error::class, $noSuchThing);
        $this->assertSame($noSuchThing->get_error_code(), $notYours->get_error_code());
        $this->assertSame($noSuchThing->get_error_message(), $notYours->get_error_message());
        $this->assertSame($noSuchThing->get_error_data(), $notYours->get_error_data());
    }

    public function testTheContactNeverAppearsInThePollResponse(): void
    {
        // The poll runs every few seconds on every handset. Personal data
        // must not travel on it — the app is told a contact exists and
        // fetches it separately, once, audited.
        $token = $this->enrol('responder@example.com');
        $alert = $this->raise(['kind' => 'call_request', 'title' => 'Callback wanted']);
        $this->contacts->save($alert->id, 'Sam, 07700 900123', time());

        $result = $this->controller()->pending($this->authed($token));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $encoded = (string) wp_json_encode($result->get_data());
        $this->assertStringNotContainsString('900123', $encoded);
        $this->assertStringNotContainsString('Sam', $encoded);
    }

    public function testThePollEchoesTheServerClock(): void
    {
        // So a handset can detect a clock that has drifted far enough to
        // make its own expiry arithmetic wrong.
        $token = $this->enrol('responder@example.com');

        $result = $this->controller()->pending($this->authed($token));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertEqualsWithDelta(time(), $result->get_data()['now'], 5);
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
            $this->audit,
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
