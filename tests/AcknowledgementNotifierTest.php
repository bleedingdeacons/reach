<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
use Reach\Alerts\AcknowledgementNotifier;
use Reach\Alerts\Alert;
use Reach\Alerts\AlertDispatcher;
use Reach\Alerts\AlertRequest;
use Reach\Alerts\MessageUuid;
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
use WP_REST_Request;
use WP_REST_Response;

/**
 * The second message: telling the rest of the rota who picked one up.
 *
 * Everything here turns on two rules that are easy to state and easy to
 * break. A notice goes to everybody the original went to <b>except</b>
 * the handset that answered — by push and by poll, because an exclusion
 * honoured on one route is an alert that arrives by the other. And a
 * notice never begets a notice, or one answered helpline call becomes an
 * unbounded correspondence.
 *
 * The messages a send raises are tied together by a uuid rather than by
 * id, because a send can raise several rows — see {@see MessageUuid} —
 * and the acknowledged row names only its own handset.
 */
final class AcknowledgementNotifierTest extends ReachTestCase
{
    private InMemoryDeviceRepository $devices;
    private InMemoryAlertRepository $alerts;
    private InMemoryAlertContactRepository $contacts;
    private DeviceTokenMinter $minter;

    /** @var array<int, MemberStub> */
    private array $members = [];

    protected function setUp(): void
    {
        parent::setUp();

        WpState::$restRoutes = [];

        $this->devices = new InMemoryDeviceRepository();
        $this->alerts = new InMemoryAlertRepository();
        $this->contacts = new InMemoryAlertContactRepository();
        $this->minter = new DeviceTokenMinter();
    }

    // --- the uuid ---------------------------------------------------------

    public function testEveryAlertGetsAMessageUuid(): void
    {
        $alert = $this->raise(['kind' => 'test', 'title' => 'Anything']);

        $this->assertTrue(MessageUuid::isValid($alert->messageUuid));
    }

    public function testTwoSendsGetDifferentUuids(): void
    {
        $first = $this->raise(['kind' => 'test', 'title' => 'One']);
        $second = $this->raise(['kind' => 'test', 'title' => 'Two']);

        $this->assertNotSame($first->messageUuid, $second->messageUuid);
    }

    public function testACallerCanJoinSeveralAlertsIntoOneMessage(): void
    {
        // The case this exists for: one message to a responder who holds
        // a phone and a tablet is two device-targeted rows, and nothing
        // else says they are the same thing somebody sent.
        $uuid = MessageUuid::generate();

        $first = $this->raise([
            'kind' => 'test', 'title' => 'Both handsets',
            'message_uuid' => $uuid, 'target_device_id' => 1,
        ]);
        $second = $this->raise([
            'kind' => 'test', 'title' => 'Both handsets',
            'message_uuid' => $uuid, 'target_device_id' => 2,
        ]);

        $this->assertSame($uuid, $first->messageUuid);
        $this->assertSame($uuid, $second->messageUuid);
    }

    public function testAMalformedUuidIsReplacedRatherThanRefused(): void
    {
        // A send must not fail over an identifier that exists to group
        // rows for display. Losing the grouping is the smaller harm when
        // the other option is a handset that never rang.
        $alert = $this->raise([
            'kind' => 'test', 'title' => 'Still sent', 'message_uuid' => 'not-a-uuid',
        ]);

        $this->assertTrue(MessageUuid::isValid($alert->messageUuid));
    }

    public function testTheUuidReachesTheHandsetOnThePoll(): void
    {
        $token = $this->enrol('jo@example.com');
        $alert = $this->raise(['kind' => 'test', 'title' => 'Everybody']);

        $data = $this->pending($token);

        $this->assertSame($alert->messageUuid, $data[0]['message_uuid']);
    }

    // --- who the notice reaches ------------------------------------------

    public function testABroadcastAcknowledgementIsAnnouncedToTheRota(): void
    {
        $first = $this->enrol('one@example.com');
        $second = $this->enrol('two@example.com');
        $alert = $this->raise(['kind' => 'test', 'title' => 'Callback wanted']);

        $this->acknowledge($first, $alert->id);

        $notice = $this->onlyNoticeFor($second);
        $this->assertSame(Alert::KIND_ACKNOWLEDGED, $notice['kind']);
        $this->assertSame('Jo B acknowledged', $notice['title']);

        // The original's own title, so the notice says which message it
        // is about. No new exposure: this handset was already sent it.
        $this->assertSame('Callback wanted', $notice['body']);
    }

