<?php

declare(strict_types=1);

namespace Reach\Tests;

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
use Reach\Rest\DirectoryController;
use Reach\Tests\Fixtures\CommitteeStub;
use Reach\Tests\Fixtures\InMemoryAlertContactRepository;
use Reach\Tests\Fixtures\InMemoryAlertReplyRepository;
use Reach\Tests\Fixtures\InMemoryAlertRepository;
use Reach\Tests\Fixtures\InMemoryDeviceRepository;
use Reach\Tests\Fixtures\MemberStub;
use Scrutiny\Testing\Doubles\SpyAuditLogger;
use Unity\Members\PreferredContact;
use Unity\Members\ResponderCertification;
use Unity\Testing\Doubles\GroupStub;
use Unity\Testing\Doubles\InMemoryCommitteeRepository;
use Unity\Testing\Doubles\InMemoryGroupRepository;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The handset as a sender: raising a message, replying to one, and
 * putting an acknowledged job back out.
 *
 * <b>Any enrolled handset may send, and there is no capability to
 * assert.</b> A device exists only because {@see ResponderGate} passed,
 * so "authenticated" already means "certified responder". What is worth
 * asserting is everything downstream of that: that a recipient must be
 * named rather than assumed, that a reply survives somebody else
 * answering first, and that a resend is a genuinely new message rather
 * than one born suppressed.
 */

function nameFor(string $email): string
{
    return match (strstr($email, '@', true)) {
        'jo'  => 'Jo B.',
        'sam' => 'Sam T.',
        'pat' => 'Pat R.',
        default => 'Someone',
    };
}

