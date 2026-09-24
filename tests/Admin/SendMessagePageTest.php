<?php

declare(strict_types=1);

namespace Reach\Tests\Admin;

use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use BleedingDeacons\WpMocks\WpState;
use Reach\Admin\SendMessagePage;
use Reach\Alerts\Alert;
use Reach\Alerts\AlertApi;
use Reach\Alerts\AlertDispatcher;
use Reach\Alerts\RecipientResolver;
use Reach\Core\Capabilities;
use Reach\Devices\Device;
use Reach\Devices\DeviceRepository;
use Reach\Devices\ResponderGate;
use Reach\Tests\Fixtures\CommitteeStub;
use Reach\Tests\Fixtures\InMemoryAlertContactRepository;
use Reach\Tests\Fixtures\InMemoryAlertRepository;
use Reach\Tests\Fixtures\InMemoryDeviceRepository;
use Reach\Tests\Fixtures\MemberStub;
use ReflectionMethod;
use Scrutiny\Privacy\PersonalDataPolicy;
use Unity\Testing\Doubles\InMemoryCommitteeRepository;
use Unity\Testing\Doubles\InMemoryMemberRepository;

/**
 * Tests for the Send Message admin screen.
 *
 * Same techniques as {@see DevicesPageTest}, which most of these moved
 * from when the form left that screen: the page renders for real inside
 * an output buffer, the capability guards are plain expectException
 * because wp_die() throws, and the POST handler ends
 * `wp_safe_redirect(); exit;` so its body was split into
 * messageFromRequest() and is driven through that.
 *
 * What is new here is the recipient. It is a text box with a datalist
 * behind it rather than a tick-box selection, so the tests that matter
 * most are the ones about what happens when somebody types something the
 * list does not contain.
 */

covers(\Reach\Admin\SendMessagePage::class);

/**
 * Intergroup
 * └── Telephones
 *
 * Jo is on Telephones, Sam is on Intergroup, Kit is on neither.
 */
function committees(): InMemoryCommitteeRepository
{
    return new InMemoryCommitteeRepository(
        [
            new CommitteeStub('intergroup', 'Intergroup', id: 1),
            new CommitteeStub('telephones', 'Telephones', id: 2, parentId: 1),
        ],
        ['intergroup' => [10], 'telephones' => [11]],
    );
}

/** @return array<int, MemberStub> */
function committeeMembers(): array
{
    return [
        new MemberStub('sam@example.test', id: 10),
        new MemberStub('jo@example.test', id: 11),
        new MemberStub('kit@example.test', id: 12),
    ];
}

function device(
    int $id = 7,
    string $memberEmail = 'jo@example.test',
    string $label = 'Duty handset',
    ?int $revokedAt = null,
): Device {
    return new Device(
        id: $id,
        memberEmail: $memberEmail,
        memberId: 42,
        label: $label,
        platform: 'android',
        pushProvider: Device::PUSH_FCM,
        pushToken: 'fcm-token',
        createdAt: 1_000,
        revokedAt: $revokedAt,
    );
}

function messageFromRequest(SendMessagePage $page): string
{
    return (string) (new ReflectionMethod(SendMessagePage::class, 'messageFromRequest'))->invoke($page);
}

