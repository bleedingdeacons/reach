<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
use Unity\Testing\Doubles\InMemoryPasswordCredentialRepository;
use Reach\Auth\DeviceCodeStore;
use Reach\Auth\DeviceRedirectValidator;
use Reach\Auth\DeviceTokenMinter;
use Reach\Auth\PasswordAuthenticator;
use Reach\Auth\PasswordPolicy;
use Reach\Auth\PasswordResetMailer;
use Reach\Auth\ProviderRegistry;
use Reach\Auth\StateStore;
use Reach\Auth\VerifiedIdentity;
use Reach\Core\RateLimiter;
use Reach\Devices\CurrentDevice;
use Reach\Devices\Device;
use Reach\Devices\ResponderGate;
use Reach\Rest\DeviceAuthController;
use Reach\Tests\Fixtures\InMemoryDeviceRepository;
use Reach\Tests\Fixtures\MemberStub;
use Scrutiny\Testing\Doubles\SpyAuditLogger;
use Unity\Members\ResponderCertification;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Enrolling a Hand handset, and the gate that decides who may.
 *
 * The behaviours worth protecting: only certified telephone responders
 * get a token; the plaintext token is emitted exactly once; a spent
 * exchange code cannot enrol a second handset; and a revoked handset
 * authenticates as nothing at all.
 */

beforeEach(function () {
    // --- helpers ----------------------------------------------------------
    $this->certified = function (string $email): MemberStub {
        return new MemberStub(
            personalEmail: $email,
            twelfthStepper: false,
            telephoneResponder: true,
            responderCertification: ResponderCertification::Certified,
        );
    };

    $this->identity = function (string $email): VerifiedIdentity {
        return new VerifiedIdentity(email: $email, provider: 'google', sub: 'sub-' . $email);
    };

    $this->controllerFor = function (MemberStub ...$members): DeviceAuthController {
        return ($this->controllerWithRepository)(new InMemoryMemberRepository($members));
    };

    $this->controllerWithRepository = function (InMemoryMemberRepository $members): DeviceAuthController {
        $gate = new ResponderGate($members);

        return new DeviceAuthController(
            $this->devices,
            $this->minter,
            $this->codes,
            new DeviceRedirectValidator(),
            $gate,
            new CurrentDevice($this->devices, $this->minter, $gate),
            new PasswordAuthenticator(
                new InMemoryPasswordCredentialRepository(),
                $members,
                new PasswordResetMailer(),
                new PasswordPolicy(),
            ),
            new ProviderRegistry(),
            new StateStore(),
            new RateLimiter(),
            new SpyAuditLogger(),
        );
    };

    /** Enrol a handset and return its plaintext token. */
    $this->enrol = function (DeviceAuthController $controller, string $email): string {
        $code = $this->codes->issue(($this->identity)($email));
        $result = $controller->exchange(($this->request)([
            'code'     => $code,
            'platform' => 'android',
        ]));

        $this->assertInstanceOf(WP_REST_Response::class, $result);

        return (string) $result->get_data()['token'];
    };

    /** @param array<string, mixed> $params */
    $this->request = function (array $params = []): WP_REST_Request {
        return new WP_REST_Request($params + [
            'label'         => '',
            'push_provider' => '',
            'push_token'    => '',
        ]);
    };

    $this->authed = function (string $token): WP_REST_Request {
        $request = ($this->request)();
        $request->set_header('authorization', 'Bearer ' . $token);

        return $request;
    };

    WpState::$transients = [];
    WpState::$options = [];

    $this->devices = new InMemoryDeviceRepository();
    $this->minter = new DeviceTokenMinter();
    $this->codes = new DeviceCodeStore();
});