beforeEach(function () {
    $this->tokens = [];

    $this->committeeSpecs = [];

    $this->members = [];

    // --- helpers ----------------------------------------------------------

    /**
     * Put Pat R. in the directory as member 2, carrying the numbers the
     * calling test wants on them. Obviously fake ones, from Ofcom's
     * reserved drama ranges, so a fixture that escapes into a log is
     * not somebody's phone.
     */
    $this->givePatNumbers = function (string $mobile, string $landline): void {
        $this->members[] = new MemberStub(
            personalEmail: 'pat@example.test',
            twelfthStepper: false,
            telephoneResponder: true,
            id: 2,
            anonymousName: 'Pat R.',
            responderCertification: ResponderCertification::Certified,
            mobileNumber: $mobile,
            landlineNumber: $landline,
            preferredContact: $landline !== '' ? PreferredContact::Landline : PreferredContact::Mobile,
        );
    };

    $this->controller = function (): AlertController {
        $members = new InMemoryMemberRepository($this->members);
        $gate = new ResponderGate($members);
        $dispatcher = new AlertDispatcher($this->alerts, $this->contacts, $this->devices, $gate, []);

        return new AlertController(
            $this->alerts,
            $this->contacts,
            new CurrentDevice($this->devices, $this->minter, $gate),
            $this->audit,
            $this->devices,
            new AcknowledgementNotifier($this->alerts, $dispatcher),
            $this->replies,
            new RecipientResolver($this->devices, $members, $this->committees),
            new AlertApi($dispatcher),
            new RateLimiter(),
        );
    };

    $this->directory = function (): DirectoryController {
        $members = new InMemoryMemberRepository($this->members);
        $gate = new ResponderGate($members);

        return new DirectoryController(
            new CurrentDevice($this->devices, $this->minter, $gate),
            $members,
            new InMemoryGroupRepository([new GroupStub(88, 'Tuesday Bristol')]),
            new RecipientResolver($this->devices, $members, $this->committees),
            $this->audit,
            new RateLimiter(),
        );
    };

    /**
     * Enrol a handset and give Unity the certified member behind it.
     *
     * The member is not optional: a handset whose responder Unity does
     * not know fails the gate and never authenticates.
     */
    $this->enrol = function (string $email, int $memberId): string {
        $token = $this->minter->mint();
        $this->devices->create(
            $this->minter->hash($token),
            $email,
            $memberId,
            'Phone',
            'android',
            Device::PUSH_FCM,
            'fcm-' . $email . '-' . count($this->devices->devices),
            time(),
        );

        // The plaintext is not recoverable from the stored hash, so the
        // first handset's token is kept for tests that authenticate as an
        // address they enrolled earlier.
        $this->tokens[$email] ??= $token;

        foreach ($this->members as $member) {
            if ($member->getPersonalEmail() === $email) {
                return $token;
            }
        }

        $this->members[] = new MemberStub(
            personalEmail: $email,
            twelfthStepper: false,
            telephoneResponder: true,
            id: $memberId,
            anonymousName: nameFor($email),
            homeGroup: 88,
            responderCertification: ResponderCertification::Certified,
        );

        return $token;
    };

    /** The token of the first handset enrolled for an address. */
    $this->tokenFor = function (string $email): string {
        $this->assertArrayHasKey($email, $this->tokens);

        return $this->tokens[$email];
    };

    /** @param array<string, mixed> $args */
    $this->raise = function (array $args): Alert {
        $request = AlertRequest::fromArray($args);
        $this->assertInstanceOf(AlertRequest::class, $request);

        return $this->alerts->create($request, time());
    };

    $this->lastAlert = function (): Alert {
        $alerts = $this->alerts->alerts;
        $this->assertNotEmpty($alerts);

        return $alerts[count($alerts) - 1];
    };

    /**
     * Add a committee to the tree the resolver reads.
     *
     * The double takes its whole tree in the constructor, so this
     * accumulates and rebuilds. Ids are positional and only have to be
     * internally consistent — the real repository is addressed by slug
     * precisely because ids differ between environments.
     *
     * @param array<int, int> $memberIds
     */
    $this->addCommittee = function (
        string $slug,
        string $name,
        string $parentSlug = '',
        array $memberIds = []
    ): void {
        $this->committeeSpecs[$slug] = [$name, $parentSlug, $memberIds];

        $ids = [];
        $position = 1;
        foreach (array_keys($this->committeeSpecs) as $known) {
            $ids[$known] = $position++;
        }

        $stubs = [];
        $members = [];
        foreach ($this->committeeSpecs as $known => [$knownName, $knownParent, $knownMembers]) {
            $stubs[] = new CommitteeStub(
                $known,
                $knownName,
                id: $ids[$known],
                parentId: $knownParent !== '' ? ($ids[$knownParent] ?? 0) : 0,
            );
            $members[$known] = $knownMembers;
        }

        $this->committees = new InMemoryCommitteeRepository($stubs, $members);
    };

    /** @param array<string, mixed> $params */
    $this->authed = function (string $token, array $params = []): WP_REST_Request {
        $request = new WP_REST_Request($params);
        $request->set_header('authorization', 'Bearer ' . $token);

        return $request;
    };

    $this->devices = new InMemoryDeviceRepository();
    $this->alerts = new InMemoryAlertRepository();
    $this->contacts = new InMemoryAlertContactRepository();
    $this->replies = new InMemoryAlertReplyRepository();
    $this->committees = new InMemoryCommitteeRepository();
    $this->minter = new DeviceTokenMinter();
    $this->audit = new SpyAuditLogger();
});

// --- raising a message ------------------------------------------------
test('a handset can raise a message to one member', function () {
    $sender = ($this->enrol)('jo@example.test', 1);
    ($this->enrol)('sam@example.test', 2);

    $response = ($this->controller)()->raise(($this->authed)($sender, [
        'subject'   => 'Can you cover tonight?',
        'body'      => 'The 22:00 slot is open.',
        'member_id' => 2,
    ]));

    $this->assertInstanceOf(WP_REST_Response::class, $response);
    $this->assertSame(201, $response->get_status());
    $this->assertSame(1, $response->get_data()['handsets']);

    $raised = $this->alerts->alerts[0];
    $this->assertSame('Can you cover tonight?', $raised->title);
    // The sender is recorded, which is the only thing that gives a
    // reply somewhere to go.
    $this->assertSame('jo@example.test', $raised->senderEmail);
});

