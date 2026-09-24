<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
use Reach\Alerts\AcknowledgementNotifier;
use Reach\Alerts\Alert;
use Reach\Alerts\AlertApi;
use Reach\Alerts\AlertDispatcher;
use Reach\Alerts\AlertRequest;
use Reach\Alerts\RecipientResolver;
use Reach\Auth\DeviceTokenMinter;
use Reach\Core\RateLimiter;
use Reach\Devices\CurrentDevice;
use Reach\Devices\Device;
use Reach\Devices\ResponderGate;
use Reach\Rest\AlertController;
use Reach\Tests\Fixtures\InMemoryAlertContactRepository;
use Reach\Tests\Fixtures\InMemoryAlertReplyRepository;
use Reach\Tests\Fixtures\InMemoryAlertRepository;
use Reach\Tests\Fixtures\InMemoryDeviceRepository;
use Reach\Tests\Fixtures\MemberStub;
use Scrutiny\Testing\Doubles\SpyAuditLogger;
use Unity\Members\ResponderCertification;
use Unity\Testing\Doubles\InMemoryCommitteeRepository;
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

beforeEach(function () {
    // --- helpers ----------------------------------------------------------
    $this->controller = function (): AlertController {
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

        // A real notifier over a real dispatcher with no transports: the
        // notice it raises is stored in the same in-memory repository the
        // assertions read, which is what lets a test see the second
        // message an acknowledgement produces without a push to stub.
        $dispatcher = new AlertDispatcher($this->alerts, $this->contacts, $this->devices, $gate, []);
        $notifier = new AcknowledgementNotifier($this->alerts, $dispatcher);

        $memberRepository = new InMemoryMemberRepository($members);

        return new AlertController(
            $this->alerts,
            $this->contacts,
            new CurrentDevice($this->devices, $this->minter, $gate),
            $this->audit,
            $this->devices,
            $notifier,
            $this->replies,
            new RecipientResolver(
                $this->devices,
                $memberRepository,
                new InMemoryCommitteeRepository(),
            ),
            new AlertApi($dispatcher),
            new RateLimiter(),
        );
    };

    /** Enrol a handset directly and return its plaintext token. */
    $this->enrol = function (string $email): string {
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
    };

    /** @param array<string, mixed> $args */
    $this->raise = function (array $args): Alert {
        return $this->alerts->create(($this->request)($args), time());
    };

    /** @param array<string, mixed> $args */
    $this->request = function (array $args): AlertRequest {
        $request = AlertRequest::fromArray($args);
        $this->assertInstanceOf(AlertRequest::class, $request);

        return $request;
    };

    /** @param array<string, mixed> $params */
    $this->authed = function (string $token, array $params = []): WP_REST_Request {
        $request = new WP_REST_Request($params);
        $request->set_header('authorization', 'Bearer ' . $token);

        return $request;
    };

    WpState::$restRoutes = [];

    $this->devices = new InMemoryDeviceRepository();
    $this->alerts = new InMemoryAlertRepository();
    $this->contacts = new InMemoryAlertContactRepository();
    $this->replies = new InMemoryAlertReplyRepository();
    $this->minter = new DeviceTokenMinter();
    // Held rather than built inside controller(), because the
    // contact endpoint's audit entry is the thing under test on that
    // path rather than a side effect of it.
    $this->audit = new SpyAuditLogger();
});

test('poll requires authentication', function () {
    $result = ($this->controller)()->pending(new WP_REST_Request());

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame(401, $result->get_error_data()['status'] ?? null);
});

test('poll returns broadcast alerts', function () {
    $token = ($this->enrol)('responder@example.com');
    ($this->raise)(['kind' => 'test', 'title' => 'Everybody']);

    $result = ($this->controller)()->pending(($this->authed)($token));

    $this->assertInstanceOf(WP_REST_Response::class, $result);
    $this->assertCount(1, $result->get_data()['alerts']);
    $this->assertSame('Everybody', $result->get_data()['alerts'][0]['title']);
});