test('exchange enrols certified responder and returns token once', function () {
    $controller = ($this->controllerFor)(($this->certified)('responder@example.com'));
    $code = $this->codes->issue(($this->identity)('responder@example.com'));

    $result = $controller->exchange(($this->request)([
        'code'          => $code,
        'platform'      => 'android',
        'label'         => 'Pixel 8',
        'push_provider' => 'fcm',
        'push_token'    => 'fcm-token-abc',
    ]));

    $this->assertInstanceOf(WP_REST_Response::class, $result);
    $this->assertSame(201, $result->get_status());

    $data = $result->get_data();
    $this->assertIsArray($data);
    $this->assertTrue($this->minter->looksLikeToken($data['token']));
    $this->assertSame('android', $data['platform']);
    $this->assertSame('fcm', $data['push_provider']);

    // Stored as a hash, never as the plaintext.
    $device = $this->devices->findById((int) $data['device_id']);
    $this->assertNotNull($device);
    $this->assertSame($this->minter->hash($data['token']), $this->devices->hashes[$device->id]);
});

test('exchange admits a member who is not a responder', function () {
    // This used to be the headline refusal — Hand's gate being
    // stricter than the website's. It is not any more: a member with
    // an address and a home group may carry a handset.
    $controller = ($this->controllerFor)(new MemberStub(
        personalEmail: 'stepper@example.com',
        twelfthStepper: true,
        telephoneResponder: false,
    ));
    $code = $this->codes->issue(($this->identity)('stepper@example.com'));

    $result = $controller->exchange(($this->request)(['code' => $code, 'platform' => 'android']));

    $this->assertNotInstanceOf(WP_Error::class, $result);
    $this->assertCount(1, $this->devices->devices);
});

test('exchange still refuses a member with no home group', function () {
    // The gate is looser, not absent. A half-imported record still
    // cannot put a handset on the rota.
    $controller = ($this->controllerFor)(new MemberStub(
        personalEmail: 'stub@example.com',
        homeGroup: 0,
    ));
    $code = $this->codes->issue(($this->identity)('stub@example.com'));

    $result = $controller->exchange(($this->request)(['code' => $code, 'platform' => 'android']));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('reach_not_eligible', $result->get_error_code());
    $this->assertSame(403, $result->get_error_data()['status'] ?? null);
    $this->assertSame([], $this->devices->devices);
});

test('exchange admits an uncertified responder', function () {
    // Certification no longer gates the handset. See ResponderGate
    // on what that gave up and why it was decided.
    $controller = ($this->controllerFor)(new MemberStub(
        personalEmail: 'trainee@example.com',
        twelfthStepper: false,
        telephoneResponder: true,
        responderCertification: ResponderCertification::InTraining,
    ));
    $code = $this->codes->issue(($this->identity)('trainee@example.com'));

    $result = $controller->exchange(($this->request)(['code' => $code, 'platform' => 'android']));

    $this->assertNotInstanceOf(WP_Error::class, $result);
    $this->assertCount(1, $this->devices->devices);
});

test('a spent code cannot enrol a second handset', function () {
    $controller = ($this->controllerFor)(($this->certified)('responder@example.com'));
    $code = $this->codes->issue(($this->identity)('responder@example.com'));

    $controller->exchange(($this->request)(['code' => $code, 'platform' => 'android']));
    $replay = $controller->exchange(($this->request)(['code' => $code, 'platform' => 'android']));

    $this->assertInstanceOf(WP_Error::class, $replay);
    $this->assertSame('reach_invalid_code', $replay->get_error_code());
    $this->assertCount(1, $this->devices->devices);
});

test('unknown platform is refused', function () {
    // The platform decides the delivery path, so guessing would mean
    // enrolling a handset that never receives anything.
    $controller = ($this->controllerFor)(($this->certified)('responder@example.com'));
    $code = $this->codes->issue(($this->identity)('responder@example.com'));

    $result = $controller->exchange(($this->request)(['code' => $code, 'platform' => 'blackberry']));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('reach_unknown_platform', $result->get_error_code());
});

test('push provider without a token falls back to polling', function () {
    $controller = ($this->controllerFor)(($this->certified)('responder@example.com'));
    $code = $this->codes->issue(($this->identity)('responder@example.com'));

    $result = $controller->exchange(($this->request)([
        'code'          => $code,
        'platform'      => 'windows',
        'push_provider' => '',
    ]));

    $this->assertInstanceOf(WP_REST_Response::class, $result);
    $device = $this->devices->findById((int) $result->get_data()['device_id']);
    $this->assertNotNull($device);
    $this->assertFalse($device->wantsPush());
});