test('a missing recipient is refused rather than broadcast', function () {
    // The failure that would matter: any responder can send, so an
    // absent recipient widening to "everybody" would let one slip put
    // the whole rota's phones on.
    $sender = ($this->enrol)('jo@example.test', 1);

    $response = ($this->controller)()->raise(($this->authed)($sender, [
        'subject' => 'Oops',
    ]));

    $this->assertInstanceOf(WP_Error::class, $response);
    $this->assertSame('reach_alert_no_recipient', $response->get_error_code());
    $this->assertSame([], $this->alerts->alerts);
});

test('naming both a member and a committee is refused', function () {
    $sender = ($this->enrol)('jo@example.test', 1);
    ($this->addCommittee)('telephones', 'Telephones', '', [2]);

    $response = ($this->controller)()->raise(($this->authed)($sender, [
        'subject'   => 'Which is it',
        'member_id' => 2,
        'committee' => 'telephones',
    ]));

    $this->assertInstanceOf(WP_Error::class, $response);
    $this->assertSame('reach_alert_no_recipient', $response->get_error_code());
});

test('a message to a committee reaches the committees under it', function () {
    $sender = ($this->enrol)('jo@example.test', 1);
    ($this->enrol)('sam@example.test', 2);
    ($this->enrol)('pat@example.test', 3);

    ($this->addCommittee)('public-information', 'Public Information', '', [2]);
    ($this->addCommittee)('health', 'Health', 'public-information', [3]);

    $response = ($this->controller)()->raise(($this->authed)($sender, [
        'subject'   => 'Committee notice',
        'committee' => 'public-information',
    ]));

    $this->assertInstanceOf(WP_REST_Response::class, $response);
    $this->assertSame(2, $response->get_data()['handsets']);

    // One message, however many handsets it took to deliver.
    $uuids = array_unique(array_map(static fn($a) => $a->messageUuid, $this->alerts->alerts));
    $this->assertCount(1, $uuids);
});

test('the senders own handsets are not rung', function () {
    // Messaging a committee you sit on should not ring your own
    // pocket — and the sender's other handset goes too, which is why
    // this is matched on the address rather than the device id.
    $sender = ($this->enrol)('jo@example.test', 1);
    ($this->enrol)('jo@example.test', 1);
    ($this->enrol)('sam@example.test', 2);

    ($this->addCommittee)('telephones', 'Telephones', '', [1, 2]);

    $response = ($this->controller)()->raise(($this->authed)($sender, [
        'subject'   => 'Everybody but me',
        'committee' => 'telephones',
    ]));

    $this->assertInstanceOf(WP_REST_Response::class, $response);
    $this->assertSame(1, $response->get_data()['handsets']);
});

test('a committee nobody can be reached on is reported not silently succeeded', function () {
    $sender = ($this->enrol)('jo@example.test', 1);
    ($this->addCommittee)('archives', 'Archives', '', [9]);

    $response = ($this->controller)()->raise(($this->authed)($sender, [
        'subject'   => 'Anybody there',
        'committee' => 'archives',
    ]));

    $this->assertInstanceOf(WP_Error::class, $response);
    $this->assertSame('reach_no_handsets', $response->get_error_code());
});

test('raising a message is audited', function () {
    $sender = ($this->enrol)('jo@example.test', 1);
    ($this->enrol)('sam@example.test', 2);

    ($this->controller)()->raise(($this->authed)($sender, [
        'subject'   => 'Noted',
        'member_id' => 2,
    ]));

    $this->assertNotEmpty($this->audit->entries);
});

test('an unauthenticated handset cannot raise anything', function () {
    $response = ($this->controller)()->raise(new WP_REST_Request(['subject' => 'Hello']));

    $this->assertInstanceOf(WP_Error::class, $response);
    $this->assertSame('reach_device_not_authenticated', $response->get_error_code());
});