test('poll returns a device targeted alert to that handset only', function () {
    // One responder, two handsets, an alert addressed to the second.
    // It carries no email, so the poll must be looking at the device
    // id rather than reading the empty address as a broadcast.
    $phone  = ($this->enrol)('responder@example.com');
    $tablet = ($this->enrol)('responder@example.com');
    $tabletId = $this->devices->devices[1]->id;

    ($this->raise)(['kind' => 'test', 'title' => 'That one', 'target_device_id' => $tabletId]);

    $onTablet = ($this->controller)()->pending(($this->authed)($tablet));
    $onPhone  = ($this->controller)()->pending(($this->authed)($phone));

    $this->assertInstanceOf(WP_REST_Response::class, $onTablet);
    $this->assertInstanceOf(WP_REST_Response::class, $onPhone);
    $this->assertCount(1, $onTablet->get_data()['alerts']);
    $this->assertSame([], $onPhone->get_data()['alerts']);
});

test('a device target overrides an address that disagrees with it', function () {
    // Both fields set, and pointing at different people. The dispatcher
    // pushes by device id, so the poll must hand it over by device id too
    // — otherwise the handset is rung about an alert it can never fetch.
    $phone = ($this->enrol)('responder@example.com');
    $deviceId = $this->devices->devices[0]->id;

    ($this->raise)([
        'kind' => 'test',
        'title' => 'That handset',
        'target_email' => 'someone-else@example.com',
        'target_device_id' => $deviceId,
    ]);

    $result = ($this->controller)()->pending(($this->authed)($phone));

    $this->assertInstanceOf(WP_REST_Response::class, $result);
    $this->assertCount(1, $result->get_data()['alerts']);
    $this->assertSame('That handset', $result->get_data()['alerts'][0]['title']);
});

test('another handset cannot acknowledge a device targeted alert', function () {
    // Same 404 as an alert that does not exist: which alerts exist is
    // not something one handset should learn about another.
    $phone = ($this->enrol)('responder@example.com');
    ($this->enrol)('responder@example.com');
    $tabletId = $this->devices->devices[1]->id;

    $alert = ($this->raise)([
        'kind' => 'test',
        'title' => 'That one',
        'target_device_id' => $tabletId,
    ]);

    $result = ($this->controller)()->acknowledge(($this->authed)($phone, ['id' => $alert->id]));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame(404, $result->get_error_data()['status'] ?? null);
});

test('poll does not return another responders targeted alert', function () {
    $token = ($this->enrol)('responder@example.com');
    ($this->raise)([
        'kind'         => 'test',
        'title'        => 'For somebody else',
        'target_email' => 'other@example.com',
    ]);

    $result = ($this->controller)()->pending(($this->authed)($token));

    $this->assertInstanceOf(WP_REST_Response::class, $result);
    $this->assertSame([], $result->get_data()['alerts']);
});

test('poll returns alerts targeted at this responder', function () {
    $token = ($this->enrol)('responder@example.com');
    ($this->raise)([
        'kind'         => 'test',
        'title'        => 'Just for you',
        'target_email' => 'responder@example.com',
    ]);

    $result = ($this->controller)()->pending(($this->authed)($token));

    $this->assertInstanceOf(WP_REST_Response::class, $result);
    $this->assertCount(1, $result->get_data()['alerts']);
});

test('expired alerts are not returned', function () {
    $token = ($this->enrol)('responder@example.com');
    // Raised far enough in the past that its default hour is spent.
    $this->alerts->create(($this->request)(['kind' => 'test', 'title' => 'Stale']), time() - 7200);

    $result = ($this->controller)()->pending(($this->authed)($token));

    $this->assertInstanceOf(WP_REST_Response::class, $result);
    $this->assertSame([], $result->get_data()['alerts']);
});

test('acknowledging stops an alert coming back', function () {
    // This is what prevents the same alert ringing a handset twice.
    $token = ($this->enrol)('responder@example.com');
    $alert = ($this->raise)(['kind' => 'test', 'title' => 'Ring once']);
    $controller = ($this->controller)();

    $ack = $controller->acknowledge(($this->authed)($token, ['id' => $alert->id]));
    $this->assertInstanceOf(WP_REST_Response::class, $ack);
    $this->assertTrue($ack->get_data()['acknowledged']);

    $result = $controller->pending(($this->authed)($token));
    $this->assertInstanceOf(WP_REST_Response::class, $result);
    $this->assertSame([], $result->get_data()['alerts']);
});