    public function testTheAcknowledgingHandsetIsNotToldAboutItself(): void
    {
        // A responder who presses Acknowledge and is immediately pushed a
        // notification about having pressed Acknowledge would reasonably
        // conclude the app is broken.
        $first = $this->enrol('one@example.com');
        $this->enrol('two@example.com');
        $alert = $this->raise(['kind' => 'test', 'title' => 'Callback wanted']);

        $this->acknowledge($first, $alert->id);

        $kinds = array_column($this->pending($first), 'kind');
        $this->assertNotContains(Alert::KIND_ACKNOWLEDGED, $kinds);
    }

    public function testTheExcludedHandsetIsNotAPushTargetEither(): void
    {
        // The poll is only half of it. An exclusion honoured on one route
        // is an alert that arrives by the other.
        $first = $this->enrol('one@example.com');
        $second = $this->enrol('two@example.com');
        $alert = $this->raise(['kind' => 'test', 'title' => 'Callback wanted']);

        $this->acknowledge($first, $alert->id);

        $notice = $this->noticeAlert();
        $targets = $this->targetsOf($notice);

        $this->assertNotContains($this->deviceIdFor('one@example.com'), $targets);
        $this->assertSame([$this->deviceIdFor('two@example.com')], $targets);
        $this->assertNotSame('', $second);
    }

    public function testAResponderWithTwoHandsetsIsToldOnTheOtherOne(): void
    {
        // One message split across two handsets is still one message.
        // Acknowledging on the phone tells the tablet, which is the whole
        // reason the two rows carry the same uuid.
        $phone = $this->enrol('jo@example.com');
        $this->enrol('jo@example.com');
        $uuid = MessageUuid::generate();

        $alert = $this->raise([
            'kind' => 'test', 'title' => 'Shift swap',
            'message_uuid' => $uuid, 'target_device_id' => 1,
        ]);
        $this->raise([
            'kind' => 'test', 'title' => 'Shift swap',
            'message_uuid' => $uuid, 'target_device_id' => 2,
        ]);

        $this->acknowledge($phone, $alert->id);

        $notice = $this->noticeAlert();
        $this->assertSame(2, $notice->targetDeviceId);
    }

    public function testTheNoticeCarriesTheMessageItIsAbout(): void
    {
        $first = $this->enrol('one@example.com');
        $this->enrol('two@example.com');
        $alert = $this->raise(['kind' => 'test', 'title' => 'Callback wanted']);

        $this->acknowledge($first, $alert->id);

        $notice = $this->noticeAlert();

        // Its own uuid, not the original's: a notice that reused the
        // uuid would be indistinguishable from the thing it reports on.
        $this->assertNotSame($alert->messageUuid, $notice->messageUuid);

        $this->assertSame(
            $alert->messageUuid,
            $notice->payload[AcknowledgementNotifier::PAYLOAD_MESSAGE_UUID],
        );
        $this->assertSame(
            (string) $alert->id,
            $notice->payload[AcknowledgementNotifier::PAYLOAD_ALERT_ID],
        );
        $this->assertSame('Jo B', $notice->payload[AcknowledgementNotifier::PAYLOAD_RESPONDER]);
    }

    public function testTheNoticeIsNeverUrgent(): void
    {
        // Urgency escalates the delivery path so it breaks through a
        // Focus mode. Nothing about "somebody else has this" earns that.
        $first = $this->enrol('one@example.com');
        $this->enrol('two@example.com');
        $alert = $this->raise([
            'kind' => 'test', 'title' => 'Callback wanted', 'priority' => Alert::PRIORITY_URGENT,
        ]);

        $this->acknowledge($first, $alert->id);

        $this->assertSame(Alert::PRIORITY_NORMAL, $this->noticeAlert()->priority);
    }