test('enrolment cap revokes the least recently seen handset', function () {
    // Someone standing there with a new phone must not be locked out
    // by five forgotten ones.
    $controller = ($this->controllerFor)(($this->certified)('responder@example.com'));

    for ($i = 0; $i < 6; $i++) {
        $code = $this->codes->issue(($this->identity)('responder@example.com'));
        $controller->exchange(($this->request)(['code' => $code, 'platform' => 'android']));
    }

    $live = $this->devices->findByMemberEmail('responder@example.com');
    $this->assertCount(5, $live);
    $this->assertCount(6, $this->devices->devices, 'Revoked rows are kept as history.');
});

test('session requires a live token', function () {
    $controller = ($this->controllerFor)(($this->certified)('responder@example.com'));
    $token = ($this->enrol)($controller, 'responder@example.com');

    $ok = $controller->session(($this->authed)($token));
    $this->assertInstanceOf(WP_REST_Response::class, $ok);
    $this->assertTrue($ok->get_data()['authorised']);

    $anonymous = $controller->session(($this->request)());
    $this->assertInstanceOf(WP_Error::class, $anonymous);
    $this->assertSame(401, $anonymous->get_error_data()['status'] ?? null);
});

test('revoked handset authenticates as nothing', function () {
    $controller = ($this->controllerFor)(($this->certified)('responder@example.com'));
    $token = ($this->enrol)($controller, 'responder@example.com');

    $controller->signout(($this->authed)($token));

    $result = $controller->session(($this->authed)($token));
    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame(401, $result->get_error_data()['status'] ?? null);
});

test('handset of a member who loses their home group is revoked on its next call', function () {
    // <b>The per-request re-check survived the gate being loosened,
    // and it is the half worth keeping.</b> It used to catch a lapsed
    // certification; it now catches a record that has stopped being a
    // usable member. Either way the handset stops itself rather than
    // waiting for somebody to remember to revoke it.
    $members = new InMemoryMemberRepository([($this->certified)('responder@example.com')]);
    $controller = ($this->controllerWithRepository)($members);
    $token = ($this->enrol)($controller, 'responder@example.com');

    $lapsed = new InMemoryMemberRepository([
        new MemberStub(
            personalEmail: 'responder@example.com',
            twelfthStepper: false,
            telephoneResponder: true,
            responderCertification: ResponderCertification::Certified,
            homeGroup: 0,
        ),
    ]);
    $afterLapse = ($this->controllerWithRepository)($lapsed);

    $result = $afterLapse->session(($this->authed)($token));

    $this->assertInstanceOf(WP_Error::class, $result);
    $device = $this->devices->devices[0];
    $this->assertTrue($device->isRevoked(), 'An ineligible responder’s handset is cut off, not just turned away.');
});

test('push token can be updated', function () {
    // Firebase rotates registration tokens without warning; a stale
    // one is the usual reason a handset goes quiet.
    $controller = ($this->controllerFor)(($this->certified)('responder@example.com'));
    $token = ($this->enrol)($controller, 'responder@example.com');

    $request = ($this->authed)($token);
    $request->set_param('push_provider', 'fcm');
    $request->set_param('push_token', 'rotated-token');

    $result = $controller->updatePush($request);

    $this->assertInstanceOf(WP_REST_Response::class, $result);
    $this->assertSame('rotated-token', $this->devices->devices[0]->pushToken);
});

test('a handset can report that its lock screen shows alert text', function () {
    // Carried on the push call because Hand re-registers its token at
    // every launch anyway, which makes this as fresh as a setting its
    // owner can change at any moment is going to get.
    $controller = ($this->controllerFor)(($this->certified)('responder@example.com'));
    $token = ($this->enrol)($controller, 'responder@example.com');

    $request = ($this->authed)($token);
    $request->set_param('push_provider', 'fcm');
    $request->set_param('push_token', 'a-token');
    $request->set_param('lock_screen', Device::LOCK_SCREEN_SHOWN);

    $controller->updatePush($request);

    $this->assertTrue($this->devices->devices[0]->showsAlertsOnLockScreen());
});

