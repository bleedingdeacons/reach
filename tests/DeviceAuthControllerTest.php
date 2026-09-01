<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
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

// Reuse the in-memory credential repo from the authenticator test.
require_once __DIR__ . '/PasswordAuthenticatorTest.php';

/**
 * Enrolling a Hand handset, and the gate that decides who may.
 *
 * The behaviours worth protecting: only certified telephone responders
 * get a token; the plaintext token is emitted exactly once; a spent
 * exchange code cannot enrol a second handset; and a revoked handset
 * authenticates as nothing at all.
 */
final class DeviceAuthControllerTest extends ReachTestCase
{
    private InMemoryDeviceRepository $devices;
    private DeviceTokenMinter $minter;
    private DeviceCodeStore $codes;

    protected function setUp(): void
    {
        parent::setUp();

        WpState::$transients = [];
        WpState::$options = [];

        $this->devices = new InMemoryDeviceRepository();
        $this->minter = new DeviceTokenMinter();
        $this->codes = new DeviceCodeStore();
    }

    public function testExchangeEnrolsCertifiedResponderAndReturnsTokenOnce(): void
    {
        $controller = $this->controllerFor($this->certified('responder@example.com'));
        $code = $this->codes->issue($this->identity('responder@example.com'));

        $result = $controller->exchange($this->request([
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
    }

    public function testExchangeAdmitsAMemberWhoIsNotAResponder(): void
    {
        // This used to be the headline refusal — Hand's gate being
        // stricter than the website's. It is not any more: a member with
        // an address and a home group may carry a handset.
        $controller = $this->controllerFor(new MemberStub(
            personalEmail: 'stepper@example.com',
            twelfthStepper: true,
            telephoneResponder: false,
        ));
        $code = $this->codes->issue($this->identity('stepper@example.com'));

        $result = $controller->exchange($this->request(['code' => $code, 'platform' => 'android']));

        $this->assertNotInstanceOf(WP_Error::class, $result);
        $this->assertCount(1, $this->devices->devices);
    }

    public function testExchangeStillRefusesAMemberWithNoHomeGroup(): void
    {
        // The gate is looser, not absent. A half-imported record still
        // cannot put a handset on the rota.
        $controller = $this->controllerFor(new MemberStub(
            personalEmail: 'stub@example.com',
            homeGroup: 0,
        ));
        $code = $this->codes->issue($this->identity('stub@example.com'));

        $result = $controller->exchange($this->request(['code' => $code, 'platform' => 'android']));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_not_eligible', $result->get_error_code());
        $this->assertSame(403, $result->get_error_data()['status'] ?? null);
        $this->assertSame([], $this->devices->devices);
    }

    public function testExchangeAdmitsAnUncertifiedResponder(): void
    {
        // Certification no longer gates the handset. See ResponderGate
        // on what that gave up and why it was decided.
        $controller = $this->controllerFor(new MemberStub(
            personalEmail: 'trainee@example.com',
            twelfthStepper: false,
            telephoneResponder: true,
            responderCertification: ResponderCertification::InTraining,
        ));
        $code = $this->codes->issue($this->identity('trainee@example.com'));

        $result = $controller->exchange($this->request(['code' => $code, 'platform' => 'android']));

        $this->assertNotInstanceOf(WP_Error::class, $result);
        $this->assertCount(1, $this->devices->devices);
    }

    public function testASpentCodeCannotEnrolASecondHandset(): void
    {
        $controller = $this->controllerFor($this->certified('responder@example.com'));
        $code = $this->codes->issue($this->identity('responder@example.com'));

        $controller->exchange($this->request(['code' => $code, 'platform' => 'android']));
        $replay = $controller->exchange($this->request(['code' => $code, 'platform' => 'android']));

        $this->assertInstanceOf(WP_Error::class, $replay);
        $this->assertSame('reach_invalid_code', $replay->get_error_code());
        $this->assertCount(1, $this->devices->devices);
    }

    public function testUnknownPlatformIsRefused(): void
    {
        // The platform decides the delivery path, so guessing would mean
        // enrolling a handset that never receives anything.
        $controller = $this->controllerFor($this->certified('responder@example.com'));
        $code = $this->codes->issue($this->identity('responder@example.com'));

        $result = $controller->exchange($this->request(['code' => $code, 'platform' => 'blackberry']));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_unknown_platform', $result->get_error_code());
    }

    public function testPushProviderWithoutATokenFallsBackToPolling(): void
    {
        $controller = $this->controllerFor($this->certified('responder@example.com'));
        $code = $this->codes->issue($this->identity('responder@example.com'));

        $result = $controller->exchange($this->request([
            'code'          => $code,
            'platform'      => 'windows',
            'push_provider' => '',
        ]));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $device = $this->devices->findById((int) $result->get_data()['device_id']);
        $this->assertNotNull($device);
        $this->assertFalse($device->wantsPush());
    }

    public function testEnrolmentCapRevokesTheLeastRecentlySeenHandset(): void
    {
        // Someone standing there with a new phone must not be locked out
        // by five forgotten ones.
        $controller = $this->controllerFor($this->certified('responder@example.com'));

        for ($i = 0; $i < 6; $i++) {
            $code = $this->codes->issue($this->identity('responder@example.com'));
            $controller->exchange($this->request(['code' => $code, 'platform' => 'android']));
        }

        $live = $this->devices->findByMemberEmail('responder@example.com');
        $this->assertCount(5, $live);
        $this->assertCount(6, $this->devices->devices, 'Revoked rows are kept as history.');
    }

    public function testSessionRequiresALiveToken(): void
    {
        $controller = $this->controllerFor($this->certified('responder@example.com'));
        $token = $this->enrol($controller, 'responder@example.com');

        $ok = $controller->session($this->authed($token));
        $this->assertInstanceOf(WP_REST_Response::class, $ok);
        $this->assertTrue($ok->get_data()['authorised']);

        $anonymous = $controller->session($this->request());
        $this->assertInstanceOf(WP_Error::class, $anonymous);
        $this->assertSame(401, $anonymous->get_error_data()['status'] ?? null);
    }

    public function testRevokedHandsetAuthenticatesAsNothing(): void
    {
        $controller = $this->controllerFor($this->certified('responder@example.com'));
        $token = $this->enrol($controller, 'responder@example.com');

        $controller->signout($this->authed($token));

        $result = $controller->session($this->authed($token));
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(401, $result->get_error_data()['status'] ?? null);
    }

    public function testHandsetOfAMemberWhoLosesTheirHomeGroupIsRevokedOnItsNextCall(): void
    {
        // <b>The per-request re-check survived the gate being loosened,
        // and it is the half worth keeping.</b> It used to catch a lapsed
        // certification; it now catches a record that has stopped being a
        // usable member. Either way the handset stops itself rather than
        // waiting for somebody to remember to revoke it.
        $members = new InMemoryMemberRepository([$this->certified('responder@example.com')]);
        $controller = $this->controllerWithRepository($members);
        $token = $this->enrol($controller, 'responder@example.com');

        $lapsed = new InMemoryMemberRepository([
            new MemberStub(
                personalEmail: 'responder@example.com',
                twelfthStepper: false,
                telephoneResponder: true,
                responderCertification: ResponderCertification::Certified,
                homeGroup: 0,
            ),
        ]);
        $afterLapse = $this->controllerWithRepository($lapsed);

        $result = $afterLapse->session($this->authed($token));

        $this->assertInstanceOf(WP_Error::class, $result);
        $device = $this->devices->devices[0];
        $this->assertTrue($device->isRevoked(), 'An ineligible responder’s handset is cut off, not just turned away.');
    }

    public function testPushTokenCanBeUpdated(): void
    {
        // Firebase rotates registration tokens without warning; a stale
        // one is the usual reason a handset goes quiet.
        $controller = $this->controllerFor($this->certified('responder@example.com'));
        $token = $this->enrol($controller, 'responder@example.com');

        $request = $this->authed($token);
        $request->set_param('push_provider', 'fcm');
        $request->set_param('push_token', 'rotated-token');

        $result = $controller->updatePush($request);

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame('rotated-token', $this->devices->devices[0]->pushToken);
    }

    public function testAHandsetCanReportThatItsLockScreenShowsAlertText(): void
    {
        // Carried on the push call because Hand re-registers its token at
        // every launch anyway, which makes this as fresh as a setting its
        // owner can change at any moment is going to get.
        $controller = $this->controllerFor($this->certified('responder@example.com'));
        $token = $this->enrol($controller, 'responder@example.com');

        $request = $this->authed($token);
        $request->set_param('push_provider', 'fcm');
        $request->set_param('push_token', 'a-token');
        $request->set_param('lock_screen', Device::LOCK_SCREEN_SHOWN);

        $controller->updatePush($request);

        $this->assertTrue($this->devices->devices[0]->showsAlertsOnLockScreen());
    }

    public function testAHandsetThatIsPutRightClearsItsOwnWarning(): void
    {
        // Unlike a key fault, this is a current setting rather than a
        // thing that happened, so the last thing the handset said is the
        // whole answer. A responder who turns sensitive content off should
        // not have to be revoked to stop being flagged.
        $controller = $this->controllerFor($this->certified('responder@example.com'));
        $token = $this->enrol($controller, 'responder@example.com');

        foreach ([Device::LOCK_SCREEN_SHOWN, Device::LOCK_SCREEN_HIDDEN] as $state) {
            $request = $this->authed($token);
            $request->set_param('push_provider', 'fcm');
            $request->set_param('push_token', 'a-token');
            $request->set_param('lock_screen', $state);
            $controller->updatePush($request);
        }

        $this->assertFalse($this->devices->devices[0]->showsAlertsOnLockScreen());
    }

    public function testAHandsetThatSaysNothingLeavesTheLastAnswerAlone(): void
    {
        // An older build does not send the field. It must not be able to
        // erase a warning a newer one raised, so absent means "no news"
        // rather than "all clear".
        $controller = $this->controllerFor($this->certified('responder@example.com'));
        $token = $this->enrol($controller, 'responder@example.com');

        $flagged = $this->authed($token);
        $flagged->set_param('push_provider', 'fcm');
        $flagged->set_param('push_token', 'a-token');
        $flagged->set_param('lock_screen', Device::LOCK_SCREEN_SHOWN);
        $controller->updatePush($flagged);

        $silent = $this->authed($token);
        $silent->set_param('push_provider', 'fcm');
        $silent->set_param('push_token', 'a-token');
        $controller->updatePush($silent);

        $this->assertTrue(
            $this->devices->devices[0]->showsAlertsOnLockScreen(),
            'a build too old to report must not clear what a newer one said',
        );
    }

    public function testAnUnrecognisedLockScreenValueIsNotStored(): void
    {
        // It would show on the admin list as neither a warning nor a
        // reassurance, and nobody would know which it meant.
        $controller = $this->controllerFor($this->certified('responder@example.com'));
        $token = $this->enrol($controller, 'responder@example.com');

        $request = $this->authed($token);
        $request->set_param('push_provider', 'fcm');
        $request->set_param('push_token', 'a-token');
        $request->set_param('lock_screen', 'maybe');

        $controller->updatePush($request);

        $this->assertSame(Device::LOCK_SCREEN_UNKNOWN, $this->devices->devices[0]->lockScreen);
    }

    public function testAFailedWriteIsReportedAsFailureNotAsAToken(): void
    {
        // The amber bug. The device table did not exist, $wpdb->insert()
        // returned false, and nothing checked - so enrolment answered 201
        // with a freshly minted token for a row that was never written.
        // The handset stored it, 401'd on its very next request, and sent
        // its responder back round the sign-in loop with an empty admin
        // device list and nothing saying why.
        //
        // A write that fails must read as a failure.
        $this->devices->failOnCreate = true;

        $controller = $this->controllerFor($this->certified('responder@example.com'));
        $code = $this->codes->issue($this->identity('responder@example.com'));

        $result = $controller->exchange($this->request([
            'code'     => $code,
            'platform' => 'android',
        ]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_enrolment_failed', $result->get_error_code());
        $this->assertSame(500, $result->get_error_data()['status'] ?? null);
        $this->assertSame([], $this->devices->devices, 'Nothing should have been enrolled.');
    }

    public function testStartRefusesARedirectOutsideTheAllowList(): void
    {
        $controller = $this->controllerFor($this->certified('responder@example.com'));

        $result = $controller->start($this->request([
            'provider'     => 'google',
            'redirect_uri' => 'https://evil.example/steal',
        ]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_invalid_redirect', $result->get_error_code());
    }

    // --- helpers ----------------------------------------------------------

    private function certified(string $email): MemberStub
    {
        return new MemberStub(
            personalEmail: $email,
            twelfthStepper: false,
            telephoneResponder: true,
            responderCertification: ResponderCertification::Certified,
        );
    }

    private function identity(string $email): VerifiedIdentity
    {
        return new VerifiedIdentity(email: $email, provider: 'google', sub: 'sub-' . $email);
    }

    private function controllerFor(MemberStub ...$members): DeviceAuthController
    {
        return $this->controllerWithRepository(new InMemoryMemberRepository($members));
    }

    private function controllerWithRepository(InMemoryMemberRepository $members): DeviceAuthController
    {
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
    }

    /** Enrol a handset and return its plaintext token. */
    private function enrol(DeviceAuthController $controller, string $email): string
    {
        $code = $this->codes->issue($this->identity($email));
        $result = $controller->exchange($this->request([
            'code'     => $code,
            'platform' => 'android',
        ]));

        $this->assertInstanceOf(WP_REST_Response::class, $result);

        return (string) $result->get_data()['token'];
    }

    /** @param array<string, mixed> $params */
    private function request(array $params = []): WP_REST_Request
    {
        return new WP_REST_Request($params + [
            'label'         => '',
            'push_provider' => '',
            'push_token'    => '',
        ]);
    }

    private function authed(string $token): WP_REST_Request
    {
        $request = $this->request();
        $request->set_header('authorization', 'Bearer ' . $token);

        return $request;
    }
}