// --- replying ---------------------------------------------------------
test('a responder can reply to a message', function () {
    $sender = ($this->enrol)('jo@example.test', 1);
    $replier = ($this->enrol)('sam@example.test', 2);

    $alert = ($this->raise)([
        'kind'         => 'admin_message',
        'title'        => 'Can you cover tonight?',
        'target_email' => 'sam@example.test',
        'sender_email' => 'jo@example.test',
    ]);

    $response = ($this->controller)()->reply(($this->authed)($replier, [
        'id'   => $alert->id,
        'body' => 'Yes, I can take it.',
    ]));

    $this->assertInstanceOf(WP_REST_Response::class, $response);
    $this->assertSame(201, $response->get_status());
    $this->assertCount(1, $this->replies->replies);
    $this->assertSame('Yes, I can take it.', $this->replies->replies[0]->body);

    // And it goes back to the sender as an alert of its own, which is
    // how a reply reaches the handset that raised the original.
    $reply = ($this->lastAlert)();
    $this->assertSame(Alert::KIND_REPLY, $reply->kind);
    $this->assertSame('jo@example.test', $reply->targetEmail);
    // Quiet, by the two fields rather than by its kind.
    $this->assertSame(Alert::LEVEL_BLUE, $reply->level);
    $this->assertSame(Alert::RESPONSE_NONE, $reply->response);
    $this->assertSame((string) $alert->id, $reply->payload['reply_to_alert_id']);
});

test('a responder can still reply after somebody else acknowledged', function () {
    // The requirement this whole path exists for. Reach stops serving
    // an answered message and Hand deletes the card, so the reply is
    // offered from history — but the server must accept it, and
    // maySee() asks about targeting rather than acknowledgement.
    $first = ($this->enrol)('jo@example.test', 1);
    $second = ($this->enrol)('sam@example.test', 2);

    $alert = ($this->raise)(['kind' => 'test', 'title' => 'Callback wanted']);

    $controller = ($this->controller)();
    $controller->acknowledge(($this->authed)($first, ['id' => $alert->id]));

    $response = $controller->reply(($this->authed)($second, [
        'id'   => $alert->id,
        'body' => 'I could have taken that one.',
    ]));

    $this->assertInstanceOf(WP_REST_Response::class, $response);
    $this->assertSame(201, $response->get_status());
    $this->assertCount(1, $this->replies->replies);
});

test('an empty reply is refused', function () {
    $token = ($this->enrol)('jo@example.test', 1);
    $alert = ($this->raise)(['kind' => 'test', 'title' => 'Callback wanted']);

    $response = ($this->controller)()->reply(($this->authed)($token, [
        'id'   => $alert->id,
        'body' => '   ',
    ]));

    $this->assertInstanceOf(WP_Error::class, $response);
    $this->assertSame('reach_reply_empty', $response->get_error_code());
});

test('a reply to a notice is refused', function () {
    // Otherwise one answered call becomes an unbounded exchange
    // between two handsets.
    $token = ($this->enrol)('jo@example.test', 1);
    $notice = ($this->raise)([
        'kind'  => Alert::KIND_ACKNOWLEDGED,
        'title' => 'Sam answered',
    ]);

    $response = ($this->controller)()->reply(($this->authed)($token, [
        'id'   => $notice->id,
        'body' => 'Thanks',
    ]));

    $this->assertInstanceOf(WP_Error::class, $response);
    $this->assertSame('reach_unknown_alert', $response->get_error_code());
});

test('a reply to an alert with no sender is stored and dispatched nowhere', function () {
    // An alert raised by a plugin has nobody behind it; one raised
    // from wp-admin has an administrator, who has no handset. Neither
    // is a failure.
    $token = ($this->enrol)('jo@example.test', 1);
    $alert = ($this->raise)(['kind' => 'shift_uncovered', 'title' => 'Night shift open']);

    $response = ($this->controller)()->reply(($this->authed)($token, [
        'id'   => $alert->id,
        'body' => 'I will take it.',
    ]));

    $this->assertInstanceOf(WP_REST_Response::class, $response);
    $this->assertCount(1, $this->replies->replies);
    // Stored, but nothing new raised: the original is still the only
    // alert in the table.
    $this->assertCount(1, $this->alerts->alerts);
});

