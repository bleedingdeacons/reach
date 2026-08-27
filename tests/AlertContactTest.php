<?php

declare(strict_types=1);

namespace Reach\Tests;

use Reach\Alerts\AcknowledgementNotifier;
use Reach\Alerts\AlertDispatcher;
use Reach\Alerts\AlertRequest;
use Reach\Auth\DeviceTokenMinter;
use Reach\Core\Cipher;
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
 * Contact details attached to an alert.
 *
 * This is the only personal data in the alerting feature, and the rules
 * around it are the point of the design rather than an implementation
 * detail — so they are asserted rather than trusted to comments:
 *
 *  - the contact never appears in a poll response, only a flag saying
 *    one exists;
 *  - reading it requires an authenticated handset the alert could have
 *    been sent to;
 *  - every read writes a Scrutiny audit entry;
 *  - it is encrypted at rest, and a rotated salt makes it unreadable.
 */
final class AlertContactTest extends ReachTestCase
{
    private InMemoryDeviceRepository $devices;
    private InMemoryAlertRepository $alerts;
    private InMemoryAlertContactRepository $contacts;
    private DeviceTokenMinter $minter;
    private SpyAuditLogger $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->devices = new InMemoryDeviceRepository();
        $this->alerts = new InMemoryAlertRepository();
        $this->contacts = new InMemoryAlertContactRepository();
        $this->minter = new DeviceTokenMinter();
        $this->audit = new SpyAuditLogger();
    }

    public function testDispatcherStoresTheContactAwayFromTheAlert(): void
    {
        $dispatcher = new AlertDispatcher(
            $this->alerts,
            $this->contacts,
            $this->devices,
            $this->gate(),
            [],
        );

        $alert = $dispatcher->dispatch($this->request([
            'contact' => 'Sam, 07700 900123',
        ]), 1_700_000_000);

        // In the contacts store...
        $this->assertSame('Sam, 07700 900123', $this->contacts->find($alert->id));

        // ...and nowhere in the alert itself, which is what travels
        // through FCM and onto a lock screen.
        $encoded = (string) json_encode($alert->toArray());
        $this->assertStringNotContainsString('900123', $encoded);
        $this->assertStringNotContainsString('Sam', $encoded);
    }

    public function testPollExposesOnlyThatAContactExists(): void
    {
        $token = $this->enrol('responder@example.com');
        $alert = $this->alerts->create($this->request(), time());
        $this->contacts->save($alert->id, 'Sam, 07700 900123', time());

        // The in-memory repository does not model the join, so assert the
        // contract directly: the serialised alert carries a flag, and the
        // number is not in it.
        $encoded = (string) json_encode($alert->toArray());
        $this->assertArrayHasKey('has_contact', $alert->toArray());
        $this->assertStringNotContainsString('900123', $encoded);

        $result = $this->controller()->pending($this->authed($token));
        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertStringNotContainsString(
            '900123',
            (string) json_encode($result->get_data()),
            'A poll response must never carry the contact itself.',
        );
    }

    public function testContactRequiresAuthentication(): void
    {
        $alert = $this->alerts->create($this->request(), time());
        $this->contacts->save($alert->id, 'Sam, 07700 900123', time());

        $result = $this->controller()->contact(new WP_REST_Request(['id' => $alert->id]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(401, $result->get_error_data()['status'] ?? null);
    }

    public function testContactIsReturnedToAnEntitledHandsetAndAudited(): void
    {
        $token = $this->enrol('responder@example.com');
        $alert = $this->alerts->create($this->request(['reference' => 'SHIFT-1']), time());
        $this->contacts->save($alert->id, 'Sam, 07700 900123', time());

        $result = $this->controller()->contact($this->authed($token, ['id' => $alert->id]));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame('Sam, 07700 900123', $result->get_data()['contact']);

        // The audit entry is what keeps Reach's promise that a regulator
        // can answer "who saw this, and when".
        $this->assertNotEmpty(
            $this->audit->entries,
            'Reading an alert contact must write a Scrutiny audit entry.',
        );
    }

    public function testAnotherRespondersTargetedContactIsNotReadable(): void
    {
        $token = $this->enrol('responder@example.com');
        $alert = $this->alerts->create(
            $this->request(['target_email' => 'someone.else@example.com']),
            time(),
        );
        $this->contacts->save($alert->id, 'Sam, 07700 900123', time());

        $result = $this->controller()->contact($this->authed($token, ['id' => $alert->id]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(404, $result->get_error_data()['status'] ?? null);
        $this->assertSame([], $this->audit->entries, 'A refused read must not be audited as a read.');
    }

    public function testAlertWithNoContactReturnsAnEmptyStringAndIsNotAudited(): void
    {
        $token = $this->enrol('responder@example.com');
        $alert = $this->alerts->create($this->request(), time());

        $result = $this->controller()->contact($this->authed($token, ['id' => $alert->id]));

        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame('', $result->get_data()['contact']);
        $this->assertSame([], $this->audit->entries, 'There was no personal data to record a view of.');
    }

    // --- encryption -------------------------------------------------------

    public function testCipherRoundTrips(): void
    {
        $cipher = new Cipher('reach-alert-contact');
        $encrypted = $cipher->encrypt('Sam, 07700 900123');

        $this->assertNotSame('', $encrypted);
        $this->assertStringNotContainsString('900123', $encrypted);
        $this->assertSame('Sam, 07700 900123', $cipher->decrypt($encrypted));
    }

    public function testCipherIsDomainSeparated(): void
    {
        // A value encrypted for one purpose must not decrypt as another,
        // so a bug that crossed the two fails closed.
        $encrypted = (new Cipher('reach-alert-contact'))->encrypt('Sam, 07700 900123');

        $this->assertSame('', (new Cipher('reach-secrets'))->decrypt($encrypted));
    }

    public function testRotatingTheSaltMakesStoredContactsUnreadable(): void
    {
        $cipher = new Cipher('reach-alert-contact');
        $encrypted = $cipher->encrypt('Sam, 07700 900123');

        $this->salts['auth'] = 'rotated-' . str_repeat('q', 48);

        $this->assertSame('', $cipher->decrypt($encrypted));
    }

    public function testTamperedCiphertextIsRefused(): void
    {
        // GCM authenticates as well as encrypts, so a modified payload is
        // detected rather than decrypting to rubbish.
        $cipher = new Cipher('reach-alert-contact');
        $encrypted = $cipher->encrypt('Sam, 07700 900123');

        $raw = base64_decode($encrypted, true);
        $this->assertIsString($raw);
        $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === 'A' ? 'B' : 'A';

        $this->assertSame('', $cipher->decrypt(base64_encode($raw)));
    }

    // --- helpers ----------------------------------------------------------

    private function controller(): AlertController
    {
        $gate = $this->gate();

        return new AlertController(
            $this->alerts,
            $this->contacts,
            new CurrentDevice($this->devices, $this->minter, $gate),
            $this->audit,
            $this->devices,
            new AcknowledgementNotifier(
                $this->alerts,
                new AlertDispatcher($this->alerts, $this->contacts, $this->devices, $gate, []),
            ),
        );
    }

    private function gate(): ResponderGate
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

        return new ResponderGate(new InMemoryMemberRepository($members));
    }

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
    private function request(array $args = []): AlertRequest
    {
        $request = AlertRequest::fromArray($args + [
            'kind'  => 'test',
            'title' => 'Something happened',
        ]);

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