    public function testANoticeIsNotAnnouncedForANotice(): void
    {
        // Otherwise every acknowledgement of a notice breeds the next.
        $first = $this->enrol('one@example.com');
        $second = $this->enrol('two@example.com');
        $alert = $this->raise(['kind' => 'test', 'title' => 'Callback wanted']);

        $this->acknowledge($first, $alert->id);
        $notice = $this->noticeAlert();
        $this->acknowledge($second, $notice->id);

        $this->assertCount(1, $this->noticeAlerts());
    }

    public function testOnlyTheFirstAnswerIsAnnounced(): void
    {
        // The second responder to press a button is not picking the job
        // up, they are clearing a card about the first one who did.
        // Without this guard each of those raises a round of its own, and
        // a rota of thirty turns one callback into a correspondence.
        $first = $this->enrol('one@example.com');
        $second = $this->enrol('two@example.com');
        $this->enrol('three@example.com');
        $alert = $this->raise(['kind' => 'test', 'title' => 'Callback wanted']);

        $this->acknowledge($first, $alert->id);
        $this->acknowledge($second, $alert->id);

        $this->assertCount(1, $this->noticeAlerts());
    }

    public function testAnswersToTheOtherHalfOfAMessageCountToo(): void
    {
        // One message split across two handsets is one message, so the
        // tablet answering after the phone did is not fresh news either.
        $phone = $this->enrol('jo@example.com');
        $tablet = $this->enrol('jo@example.com');
        $uuid = MessageUuid::generate();

        $first = $this->raise([
            'kind' => 'test', 'title' => 'Shift swap',
            'message_uuid' => $uuid, 'target_device_id' => 1,
        ]);
        $second = $this->raise([
            'kind' => 'test', 'title' => 'Shift swap',
            'message_uuid' => $uuid, 'target_device_id' => 2,
        ]);

        $this->acknowledge($phone, $first->id);
        $this->acknowledge($tablet, $second->id);

        $this->assertCount(1, $this->noticeAlerts());
    }

    public function testARepeatedAcknowledgementAnnouncesOnce(): void
    {
        // A handset retrying after a dropped response must not tell the
        // rota twice that the same person picked the same thing up.
        $first = $this->enrol('one@example.com');
        $this->enrol('two@example.com');
        $alert = $this->raise(['kind' => 'test', 'title' => 'Callback wanted']);

        $this->acknowledge($first, $alert->id);
        $this->acknowledge($first, $alert->id);

        $this->assertCount(1, $this->noticeAlerts());
    }

    public function testAnAcknowledgementNobodyIsLeftToHearIsNotAnnounced(): void
    {
        // One handset, one message: there is nobody to tell.
        $only = $this->enrol('one@example.com');
        $alert = $this->raise([
            'kind' => 'test', 'title' => 'Just you', 'target_device_id' => 1,
        ]);

        $this->acknowledge($only, $alert->id);

        $this->assertSame([], $this->noticeAlerts());
    }

    public function testAResponderWithNoNameOnFileIsNamedGenerically(): void
    {
        // The usual admin fallback is to show the address, on the grounds
        // that an address is itself the diagnostic. A notice goes to a
        // lock screen instead of to an administrator, so here the
        // fallback has to be the anonymous one.
        $first = $this->enrol('nameless@example.com', anonymousName: '');
        $this->enrol('two@example.com');
        $alert = $this->raise(['kind' => 'test', 'title' => 'Callback wanted']);

        $this->acknowledge($first, $alert->id);

        $notice = $this->noticeAlert();
        $this->assertSame(
            AcknowledgementNotifier::UNKNOWN_RESPONDER . ' acknowledged',
            $notice->title,
        );
        $this->assertStringNotContainsString(
            'nameless@example.com',
            $notice->title . $notice->body . implode('', $notice->payload),
        );
    }

    public function testAnExcludedHandsetCannotAcknowledgeTheNotice(): void
    {
        // The exclusion overrides every address: a broadcast notice is
        // addressed to everybody, and "everybody" is exactly the shape
        // the one handset it is kept from would otherwise match.
        $first = $this->enrol('one@example.com');
        $this->enrol('two@example.com');
        $alert = $this->raise(['kind' => 'test', 'title' => 'Callback wanted']);

        $this->acknowledge($first, $alert->id);
        $notice = $this->noticeAlert();

        $response = $this->controller()->acknowledge(
            $this->authed($first, ['id' => $notice->id]),
        );

        $this->assertInstanceOf(\WP_Error::class, $response);
        $this->assertSame('reach_unknown_alert', $response->get_error_code());
    }

