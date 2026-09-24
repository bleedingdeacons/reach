<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
use Reach\Alerts\AcknowledgementNotifier;
use Reach\Alerts\Alert;
use Reach\Alerts\AlertApi;
use Reach\Alerts\AlertDispatcher;
use Reach\Alerts\AlertRequest;
use Reach\Alerts\MessageUuid;
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

beforeEach(function () {
    $this->members = [];

    // --- helpers ----------------------------------------------------------
    $this->controller = function (): AlertController {
        $members = new InMemoryMemberRepository($this->members);
        $gate = new ResponderGate($members);
        $dispatcher = new AlertDispatcher($this->alerts, $this->contacts, $this->devices, $gate, []);

        return new AlertController(
            $this->alerts,
            $this->contacts,
            new CurrentDevice($this->devices, $this->minter, $gate),
            new SpyAuditLogger(),
            $this->devices,
            new AcknowledgementNotifier($this->alerts, $dispatcher),
            new InMemoryAlertReplyRepository(),
            new RecipientResolver($this->devices, $members, new InMemoryCommitteeRepository()),
            new AlertApi($dispatcher),
            new RateLimiter(),
        );
    };

    /**
     * Enrol a handset, and give Unity a certified member behind it.
     *
     * The member is not optional: a handset whose responder Unity does
     * not know fails the gate and never authenticates, so there is no
     * such thing here as an acknowledgement from one. `$anonymousName`
     * empty is the reachable version of "no name to show".
     */
    $this->enrol = function (string $email, string $anonymousName = 'Jo B'): string {
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
    };

    $this->deviceIdFor = function (string $email): int {
        foreach ($this->devices->devices as $device) {
            if ($device->memberEmail === $email) {
                return $device->id;
            }
        }

        return 0;
    };

    /**
     * An alert as it would have been written before message_uuid
     * existed: stored directly, bypassing AlertRequest, which mints one.
     */
    $this->raiseLegacy = function (string $title): Alert {
        $request = AlertRequest::fromArray(['kind' => 'test', 'title' => $title]);
        $this->assertInstanceOf(AlertRequest::class, $request);

        $alert = $this->alerts->create($request, time());

        $legacy = new Alert(
            $alert->id,
            $alert->kind,
            $alert->source,
            $alert->priority,
            $alert->title,
            $alert->body,
            $alert->reference,
            $alert->payload,
            $alert->targetEmail,
            $alert->createdAt,
            $alert->expiresAt,
        );

        foreach ($this->alerts->alerts as $index => $stored) {
            if ($stored->id === $alert->id) {
                $this->alerts->alerts[$index] = $legacy;
            }
        }

        return $legacy;
    };

    /** @param array<string, mixed> $args */
    $this->raise = function (array $args): Alert {
        $request = AlertRequest::fromArray($args);
        $this->assertInstanceOf(AlertRequest::class, $request);

        return $this->alerts->create($request, time());
    };

    $this->acknowledge = function (string $token, int $alertId): void {
        ($this->controller)()->acknowledge(($this->authed)($token, ['id' => $alertId]));
    };

    /** @return array<int, array<string, mixed>> */
    $this->pending = function (string $token): array {
        $response = ($this->controller)()->pending(($this->authed)($token));
        $this->assertInstanceOf(WP_REST_Response::class, $response);

        /** @var array<int, array<string, mixed>> $alerts */
        $alerts = $response->get_data()['alerts'];

        return $alerts;
    };

    /** @return array<string, mixed> */
    $this->onlyNoticeFor = function (string $token): array {
        $notices = array_values(array_filter(
            ($this->pending)($token),
            static fn(array $alert): bool => $alert['kind'] === Alert::KIND_ACKNOWLEDGED,
        ));

        $this->assertCount(1, $notices);

        return $notices[0];
    };

    /** @return array<int, Alert> */
    $this->noticeAlerts = function (): array {
        return array_values(array_filter(
            $this->alerts->alerts,
            static fn(Alert $alert): bool => $alert->isAcknowledgementNotice(),
        ));
    };

    $this->noticeAlert = function (): Alert {
        $notices = ($this->noticeAlerts)();
        $this->assertCount(1, $notices);

        return $notices[0];
    };

    /**
     * The device ids a notice would actually be pushed to, resolved the
     * way the dispatcher resolves them.
     *
     * @return array<int, int>
     */
    $this->targetsOf = function (Alert $notice): array {
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
    $this->minter = new DeviceTokenMinter();
});

// --- the uuid ---------------------------------------------------------
test('every alert gets a message uuid', function () {
    $alert = ($this->raise)(['kind' => 'test', 'title' => 'Anything']);

    $this->assertTrue(MessageUuid::isValid($alert->messageUuid));

    // What Reach mints is still version 4, whatever it accepts from
    // a caller. Relaxing the check did not relax the generator.
    $this->assertSame('4', $alert->messageUuid[14]);
});

test('two sends get different uuids', function () {
    $first = ($this->raise)(['kind' => 'test', 'title' => 'One']);
    $second = ($this->raise)(['kind' => 'test', 'title' => 'Two']);

    $this->assertNotSame($first->messageUuid, $second->messageUuid);
});

test('a caller can join several alerts into one message', function () {
    // The case this exists for: one message to a responder who holds
    // a phone and a tablet is two device-targeted rows, and nothing
    // else says they are the same thing somebody sent.
    $uuid = MessageUuid::generate();

    $first = ($this->raise)([
        'kind' => 'test', 'title' => 'Both handsets',
        'message_uuid' => $uuid, 'target_device_id' => 1,
    ]);
    $second = ($this->raise)([
        'kind' => 'test', 'title' => 'Both handsets',
        'message_uuid' => $uuid, 'target_device_id' => 2,
    ]);

    $this->assertSame($uuid, $first->messageUuid);
    $this->assertSame($uuid, $second->messageUuid);
});

test('a caller s own uuid is kept whatever version it is', function (string $uuid) {
    // The value is an opaque grouping key and nothing reads meaning
    // out of it, so a caller minting version 1 or version 7 ids is
    // not making a mistake. Refusing those would replace them — and
    // a replacement is per row, so it would silently split the very
    // message the caller was joining.
    $alert = ($this->raise)([
        'kind' => 'test', 'title' => 'Joined', 'message_uuid' => $uuid,
    ]);

    $this->assertSame($uuid, $alert->messageUuid);
})->with('callerUuids');

/** @return array<string, array{string}> */
dataset('callerUuids', function (): array {
    return [
        'version 1' => ['f81d4fae-7dec-11d0-a765-00a0c91e6bf6'],
        'version 4' => ['3f2a1b4c-5d6e-4f70-8a9b-0c1d2e3f4a5b'],
        'version 7' => ['0192f3c4-5d6e-7f80-8a9b-0c1d2e3f4a5b'],
    ];
});

test('a malformed uuid is replaced rather than refused', function (string $uuid) {
    // Still refused: a value that is not a uuid at all. That is a
    // mistake, and a fresh id is the right answer to it.
    $alert = ($this->raise)([
        'kind' => 'test', 'title' => 'Still sent', 'message_uuid' => $uuid,
    ]);

    $this->assertNotSame($uuid, $alert->messageUuid);
    $this->assertTrue(MessageUuid::isValid($alert->messageUuid));
})->with('unusableUuids');

/** @return array<string, array{string}> */
dataset('unusableUuids', function (): array {
    return [
        'not a uuid'        => ['not-a-uuid'],
        'bare hex'          => ['3f2a1b4c5d6e4f708a9b0c1d2e3f4a5b'],
        'truncated'         => ['3f2a1b4c-5d6e-4f70-8a9b'],
        'nil uuid'          => ['00000000-0000-0000-0000-000000000000'],
        'wrong variant'     => ['3f2a1b4c-5d6e-4f70-0a9b-0c1d2e3f4a5b'],
    ];
});

test('a malformed uuid is still a replacement not a refusal', function () {
    // A send must not fail over an identifier that exists to group
    // rows for display. Losing the grouping is the smaller harm when
    // the other option is a handset that never rang.
    $request = AlertRequest::fromArray([
        'kind' => 'test', 'title' => 'Still sent', 'message_uuid' => 'not-a-uuid',
    ]);

    $this->assertInstanceOf(AlertRequest::class, $request);
});

test('the uuid reaches the handset on the poll', function () {
    $token = ($this->enrol)('jo@example.com');
    $alert = ($this->raise)(['kind' => 'test', 'title' => 'Everybody']);

    $data = ($this->pending)($token);

    $this->assertSame($alert->messageUuid, $data[0]['message_uuid']);
});

// --- an answered message is over ---------------------------------------
test('an answered message is no longer served to anybody', function () {
    $first = ($this->enrol)('one@example.com');
    $second = ($this->enrol)('two@example.com');
    $alert = ($this->raise)(['kind' => 'test', 'title' => 'Callback wanted']);

    ($this->acknowledge)($first, $alert->id);

    $ids = array_column(($this->pending)($second), 'id');
    $this->assertNotContains($alert->id, $ids);
});

test('answering on one handset clears the other copy of the same message', function () {
    // The admin-message case: one message, two device-targeted rows,
    // one responder holding both. Answering on the phone takes it off
    // the tablet, which is the point — the alternative is the same
    // person dismissing the same message twice.
    $phone = ($this->enrol)('jo@example.com');
    $tablet = ($this->enrol)('jo@example.com');
    $uuid = MessageUuid::generate();

    $first = ($this->raise)([
        'kind' => 'test', 'title' => 'Shift swap',
        'message_uuid' => $uuid, 'target_device_id' => 1,
    ]);
    $second = ($this->raise)([
        'kind' => 'test', 'title' => 'Shift swap',
        'message_uuid' => $uuid, 'target_device_id' => 2,
    ]);

    ($this->acknowledge)($phone, $first->id);

    $ids = array_column(($this->pending)($tablet), 'id');
    $this->assertNotContains($second->id, $ids);
});

test('a notice is not silenced by another handset closing it', function () {
    // The first exemption, and it matters: a notice is addressed to
    // everybody and read by each of them separately. Suppressing it
    // the way an alert is suppressed would mean the fastest handset
    // to press Close decided nobody else got to read who answered.
    $first = ($this->enrol)('one@example.com');
    $second = ($this->enrol)('two@example.com');
    $third = ($this->enrol)('three@example.com');
    $alert = ($this->raise)(['kind' => 'test', 'title' => 'Callback wanted']);

    ($this->acknowledge)($first, $alert->id);
    $notice = ($this->noticeAlert)();

    // The second handset reads the notice and closes it.
    ($this->acknowledge)($second, $notice->id);

    // The third has not seen it yet, and still must.
    $ids = array_column(($this->pending)($third), 'id');
    $this->assertContains($notice->id, $ids);
});

test('an alert older than the uuid column keeps its old behaviour', function () {
    // The second exemption. Every row written before message_uuid
    // existed carries the empty string, so matching on it would let
    // any one of them silence all the others — turning a schema
    // upgrade into a rota that stops ringing.
    $first = ($this->enrol)('one@example.com');
    $second = ($this->enrol)('two@example.com');

    $legacy = ($this->raiseLegacy)('Written before the column existed');
    $other  = ($this->raiseLegacy)('A different message entirely');

    ($this->acknowledge)($first, $legacy->id);

    $ids = array_column(($this->pending)($second), 'id');
    $this->assertContains($legacy->id, $ids);
    $this->assertContains($other->id, $ids);
});

// --- who the notice reaches ------------------------------------------
test('a broadcast acknowledgement is announced to the rota', function () {
    $first = ($this->enrol)('one@example.com');
    $second = ($this->enrol)('two@example.com');
    $alert = ($this->raise)(['kind' => 'test', 'title' => 'Callback wanted']);

    ($this->acknowledge)($first, $alert->id);

    $notice = ($this->onlyNoticeFor)($second);
    $this->assertSame(Alert::KIND_ACKNOWLEDGED, $notice['kind']);
    $this->assertSame('Jo B acknowledged', $notice['title']);

    // The original's own title, so the notice says which message it
    // is about. No new exposure: this handset was already sent it.
    $this->assertSame('Callback wanted', $notice['body']);
});

test('the acknowledging handset is not told about itself', function () {
    // A responder who presses Acknowledge and is immediately pushed a
    // notification about having pressed Acknowledge would reasonably
    // conclude the app is broken.
    $first = ($this->enrol)('one@example.com');
    ($this->enrol)('two@example.com');
    $alert = ($this->raise)(['kind' => 'test', 'title' => 'Callback wanted']);

    ($this->acknowledge)($first, $alert->id);

    $kinds = array_column(($this->pending)($first), 'kind');
    $this->assertNotContains(Alert::KIND_ACKNOWLEDGED, $kinds);
});

test('the excluded handset is not a push target either', function () {
    // The poll is only half of it. An exclusion honoured on one route
    // is an alert that arrives by the other.
    $first = ($this->enrol)('one@example.com');
    $second = ($this->enrol)('two@example.com');
    $alert = ($this->raise)(['kind' => 'test', 'title' => 'Callback wanted']);

    ($this->acknowledge)($first, $alert->id);

    $notice = ($this->noticeAlert)();
    $targets = ($this->targetsOf)($notice);

    $this->assertNotContains(($this->deviceIdFor)('one@example.com'), $targets);
    $this->assertSame([($this->deviceIdFor)('two@example.com')], $targets);
    $this->assertNotSame('', $second);
});

test('a responder with two handsets is told on the other one', function () {
    // One message split across two handsets is still one message.
    // Acknowledging on the phone tells the tablet, which is the whole
    // reason the two rows carry the same uuid.
    $phone = ($this->enrol)('jo@example.com');
    ($this->enrol)('jo@example.com');
    $uuid = MessageUuid::generate();

    $alert = ($this->raise)([
        'kind' => 'test', 'title' => 'Shift swap',
        'message_uuid' => $uuid, 'target_device_id' => 1,
    ]);
    ($this->raise)([
        'kind' => 'test', 'title' => 'Shift swap',
        'message_uuid' => $uuid, 'target_device_id' => 2,
    ]);

    ($this->acknowledge)($phone, $alert->id);

    $notice = ($this->noticeAlert)();
    $this->assertSame(2, $notice->targetDeviceId);
});

test('the notice carries the message it is about', function () {
    $first = ($this->enrol)('one@example.com');
    ($this->enrol)('two@example.com');
    $alert = ($this->raise)(['kind' => 'test', 'title' => 'Callback wanted']);

    ($this->acknowledge)($first, $alert->id);

    $notice = ($this->noticeAlert)();

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
});

test('the notice is never urgent', function () {
    // Urgency escalates the delivery path so it breaks through a
    // Focus mode. Nothing about "somebody else has this" earns that.
    $first = ($this->enrol)('one@example.com');
    ($this->enrol)('two@example.com');
    $alert = ($this->raise)([
        'kind' => 'test', 'title' => 'Callback wanted', 'priority' => Alert::PRIORITY_URGENT,
    ]);

    ($this->acknowledge)($first, $alert->id);

    $this->assertSame(Alert::PRIORITY_NORMAL, ($this->noticeAlert)()->priority);
});

test('a notice is not announced for a notice', function () {
    // Otherwise every acknowledgement of a notice breeds the next.
    $first = ($this->enrol)('one@example.com');
    $second = ($this->enrol)('two@example.com');
    $alert = ($this->raise)(['kind' => 'test', 'title' => 'Callback wanted']);

    ($this->acknowledge)($first, $alert->id);
    $notice = ($this->noticeAlert)();
    ($this->acknowledge)($second, $notice->id);

    $this->assertCount(1, ($this->noticeAlerts)());
});

test('an informational message is never announced', function () {
    // Nobody was taking it on, so there is no first answer to report.
    // Saying one responder "acknowledged" it would read as "somebody
    // has dealt with this", which is exactly what did not happen —
    // they closed their own copy and nobody else's.
    $first = ($this->enrol)('one@example.com');
    ($this->enrol)('two@example.com');
    $alert = ($this->raise)([
        'kind' => 'shift_reminder',
        'title' => 'The office is shut on Monday',
        'response' => Alert::RESPONSE_NONE,
    ]);

    ($this->acknowledge)($first, $alert->id);

    $this->assertSame([], ($this->noticeAlerts)());
});

test('an informational message stays on everybody elses handset', function () {
    // The other half of the same rule, and the one that matters to a
    // responder: one person closing a reminder must not take it off
    // twenty-nine other screens.
    $first = ($this->enrol)('one@example.com');
    $second = ($this->enrol)('two@example.com');
    $alert = ($this->raise)([
        'kind' => 'shift_reminder',
        'title' => 'The office is shut on Monday',
        'response' => Alert::RESPONSE_NONE,
    ]);

    ($this->acknowledge)($first, $alert->id);

    $ids = array_column(($this->pending)($second), 'id');
    $this->assertContains($alert->id, $ids);

    // And it is gone from the handset that actually closed it.
    $this->assertNotContains($alert->id, array_column(($this->pending)($first), 'id'));
});

test('a first to respond message clears off everybody elses handset', function () {
    // The contrast, stated on the same fixtures: this is the whole
    // point of first-to-respond, and it is what stops thirty people
    // dismissing a callback one of them has already taken.
    $first = ($this->enrol)('one@example.com');
    $second = ($this->enrol)('two@example.com');
    $alert = ($this->raise)([
        'kind' => 'call_request',
        'title' => 'Callback wanted',
        'response' => Alert::RESPONSE_FIRST,
    ]);

    ($this->acknowledge)($first, $alert->id);

    $this->assertNotContains($alert->id, array_column(($this->pending)($second), 'id'));
});

test('the notice is blue and nobodys to take on', function () {
    // It is not an alert and must never alarm: blue so no handset
    // sirens for it, informational so each reads and closes its own
    // copy — and so closing it cannot take the news off the others.
    $first = ($this->enrol)('one@example.com');
    ($this->enrol)('two@example.com');
    $alert = ($this->raise)(['kind' => 'test', 'title' => 'Callback wanted']);

    ($this->acknowledge)($first, $alert->id);

    $notice = ($this->noticeAlert)();
    $this->assertSame(Alert::LEVEL_BLUE, $notice->level);
    $this->assertTrue($notice->isInformational());
    $this->assertFalse($notice->isUrgent());
});

test('the notice is blue even when the alert was red', function () {
    // Red escalates the delivery path so it breaks through a Focus
    // mode, and nothing about "somebody else has this" is worth that.
    $first = ($this->enrol)('one@example.com');
    ($this->enrol)('two@example.com');
    $alert = ($this->raise)([
        'kind' => 'call_request',
        'title' => 'Callback wanted',
        'level' => Alert::LEVEL_RED,
    ]);

    ($this->acknowledge)($first, $alert->id);

    $this->assertSame(Alert::LEVEL_BLUE, ($this->noticeAlert)()->level);
});

test('only the first answer is announced', function () {
    // The second responder to press a button is not picking the job
    // up, they are clearing a card about the first one who did.
    // Without this guard each of those raises a round of its own, and
    // a rota of thirty turns one callback into a correspondence.
    $first = ($this->enrol)('one@example.com');
    $second = ($this->enrol)('two@example.com');
    ($this->enrol)('three@example.com');
    $alert = ($this->raise)(['kind' => 'test', 'title' => 'Callback wanted']);

    ($this->acknowledge)($first, $alert->id);
    ($this->acknowledge)($second, $alert->id);

    $this->assertCount(1, ($this->noticeAlerts)());
});

test('answers to the other half of a message count too', function () {
    // One message split across two handsets is one message, so the
    // tablet answering after the phone did is not fresh news either.
    $phone = ($this->enrol)('jo@example.com');
    $tablet = ($this->enrol)('jo@example.com');
    $uuid = MessageUuid::generate();

    $first = ($this->raise)([
        'kind' => 'test', 'title' => 'Shift swap',
        'message_uuid' => $uuid, 'target_device_id' => 1,
    ]);
    $second = ($this->raise)([
        'kind' => 'test', 'title' => 'Shift swap',
        'message_uuid' => $uuid, 'target_device_id' => 2,
    ]);

    ($this->acknowledge)($phone, $first->id);
    ($this->acknowledge)($tablet, $second->id);

    $this->assertCount(1, ($this->noticeAlerts)());
});

test('a repeated acknowledgement announces once', function () {
    // A handset retrying after a dropped response must not tell the
    // rota twice that the same person picked the same thing up.
    $first = ($this->enrol)('one@example.com');
    ($this->enrol)('two@example.com');
    $alert = ($this->raise)(['kind' => 'test', 'title' => 'Callback wanted']);

    ($this->acknowledge)($first, $alert->id);
    ($this->acknowledge)($first, $alert->id);

    $this->assertCount(1, ($this->noticeAlerts)());
});

test('an acknowledgement nobody is left to hear is not announced', function () {
    // One handset, one message: there is nobody to tell.
    $only = ($this->enrol)('one@example.com');
    $alert = ($this->raise)([
        'kind' => 'test', 'title' => 'Just you', 'target_device_id' => 1,
    ]);

    ($this->acknowledge)($only, $alert->id);

    $this->assertSame([], ($this->noticeAlerts)());
});

test('a responder with no name on file is named generically', function () {
    // The usual admin fallback is to show the address, on the grounds
    // that an address is itself the diagnostic. A notice goes to a
    // lock screen instead of to an administrator, so here the
    // fallback has to be the anonymous one.
    $first = ($this->enrol)('nameless@example.com', anonymousName: '');
    ($this->enrol)('two@example.com');
    $alert = ($this->raise)(['kind' => 'test', 'title' => 'Callback wanted']);

    ($this->acknowledge)($first, $alert->id);

    $notice = ($this->noticeAlert)();
    $this->assertSame(
        AcknowledgementNotifier::UNKNOWN_RESPONDER . ' acknowledged',
        $notice->title,
    );
    $this->assertStringNotContainsString(
        'nameless@example.com',
        $notice->title . $notice->body . implode('', $notice->payload),
    );
});

test('an excluded handset cannot acknowledge the notice', function () {
    // The exclusion overrides every address: a broadcast notice is
    // addressed to everybody, and "everybody" is exactly the shape
    // the one handset it is kept from would otherwise match.
    $first = ($this->enrol)('one@example.com');
    ($this->enrol)('two@example.com');
    $alert = ($this->raise)(['kind' => 'test', 'title' => 'Callback wanted']);

    ($this->acknowledge)($first, $alert->id);
    $notice = ($this->noticeAlert)();

    $response = ($this->controller)()->acknowledge(
        ($this->authed)($first, ['id' => $notice->id]),
    );

    $this->assertInstanceOf(\WP_Error::class, $response);
    $this->assertSame('reach_unknown_alert', $response->get_error_code());
});
