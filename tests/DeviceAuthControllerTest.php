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

    public function testExchangeRefusesTwelfthStepperWhoIsNotAResponder(): void
    {
        // Hand's gate is stricter than the website's. This member can
        // sign in to Reach and must not get a handset.
        $controller = $this->controllerFor(new MemberStub(
            personalEmail: 'stepper@example.com',
            twelfthStepper: true,
            telephoneResponder: false,
        ));
        $code = $this->codes->issue($this->identity('stepper@example.com'));

        $result = $controller->exchange($this->request(['code' => $code, 'platform' => 'android']));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_not_eligible', $result->get_error_code());
        $this->assertSame(403, $result->get_error_data()['status'] ?? null);
        $this->assertSame([], $this->devices->devices);
    }

    public function testExchangeRefusesUncertifiedResponder(): void
    {
        $controller = $this->controllerFor(new MemberStub(
            personalEmail: 'trainee@example.com',
            twelfthStepper: false,
            telephoneResponder: true,
            responderCertification: ResponderCertification::InTraining,
        ));
        $code = $this->codes->issue($this->identity('trainee@example.com'));

        $result = $controller->exchange($this->request(['code' => $code, 'platform' => 'android']));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_not_eligible', $result->get_error_code());
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

    public function testHandsetOfALapsedResponderIsRevokedOnItsNextCall(): void
    {
        // Enrol while certified, then let the certification lapse. The
        // gate is re-run per request, so the handset stops itself.
        $members = new InMemoryMemberRepository([$this->certified('responder@example.com')]);
        $controller = $this->controllerWithRepository($members);
        $token = $this->enrol($controller, 'responder@example.com');

        $lapsed = new InMemoryMemberRepository([
            new MemberStub(
                personalEmail: 'responder@example.com',
                twelfthStepper: false,
                telephoneResponder: true,
                responderCertification: ResponderCertification::Pending,
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