test('acknowledging is idempotent', function () {
    // A handset retrying after a dropped response has achieved what
    // it asked for; that is not an error.
    $token = ($this->enrol)('responder@example.com');
    $alert = ($this->raise)(['kind' => 'test', 'title' => 'Ring once']);
    $controller = ($this->controller)();

    $controller->acknowledge(($this->authed)($token, ['id' => $alert->id]));
    $second = $controller->acknowledge(($this->authed)($token, ['id' => $alert->id]));

    $this->assertInstanceOf(WP_REST_Response::class, $second);
    $this->assertCount(1, $this->alerts->acknowledgementsFor($alert->id));
});

test('one handset acknowledging clears the alert from the others', function () {
    // An answered message is over, for everybody. This used to assert
    // the opposite — that the second handset's copy stayed
    // outstanding, because each handset rang and answered for itself
    // — and the rule was deliberately reversed: a responder taking a
    // job should clear it off the rest of the rota's screens rather
    // than leave thirty people to dismiss it one by one.
    //
    // What the others are left with is the notice saying who took it,
    // which is the whole of what they still need to know.
    $first = ($this->enrol)('one@example.com');
    $second = ($this->enrol)('two@example.com');
    $alert = ($this->raise)(['kind' => 'test', 'title' => 'Everybody']);
    $controller = ($this->controller)();

    $controller->acknowledge(($this->authed)($first, ['id' => $alert->id]));

    $result = $controller->pending(($this->authed)($second));
    $this->assertInstanceOf(WP_REST_Response::class, $result);

    $kinds = array_column($result->get_data()['alerts'], 'kind');
    $this->assertNotContains('test', $kinds);
    $this->assertContains(Alert::KIND_ACKNOWLEDGED, $kinds);
});

test('cannot acknowledge another responders targeted alert', function () {
    // Otherwise the admin view would show an alert answered by
    // someone who never saw it.
    $token = ($this->enrol)('responder@example.com');
    $alert = ($this->raise)([
        'kind'         => 'test',
        'title'        => 'For somebody else',
        'target_email' => 'other@example.com',
    ]);

    $result = ($this->controller)()->acknowledge(($this->authed)($token, ['id' => $alert->id]));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame(404, $result->get_error_data()['status'] ?? null);
    $this->assertSame([], $this->alerts->acknowledgementsFor($alert->id));
});

test('acknowledging an unknown alert is404', function () {
    $token = ($this->enrol)('responder@example.com');

    $result = ($this->controller)()->acknowledge(($this->authed)($token, ['id' => 9999]));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame(404, $result->get_error_data()['status'] ?? null);
});

// --- route wiring -----------------------------------------------------
test('register hangs route registration on rest api init', function () {
    $this->captureAction('rest_api_init');

    ($this->controller)()->register();

    $this->assertCount(1, $this->actionCallbacks('rest_api_init'));
});

test('all three alert routes are declared', function () {
    ($this->controller)()->registerRoutes();

    $routes = array_column(WpState::$restRoutes, 'route');
    $this->assertContains('/alerts', $routes);
    $this->assertContains('/alerts/(?P<id>\d+)/contact', $routes);
    $this->assertContains('/alerts/(?P<id>\d+)/ack', $routes);
});

test('the alert id is coerced to a positive integer', function () {
    // absint on both id-bearing routes: the pattern already restricts
    // it to digits, and this makes the callback's (int) cast a
    // formality rather than the only guard.
    ($this->controller)()->registerRoutes();

    foreach (WpState::$restRoutes as $route) {
        if (!str_contains((string) $route['route'], '<id>')) {
            continue;
        }
        $this->assertSame('absint', $route['args']['args']['id']['sanitize_callback']);
        $this->assertTrue($route['args']['args']['id']['required']);
    }
});

// --- the contact endpoint ---------------------------------------------
test('fetching a contact requires authentication', function () {
    $alert = ($this->raise)(['kind' => 'call_request', 'title' => 'Callback wanted']);
    $this->contacts->save($alert->id, 'Sam, 07700 900123', time());

    $result = ($this->controller)()->contact(new WP_REST_Request(['id' => $alert->id]));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame(401, $result->get_error_data()['status'] ?? null);
});

