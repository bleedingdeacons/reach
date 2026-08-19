<?php

declare(strict_types=1);

namespace Reach\Tests;

use Reach\Alerts\Alert;
use Reach\Alerts\AlertDispatcher;
use Reach\Alerts\AlertRequest;
use Reach\Alerts\Transport\AlertTransport;
use Reach\Devices\Device;
use Reach\Devices\ResponderGate;
use Reach\Tests\Fixtures\InMemoryAlertContactRepository;
use Reach\Tests\Fixtures\InMemoryAlertRepository;
use Reach\Tests\Fixtures\InMemoryDeviceRepository;
use Reach\Tests\Fixtures\MemberStub;
use Unity\Members\ResponderCertification;
use Unity\Testing\Doubles\InMemoryMemberRepository;

/**
 * Who an alert reaches, and what happens when the push fails.
 *
 * Two behaviours matter most here and neither is obvious from the code
 * alone: an alert is stored before any delivery is attempted, so a
 * broken transport delays rather than loses it; and the eligibility gate
 * is re-run at dispatch time, so a responder who has lost their
 * certification stops being a target even though their handset row is
 * still live.
 */
final class AlertDispatcherTest extends ReachTestCase
{
    public function testStoresTheAlertAndPushesToEveryEligibleHandset(): void
    {
        $devices = new InMemoryDeviceRepository();
        $devices->create('h1', 'a@example.com', 1, 'Phone', 'android', Device::PUSH_FCM, 'tok-a', 100);
        $devices->create('h2', 'b@example.com', 2, 'Phone', 'ios', Device::PUSH_FCM, 'tok-b', 100);

        $transport = new RecordingTransport();
        $alerts = new InMemoryAlertRepository();

        $dispatcher = new AlertDispatcher(
            $alerts,
            new InMemoryAlertContactRepository(),
            $devices,
            $this->gateAdmitting('a@example.com', 'b@example.com'),
            [$transport],
        );

        $alert = $dispatcher->dispatch($this->request(), 1_700_000_000);

        $this->assertCount(1, $alerts->alerts);
        $this->assertSame($alert->id, $alerts->alerts[0]->id);
        $this->assertCount(2, $transport->delivered);
    }

    public function testAlertIsStoredEvenWhenEveryPushFails(): void
    {
        // The whole reliability story: the handset polls too, so a
        // transport having a bad afternoon must not lose the alert.
        $devices = new InMemoryDeviceRepository();
        $devices->create('h1', 'a@example.com', 1, 'Phone', 'android', Device::PUSH_FCM, 'tok-a', 100);

        $alerts = new InMemoryAlertRepository();
        $dispatcher = new AlertDispatcher(
            $alerts,
            new InMemoryAlertContactRepository(),
            $devices,
            $this->gateAdmitting('a@example.com'),
            [new RecordingTransport(succeeds: false)],
        );

        $dispatcher->dispatch($this->request(), 1_700_000_000);

        $this->assertCount(1, $alerts->alerts);
    }

    public function testHandsetsOfIneligibleRespondersAreSkipped(): void
    {
        // The device row is live; the responder is not certified. The
        // gate is what decides, not the row.
        $devices = new InMemoryDeviceRepository();
        $devices->create('h1', 'certified@example.com', 1, 'Phone', 'android', Device::PUSH_FCM, 'tok-a', 100);
        $devices->create('h2', 'lapsed@example.com', 2, 'Phone', 'android', Device::PUSH_FCM, 'tok-b', 100);

        $transport = new RecordingTransport();

        $dispatcher = new AlertDispatcher(
            new InMemoryAlertRepository(),
            new InMemoryAlertContactRepository(),
            $devices,
            $this->gateAdmitting('certified@example.com'),
            [$transport],
        );

        $dispatcher->dispatch($this->request(), 1_700_000_000);

        $this->assertCount(1, $transport->delivered);
        $this->assertSame('tok-a', $transport->delivered[0]->pushToken);
    }

    public function testRevokedHandsetsAreNotTargets(): void
    {
        $devices = new InMemoryDeviceRepository();
        $live = $devices->create('h1', 'a@example.com', 1, 'Phone', 'android', Device::PUSH_FCM, 'tok-a', 100);
        $gone = $devices->create('h2', 'a@example.com', 1, 'Old phone', 'android', Device::PUSH_FCM, 'tok-b', 100);
        $devices->revoke($gone->id, 200);

        $transport = new RecordingTransport();

        (new AlertDispatcher(
            new InMemoryAlertRepository(),
            new InMemoryAlertContactRepository(),
            $devices,
            $this->gateAdmitting('a@example.com'),
            [$transport],
        ))->dispatch($this->request(), 1_700_000_000);

        $this->assertCount(1, $transport->delivered);
        $this->assertSame($live->id, $transport->delivered[0]->id);
    }