test('another responders targeted message cannot be replied to', function () {
    $token = ($this->enrol)('jo@example.test', 1);
    $alert = ($this->raise)([
        'kind'         => 'test',
        'title'        => 'Not yours',
        'target_email' => 'someone.else@example.test',
    ]);

    $response = ($this->controller)()->reply(($this->authed)($token, [
        'id'   => $alert->id,
        'body' => 'Hello',
    ]));

    $this->assertInstanceOf(WP_Error::class, $response);
    $this->assertSame('reach_unknown_alert', $response->get_error_code());
    $this->assertSame([], $this->replies->replies);
});

// --- resending --------------------------------------------------------
test('the acknowledging responder can put the job back out', function () {
    $first = ($this->enrol)('jo@example.test', 1);
    ($this->enrol)('sam@example.test', 2);

    $alert = ($this->raise)(['kind' => 'callback', 'title' => 'Callback wanted']);
    $this->contacts->save($alert->id, 'Sam, 07700 900123', time());

    $controller = ($this->controller)();
    $controller->acknowledge(($this->authed)($first, ['id' => $alert->id]));

    $before = count($this->alerts->alerts);
    $response = $controller->resend(($this->authed)($first, ['id' => $alert->id]));

    $this->assertInstanceOf(WP_REST_Response::class, $response);
    $this->assertSame(201, $response->get_status());
    $this->assertGreaterThan($before, count($this->alerts->alerts));

    $resent = ($this->lastAlert)();
    $this->assertSame('Callback wanted', $resent->title);
    // A NEW message. Reusing the original uuid would raise something
    // born suppressed, because the original already carries an
    // acknowledgement.
    $this->assertNotSame($alert->messageUuid, $resent->messageUuid);
    // The audience the original had.
    $this->assertSame($alert->targetEmail, $resent->targetEmail);
    // And the contact travels, so whoever picks it up can ring back.
    $this->assertSame('Sam, 07700 900123', $this->contacts->find($resent->id));
    $this->assertSame((string) $alert->id, $resent->payload['resent_from_alert_id']);
});

test('resending is refused to somebody who did not acknowledge', function () {
    $first = ($this->enrol)('jo@example.test', 1);
    $second = ($this->enrol)('sam@example.test', 2);

    $alert = ($this->raise)(['kind' => 'callback', 'title' => 'Callback wanted']);

    $controller = ($this->controller)();
    $controller->acknowledge(($this->authed)($first, ['id' => $alert->id]));

    $response = $controller->resend(($this->authed)($second, ['id' => $alert->id]));

    $this->assertInstanceOf(WP_Error::class, $response);
    $this->assertSame('reach_unknown_alert', $response->get_error_code());
});

test('an unacknowledged alert cannot be resent', function () {
    $token = ($this->enrol)('jo@example.test', 1);
    $alert = ($this->raise)(['kind' => 'callback', 'title' => 'Callback wanted']);

    $response = ($this->controller)()->resend(($this->authed)($token, ['id' => $alert->id]));

    $this->assertInstanceOf(WP_Error::class, $response);
    $this->assertSame('reach_unknown_alert', $response->get_error_code());
});

test('an informational message cannot be resent', function () {
    // Nobody was taking it on, so there is no acknowledger and
    // nothing to hand back.
    $token = ($this->enrol)('jo@example.test', 1);
    $alert = ($this->raise)([
        'kind'     => 'notice',
        'title'    => 'Office shut Monday',
        'response' => Alert::RESPONSE_NONE,
    ]);

    $controller = ($this->controller)();
    $controller->acknowledge(($this->authed)($token, ['id' => $alert->id]));

    $response = $controller->resend(($this->authed)($token, ['id' => $alert->id]));

    $this->assertInstanceOf(WP_Error::class, $response);
    $this->assertSame('reach_unknown_alert', $response->get_error_code());
});