test('a responder can fetch the contact for a broadcast alert', function () {
    $token = ($this->enrol)('responder@example.com');
    $alert = ($this->raise)(['kind' => 'call_request', 'title' => 'Callback wanted']);
    $this->contacts->save($alert->id, 'Sam, 07700 900123', time());

    $result = ($this->controller)()->contact(($this->authed)($token, ['id' => $alert->id]));

    $this->assertInstanceOf(WP_REST_Response::class, $result);
    $this->assertSame(200, $result->get_status());
    $this->assertSame('Sam, 07700 900123', $result->get_data()['contact']);
    $this->assertSame($alert->id, $result->get_data()['alert_id']);
});

test('every contact read is audited', function () {
    // The point of the endpoint's shape: a regulator can answer
    // "which user saw this personal data, and when". An alert contact
    // is personal data reaching a responder, so it is answerable the
    // same way as everything else Reach exposes.
    $token = ($this->enrol)('responder@example.com');
    $alert = ($this->raise)([
        'kind'      => 'call_request',
        'title'     => 'Callback wanted',
        'reference' => 'CR-000123',
    ]);
    $this->contacts->save($alert->id, 'Sam, 07700 900123', time());

    ($this->controller)()->contact(($this->authed)($token, ['id' => $alert->id]));

    $this->assertCount(1, $this->audit->entries);
    $entry = $this->audit->entries[0];
    $this->assertSame('alert_contact', $entry['fieldName']);
    $this->assertStringContainsString('Alert contact viewed', $entry['detail']);
    $this->assertStringContainsString('ref:CR-000123', $entry['detail']);
    $this->assertStringContainsString('alert:' . $alert->id, $entry['detail']);
});

test('the audit entry omits an empty reference', function () {
    $token = ($this->enrol)('responder@example.com');
    $alert = ($this->raise)(['kind' => 'call_request', 'title' => 'Callback wanted']);
    $this->contacts->save($alert->id, 'Sam, 07700 900123', time());

    ($this->controller)()->contact(($this->authed)($token, ['id' => $alert->id]));

    $this->assertStringNotContainsString('ref:', $this->audit->entries[0]['detail']);
});

test('the audit trail never records the contact itself', function () {
    // Auditing the read must not become a second, unencrypted copy of
    // the thing being protected.
    $token = ($this->enrol)('responder@example.com');
    $alert = ($this->raise)(['kind' => 'call_request', 'title' => 'Callback wanted']);
    $this->contacts->save($alert->id, 'Sam, 07700 900123', time());

    ($this->controller)()->contact(($this->authed)($token, ['id' => $alert->id]));

    $this->assertStringNotContainsString('900123', $this->audit->entries[0]['detail']);
    $this->assertStringNotContainsString('Sam', $this->audit->entries[0]['detail']);
});

test('an alert with no contact answers empty and is not audited', function () {
    // Nothing personal was disclosed, so there is nothing to audit —
    // and an audit trail padded with non-events is a worse answer to
    // "who saw this" than one without them.
    $token = ($this->enrol)('responder@example.com');
    $alert = ($this->raise)(['kind' => 'call_request', 'title' => 'Callback wanted']);

    $result = ($this->controller)()->contact(($this->authed)($token, ['id' => $alert->id]));

    $this->assertInstanceOf(WP_REST_Response::class, $result);
    $this->assertSame('', $result->get_data()['contact']);
    $this->assertSame([], $this->audit->entries);
});

test('a responder can fetch the contact for their own targeted alert', function () {
    $token = ($this->enrol)('responder@example.com');
    $alert = ($this->raise)([
        'kind'         => 'call_request',
        'title'        => 'Callback wanted',
        'target_email' => 'responder@example.com',
    ]);
    $this->contacts->save($alert->id, 'Sam, 07700 900123', time());

    $result = ($this->controller)()->contact(($this->authed)($token, ['id' => $alert->id]));

    $this->assertInstanceOf(WP_REST_Response::class, $result);
    $this->assertSame('Sam, 07700 900123', $result->get_data()['contact']);
});