beforeEach(function () {
    // ── helpers ───────────────────────────────────────────────────────

    /** @param array<int, MemberStub> $members */
    $this->page = function (
        ?DeviceRepository $devices = null,
        ?InMemoryAlertRepository $alerts = null,
        array $members = [],
        ?InMemoryCommitteeRepository $committees = null,
    ): SendMessagePage {
        $devices ??= new InMemoryDeviceRepository();
        $alerts ??= new InMemoryAlertRepository();

        $memberRepository = new InMemoryMemberRepository($members);

        // A real AlertApi over a real dispatcher, for the reason
        // DevicesPageTest gives: the send path is only worth asserting on
        // if it goes through the machinery an ordinary alert does.
        $api = new AlertApi(new AlertDispatcher(
            $alerts,
            new InMemoryAlertContactRepository(),
            $devices,
            new ResponderGate($memberRepository),
            [],
        ));

        // A real resolver over the same doubles, for the same reason. It
        // is the object this screen and Hand's compose route now share,
        // so a double here would assert against something neither uses.
        return new SendMessagePage(
            $devices,
            $api,
            $memberRepository,
            new RecipientResolver(
                $devices,
                $memberRepository,
                $committees ?? new InMemoryCommitteeRepository(),
            ),
        );
    };

    $this->devicesWith = function (Device ...$devices): InMemoryDeviceRepository {
        return new InMemoryDeviceRepository($devices);
    };

    $this->render = function (SendMessagePage $page): string {
        ob_start();
        try {
            $page->render();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    };

    $_GET = [];
    $_POST = [];
});

afterEach(function () {
    $_GET = [];
    $_POST = [];
});

// ── registration ──────────────────────────────────────────────────
test('register hooks the menu and the post handler', function () {
    ($this->page)()->register();

    $this->assertActionAdded('admin_menu', false, 'the page should register its menu on admin_menu');
    $this->assertActionAdded(
        'admin_post_reach_send_message',
        false,
        'the send buttons post to admin-post.php and need their handler hooked',
    );
});

test('add menu attaches under the reach menu as send message', function () {
    ($this->page)()->addMenu();

    $this->assertCount(1, WpState::$menus);
    $this->assertSame('submenu', WpState::$menus[0]['type']);
    $this->assertSame('reach', WpState::$menus[0]['parent']);
    $this->assertSame(SendMessagePage::PAGE_SLUG, WpState::$menus[0]['slug']);
    $this->assertSame('Send Message', WpState::$menus[0]['title']);
    // The recipient list names responders, so reaching the screen is
    // a personal-data read like its siblings.
    $this->assertSame(PersonalDataPolicy::VIEW_CAPABILITY, WpState::$menus[0]['cap']);
});

// ── capability guards ─────────────────────────────────────────────
test('the page renders nothing without the personal data capability', function () {
    WpState::$deniedCaps = [PersonalDataPolicy::VIEW_CAPABILITY];

    $this->assertSame('', ($this->render)(($this->page)()));
});

test('a reader who cannot send is shown no form', function () {
    // Not buttons that answer 403. The handler checks again anyway.
    WpState::$deniedCaps = [Capabilities::SEND_ALERTS];

    $html = ($this->render)(($this->page)(devices: ($this->devicesWith)(device())));

    $this->assertStringNotContainsString('reach_subject', $html);
    $this->assertStringContainsString('do not have permission', $html);
});

test('sending is refused without the send capability', function () {
    WpState::$deniedCaps = [Capabilities::SEND_ALERTS];
    $alerts = new InMemoryAlertRepository();

    try {
        ($this->page)(alerts: $alerts)->handleMessage();
        $this->fail('expected wp_die() for a user who may read but not send');
    } catch (WpDieException) {
        $this->assertSame([], $alerts->alerts);
    }
});

// ── the form ──────────────────────────────────────────────────────
test('the page offers a message form and says where the text ends up', function () {
    $html = ($this->render)(($this->page)(devices: ($this->devicesWith)(device())));

    $this->assertStringContainsString('reach_subject', $html);
    $this->assertStringContainsString('reach_body', $html);
    $this->assertStringContainsString('Send to every live handset', $html);
    $this->assertStringContainsString('Send to the chosen responder', $html);

    // The warning is the whole reason the screen is safe to have.
    $this->assertStringContainsString('goes where an alert goes', $html);
    $this->assertStringContainsString('lock screen', $html);

    // It posts to admin-post.php under its own action.
    $this->assertStringContainsString('action=reach_send_message', $html);
});

test('the recipient is a text box backed by a datalist', function () {
    // Text *and* dropdown: an admin can type a few letters or open
    // the list, which is what makes a long rota usable.
    $devices = ($this->devicesWith)(
        device(id: 7, memberEmail: 'jo@example.test'),
    );

    $html = ($this->render)(($this->page)(
        devices: $devices,
        members: [new MemberStub(id: 7, personalEmail: 'jo@example.test', anonymousName: 'Jo M.')],
    ));

    $this->assertStringContainsString('list="reach-responder-options"', $html);
    $this->assertStringContainsString('<datalist id="reach-responder-options">', $html);
    $this->assertStringContainsString('value="jo@example.test"', $html);
    $this->assertStringContainsString('Jo M.', $html);
});

test('a responder with two handsets is offered once', function () {
    // The list is of people, not of phones.
    $devices = ($this->devicesWith)(
        device(id: 7, memberEmail: 'jo@example.test', label: 'Phone'),
        device(id: 8, memberEmail: 'jo@example.test', label: 'Tablet'),
    );

    $html = ($this->render)(($this->page)(devices: $devices));

    $this->assertSame(1, substr_count($html, 'value="jo@example.test"'));
});

test('a responder with only a revoked handset is not offered', function () {
    // There is nothing to send to, so offering them would be an
    // invitation to a message that silently reaches nobody.
    $devices = ($this->devicesWith)(
        device(id: 7, memberEmail: 'gone@example.test', revokedAt: 1_000),
    );

    $html = ($this->render)(($this->page)(devices: $devices));

    $this->assertStringNotContainsString('gone@example.test', $html);
    $this->assertStringContainsString('No handsets are enrolled', $html);
});

// ── sending ───────────────────────────────────────────────────────
test('a message to every handset is raised as one broadcast alert', function () {
    $_POST = [
        'reach_scope'   => 'all',
        'reach_subject' => 'Line down until 18:00',
        'reach_body'    => 'Use the mobile rota.',
    ];
    $alerts = new InMemoryAlertRepository();

    $target = messageFromRequest(($this->page)(alerts: $alerts));

    $this->assertStringContainsString('reach_result=message_sent', $target);
    $this->assertCount(1, $alerts->alerts);
    $this->assertSame('admin_message', $alerts->alerts[0]->kind);
    $this->assertSame('Line down until 18:00', $alerts->alerts[0]->title);
});

test('the form offers all three levels and defaults to yellow', function () {
    $html = ($this->render)(($this->page)());

    $this->assertStringContainsString('value="red"', $html);
    $this->assertStringContainsString('value="yellow"', $html);
    $this->assertStringContainsString('value="blue"', $html);
    // Yellow: an admin who does not choose has not thereby declared an
    // emergency, and red takes over somebody's screen.
    $this->assertMatchesRegularExpression('/value="yellow"[^>]*checked/', $html);
});

test('the form offers first to respond and starts ticked', function () {
    // Ticked, because that is what every message did before the
    // control existed.
    $html = ($this->render)(($this->page)());

    $this->assertMatchesRegularExpression(
        '/name="reach_first_to_respond"[^>]*checked/',
        $html,
    );
});

test('the chosen level reaches the alert', function () {
    $_POST = [
        'reach_scope'   => 'all',
        'reach_subject' => 'Everybody out',
        'reach_level'   => 'red',
    ];
    $alerts = new InMemoryAlertRepository();

    messageFromRequest(($this->page)(alerts: $alerts));

    $this->assertSame(Alert::LEVEL_RED, $alerts->alerts[0]->level);
});

test('an unticked box makes the message informational', function () {
    // An unticked checkbox posts nothing at all, so absent has to mean
    // informational: the tick is the affirmative claim that somebody
    // is meant to take this on.
    $_POST = [
        'reach_scope'   => 'all',
        'reach_subject' => 'The office is shut on Monday',
    ];
    $alerts = new InMemoryAlertRepository();

    messageFromRequest(($this->page)(alerts: $alerts));

    $this->assertTrue($alerts->alerts[0]->isInformational());
});

test('a ticked box makes the message first to respond', function () {
    $_POST = [
        'reach_scope'            => 'all',
        'reach_subject'          => 'Callback wanted',
        'reach_first_to_respond' => '1',
    ];
    $alerts = new InMemoryAlertRepository();

    messageFromRequest(($this->page)(alerts: $alerts));

    $this->assertTrue($alerts->alerts[0]->isFirstToRespond());
});

test('a message that names no level is yellow', function () {
    $_POST = ['reach_scope' => 'all', 'reach_subject' => 'Anything'];
    $alerts = new InMemoryAlertRepository();

    messageFromRequest(($this->page)(alerts: $alerts));

    $this->assertSame(Alert::LEVEL_YELLOW, $alerts->alerts[0]->level);
});

test('both of a responders handsets get the same level and response', function () {
    // One message told two ways would be a responder whose phone
    // sirened and whose tablet did not.
    $devices = ($this->devicesWith)(
        device(id: 7, memberEmail: 'jo@example.test', label: 'Phone'),
        device(id: 8, memberEmail: 'jo@example.test', label: 'Tablet'),
    );
    $_POST = [
        'reach_scope'     => 'responder',
        'reach_responder' => 'jo@example.test',
        'reach_subject'   => 'Can you cover tonight?',
        'reach_level'     => 'blue',
    ];
    $alerts = new InMemoryAlertRepository();

    messageFromRequest(($this->page)(devices: $devices, alerts: $alerts));

    $this->assertCount(2, $alerts->alerts);
    $this->assertSame(Alert::LEVEL_BLUE, $alerts->alerts[0]->level);
    $this->assertSame(Alert::LEVEL_BLUE, $alerts->alerts[1]->level);
    $this->assertTrue($alerts->alerts[0]->isInformational());
    $this->assertTrue($alerts->alerts[1]->isInformational());
});

test('a message to a responder is raised once per handset', function () {
    // One alert per handset, so each carries its own acknowledgement
    // and a silent phone cannot hide behind the other one answering.
    $devices = ($this->devicesWith)(
        device(id: 7, memberEmail: 'jo@example.test', label: 'Phone'),
        device(id: 8, memberEmail: 'jo@example.test', label: 'Tablet'),
        device(id: 9, memberEmail: 'sam@example.test'),
    );
    $_POST = [
        'reach_scope'     => 'responder',
        'reach_responder' => 'jo@example.test',
        'reach_subject'   => 'Can you cover tonight?',
    ];
    $alerts = new InMemoryAlertRepository();

    $target = messageFromRequest(($this->page)(devices: $devices, alerts: $alerts));

    $this->assertStringContainsString('reach_result=message_sent_responder', $target);
    $this->assertCount(2, $alerts->alerts, 'one per handset that responder holds, and nobody else');
});

test('a responder is matched without regard to case', function () {
    // An admin typing an address by hand should not have to match the
    // capitalisation Unity happens to hold.
    $devices = ($this->devicesWith)(device(id: 7, memberEmail: 'jo@example.test'));
    $_POST = [
        'reach_scope'     => 'responder',
        'reach_responder' => 'Jo@Example.Test',
        'reach_subject'   => 'Can you cover tonight?',
    ];
    $alerts = new InMemoryAlertRepository();

    $target = messageFromRequest(($this->page)(devices: $devices, alerts: $alerts));

    $this->assertStringContainsString('reach_result=message_sent_responder', $target);
    $this->assertCount(1, $alerts->alerts);
});

test('a message to a committee reaches its members handsets', function () {
    $devices = ($this->devicesWith)(
        device(id: 7, memberEmail: 'jo@example.test'),
        device(id: 8, memberEmail: 'kit@example.test'),
    );
    $_POST = [
        'reach_scope'     => 'committee',
        'reach_committee' => 'telephones',
        'reach_subject'   => 'Rota change',
    ];
    $alerts = new InMemoryAlertRepository();

    $target = messageFromRequest(($this->page)(
        devices: $devices,
        alerts: $alerts,
        members: committeeMembers(),
        committees: committees(),
    ));

    $this->assertStringContainsString('reach_result=message_sent_committee', $target);
    $this->assertCount(1, $alerts->alerts, 'Jo is on Telephones; Kit is on no committee');
    $this->assertSame(7, $alerts->alerts[0]->targetDeviceId, 'Jo’s handset, not Kit’s');
});

/**
 * Messaging a parent and not reaching the committees under it would be a
 * trap: the tree says they are part of it.
 */
test('a message to a committee reaches the committees under it', function () {
    $devices = ($this->devicesWith)(
        device(id: 7, memberEmail: 'jo@example.test'),
        device(id: 8, memberEmail: 'sam@example.test'),
    );
    $_POST = [
        'reach_scope'     => 'committee',
        'reach_committee' => 'intergroup',
        'reach_subject'   => 'Rota change',
    ];
    $alerts = new InMemoryAlertRepository();

    messageFromRequest(($this->page)(
        devices: $devices,
        alerts: $alerts,
        members: committeeMembers(),
        committees: committees(),
    ));

    $this->assertCount(2, $alerts->alerts, 'Sam on Intergroup and Jo on Telephones beneath it');
});

/**
 * Splitting by handset is a delivery decision. Ten people on a committee
 * were sent one message, and an acknowledgement from any of them has to be
 * able to find the rest.
 */
test('a message to a committee is one message across every handset', function () {
    $devices = ($this->devicesWith)(
        device(id: 7, memberEmail: 'jo@example.test', label: 'Phone'),
        device(id: 8, memberEmail: 'jo@example.test', label: 'Tablet'),
        device(id: 9, memberEmail: 'sam@example.test'),
    );
    $_POST = [
        'reach_scope'     => 'committee',
        'reach_committee' => 'intergroup',
        'reach_subject'   => 'Rota change',
    ];
    $alerts = new InMemoryAlertRepository();

    messageFromRequest(($this->page)(
        devices: $devices,
        alerts: $alerts,
        members: committeeMembers(),
        committees: committees(),
    ));

    $this->assertCount(3, $alerts->alerts);

    $uuids = array_unique(array_map(
        static fn ($alert): string => $alert->messageUuid,
        $alerts->alerts,
    ));
    $this->assertCount(1, $uuids, 'one message uuid across the whole committee');
});

/**
 * A member can hold the parent and the child, and two paths must not ring
 * the same phone twice.
 */
test('a member on two committees in the branch is only sent one copy', function () {
    $committees = new InMemoryCommitteeRepository(
        [
            new CommitteeStub('intergroup', 'Intergroup', id: 1),
            new CommitteeStub('telephones', 'Telephones', id: 2, parentId: 1),
        ],
        ['intergroup' => [11], 'telephones' => [11]],
    );
    $devices = ($this->devicesWith)(device(id: 7, memberEmail: 'jo@example.test'));
    $_POST = [
        'reach_scope'     => 'committee',
        'reach_committee' => 'intergroup',
        'reach_subject'   => 'Rota change',
    ];
    $alerts = new InMemoryAlertRepository();

    messageFromRequest(($this->page)(
        devices: $devices,
        alerts: $alerts,
        members: committeeMembers(),
        committees: $committees,
    ));

    $this->assertCount(1, $alerts->alerts);
});

test('a committee message never reaches a revoked handset', function () {
    $devices = ($this->devicesWith)(
        device(id: 7, memberEmail: 'jo@example.test'),
        device(id: 8, memberEmail: 'jo@example.test', revokedAt: 1_000),
    );
    $_POST = [
        'reach_scope'     => 'committee',
        'reach_committee' => 'telephones',
        'reach_subject'   => 'Rota change',
    ];
    $alerts = new InMemoryAlertRepository();

    messageFromRequest(($this->page)(
        devices: $devices,
        alerts: $alerts,
        members: committeeMembers(),
        committees: committees(),
    ));

    $this->assertCount(1, $alerts->alerts, 'the revoked handset is not a destination');
});

/**
 * Saying "sent" when nothing was sent is a lie an admin acts on.
 */
test('a committee whose members have no handsets is reported not swallowed', function () {
    $devices = ($this->devicesWith)(device(id: 7, memberEmail: 'kit@example.test'));
    $_POST = [
        'reach_scope'     => 'committee',
        'reach_committee' => 'telephones',
        'reach_subject'   => 'Rota change',
    ];
    $alerts = new InMemoryAlertRepository();

    $target = messageFromRequest(($this->page)(
        devices: $devices,
        alerts: $alerts,
        members: committeeMembers(),
        committees: committees(),
    ));

    $this->assertStringContainsString('reach_result=message_committee_silent', $target);
    $this->assertCount(0, $alerts->alerts);
});

test('a committee message needs a committee', function () {
    $_POST = [
        'reach_scope'     => 'committee',
        'reach_committee' => '',
        'reach_subject'   => 'Rota change',
    ];
    $alerts = new InMemoryAlertRepository();

    $target = messageFromRequest(($this->page)(
        alerts: $alerts,
        committees: committees(),
    ));

    $this->assertStringContainsString('reach_result=message_no_committee', $target);
    $this->assertCount(0, $alerts->alerts);
});

/**
 * The control posts a slug, and a slug is only ever a string somebody's
 * browser sent back.
 */
test('an unknown committee is told so rather than silently sending nothing', function () {
    $_POST = [
        'reach_scope'     => 'committee',
        'reach_committee' => 'no-such-committee',
        'reach_subject'   => 'Rota change',
    ];
    $alerts = new InMemoryAlertRepository();

    $target = messageFromRequest(($this->page)(
        alerts: $alerts,
        committees: committees(),
    ));

    $this->assertStringContainsString('reach_result=message_unknown_committee', $target);
    $this->assertCount(0, $alerts->alerts);
});

test('a message never reaches a revoked handset', function () {
    $devices = ($this->devicesWith)(
        device(id: 7, memberEmail: 'jo@example.test'),
        device(id: 8, memberEmail: 'jo@example.test', revokedAt: 1_000),
    );
    $_POST = [
        'reach_scope'     => 'responder',
        'reach_responder' => 'jo@example.test',
        'reach_subject'   => 'Can you cover tonight?',
    ];
    $alerts = new InMemoryAlertRepository();

    messageFromRequest(($this->page)(devices: $devices, alerts: $alerts));

    $this->assertCount(1, $alerts->alerts, 'the revoked handset is not a destination');
});

test('a subject and body are unslashed before they are sent', function () {
    // WordPress runs wp_magic_quotes() on every request, so $_POST
    // arrives slash-escaped whatever the PHP configuration says. Left
    // alone, an apostrophe reaches the responder's lock screen with a
    // backslash in front of it.
    $_POST = [
        'reach_scope'   => 'all',
        'reach_subject' => "Jo\'s phone is down",
        'reach_body'    => "Use Sam\'s instead",
    ];
    $alerts = new InMemoryAlertRepository();

    messageFromRequest(($this->page)(alerts: $alerts));

    $this->assertCount(1, $alerts->alerts);
    $this->assertSame("Jo's phone is down", $alerts->alerts[0]->title);
    $this->assertSame("Use Sam's instead", $alerts->alerts[0]->body);
});

// ── refusals ──────────────────────────────────────────────────────
test('a message with no subject is refused', function () {
    $_POST = ['reach_scope' => 'all', 'reach_body' => 'Body only'];
    $alerts = new InMemoryAlertRepository();

    $target = messageFromRequest(($this->page)(alerts: $alerts));

    $this->assertStringContainsString('reach_result=message_no_subject', $target);
    $this->assertSame([], $alerts->alerts);
});

test('a message with no scope is refused rather than broadcast', function () {
    // The form has text boxes in it, so Enter can submit it with no
    // button pressed. If that were read as "everybody", a keystroke
    // would ring the whole rota.
    $_POST = ['reach_subject' => 'Typed and then Entered'];
    $alerts = new InMemoryAlertRepository();

    $target = messageFromRequest(($this->page)(alerts: $alerts));

    $this->assertStringContainsString('reach_result=message_no_scope', $target);
    $this->assertSame([], $alerts->alerts);
});

test('a message to nobody in particular is refused', function () {
    $_POST = ['reach_scope' => 'responder', 'reach_subject' => 'Who is this for?'];
    $alerts = new InMemoryAlertRepository();

    $target = messageFromRequest(($this->page)(alerts: $alerts));

    $this->assertStringContainsString('reach_result=message_no_responder', $target);
    $this->assertSame([], $alerts->alerts);
});

test('a typed responder who matches nothing is refused', function () {
    // The datalist offers the list; it does not confine anyone to it.
    // A posted address is only ever a string a browser sent back, so
    // it is resolved against the enrolled handsets rather than
    // trusted — and an address matching nothing must not quietly
    // become a broadcast.
    $devices = ($this->devicesWith)(device(id: 7, memberEmail: 'jo@example.test'));
    $_POST = [
        'reach_scope'     => 'responder',
        'reach_responder' => 'nobody@example.test',
        'reach_subject'   => 'Can you cover tonight?',
    ];
    $alerts = new InMemoryAlertRepository();

    $target = messageFromRequest(($this->page)(devices: $devices, alerts: $alerts));

    $this->assertStringContainsString('reach_result=message_unknown_responder', $target);
    $this->assertSame([], $alerts->alerts, 'nothing may be sent to an address nobody holds');
});

// ── notices ───────────────────────────────────────────────────────
test('notices are plain text because they are escaped on the way out', function () {
    // The whole string goes through esc_html(), so an HTML entity
    // written into the table arrives as the literal characters.
    $_GET = ['reach_result' => 'message_no_subject'];

    $html = ($this->render)(($this->page)());

    $this->assertStringNotContainsString('&amp;mdash;', $html);
    $this->assertStringContainsString('A message needs a subject', $html);
});

test('an unknown result shows no notice', function () {
    $_GET = ['reach_result' => 'something_else'];

    $this->assertStringNotContainsString('notice-success', ($this->render)(($this->page)()));
});