    // --- helpers ----------------------------------------------------------

    private function controller(): AlertController
    {
        $gate = new ResponderGate(new InMemoryMemberRepository($this->members));

        return new AlertController(
            $this->alerts,
            $this->contacts,
            new CurrentDevice($this->devices, $this->minter, $gate),
            new SpyAuditLogger(),
            $this->devices,
            new AcknowledgementNotifier(
                $this->alerts,
                new AlertDispatcher($this->alerts, $this->contacts, $this->devices, $gate, []),
            ),
        );
    }

    /**
     * Enrol a handset, and give Unity a certified member behind it.
     *
     * The member is not optional: a handset whose responder Unity does
     * not know fails the gate and never authenticates, so there is no
     * such thing here as an acknowledgement from one. `$anonymousName`
     * empty is the reachable version of "no name to show".
     */
    private function enrol(string $email, string $anonymousName = 'Jo B'): string
    {
        $token = $this->minter->mint();
        $id = count($this->devices->devices) + 1;

        $this->devices->create(
            $this->minter->hash($token),
            $email,
            $id,
            'Phone',
            'android',
            Device::PUSH_FCM,
            'fcm-' . $id,
            time(),
        );

        $this->members[] = new MemberStub(
            personalEmail: $email,
            twelfthStepper: false,
            telephoneResponder: true,
            id: $id,
            responderCertification: ResponderCertification::Certified,
            anonymousName: $anonymousName,
        );

        return $token;
    }

    private function deviceIdFor(string $email): int
    {
        foreach ($this->devices->devices as $device) {
            if ($device->memberEmail === $email) {
                return $device->id;
            }
        }

        return 0;
    }

    /** @param array<string, mixed> $args */
    private function raise(array $args): Alert
    {
        $request = AlertRequest::fromArray($args);
        $this->assertInstanceOf(AlertRequest::class, $request);

        return $this->alerts->create($request, time());
    }

    private function acknowledge(string $token, int $alertId): void
    {
        $this->controller()->acknowledge($this->authed($token, ['id' => $alertId]));
    }

    /** @return array<int, array<string, mixed>> */
    private function pending(string $token): array
    {
        $response = $this->controller()->pending($this->authed($token));
        $this->assertInstanceOf(WP_REST_Response::class, $response);

        /** @var array<int, array<string, mixed>> $alerts */
        $alerts = $response->get_data()['alerts'];

        return $alerts;
    }

    /** @return array<string, mixed> */
    private function onlyNoticeFor(string $token): array
    {
        $notices = array_values(array_filter(
            $this->pending($token),
            static fn(array $alert): bool => $alert['kind'] === Alert::KIND_ACKNOWLEDGED,
        ));

        $this->assertCount(1, $notices);

        return $notices[0];
    }

    /** @return array<int, Alert> */
    private function noticeAlerts(): array
    {
        return array_values(array_filter(
            $this->alerts->alerts,
            static fn(Alert $alert): bool => $alert->isAcknowledgementNotice(),
        ));
    }

    private function noticeAlert(): Alert
    {
        $notices = $this->noticeAlerts();
        $this->assertCount(1, $notices);

        return $notices[0];
    }

    /**
     * The device ids a notice would actually be pushed to, resolved the
     * way the dispatcher resolves them.
     *
     * @return array<int, int>
     */
    private function targetsOf(Alert $notice): array
    {
        $ids = [];
        foreach ($this->devices->findAllLive() as $device) {
            if ($notice->excludes($device->id)) {
                continue;
            }

            if ($notice->isDeviceTargeted() && $notice->targetDeviceId !== $device->id) {
                continue;
            }

            if (
                !$notice->isDeviceTargeted()
                && !$notice->isBroadcast()
                && $notice->targetEmail !== $device->memberEmail
            ) {
                continue;
            }

            $ids[] = $device->id;
        }

        return $ids;
    }

    /** @param array<string, mixed> $params */
    private function authed(string $token, array $params = []): WP_REST_Request
    {
        $request = new WP_REST_Request($params);
        $request->set_header('authorization', 'Bearer ' . $token);

        return $request;
    }
}