    public function testTargetedAlertOnlyReachesThatResponder(): void
    {
        $devices = new InMemoryDeviceRepository();
        $devices->create('h1', 'a@example.com', 1, 'Phone', 'android', Device::PUSH_FCM, 'tok-a', 100);
        $devices->create('h2', 'b@example.com', 2, 'Phone', 'android', Device::PUSH_FCM, 'tok-b', 100);

        $transport = new RecordingTransport();

        (new AlertDispatcher(
            new InMemoryAlertRepository(),
            new InMemoryAlertContactRepository(),
            $devices,
            $this->gateAdmitting('a@example.com', 'b@example.com'),
            [$transport],
        ))->dispatch($this->request(['target_email' => 'b@example.com']), 1_700_000_000);

        $this->assertCount(1, $transport->delivered);
        $this->assertSame('tok-b', $transport->delivered[0]->pushToken);
    }

    public function testTargetedAlertToAnIneligibleResponderReachesNobodyButIsStillStored(): void
    {
        $devices = new InMemoryDeviceRepository();
        $devices->create('h1', 'lapsed@example.com', 1, 'Phone', 'android', Device::PUSH_FCM, 'tok-a', 100);

        $alerts = new InMemoryAlertRepository();
        $transport = new RecordingTransport();

        (new AlertDispatcher(
            $alerts,
            new InMemoryAlertContactRepository(),
            $devices,
            $this->gateAdmitting(),
            [$transport],
        ))->dispatch($this->request(['target_email' => 'lapsed@example.com']), 1_700_000_000);

        $this->assertSame([], $transport->delivered);
        $this->assertCount(1, $alerts->alerts, 'The alert is history even when it reached nobody.');
    }

    public function testHandsetsWithoutPushAreLeftToPoll(): void
    {
        // A Windows or macOS handset enrols with no push transport. It
        // is not a failure, it collects its own alerts.
        $devices = new InMemoryDeviceRepository();
        $devices->create('h1', 'a@example.com', 1, 'Desktop', 'windows', Device::PUSH_NONE, '', 100);

        $transport = new RecordingTransport();

        (new AlertDispatcher(
            new InMemoryAlertRepository(),
            new InMemoryAlertContactRepository(),
            $devices,
            $this->gateAdmitting('a@example.com'),
            [$transport],
        ))->dispatch($this->request(), 1_700_000_000);

        $this->assertSame([], $transport->delivered);
    }

    public function testEachDeviceIsOfferedToOnlyOneTransport(): void
    {
        $devices = new InMemoryDeviceRepository();
        $devices->create('h1', 'a@example.com', 1, 'Phone', 'android', Device::PUSH_FCM, 'tok-a', 100);

        $first = new RecordingTransport();
        $second = new RecordingTransport();

        (new AlertDispatcher(
            new InMemoryAlertRepository(),
            new InMemoryAlertContactRepository(),
            $devices,
            $this->gateAdmitting('a@example.com'),
            [$first, $second],
        ))->dispatch($this->request(), 1_700_000_000);

        $this->assertCount(1, $first->delivered);
        $this->assertSame([], $second->delivered, 'The second transport should not double-deliver.');
    }

    public function testADeviceTargetedAlertReachesOnlyThatHandset(): void
    {
        // One responder, two handsets. Addressing by email would ring
        // both, which is exactly the ambiguity the admin test button
        // exists to remove.
        $devices = new InMemoryDeviceRepository();
        $phone  = $devices->create('h1', 'a@example.com', 1, 'Phone', 'android', Device::PUSH_FCM, 'tok-a', 100);
        $devices->create('h2', 'a@example.com', 1, 'Tablet', 'android', Device::PUSH_FCM, 'tok-b', 100);

        $transport = new RecordingTransport();

        (new AlertDispatcher(
            new InMemoryAlertRepository(),
            new InMemoryAlertContactRepository(),
            $devices,
            $this->gateAdmitting('a@example.com'),
            [$transport],
        ))->dispatch($this->request(['target_device_id' => $phone->id]), 1_700_000_000);

        $this->assertCount(1, $transport->delivered);
        $this->assertSame($phone->id, $transport->delivered[0]->id);
    }