test('acknowledging on one handset lets the other resend', function () {
    // One responder, two handsets, one message. Answering on the
    // phone must let them resend from the tablet — which matching on
    // the alert row rather than the message would refuse.
    $phone = ($this->enrol)('jo@example.test', 1);
    $tablet = ($this->enrol)('jo@example.test', 1);

    $uuid = '11111111-2222-4333-8444-555555555555';
    $onPhone = ($this->raise)([
        'kind'             => 'callback',
        'title'            => 'Callback wanted',
        'message_uuid'     => $uuid,
        'target_device_id' => $this->devices->devices[0]->id,
    ]);
    $onTablet = ($this->raise)([
        'kind'             => 'callback',
        'title'            => 'Callback wanted',
        'message_uuid'     => $uuid,
        'target_device_id' => $this->devices->devices[1]->id,
    ]);

    $controller = ($this->controller)();
    $controller->acknowledge(($this->authed)($phone, ['id' => $onPhone->id]));

    // The tablet resends its *own* copy — it may not touch the
    // phone's row, and maySee() says so. What has to work is that the
    // acknowledgement on the other row still counts, because the two
    // are one message.
    $response = $controller->resend(($this->authed)($tablet, ['id' => $onTablet->id]));

    $this->assertInstanceOf(WP_REST_Response::class, $response);
    $this->assertSame(201, $response->get_status());
});

test('passing a job on is audited as a handover', function () {
    // Reads of a contact were already answerable. Handing it to a new
    // audience is a wider disclosure that happens without anybody
    // reading anything, so it is recorded in its own right.
    $token = ($this->enrol)('jo@example.test', 1);
    $alert = ($this->raise)(['kind' => 'callback', 'title' => 'Callback wanted']);
    $this->contacts->save($alert->id, 'Sam, 07700 900123', time());

    $controller = ($this->controller)();
    $controller->acknowledge(($this->authed)($token, ['id' => $alert->id]));
    $this->audit->entries = [];

    $controller->resend(($this->authed)($token, ['id' => $alert->id]));

    $details = array_map(
        static fn(array $entry) => (string) ($entry['detail'] ?? ''),
        $this->audit->entries,
    );

    $this->assertNotEmpty($this->audit->entries);
    $this->assertStringContainsString('Alert passed on', implode(' ', $details));
    $this->assertStringContainsString('contact:copied', implode(' ', $details));
});

// --- the directory ----------------------------------------------------
test('the member directory carries names and home groups but no addresses', function () {
    ($this->enrol)('jo@example.test', 1);
    $this->members[] = new MemberStub(
        personalEmail: 'sam@example.test',
        twelfthStepper: false,
        telephoneResponder: true,
        id: 2,
        anonymousName: 'Sam T.',
        homeGroup: 88,
        responderCertification: ResponderCertification::Certified,
    );

    $response = ($this->directory)()->members(($this->authed)(($this->tokenFor)('jo@example.test')));

    $this->assertInstanceOf(WP_REST_Response::class, $response);

    $encoded = (string) json_encode($response->get_data());
    $this->assertStringContainsString('Sam T.', $encoded);
    $this->assertStringContainsString('Tuesday Bristol', $encoded);
    // The whole reason a directory on every handset is acceptable.
    $this->assertStringNotContainsString('@example.test', $encoded);
});

test('the directory marks who can actually be reached', function () {
    ($this->enrol)('jo@example.test', 1);
    $this->members[] = new MemberStub(
        personalEmail: 'nohandset@example.test',
        twelfthStepper: false,
        telephoneResponder: true,
        id: 2,
        anonymousName: 'Pat R.',
        responderCertification: ResponderCertification::Certified,
    );

    $response = ($this->directory)()->members(($this->authed)(($this->tokenFor)('jo@example.test')));

    $this->assertInstanceOf(WP_REST_Response::class, $response);

    $byName = [];
    foreach ($response->get_data()['members'] as $member) {
        $byName[$member['anonymous_name']] = $member['reachable'];
    }

    $this->assertTrue($byName['Jo B.'] ?? false);
    $this->assertFalse($byName['Pat R.'] ?? true);
});