test('another responders targeted contact is refused as unknown', function () {
    // Same 404 as "no such alert": which alerts exist is not
    // something one responder should learn about another's.
    ($this->enrol)('other@example.com');
    $token = ($this->enrol)('responder@example.com');
    $alert = ($this->raise)([
        'kind'         => 'call_request',
        'title'        => 'Callback wanted',
        'target_email' => 'other@example.com',
    ]);
    $this->contacts->save($alert->id, 'Sam, 07700 900123', time());

    $result = ($this->controller)()->contact(($this->authed)($token, ['id' => $alert->id]));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('reach_unknown_alert', $result->get_error_code());
    $this->assertSame(404, $result->get_error_data()['status'] ?? null);
    $this->assertSame([], $this->audit->entries, 'a refused read is not a read');
});

test('fetching an unknown alerts contact is404', function () {
    $token = ($this->enrol)('responder@example.com');

    $result = ($this->controller)()->contact(($this->authed)($token, ['id' => 9999]));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame(404, $result->get_error_data()['status'] ?? null);
});

test('the contact is refused identically whether it is missing or not yours', function () {
    // The two 404s must be indistinguishable or the difference is
    // itself the disclosure.
    ($this->enrol)('other@example.com');
    $token = ($this->enrol)('responder@example.com');
    $theirs = ($this->raise)([
        'kind'         => 'call_request',
        'title'        => 'Callback wanted',
        'target_email' => 'other@example.com',
    ]);

    $notYours = ($this->controller)()->contact(($this->authed)($token, ['id' => $theirs->id]));
    $noSuchThing = ($this->controller)()->contact(($this->authed)($token, ['id' => 9999]));

    $this->assertInstanceOf(WP_Error::class, $notYours);
    $this->assertInstanceOf(WP_Error::class, $noSuchThing);
    $this->assertSame($noSuchThing->get_error_code(), $notYours->get_error_code());
    $this->assertSame($noSuchThing->get_error_message(), $notYours->get_error_message());
    $this->assertSame($noSuchThing->get_error_data(), $notYours->get_error_data());
});

test('the contact never appears in the poll response', function () {
    // The poll runs every few seconds on every handset. Personal data
    // must not travel on it — the app is told a contact exists and
    // fetches it separately, once, audited.
    $token = ($this->enrol)('responder@example.com');
    $alert = ($this->raise)(['kind' => 'call_request', 'title' => 'Callback wanted']);
    $this->contacts->save($alert->id, 'Sam, 07700 900123', time());

    $result = ($this->controller)()->pending(($this->authed)($token));

    $this->assertInstanceOf(WP_REST_Response::class, $result);
    $encoded = (string) wp_json_encode($result->get_data());
    $this->assertStringNotContainsString('900123', $encoded);
    $this->assertStringNotContainsString('Sam', $encoded);
});

test('the poll echoes the server clock', function () {
    // So a handset can detect a clock that has drifted far enough to
    // make its own expiry arithmetic wrong.
    $token = ($this->enrol)('responder@example.com');

    $result = ($this->controller)()->pending(($this->authed)($token));

    $this->assertInstanceOf(WP_REST_Response::class, $result);
    $this->assertEqualsWithDelta(time(), $result->get_data()['now'], 5);
});

test('a handset can report that it could not read an alert', function () {
    // Reach can see that a device row has no key. It cannot see a
    // handset whose own copy has gone — a reinstall, a restore that
    // skipped the keystore — and from here that handset looks healthy
    // right up until an alert it cannot open.
    $token = ($this->enrol)('jo@example.test');

    $response = ($this->controller)()->unreadable(($this->authed)($token));

    $this->assertInstanceOf(WP_REST_Response::class, $response);
    $this->assertSame(204, $response->get_status());
    $this->assertTrue($this->devices->devices[0]->hasKeyFault());
});

test('reporting is idempotent', function () {
    // A handset reporting the same fault on three alerts in a row is
    // not an error, and there is nothing it could do with a different
    // answer.
    $token = ($this->enrol)('jo@example.test');
    $controller = ($this->controller)();

    $controller->unreadable(($this->authed)($token));
    $second = $controller->unreadable(($this->authed)($token));

    $this->assertInstanceOf(WP_REST_Response::class, $second);
    $this->assertSame(204, $second->get_status());
});

test('an unauthenticated report is refused', function () {
    // Otherwise anyone could mark any handset as broken, and an admin
    // would revoke a working one.
    $result = ($this->controller)()->unreadable(new WP_REST_Request());

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('reach_device_not_authenticated', $result->get_error_code());
});