test('a handset that is put right clears its own warning', function () {
    // Unlike a key fault, this is a current setting rather than a
    // thing that happened, so the last thing the handset said is the
    // whole answer. A responder who turns sensitive content off should
    // not have to be revoked to stop being flagged.
    $controller = ($this->controllerFor)(($this->certified)('responder@example.com'));
    $token = ($this->enrol)($controller, 'responder@example.com');

    foreach ([Device::LOCK_SCREEN_SHOWN, Device::LOCK_SCREEN_HIDDEN] as $state) {
        $request = ($this->authed)($token);
        $request->set_param('push_provider', 'fcm');
        $request->set_param('push_token', 'a-token');
        $request->set_param('lock_screen', $state);
        $controller->updatePush($request);
    }

    $this->assertFalse($this->devices->devices[0]->showsAlertsOnLockScreen());
});

test('a handset that says nothing leaves the last answer alone', function () {
    // An older build does not send the field. It must not be able to
    // erase a warning a newer one raised, so absent means "no news"
    // rather than "all clear".
    $controller = ($this->controllerFor)(($this->certified)('responder@example.com'));
    $token = ($this->enrol)($controller, 'responder@example.com');

    $flagged = ($this->authed)($token);
    $flagged->set_param('push_provider', 'fcm');
    $flagged->set_param('push_token', 'a-token');
    $flagged->set_param('lock_screen', Device::LOCK_SCREEN_SHOWN);
    $controller->updatePush($flagged);

    $silent = ($this->authed)($token);
    $silent->set_param('push_provider', 'fcm');
    $silent->set_param('push_token', 'a-token');
    $controller->updatePush($silent);

    $this->assertTrue(
        $this->devices->devices[0]->showsAlertsOnLockScreen(),
        'a build too old to report must not clear what a newer one said',
    );
});

test('an unrecognised lock screen value is not stored', function () {
    // It would show on the admin list as neither a warning nor a
    // reassurance, and nobody would know which it meant.
    $controller = ($this->controllerFor)(($this->certified)('responder@example.com'));
    $token = ($this->enrol)($controller, 'responder@example.com');

    $request = ($this->authed)($token);
    $request->set_param('push_provider', 'fcm');
    $request->set_param('push_token', 'a-token');
    $request->set_param('lock_screen', 'maybe');

    $controller->updatePush($request);

    $this->assertSame(Device::LOCK_SCREEN_UNKNOWN, $this->devices->devices[0]->lockScreen);
});

test('a failed write is reported as failure not as a token', function () {
    // The amber bug. The device table did not exist, $wpdb->insert()
    // returned false, and nothing checked - so enrolment answered 201
    // with a freshly minted token for a row that was never written.
    // The handset stored it, 401'd on its very next request, and sent
    // its responder back round the sign-in loop with an empty admin
    // device list and nothing saying why.
    //
    // A write that fails must read as a failure.
    $this->devices->failOnCreate = true;

    $controller = ($this->controllerFor)(($this->certified)('responder@example.com'));
    $code = $this->codes->issue(($this->identity)('responder@example.com'));

    $result = $controller->exchange(($this->request)([
        'code'     => $code,
        'platform' => 'android',
    ]));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('reach_enrolment_failed', $result->get_error_code());
    $this->assertSame(500, $result->get_error_data()['status'] ?? null);
    $this->assertSame([], $this->devices->devices, 'Nothing should have been enrolled.');
});

test('start refuses a redirect outside the allow list', function () {
    $controller = ($this->controllerFor)(($this->certified)('responder@example.com'));

    $result = $controller->start(($this->request)([
        'provider'     => 'google',
        'redirect_uri' => 'https://evil.example/steal',
    ]));

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('reach_invalid_redirect', $result->get_error_code());
});