test('the committee tree is keyed by slug and carries its depth', function () {
    ($this->enrol)('jo@example.test', 1);
    ($this->addCommittee)('public-information', 'Public Information', '', [1]);
    ($this->addCommittee)('health', 'Health', 'public-information', [1]);

    $response = ($this->directory)()->committees(($this->authed)(($this->tokenFor)('jo@example.test')));

    $this->assertInstanceOf(WP_REST_Response::class, $response);

    $rows = $response->get_data()['committees'];
    $this->assertSame('public-information', $rows[0]['slug']);
    $this->assertSame(0, $rows[0]['depth']);
    $this->assertSame('health', $rows[1]['slug']);
    $this->assertSame(1, $rows[1]['depth']);
});

test('the directory refuses an unauthenticated handset', function () {
    $response = ($this->directory)()->members(new WP_REST_Request());

    $this->assertInstanceOf(WP_Error::class, $response);
    $this->assertSame('reach_device_not_authenticated', $response->get_error_code());
});

test('a handset can fetch one members numbers', function () {
    ($this->enrol)('jo@example.test', 1);
    ($this->givePatNumbers)(mobile: '07700 900123', landline: '0117 496 0123');

    $response = ($this->directory)()->contact(
        ($this->authed)(($this->tokenFor)('jo@example.test'), ['id' => 2]),
    );

    $this->assertInstanceOf(WP_REST_Response::class, $response);
    $this->assertSame(
        [
            'id'                => 2,
            'mobile_number'     => '07700 900123',
            'landline_number'   => '0117 496 0123',
            'preferred_contact' => 'Landline',
        ],
        $response->get_data(),
    );
});

test('a member with nothing on file is an empty answer not a missing one', function () {
    // They exist, the handset asked a fair question, and the answer
    // is that there is nothing to dial. A 404 here would read as
    // "no such member" and send somebody looking for a record that
    // is present and simply blank.
    ($this->enrol)('jo@example.test', 1);
    ($this->givePatNumbers)(mobile: '', landline: '');

    $response = ($this->directory)()->contact(
        ($this->authed)(($this->tokenFor)('jo@example.test'), ['id' => 2]),
    );

    $this->assertInstanceOf(WP_REST_Response::class, $response);
    $this->assertSame('', $response->get_data()['mobile_number']);
    $this->assertSame('', $response->get_data()['landline_number']);
});

test('the directory list still carries no numbers', function () {
    // The whole point of putting the numbers behind their own route.
    // If they ever leak into the list payload, one handset can copy
    // the intergroup's directory in a couple of requests — which is
    // the thing this design exists to prevent, so it is asserted
    // rather than left to a reviewer to notice.
    ($this->enrol)('jo@example.test', 1);
    ($this->givePatNumbers)(mobile: '07700 900123', landline: '0117 496 0123');

    $response = ($this->directory)()->members(($this->authed)(($this->tokenFor)('jo@example.test')));

    $this->assertInstanceOf(WP_REST_Response::class, $response);

    $encoded = (string) json_encode($response->get_data());
    $this->assertStringNotContainsString('07700 900123', $encoded);
    $this->assertStringNotContainsString('0117 496 0123', $encoded);
    $this->assertStringNotContainsString('mobile_number', $encoded);
    $this->assertStringNotContainsString('landline_number', $encoded);
});