    public function testADeviceTargetedAlertIsStillStoredWhenTheHandsetIsGone(): void
    {
        // Storing first is unconditional. The admin needs the row in the
        // Recent alerts table whatever the handset did.
        $alerts = new InMemoryAlertRepository();
        $transport = new RecordingTransport();

        (new AlertDispatcher(
            $alerts,
            new InMemoryAlertContactRepository(),
            new InMemoryDeviceRepository(),
            $this->gateAdmitting('a@example.com'),
            [$transport],
        ))->dispatch($this->request(['target_device_id' => 999]), 1_700_000_000);

        $this->assertCount(1, $alerts->alerts);
        $this->assertSame([], $transport->delivered);
    }

    public function testADeviceTargetedAlertSkipsARevokedHandset(): void
    {
        $devices = new InMemoryDeviceRepository();
        $device = $devices->create('h1', 'a@example.com', 1, 'Phone', 'android', Device::PUSH_FCM, 'tok-a', 100);
        $devices->revoke($device->id, 200);

        $transport = new RecordingTransport();

        (new AlertDispatcher(
            new InMemoryAlertRepository(),
            new InMemoryAlertContactRepository(),
            $devices,
            $this->gateAdmitting('a@example.com'),
            [$transport],
        ))->dispatch($this->request(['target_device_id' => $device->id]), 1_700_000_000);

        $this->assertSame([], $transport->delivered);
    }

    public function testADeviceTargetedAlertStillObeysTheEligibilityGate(): void
    {
        // An admin testing the handset of someone who has stepped down
        // should find it silent — that is the correct answer, not a bug.
        $devices = new InMemoryDeviceRepository();
        $device = $devices->create('h1', 'lapsed@example.com', 1, 'Phone', 'android', Device::PUSH_FCM, 'tok-a', 100);

        $transport = new RecordingTransport();

        (new AlertDispatcher(
            new InMemoryAlertRepository(),
            new InMemoryAlertContactRepository(),
            $devices,
            $this->gateAdmitting('someone-else@example.com'),
            [$transport],
        ))->dispatch($this->request(['target_device_id' => $device->id]), 1_700_000_000);

        $this->assertSame([], $transport->delivered);
    }

    public function testADeviceTargetedAlertIsNotABroadcast(): void
    {
        $devices = new InMemoryDeviceRepository();
        $device = $devices->create('h1', 'a@example.com', 1, 'Phone', 'android', Device::PUSH_FCM, 'tok-a', 100);

        $alerts = new InMemoryAlertRepository();

        (new AlertDispatcher(
            $alerts,
            new InMemoryAlertContactRepository(),
            $devices,
            $this->gateAdmitting('a@example.com'),
            [],
        ))->dispatch($this->request(['target_device_id' => $device->id]), 1_700_000_000);

        // It carries no address, so anything reading isBroadcast() to
        // decide who may see it must not be fooled by the empty one.
        $this->assertFalse($alerts->alerts[0]->isBroadcast());
        $this->assertTrue($alerts->alerts[0]->isDeviceTargeted());
    }

    private function request(array $overrides = []): AlertRequest
    {
        $request = AlertRequest::fromArray($overrides + [
            'kind'   => 'test',
            'source' => 'reach',
            'title'  => 'Something happened',
        ]);

        $this->assertInstanceOf(AlertRequest::class, $request);

        return $request;
    }

    /**
     * A gate admitting exactly the named emails as certified responders.
     */
    private function gateAdmitting(string ...$emails): ResponderGate
    {
        $members = [];
        foreach ($emails as $index => $email) {
            $members[] = new MemberStub(
                personalEmail: $email,
                twelfthStepper: false,
                telephoneResponder: true,
                id: $index + 1,
                responderCertification: ResponderCertification::Certified,
            );
        }

        return new ResponderGate(new InMemoryMemberRepository($members));
    }
}

/**
 * A transport that records what it was asked to deliver.
 *
 * Supports any device claiming a push transport, so the dispatcher's own
 * targeting decisions — not the transport's — are what the assertions
 * above are measuring.
 */
final class RecordingTransport implements AlertTransport
{
    /** @var array<int, Device> */
    public array $delivered = [];

    public function __construct(private readonly bool $succeeds = true)
    {
    }

    public function supports(Device $device): bool
    {
        return $device->wantsPush();
    }

    public function deliver(Alert $alert, Device $device): bool
    {
        $this->delivered[] = $device;

        return $this->succeeds;
    }
}