test('fetching numbers is audited against the member and names the asker', function () {
    ($this->enrol)('jo@example.test', 1);
    ($this->givePatNumbers)(mobile: '07700 900123', landline: '0117 496 0123');
    $this->audit->entries = [];

    ($this->directory)()->contact(
        ($this->authed)(($this->tokenFor)('jo@example.test'), ['id' => 2]),
    );

    $fields = [];
    foreach ($this->audit->entries as $entry) {
        // Subject is the member whose numbers were handed over, not
        // the responder who asked — "who saw this number" only makes
        // sense as a question about the person it belongs to.
        $this->assertSame(2, $entry['entityId']);
        $this->assertSame('caller:Jo B.#1', $entry['detail']);
        $fields[] = $entry['fieldName'];
    }

    $this->assertSame(['mobile_number', 'landline_number'], $fields);
});

test('the landline is audited only for the members who have one', function () {
    // Logging it for everyone would double a lookup's audit volume
    // while recording an exposure that never happened.
    ($this->enrol)('jo@example.test', 1);
    ($this->givePatNumbers)(mobile: '07700 900123', landline: '');
    $this->audit->entries = [];

    ($this->directory)()->contact(
        ($this->authed)(($this->tokenFor)('jo@example.test'), ['id' => 2]),
    );

    $this->assertSame(
        ['mobile_number'],
        array_column($this->audit->entries, 'fieldName'),
    );
});

test('a blank lookup is still audited', function () {
    // It is the sweeping these rows exist to make visible, not the
    // yield. A member with no numbers still cost the asker one of
    // their sixty, and still leaves a trace of having been asked
    // about.
    ($this->enrol)('jo@example.test', 1);
    ($this->givePatNumbers)(mobile: '', landline: '');
    $this->audit->entries = [];

    ($this->directory)()->contact(
        ($this->authed)(($this->tokenFor)('jo@example.test'), ['id' => 2]),
    );

    $this->assertSame(
        ['mobile_number'],
        array_column($this->audit->entries, 'fieldName'),
    );
});

test('sweeping the directory for numbers is throttled', function () {
    ($this->enrol)('jo@example.test', 1);
    ($this->givePatNumbers)(mobile: '07700 900123', landline: '');

    $directory = ($this->directory)();
    $token = ($this->tokenFor)('jo@example.test');

    for ($i = 0; $i < 60; $i++) {
        $allowed = $directory->contact(($this->authed)($token, ['id' => 2]));
        $this->assertInstanceOf(WP_REST_Response::class, $allowed);
    }

    $refused = $directory->contact(($this->authed)($token, ['id' => 2]));

    $this->assertInstanceOf(WP_Error::class, $refused);
    $this->assertSame('reach_rate_limited', $refused->get_error_code());
    $this->assertSame(429, $refused->get_error_data()['status'] ?? null);
});

test('the throttle follows the responder not the handset', function () {
    // A responder with a phone and a tablet does not get twice the
    // allowance for copying a directory.
    ($this->enrol)('jo@example.test', 1);
    $second = ($this->enrol)('jo@example.test', 1);
    ($this->givePatNumbers)(mobile: '07700 900123', landline: '');

    $directory = ($this->directory)();
    $first = ($this->tokenFor)('jo@example.test');

    for ($i = 0; $i < 60; $i++) {
        $directory->contact(($this->authed)($first, ['id' => 2]));
    }

    $onTheTablet = $directory->contact(($this->authed)($second, ['id' => 2]));

    $this->assertInstanceOf(WP_Error::class, $onTheTablet);
    $this->assertSame('reach_rate_limited', $onTheTablet->get_error_code());
});

test('contact refuses an unauthenticated handset', function () {
    $response = ($this->directory)()->contact(new WP_REST_Request(['id' => 2]));

    $this->assertInstanceOf(WP_Error::class, $response);
    $this->assertSame('reach_device_not_authenticated', $response->get_error_code());
});

test('contact refuses a member who does not exist', function () {
    ($this->enrol)('jo@example.test', 1);

    $response = ($this->directory)()->contact(
        ($this->authed)(($this->tokenFor)('jo@example.test'), ['id' => 9999]),
    );

    $this->assertInstanceOf(WP_Error::class, $response);
    $this->assertSame('reach_unknown_member', $response->get_error_code());
    $this->assertSame(404, $response->get_error_data()['status'] ?? null);
});
