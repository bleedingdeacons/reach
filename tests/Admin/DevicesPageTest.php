<?php

declare(strict_types=1);

namespace Reach\Tests\Admin;

use function Brain\Monkey\Functions\when;
use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use BleedingDeacons\WpMocks\WpState;
use LogicException;
use Reach\Admin\DevicesPage;
use Reach\Alerts\Alert;
use Reach\Alerts\AlertApi;
use Reach\Alerts\AlertDispatcher;
use Reach\Alerts\AlertRequest;
use Reach\Core\Capabilities;
use Reach\Devices\Device;
use Reach\Devices\DeviceRepository;
use Reach\Devices\ResponderGate;
use Reach\Tests\Fixtures\InMemoryAlertContactRepository;
use Reach\Tests\Fixtures\InMemoryAlertReplyRepository;
use Reach\Tests\Fixtures\InMemoryAlertRepository;
use Reach\Tests\Fixtures\InMemoryDeviceRepository;
use Reach\Tests\Fixtures\MemberStub;
use ReflectionMethod;
use Scrutiny\Privacy\PersonalDataPolicy;
use Unity\Members\Interfaces\Member;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use WP_User;

/**
 * Tests for the Hand devices admin screen.
 *
 * Same three techniques as the sibling pages: the list runs for real
 * inside an output buffer, the capability guards are plain
 * expectException because wp_die() throws, and the POST handlers end
 * `wp_safe_redirect(); exit;` so their bodies were split into
 * revokeFromRequest()/testAlertFromRequest() and are driven through
 * those. That split is the only production change these tests needed and
 * it is behaviour-identical — the precedent is
 * {@see \Reach\Admin\CallRequestsPage::completeFromRequest()}.
 *
 * The screen shows which responder each handset belongs to, so it is
 * gated on {@see PersonalDataPolicy::VIEW_CAPABILITY} like the rest of
 * Reach's admin.
 */

covers(\Reach\Admin\DevicesPage::class);

function revokedAt(): int
{
    return (int) strtotime('2026-07-24 10:15:00 UTC');
}

function revokeFromRequest(DevicesPage $page): string
{
    return (string) (new ReflectionMethod(DevicesPage::class, 'revokeFromRequest'))->invoke($page);
}

function testAlertFromRequest(DevicesPage $page): string
{
    return (string) (new ReflectionMethod(DevicesPage::class, 'testAlertFromRequest'))->invoke($page);
}

function handsetsUri(DevicesPage $page): string
{
    return (string) (new ReflectionMethod(DevicesPage::class, 'handsetsUri'))->invoke($page);
}

function handsetsFragment(DevicesPage $page): string
{
    return (string) (new ReflectionMethod(DevicesPage::class, 'handsetsFragment'))->invoke($page);
}

function recentAlertsFragment(DevicesPage $page): string
{
    return (string) (new ReflectionMethod(DevicesPage::class, 'recentAlertsFragment'))->invoke($page);
}

function removeFromRequest(DevicesPage $page): string
{
    return (string) (new ReflectionMethod(DevicesPage::class, 'removeFromRequest'))->invoke($page);
}

beforeEach(function () {
    /**
     * The rendered table row carrying a given handset label.
     *
     * A status is only meaningful next to the handset it describes, so a
     * test that asserts one has to say which row it read it from.
     */
    $this->rowFor = function (string $html, string $label): string {
        foreach (explode('<tr', $html) as $row) {
            if (str_contains($row, $label)) {
                return $row;
            }
        }

        $this->fail('No row found for handset "' . $label . '"');
    };

    // ── helpers ───────────────────────────────────────────────────────

    /** @param array<int, Member> $members */
    $this->page = function (
        ?DeviceRepository $devices = null,
        ?InMemoryAlertRepository $alerts = null,
        array $members = [],
        ?InMemoryAlertReplyRepository $replies = null,
    ): DevicesPage {
        $devices ??= new InMemoryDeviceRepository();
        $alerts ??= new InMemoryAlertRepository();
        $replies ??= new InMemoryAlertReplyRepository();

        // A real AlertApi over a real dispatcher: the test-alert path is
        // only worth asserting on if it goes through the machinery an
        // ordinary alert does.
        $api = new AlertApi(new AlertDispatcher(
            $alerts,
            new InMemoryAlertContactRepository(),
            $devices,
            new ResponderGate(new InMemoryMemberRepository($members)),
            [],
        ));

        return new DevicesPage(
            $devices,
            $alerts,
            $api,
            new InMemoryMemberRepository($members),
            $replies,
        );
    };

    $this->devicesWith = function (Device ...$devices): InMemoryDeviceRepository {
        return new InMemoryDeviceRepository($devices);
    };

    $this->device = function (
        int $id = 7,
        string $memberEmail = 'jo@example.test',
        string $label = 'Duty handset',
        string $platform = 'android',
        string $pushProvider = Device::PUSH_FCM,
        string $pushToken = 'fcm-token',
        int $lastSeenAt = 0,
        ?int $revokedAt = null,
        ?int $keyFaultAt = null,
        string $lockScreen = Device::LOCK_SCREEN_UNKNOWN,
    ): Device {
        return new Device(
            id: $id,
            memberEmail: $memberEmail,
            memberId: 42,
            label: $label,
            platform: $platform,
            pushProvider: $pushProvider,
            pushToken: $pushToken,
            createdAt: ($this->createdAt)(),
            lastSeenAt: $lastSeenAt,
            revokedAt: $revokedAt,
            keyFaultAt: $keyFaultAt,
            lockScreen: $lockScreen,
        );
    };

    /** @param array<string, mixed> $extra */
    $this->alertRequest = function (
        string $priority = 'normal',
        string $kind = 'call_request',
        array $extra = []
    ): AlertRequest {
        $request = AlertRequest::fromArray($extra + [
            'kind'     => $kind,
            'source'   => 'reach',
            'title'    => 'Callback wanted',
            'priority' => $priority,
        ]);

        $this->assertInstanceOf(AlertRequest::class, $request);

        return $request;
    };

    $this->createdAt = function (): int {
        return (int) strtotime('2026-07-24 09:15:00 UTC');
    };

    /**
     * Fix who wp_get_current_user() reports. Patchwork keeps the
     * redefined function's signature — `: \WP_User` — so this has to hand
     * back a real WP_User rather than a convenient stdClass.
     */
    $this->signedInAs = function (string $displayName): void {
        $user = new WP_User(['administrator'], 1);
        $user->display_name = $displayName;

        when('wp_get_current_user')->justReturn($user);
    };

    $this->renderList = function (DevicesPage $page): string {
        ob_start();
        try {
            $page->renderList();
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
test('register hooks the menu and every post handler', function () {
    ($this->page)()->register();

    $this->assertActionAdded('admin_menu', false, 'the page should register its menu on admin_menu');
    $this->assertActionAdded(
        'admin_post_reach_revoke_device',
        false,
        'the per-row Revoke button posts to admin-post.php and needs its handler hooked',
    );
    $this->assertActionAdded(
        'admin_post_reach_remove_device',
        false,
        'the per-row Remove button posts to admin-post.php and needs its handler hooked',
    );
    $this->assertActionAdded(
        'admin_post_reach_send_test_alert',
        false,
        'the Send test alert button posts to admin-post.php and needs its handler hooked',
    );
    $this->assertActionAdded(
        'wp_ajax_reach_recent_alerts',
        false,
        'the Recent alerts table refreshes itself through admin-ajax',
    );
});

test('add menu attaches under the reach menu behind the personal data capability', function () {
    ($this->page)()->addMenu();

    $this->assertCount(1, WpState::$menus);
    $this->assertSame('submenu', WpState::$menus[0]['type']);
    $this->assertSame('reach', WpState::$menus[0]['parent']);
    $this->assertSame(DevicesPage::PAGE_SLUG, WpState::$menus[0]['slug']);
    $this->assertSame(PersonalDataPolicy::VIEW_CAPABILITY, WpState::$menus[0]['cap']);
});

// ── capability guards ─────────────────────────────────────────────
test('the list renders nothing without the personal data capability', function () {
    WpState::$deniedCaps = [PersonalDataPolicy::VIEW_CAPABILITY];

    $this->assertSame('', ($this->renderList)(($this->page)(devices: ($this->devicesWith)(($this->device)()))));
});

test('revoking without the manage capability dies', function () {
    WpState::$deniedCaps = [Capabilities::MANAGE_DEVICES];

    $_POST = ['device_id' => '7'];
    $devices = ($this->devicesWith)(($this->device)(id: 7));

    try {
        ($this->page)(devices: $devices)->handleRevoke();
        $this->fail('expected wp_die() for a user without the capability');
    } catch (WpDieException) {
        $this->assertFalse(
            $devices->findById(7)?->isRevoked(),
            'nothing may be revoked behind the guard',
        );
    }
});

// ── list rendering ────────────────────────────────────────────────
test('an empty list says so for both tables', function () {
    $html = ($this->renderList)(($this->page)());

    $this->assertStringContainsString('No handsets have been enrolled yet.', $html);
    $this->assertStringContainsString('No alerts have been raised yet.', $html);
    $this->assertStringContainsString('0 items', $html);
});

test('a live handset shows its responder platform and a revoke button', function () {
    $page = ($this->page)(
        devices: ($this->devicesWith)(($this->device)(id: 7, label: 'Duty handset')),
        members: [new MemberStub(personalEmail: 'jo@example.test', anonymousName: 'Jo M.')],
    );

    $html = ($this->renderList)($page);

    $this->assertStringContainsString('Jo M.', $html);
    $this->assertStringContainsString('Duty handset', $html);
    $this->assertStringContainsString('android', $html);
    $this->assertStringContainsString('Live', $html);
    $this->assertStringContainsString('name="device_id" value="7"', $html);
    $this->assertStringContainsString('Revoke</button>', $html);
});

test('a responder name links to their member record', function () {
    // The screen answers "whose handset is this"; the next question
    // is always "and who are they". Same link the call-attempts list
    // puts under a responder's name.
    $page = ($this->page)(
        devices: ($this->devicesWith)(($this->device)(memberEmail: 'jo@example.test')),
        members: [new MemberStub(id: 7, personalEmail: 'jo@example.test', anonymousName: 'Jo M.')],
    );

    $html = ($this->renderList)($page);

    $this->assertStringContainsString('post.php?post=7', $html);
    $this->assertStringContainsString('>Jo M.</a>', $html);
});

test('a responder the admin cannot edit is named without a link', function () {
    // get_edit_post_link() answers null when the current user cannot
    // edit the member. The name still has to appear — as plain text
    // rather than a link that only leads to a permissions error.
    when('get_edit_post_link')->justReturn(null);

    $page = ($this->page)(
        devices: ($this->devicesWith)(($this->device)(memberEmail: 'jo@example.test')),
        members: [new MemberStub(id: 7, personalEmail: 'jo@example.test', anonymousName: 'Jo M.')],
    );

    $html = ($this->renderList)($page);

    $this->assertStringContainsString('Jo M.', $html);
    $this->assertStringNotContainsString('>Jo M.</a>', $html);
});

test('a handset whose responder unity does not know is not linked', function () {
    // There is no record to link to. The address is the diagnostic:
    // the member was deleted, or the address no longer matches one.
    $page = ($this->page)(
        devices: ($this->devicesWith)(($this->device)(memberEmail: 'unknown@example.test')),
        members: [],
    );

    $html = ($this->renderList)($page);

    $this->assertStringContainsString('unknown@example.test', $html);
    $this->assertStringNotContainsString('>unknown@example.test</a>', $html);
});

test('a handset that cannot read its alerts is flagged rather than shown live', function () {
    // Worse than revoked, and looks better: this handset is still on
    // the rota and still counted as covering a shift, but cannot read
    // what it is sent. The only other symptom is a responder who does
    // not answer.
    $page = ($this->page)(devices: ($this->devicesWith)(
        ($this->device)(id: 7, keyFaultAt: revokedAt()),
    ));

    $html = ($this->renderList)($page);

    $this->assertStringContainsString('Cannot read alerts', $html);
    $this->assertStringNotContainsString('>Live<', $html);
});

test('a handset that shows alert text when locked is flagged but still live', function () {
    // Alongside Live, not instead of it. This handset works; the note
    // is about who else can read what it is sent. Hand asks Android to
    // redact the lock screen, but Android only obliges where the
    // phone's owner has chosen to hide sensitive content — so this is
    // a fact somebody has to decide about, not a bug to be fixed.
    $page = ($this->page)(devices: ($this->devicesWith)(
        ($this->device)(id: 7, lockScreen: Device::LOCK_SCREEN_SHOWN),
    ));

    $html = ($this->renderList)($page);

    $this->assertStringContainsString('Alerts readable when locked', $html);
    $this->assertStringContainsString('>Live<', $html, 'it is still a working handset');
});

test('a handset that has never reported its lock screen is not reassured about', function () {
    // Unknown is not "safe" — it is the absence of a claim either way,
    // so it must not be shown as either.
    $html = ($this->renderList)(($this->page)(devices: ($this->devicesWith)(
        ($this->device)(id: 7, lockScreen: Device::LOCK_SCREEN_UNKNOWN),
    )));

    $this->assertStringNotContainsString('Alerts readable when locked', $html);
    $this->assertStringNotContainsString('Alerts hidden when locked', $html);
});

test('a handset that hides alert text when locked is not flagged', function () {
    $html = ($this->renderList)(($this->page)(devices: ($this->devicesWith)(
        ($this->device)(id: 7, lockScreen: Device::LOCK_SCREEN_HIDDEN),
    )));

    $this->assertStringNotContainsString('Alerts readable when locked', $html);
    $this->assertStringContainsString('Live', $html);
});

test('a healthy handset is not flagged', function () {
    $html = ($this->renderList)(($this->page)(devices: ($this->devicesWith)(($this->device)(id: 7))));

    $this->assertStringNotContainsString('Cannot read alerts', $html);
    $this->assertStringContainsString('Live', $html);
});

test('a superseded handset keeps its warning until it is revoked', function () {
    // Signing in again does not repair a faulted row — it creates a
    // new one, and the old row's report stays true of the old row.
    // What retires the warning is revoking or removing it, and
    // enrolment already revokes the oldest rows past the handset cap.
    //
    // Asserted per row rather than across the page: the warning
    // landing on the replacement instead of the faulted handset would
    // satisfy any page-wide check while telling an admin to go and
    // fix the wrong phone.
    $page = ($this->page)(devices: ($this->devicesWith)(
        ($this->device)(id: 7, label: 'The old one', keyFaultAt: revokedAt()),
        ($this->device)(id: 8, label: 'Its replacement'),
    ));

    $html = ($this->renderList)($page);

    $this->assertStringContainsString('Cannot read alerts', ($this->rowFor)($html, 'The old one'));
    $this->assertStringNotContainsString('Cannot read alerts', ($this->rowFor)($html, 'Its replacement'));
    $this->assertStringContainsString('Live', ($this->rowFor)($html, 'Its replacement'));
});

test('a revoked handset reads as revoked even with a key fault', function () {
    // Revoked is the more final of the two and the one an admin acted
    // on; a handset that is off the rota cannot also be a rota problem.
    $page = ($this->page)(devices: ($this->devicesWith)(
        ($this->device)(id: 7, revokedAt: revokedAt(), keyFaultAt: revokedAt()),
    ));

    $html = ($this->renderList)($page);

    $this->assertStringContainsString('Revoked', $html);
    $this->assertStringNotContainsString('Cannot read alerts', $html);
});

test('a revoked handset is shown as history with no revoke button', function () {
    // Rows are kept rather than deleted so the list is a record of
    // what has been enrolled, not only what is enrolled now.
    $page = ($this->page)(devices: ($this->devicesWith)(
        ($this->device)(id: 7, revokedAt: revokedAt()),
    ));

    $html = ($this->renderList)($page);

    $this->assertStringContainsString('Revoked', $html);
    $this->assertStringNotContainsString('Revoke</button>', $html, 'history is not re-revocable');
});

test('a handset falls back to its email when unity knows no name', function () {
    $page = ($this->page)(
        devices: ($this->devicesWith)(($this->device)(memberEmail: 'unknown@example.test')),
        members: [],
    );

    $this->assertStringContainsString('unknown@example.test', ($this->renderList)($page));
});

test('a member with a blank anonymous name falls back to the email', function () {
    $page = ($this->page)(
        devices: ($this->devicesWith)(($this->device)(memberEmail: 'jo@example.test')),
        members: [new MemberStub(personalEmail: 'jo@example.test', anonymousName: '  ')],
    );

    $this->assertStringContainsString('jo@example.test', ($this->renderList)($page));
});

test('an unlabelled handset renders a placeholder', function () {
    $page = ($this->page)(devices: ($this->devicesWith)(($this->device)(label: '')));

    $this->assertStringContainsString('—', ($this->renderList)($page));
});

test('the delivery column distinguishes push from poll', function (
    string $pushProvider,
    string $pushToken,
    string $expected
) {
    // A handset can claim FCM but have no token yet — the app enrols
    // before Firebase hands one over — and that gap must read as Poll
    // rather than as a push to nowhere.
    $page = ($this->page)(devices: ($this->devicesWith)(
        ($this->device)(pushProvider: $pushProvider, pushToken: $pushToken),
    ));

    $this->assertStringContainsString($expected, ($this->renderList)($page));
})->with('deliveryModes');

/** @return array<string, array{0: string, 1: string, 2: string}> */
dataset('deliveryModes', function (): array {
    return [
        'enrolled with a token' => [Device::PUSH_FCM, 'fcm-token', 'Push'],
        'enrolled, token pending' => [Device::PUSH_FCM, '', 'Poll'],
        'no push at all' => [Device::PUSH_NONE, '', 'Poll'],
    ];
});

test('a handset never seen since enrolment shows a placeholder', function () {
    $page = ($this->page)(devices: ($this->devicesWith)(($this->device)(lastSeenAt: 0)));

    $html = ($this->renderList)($page);

    $this->assertStringContainsString(gmdate('Y-m-d H:i', ($this->createdAt)()), $html);
    $this->assertStringContainsString('—', $html);
});

test('the total counts every row not just the page', function () {
    $devices = ($this->devicesWith)(
        ($this->device)(id: 1),
        ($this->device)(id: 2),
        ($this->device)(id: 3, revokedAt: revokedAt()),
    );

    // The count comes from core's pagination now rather than a line of
    // our own above the table, which is where WordPress puts it.
    $this->assertStringContainsString('3 items', ($this->renderList)(($this->page)(devices: $devices)));
});

test('the list pages fifty at a time', function () {
    $_GET = ['paged' => '3'];
    $devices = new PagingDeviceRepository();

    ($this->renderList)(($this->page)(devices: $devices));

    $this->assertSame([['limit' => 50, 'offset' => 100]], $devices->paging);
});

test('a nonsense page number falls back to the first', function (string $paged) {
    $_GET = ['paged' => $paged];
    $devices = new PagingDeviceRepository();

    ($this->renderList)(($this->page)(devices: $devices));

    $this->assertSame([['limit' => 50, 'offset' => 0]], $devices->paging);
})->with('nonsensePages');

/** @return array<string, array{0: string}> */
dataset('nonsensePages', function (): array {
    return [
        'zero'     => ['0'],
        'negative' => ['-4'],
        'words'    => ['nonsense'],
    ];
});

test('sorting a handset column reaches the repository as its own column name', function () {
    // The list is paginated over the whole table, so the sort has to
    // happen in SQL: ordering only the rows in hand would order the
    // page rather than the list.
    $_GET = ['orderby' => 'last_seen', 'order' => 'asc'];
    $devices = new PagingDeviceRepository();

    ($this->renderList)(($this->page)(devices: $devices));

    $this->assertSame([['orderBy' => 'last_seen_at', 'order' => 'asc']], $devices->sorting);
});

test('an unsortable handset column leaves the list in its default order', function () {
    // Both tables on this screen read the same `orderby`, so the
    // alerts table's columns arrive here too and must mean nothing.
    $_GET = ['orderby' => 'acknowledged', 'order' => 'asc'];
    $devices = new PagingDeviceRepository();

    ($this->renderList)(($this->page)(devices: $devices));

    $this->assertSame([['orderBy' => '', 'order' => 'asc']], $devices->sorting);
});

test('sorting by responder orders by the name shown not the email behind it', function () {
    // The name comes from Unity, not from the devices table, so there
    // is no ORDER BY that produces it — and a header that reorders
    // the list by something other than what the column displays is a
    // header that lies. These two rows disagree on purpose: by name
    // Alan comes first, by address Zoe's does.
    $devices = ($this->devicesWith)(
        ($this->device)(id: 1, memberEmail: 'zoe@example.test'),
        ($this->device)(id: 2, memberEmail: 'alan@example.test'),
    );
    $members = [
        new MemberStub(personalEmail: 'zoe@example.test', anonymousName: 'Alan B.'),
        new MemberStub(personalEmail: 'alan@example.test', anonymousName: 'Zoe T.'),
    ];

    $_GET = ['orderby' => 'responder', 'order' => 'asc'];

    $html = ($this->renderList)(($this->page)(devices: $devices, members: $members));

    $this->assertLessThan(
        strpos($html, 'Zoe T.'),
        strpos($html, 'Alan B.'),
        'ascending by Responder should put Alan B. above Zoe T.',
    );
});

test('sorting by responder ignores the markup around the name', function () {
    // The cell is a link, so sorting the rendered cell would sort
    // every linked name under "<" and leave the unlinked ones — the
    // addresses of responders Unity has lost — in a block of their
    // own. Alan's row is linked and Zoe's is not; by name Alan still
    // comes first.
    $devices = ($this->devicesWith)(
        ($this->device)(id: 1, memberEmail: 'alan@example.test'),
        ($this->device)(id: 2, memberEmail: 'zoe@example.test'),
    );

    $_GET = ['orderby' => 'responder', 'order' => 'asc'];

    $html = ($this->renderList)(($this->page)(
        devices: $devices,
        members: [new MemberStub(id: 7, personalEmail: 'alan@example.test', anonymousName: 'Alan B.')],
    ));

    $this->assertStringContainsString('>Alan B.</a>', $html, 'Alan is linked');
    $this->assertStringNotContainsString('>zoe@example.test</a>', $html, 'Zoe is not');
    $this->assertLessThan(
        strpos($html, 'zoe@example.test'),
        strpos($html, 'Alan B.'),
        'ascending by Responder should still put Alan B. above zoe@example.test',
    );
});

test('sorting by responder reverses on a descending request', function () {
    $devices = ($this->devicesWith)(
        ($this->device)(id: 1, memberEmail: 'zoe@example.test'),
        ($this->device)(id: 2, memberEmail: 'alan@example.test'),
    );
    $members = [
        new MemberStub(personalEmail: 'zoe@example.test', anonymousName: 'Alan B.'),
        new MemberStub(personalEmail: 'alan@example.test', anonymousName: 'Zoe T.'),
    ];

    $_GET = ['orderby' => 'responder', 'order' => 'desc'];

    $html = ($this->renderList)(($this->page)(devices: $devices, members: $members));

    $this->assertLessThan(
        strpos($html, 'Alan B.'),
        strpos($html, 'Zoe T.'),
        'descending by Responder should put Zoe T. above Alan B.',
    );
});

test('sorting by responder asks the repository for no ordering of its own', function () {
    // The ordering cannot happen in SQL, so the read is the whole
    // table in the repository's default order and the sort happens
    // after it. Asking for member_email here is what this replaced.
    $devices = new PagingDeviceRepository();
    $devices->total = 2;

    $_GET = ['orderby' => 'responder', 'order' => 'asc'];

    ($this->renderList)(($this->page)(devices: $devices));

    $this->assertSame([['orderBy' => '', 'order' => 'desc']], $devices->sorting);
    $this->assertSame([['limit' => 500, 'offset' => 0]], $devices->paging);
});

test('a sortable handset column is offered as a link in the header', function () {
    $html = ($this->renderList)(($this->page)());

    $this->assertStringContainsString('orderby=enrolled', $html);
    $this->assertStringContainsString('orderby=responder', $html);
});

// ── the recent-alerts table ───────────────────────────────────────
test('a recent alert shows its kind source and title', function () {
    $alerts = new InMemoryAlertRepository();
    $alerts->create(($this->alertRequest)(), ($this->createdAt)());

    $html = ($this->renderList)(($this->page)(alerts: $alerts));

    $this->assertStringContainsString('call_request', $html);
    $this->assertStringContainsString('reach', $html);
    $this->assertStringContainsString('Callback wanted', $html);
});

test('the level column shows the level', function () {
    $alerts = new InMemoryAlertRepository();
    $alerts->create(($this->alertRequest)(priority: 'urgent'), ($this->createdAt)());

    $this->assertStringContainsString('>red<', ($this->renderList)(($this->page)(alerts: $alerts)));
});

test('an alert that named no level shows as yellow', function () {
    $alerts = new InMemoryAlertRepository();
    $alerts->create(($this->alertRequest)(), ($this->createdAt)());

    $html = ($this->renderList)(($this->page)(alerts: $alerts));

    $this->assertStringContainsString('>yellow<', $html);
    $this->assertStringNotContainsString('>red<', $html);
});

test('the response column says whether somebody has to take it on', function () {
    // The column answers "will this clear off the rest of the rota
    // when somebody picks it up", which is not a thing the level says.
    $alerts = new InMemoryAlertRepository();
    $alerts->create(($this->alertRequest)(), ($this->createdAt)());
    $alerts->create(
        ($this->alertRequest)(extra: ['response' => Alert::RESPONSE_NONE]),
        ($this->createdAt)(),
    );

    $html = ($this->renderList)(($this->page)(alerts: $alerts));

    $this->assertStringContainsString('First to respond', $html);
    $this->assertStringContainsString('Everyone closes', $html);
});

test('an unacknowledged alert says nobody yet', function () {
    // The answer to "did this reach anybody", which is the whole
    // reason the table is on this screen.
    $alerts = new InMemoryAlertRepository();
    $alerts->create(($this->alertRequest)(), ($this->createdAt)());

    $this->assertStringContainsString('Nobody yet', ($this->renderList)(($this->page)(alerts: $alerts)));
});

test('an acknowledged alert names who answered it', function () {
    $alerts = new InMemoryAlertRepository();
    $alert = $alerts->create(($this->alertRequest)(), ($this->createdAt)());
    $alerts->acknowledge($alert->id, 7, 'jo@example.test', ($this->createdAt)() + 30);

    $html = ($this->renderList)(($this->page)(
        alerts: $alerts,
        members: [new MemberStub(personalEmail: 'jo@example.test', anonymousName: 'Jo M.')],
    ));

    $this->assertStringContainsString('Jo M.', $html);
    $this->assertStringNotContainsString('Nobody yet', $html);
});

test('an acknowledging responder is linked to their member record', function () {
    // "Who answered this" is only half the question; the other half
    // is "and who are they". Same link the Responder column carries.
    $alerts = new InMemoryAlertRepository();
    $alert = $alerts->create(($this->alertRequest)(), ($this->createdAt)());
    $alerts->acknowledge($alert->id, 7, 'jo@example.test', ($this->createdAt)() + 30);

    $html = ($this->renderList)(($this->page)(
        alerts: $alerts,
        members: [new MemberStub(id: 7, personalEmail: 'jo@example.test', anonymousName: 'Jo M.')],
    ));

    $this->assertStringContainsString('post.php?post=7', $html);
    $this->assertStringContainsString('>Jo M.</a>', $html);
});

test('an acknowledging responder the admin cannot edit is named without a link', function () {
    when('get_edit_post_link')->justReturn(null);

    $alerts = new InMemoryAlertRepository();
    $alert = $alerts->create(($this->alertRequest)(), ($this->createdAt)());
    $alerts->acknowledge($alert->id, 7, 'jo@example.test', ($this->createdAt)() + 30);

    $html = ($this->renderList)(($this->page)(
        alerts: $alerts,
        members: [new MemberStub(id: 7, personalEmail: 'jo@example.test', anonymousName: 'Jo M.')],
    ));

    $this->assertStringContainsString('Jo M.', $html);
    $this->assertStringNotContainsString('>Jo M.</a>', $html);
});

test('an acknowledgement from someone unity does not know is not linked', function () {
    $alerts = new InMemoryAlertRepository();
    $alert = $alerts->create(($this->alertRequest)(), ($this->createdAt)());
    $alerts->acknowledge($alert->id, 7, 'stranger@example.test', ($this->createdAt)() + 30);

    $html = ($this->renderList)(($this->page)(alerts: $alerts, members: []));

    $this->assertStringContainsString('stranger@example.test', $html);
    $this->assertStringNotContainsString('>stranger@example.test</a>', $html);
});

test('two responders sharing an anonymous name are both listed', function () {
    // Deduplication is by address, not by name: two people who happen
    // to be called the same thing are two answers, and they link to
    // two different records.
    $alerts = new InMemoryAlertRepository();
    $alert = $alerts->create(($this->alertRequest)(), ($this->createdAt)());
    $alerts->acknowledge($alert->id, 7, 'jo.b@example.test', ($this->createdAt)() + 30);
    $alerts->acknowledge($alert->id, 8, 'jo.c@example.test', ($this->createdAt)() + 40);

    $html = ($this->renderList)(($this->page)(
        alerts: $alerts,
        members: [
            new MemberStub(id: 7, personalEmail: 'jo.b@example.test', anonymousName: 'Jo M.'),
            new MemberStub(id: 8, personalEmail: 'jo.c@example.test', anonymousName: 'Jo M.'),
        ],
    ));

    $this->assertSame(2, substr_count($html, '>Jo M.</a>'));
    $this->assertStringContainsString('post.php?post=7', $html);
    $this->assertStringContainsString('post.php?post=8', $html);
});

test('sorting by acknowledged by ignores the markup around the names', function () {
    // The linked names would otherwise all sort under "<", leaving
    // the unanswered alerts and the unlinked strangers in blocks of
    // their own instead of under the text the column shows.
    $alerts = new InMemoryAlertRepository();
    $zoe = $alerts->create(($this->alertRequest)(kind: 'first'), ($this->createdAt)());
    $ann = $alerts->create(($this->alertRequest)(kind: 'second'), ($this->createdAt)() + 60);
    $alerts->acknowledge($zoe->id, 7, 'zoe@example.test', ($this->createdAt)() + 30);
    $alerts->acknowledge($ann->id, 8, 'ann@example.test', ($this->createdAt)() + 90);

    $_GET = ['orderby' => 'acknowledged', 'order' => 'asc'];

    $html = ($this->renderList)(($this->page)(
        alerts: $alerts,
        members: [
            new MemberStub(id: 7, personalEmail: 'zoe@example.test', anonymousName: 'Zoe T.'),
            new MemberStub(id: 8, personalEmail: 'ann@example.test', anonymousName: 'Ann B.'),
        ],
    ));

    $this->assertLessThan(
        strpos($html, '>Zoe T.</a>'),
        strpos($html, '>Ann B.</a>'),
        'ascending by Acknowledged by should put Ann B. above Zoe T.',
    );
});

test('one responder answering on two handsets is named once', function () {
    // The same person acknowledging from a phone and a tablet is one
    // answer, not two.
    $alerts = new InMemoryAlertRepository();
    $alert = $alerts->create(($this->alertRequest)(), ($this->createdAt)());
    $alerts->acknowledge($alert->id, 7, 'jo@example.test', ($this->createdAt)() + 30);
    $alerts->acknowledge($alert->id, 8, 'jo@example.test', ($this->createdAt)() + 40);

    $html = ($this->renderList)(($this->page)(
        alerts: $alerts,
        members: [new MemberStub(personalEmail: 'jo@example.test', anonymousName: 'Jo M.')],
    ));

    $this->assertSame(1, substr_count($html, 'Jo M.'));
});

test('an acknowledgement from someone unity does not know falls back to the email', function () {
    $alerts = new InMemoryAlertRepository();
    $alert = $alerts->create(($this->alertRequest)(), ($this->createdAt)());
    $alerts->acknowledge($alert->id, 7, 'stranger@example.test', ($this->createdAt)() + 30);

    $this->assertStringContainsString(
        'stranger@example.test',
        ($this->renderList)(($this->page)(alerts: $alerts, members: [])),
    );
});

test('the alerts table sorts the window it shows rather than the whole table', function () {
    // Pushing this sort down to the database would apply it before
    // the limit, so sorting by title would answer with the alerts
    // whose titles start earliest in the alphabet rather than
    // reordering the ones on the screen.
    $alerts = new InMemoryAlertRepository();
    $alerts->create(($this->alertRequest)(kind: 'aardvark'), ($this->createdAt)());
    $alerts->create(($this->alertRequest)(kind: 'zebra'), ($this->createdAt)() + 60);

    $_GET = ['orderby' => 'kind', 'order' => 'asc'];

    $html = ($this->renderList)(($this->page)(alerts: $alerts));

    $this->assertLessThan(
        strpos($html, 'zebra'),
        strpos($html, 'aardvark'),
        'ascending by Kind should put aardvark above zebra',
    );
});

test('the alerts table ignores a sort that belongs to the handsets table', function () {
    $alerts = new InMemoryAlertRepository();
    $alerts->create(($this->alertRequest)(kind: 'aardvark'), ($this->createdAt)());
    $alerts->create(($this->alertRequest)(kind: 'zebra'), ($this->createdAt)() + 60);

    $_GET = ['orderby' => 'last_seen', 'order' => 'asc'];

    $html = ($this->renderList)(($this->page)(alerts: $alerts));

    $this->assertLessThan(
        strpos($html, 'aardvark'),
        strpos($html, 'zebra'),
        'the repository order — newest first — should survive untouched',
    );
});

// ── sending is its own capability ─────────────────────────────────
test('a reader who cannot send is offered no send form', function () {
    // Buttons that answer 403 are worse than no buttons. The handler
    // checks again anyway — what the page rendered is not a guard.
    WpState::$deniedCaps = [Capabilities::SEND_ALERTS];

    $html = ($this->renderList)(($this->page)(devices: ($this->devicesWith)(($this->device)(id: 7))));

    $this->assertStringNotContainsString('reach-handset-actions', $html);
    $this->assertStringNotContainsString('name="reach_subject"', $html);
    $this->assertStringNotContainsString('Send a test to every live handset', $html);
});

test('a reader who cannot send is offered no tick boxes', function () {
    // The tick column exists to choose who a send goes to. Without
    // the send form it is a column of controls wired to nothing.
    WpState::$deniedCaps = [Capabilities::SEND_ALERTS];

    $html = ($this->renderList)(($this->page)(devices: ($this->devicesWith)(($this->device)(id: 7))));

    $this->assertStringNotContainsString('name="device_ids[]"', $html);
    $this->assertStringNotContainsString('reach-device-select', $html);
});

test('a reader who cannot send still sees the handsets', function () {
    // Reading the screen is a personal-data read and stays on
    // Scrutiny's view capability; only sending moved.
    WpState::$deniedCaps = [Capabilities::SEND_ALERTS];

    $html = ($this->renderList)(($this->page)(
        devices: ($this->devicesWith)(($this->device)(id: 7, label: 'Duty handset')),
        members: [new MemberStub(id: 7, personalEmail: 'jo@example.test', anonymousName: 'Jo M.')],
    ));

    $this->assertStringContainsString('Duty handset', $html);
    $this->assertStringContainsString('Jo M.', $html);
    $this->assertStringContainsString('Revoke</button>', $html);
});

test('a test alert is refused without the send capability', function () {
    WpState::$deniedCaps = [Capabilities::SEND_ALERTS];
    $alerts = new InMemoryAlertRepository();

    try {
        ($this->page)(alerts: $alerts)->handleTestAlert();
        $this->fail('expected wp_die() for a user who may read but not send');
    } catch (WpDieException) {
        $this->assertSame([], $alerts->alerts);
    }
});

test('revoking is not affected by the send capability', function () {
    // Three separate powers over the same screen: reading it, ringing
    // the handsets on it, and cutting one off. Losing one must not
    // take another with it.
    WpState::$deniedCaps = [Capabilities::SEND_ALERTS];
    $devices = ($this->devicesWith)(($this->device)(id: 7));
    $_POST = ['device_id' => '7'];

    $target = revokeFromRequest(($this->page)(devices: $devices));

    $this->assertStringContainsString('reach_result=revoked', $target);
});

test('a reader who cannot manage is offered no revoke or remove', function () {
    // The actions column holds nothing else, so it goes with them
    // rather than sitting there empty under a blank heading.
    WpState::$deniedCaps = [Capabilities::MANAGE_DEVICES];

    $html = ($this->renderList)(($this->page)(devices: ($this->devicesWith)(($this->device)(id: 7))));

    $this->assertStringNotContainsString('Revoke</button>', $html);
    $this->assertStringNotContainsString('Remove</button>', $html);
    $this->assertStringNotContainsString('reach_revoke_device', $html);
});

test('a reader who cannot manage still sees the handsets', function () {
    WpState::$deniedCaps = [Capabilities::MANAGE_DEVICES];

    $html = ($this->renderList)(($this->page)(
        devices: ($this->devicesWith)(($this->device)(id: 7, label: 'Duty handset')),
        members: [new MemberStub(id: 7, personalEmail: 'jo@example.test', anonymousName: 'Jo M.')],
    ));

    $this->assertStringContainsString('Duty handset', $html);
    $this->assertStringContainsString('Jo M.', $html);
    $this->assertStringContainsString('Live', $html);
});

test('managing is not affected by the send capability', function () {
    // The mirror of revoking_is_not_affected_by_the_send_capability:
    // someone who may ring handsets need not be able to cut one off.
    WpState::$deniedCaps = [Capabilities::MANAGE_DEVICES];
    $devices = ($this->devicesWith)(($this->device)(id: 7));
    $_POST = ['reach_scope_all' => 'Send test to all live handsets'];
    $alerts = new InMemoryAlertRepository();

    $target = testAlertFromRequest(($this->page)(devices: $devices, alerts: $alerts));

    $this->assertStringContainsString('reach_result=test_sent', $target);
    $this->assertCount(1, $alerts->alerts);
});

test('the test alert form and its handler agree on one nonce', function () {
    // The nonce is named for the screen's actions rather than for the
    // test alert, because it once covered the custom message too.
    // When the handler went on verifying its own action name after
    // the form moved to the shared nonce, every test alert died on
    // WordPress's "Are you sure you want to do this?" screen.
    //
    // Nothing caught it, because the shared stubs answer
    // check_admin_referer() true whatever action they are handed --
    // a nonce mismatch is invisible to a test that only checks the
    // outcome. This one watches the argument instead.
    // The shared stub renders a nonce field as value="nonce-<action>",
    // so the action the form issued is readable straight off the page.
    $html = ($this->renderList)(($this->page)(devices: ($this->devicesWith)(($this->device)(id: 7))));

    $verified = [];
    when('check_admin_referer')->alias(
        static function (string $action = '', string $name = '_wpnonce') use (&$verified): bool {
            $verified[] = $action;

            return true;
        },
    );

    $_POST = ['reach_scope_all' => 'Send test to all live handsets'];
    testAlertFromRequest(($this->page)());

    $this->assertSame(['reach_handset_actions'], $verified);
    $this->assertStringContainsString(
        'value="nonce-reach_handset_actions"',
        $html,
        'the test-alert form must issue the nonce its handler verifies',
    );
});

test('notices are plain text because they are escaped on the way out', function () {
    // The whole string goes through esc_html(), so an HTML entity
    // written into the table arrives as the literal characters.
    $_GET = ['reach_result' => 'revoke_failed'];

    $html = ($this->renderList)(($this->page)());

    $this->assertStringNotContainsString('&amp;mdash;', $html);
    $this->assertStringContainsString('could not be revoked', $html);
});

// ── the Recent alerts refresh ─────────────────────────────────────
test('the recent alerts table is wrapped for refreshing on its own', function () {
    // Reloading the whole screen every five seconds would throw away
    // the handset selection and wherever the admin had scrolled to.
    $html = ($this->renderList)(($this->page)());

    $this->assertStringContainsString('id="reach-recent-alerts"', $html);
    $this->assertStringContainsString('data-action="reach_recent_alerts"', $html);
    $this->assertStringContainsString('data-nonce="', $html);
});

test('the refresh answers with the alerts table and nothing else', function () {
    $alerts = new InMemoryAlertRepository();
    $alerts->create(($this->alertRequest)(), ($this->createdAt)());

    $html = recentAlertsFragment(($this->page)(alerts: $alerts));

    $this->assertStringContainsString('call_request', $html);
    $this->assertStringContainsString('reach-alerts', $html);
    $this->assertStringNotContainsString('Enrolled handsets', $html, 'the handsets table is not part of it');
    $this->assertStringNotContainsString('reach_scope', $html, 'nor is the test-alert form');
});

test('the refreshed table links its sort back to the screen not to admin ajax', function () {
    // Core builds the sort links from REQUEST_URI, which during an
    // admin-ajax request is admin-ajax.php — so without help the first
    // refresh would leave headers pointing at a bare fragment.
    $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php?action=reach_recent_alerts';

    recentAlertsFragment(($this->page)());

    $this->assertStringContainsString('page=reach-devices', $_SERVER['REQUEST_URI']);
    $this->assertStringNotContainsString('admin-ajax', $_SERVER['REQUEST_URI']);
});

test('the refresh keeps whatever sort the screen is showing', function () {
    $_GET = ['orderby' => 'kind', 'order' => 'asc'];
    $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php?action=reach_recent_alerts';

    recentAlertsFragment(($this->page)());

    $this->assertStringContainsString('orderby=kind', $_SERVER['REQUEST_URI']);
    $this->assertStringContainsString('order=asc', $_SERVER['REQUEST_URI']);
});

test('refreshing without the personal data capability dies', function () {
    // The table names the responders who acknowledged, so the
    // fragment is gated exactly as the screen is.
    WpState::$deniedCaps = [PersonalDataPolicy::VIEW_CAPABILITY];

    $this->expectException(WpDieException::class);

    ($this->page)()->handleRecentAlerts();
});

// ── notices ───────────────────────────────────────────────────────
test('the result of the last action is reported', function (string $result, string $class, string $text) {
    $_GET = ['reach_result' => $result];

    $html = ($this->renderList)(($this->page)());

    $this->assertStringContainsString('notice-' . $class, $html);
    $this->assertStringContainsString($text, $html);
})->with('notices');

/** @return array<string, array{0: string, 1: string, 2: string}> */
dataset('notices', function (): array {
    return [
        'revoked'       => ['revoked', 'success', 'Handset revoked.'],
        'revoke failed' => ['revoke_failed', 'error', 'could not be revoked'],
        'removed'       => ['removed', 'success', 'Handset removed.'],
        'remove failed' => ['remove_failed', 'error', 'could not be removed'],
        'test sent'     => ['test_sent', 'success', 'Every live handset should be ringing.'],
        'test sent to a selection' => ['test_sent_selected', 'success', 'The selected handsets should be ringing.'],
        'nothing selected' => ['test_none_selected', 'warning', 'Tick at least one live handset'],
        'test failed'   => ['test_failed', 'error', 'could not be sent'],
    ];
});

test('an unrecognised result shows no notice', function (mixed $result) {
    $_GET = ['reach_result' => $result];

    $this->assertStringNotContainsString('is-dismissible', ($this->renderList)(($this->page)()));
})->with('unknownResults');

/** @return array<string, array{0: mixed}> */
dataset('unknownResults', function (): array {
    return [
        'invented'   => ['something_else'],
        'empty'      => [''],
        'not a string' => [['revoked']],
    ];
});

test('no notice is shown on a plain visit', function () {
    $this->assertStringNotContainsString('is-dismissible', ($this->renderList)(($this->page)()));
});

// ── revoking ──────────────────────────────────────────────────────
test('revoking cuts the handset off and reports it', function () {
    $_POST = ['device_id' => '7'];
    $devices = ($this->devicesWith)(($this->device)(id: 7));

    $target = revokeFromRequest(($this->page)(devices: $devices));

    $this->assertTrue($devices->findById(7)?->isRevoked());
    $this->assertStringContainsString('reach_result=revoked', $target);
    $this->assertStringContainsString('page=' . DevicesPage::PAGE_SLUG, $target);
});

test('a revoked handset stops appearing in the broadcast list', function () {
    // The point of the button: the handset is cut off immediately
    // rather than at the next eligibility re-check.
    $_POST = ['device_id' => '7'];
    $devices = ($this->devicesWith)(($this->device)(id: 7));
    $this->assertCount(1, $devices->findAllLive());

    revokeFromRequest(($this->page)(devices: $devices));

    $this->assertSame([], $devices->findAllLive());
});

test('revoking an already revoked handset reports the failure', function () {
    $_POST = ['device_id' => '7'];
    $devices = ($this->devicesWith)(($this->device)(id: 7, revokedAt: revokedAt()));

    $target = revokeFromRequest(($this->page)(devices: $devices));

    $this->assertStringContainsString('reach_result=revoke_failed', $target);
});

/**
 * @param array<string, string> $post
 */
test('a revoke without a usable device id touches nothing', function (array $post) {
    $_POST = $post;
    $devices = ($this->devicesWith)(($this->device)(id: 7));

    $target = revokeFromRequest(($this->page)(devices: $devices));

    $this->assertStringContainsString('reach_result=revoke_failed', $target);
    $this->assertFalse($devices->findById(7)?->isRevoked());
})->with('missingDeviceIds');

/** @return array<string, array{0: array<string, string>}> */
dataset('missingDeviceIds', function (): array {
    return [
        'absent'   => [[]],
        'zero'     => [['device_id' => '0']],
        'negative' => [['device_id' => '-3']],
        'words'    => [['device_id' => 'nonsense']],
    ];
});

test('revoking an unknown handset reports the failure', function () {
    $_POST = ['device_id' => '999'];

    $target = revokeFromRequest(($this->page)(devices: ($this->devicesWith)(($this->device)(id: 7))));

    $this->assertStringContainsString('reach_result=revoke_failed', $target);
});

// ── the test alert ────────────────────────────────────────────────
test('the test alert is a real alert through the real path', function () {
    // Nothing about it is special-cased — that is what makes it worth
    // anything as a check of the delivery chain.
    ($this->signedInAs)('Site Admin');
    $alerts = new InMemoryAlertRepository();

    $_POST = ['reach_scope_all' => 'Send test to all live handsets'];
            $target = testAlertFromRequest(($this->page)(alerts: $alerts));

    $this->assertCount(1, $alerts->alerts);
    $this->assertSame('test', $alerts->alerts[0]->kind);
    $this->assertSame('reach', $alerts->alerts[0]->source);
    $this->assertSame('Hand test alert', $alerts->alerts[0]->title);
    $this->assertStringContainsString('reach_result=test_sent', $target);
});

test('the test alert is a red broadcast nobody has to take on', function () {
    // Red because the whole value of a test is exercising the loudest
    // path there is; informational because its own body says no action
    // is needed, so the handset offers Close rather than Acknowledge.
    ($this->signedInAs)('Site Admin');
    $alerts = new InMemoryAlertRepository();

    $_POST = ['reach_scope_all' => 'Send test to all live handsets'];
            testAlertFromRequest(($this->page)(alerts: $alerts));

    $this->assertTrue($alerts->alerts[0]->isBroadcast());
    $this->assertSame(Alert::LEVEL_RED, $alerts->alerts[0]->level);
    $this->assertTrue($alerts->alerts[0]->isInformational());
});

test('the test alert expires in five minutes', function () {
    // A test still ringing handsets ten minutes later is a nuisance;
    // its only job is to arrive now.
    ($this->signedInAs)('Site Admin');
    $alerts = new InMemoryAlertRepository();

    $_POST = ['reach_scope_all' => 'Send test to all live handsets'];
            testAlertFromRequest(($this->page)(alerts: $alerts));

    $alert = $alerts->alerts[0];
    $this->assertSame(300, $alert->expiresAt - $alert->createdAt);
});

test('the test alert names the admin who sent it', function () {
    ($this->signedInAs)('Site Admin');
    $alerts = new InMemoryAlertRepository();

    $_POST = ['reach_scope_all' => 'Send test to all live handsets'];
            testAlertFromRequest(($this->page)(alerts: $alerts));

    $this->assertStringContainsString('Site Admin', $alerts->alerts[0]->body);
});

test('an admin with no display name is described generically', function () {
    ($this->signedInAs)('');
    $alerts = new InMemoryAlertRepository();

    $_POST = ['reach_scope_all' => 'Send test to all live handsets'];
            testAlertFromRequest(($this->page)(alerts: $alerts));

    $this->assertStringContainsString('an administrator', $alerts->alerts[0]->body);
});

test('the test alert carries no personal data', function () {
    // Its text travels through Google's push infrastructure and onto
    // a lock screen, so it says who sent it and nothing else.
    ($this->signedInAs)('Site Admin');
    $alerts = new InMemoryAlertRepository();

    $_POST = ['reach_scope_all' => 'Send test to all live handsets'];
            testAlertFromRequest(($this->page)(alerts: $alerts));

    $alert = $alerts->alerts[0];
    $this->assertSame('', $alert->reference);
    $this->assertSame([], $alert->payload);
    $this->assertStringNotContainsString('@', $alert->body);
});

// ── selecting handsets ────────────────────────────────────────────
test('a live handset can be ticked for a test', function () {
    // Bound to the test form by its `form` attribute rather than by
    // nesting: the row already carries its own Revoke and Remove
    // forms, and a form inside a form is not parseable.
    $page = ($this->page)(devices: ($this->devicesWith)(($this->device)(id: 7)));

    $html = ($this->renderList)($page);

    $this->assertStringContainsString('name="device_ids[]"', $html);
    $this->assertStringContainsString('form="reach-handset-actions"', $html);
    $this->assertStringContainsString('value="7"', $html);
});

test('a revoked handset has no checkbox', function () {
    // Nothing to test on a handset that has already been cut off.
    $page = ($this->page)(devices: ($this->devicesWith)(
        ($this->device)(id: 7, revokedAt: revokedAt()),
    ));

    $this->assertStringNotContainsString('name="device_ids[]"', ($this->renderList)($page));
});

test('the page offers both test scopes in the table toolbar', function () {
    // Both live in the tablenav now, in WordPress's own shape: the
    // selection as a bulk action, the broadcast as a button beside it.
    $html = ($this->renderList)(($this->page)(devices: ($this->devicesWith)(($this->device)(id: 7))));

    $this->assertStringContainsString('class="alignleft actions bulkactions"', $html);
    $this->assertStringContainsString('value="reach_test"', $html);
    $this->assertStringContainsString('Send test alert', $html);
    $this->assertStringContainsString('name="reach_scope_all"', $html);
    $this->assertStringContainsString('Send test to all live handsets', $html);
});

test('no toolbar control is named action because admin post routes on it', function () {
    // admin-post.php picks its handler from $_REQUEST['action'], and
    // POST beats the query string. So a control called `action` in
    // this form hijacks the routing of the form it sits in: pressing
    // anything sent the request to admin_post_reach_test, or to
    // admin_post_-1 with the dropdown left alone, and WordPress died
    // with no handler to run. Core names it `action` safely only
    // because its list screens post to themselves.
    //
    // The row forms do use a hidden `action` — that is how they pick
    // their own handler — so this looks at the toolbar only.
    $html = ($this->renderList)(($this->page)(devices: ($this->devicesWith)(($this->device)(id: 7))));

    $toolbar = substr($html, 0, strpos($html, '<table') ?: strlen($html));

    $this->assertStringNotContainsString('name="action"', $toolbar);
    $this->assertStringNotContainsString('name="action2"', $toolbar);
    $this->assertStringContainsString('name="reach_bulk"', $toolbar);
});

test('the table controls are bound to the form they are not inside', function () {
    // The rows carry POST forms of their own for Revoke and Remove, so
    // the table cannot be wrapped in one. Every control that has to
    // submit therefore names the form instead — and a control that
    // lost that attribute would silently submit nothing.
    $html = ($this->renderList)(($this->page)(devices: ($this->devicesWith)(($this->device)(id: 7))));

    $bound = substr_count($html, 'form="' . 'reach-handset-actions"');

    $this->assertGreaterThanOrEqual(
        4,
        $bound,
        'the dropdown, its Apply button, the broadcast button and the tick boxes all need it',
    );
});

test('a handset keeps its lock screen report through every other write', function () {
    // The repository updates one column at a time, so nothing it does
    // can drop this. The in-memory double rebuilds the whole Device
    // and can, which is exactly the sort of difference that lets a
    // test pass against behaviour the site does not have — it had
    // already lost keyFaultAt this way.
    $devices = ($this->devicesWith)(($this->device)(id: 7));
    $devices->recordLockScreen(7, Device::LOCK_SCREEN_SHOWN);

    $devices->touch(7, 2_000);
    $devices->updatePushToken(7, Device::PUSH_FCM, 'rotated');
    $devices->markKeyFault(7, 3_000);

    $device = $devices->findById(7);

    $this->assertNotNull($device);
    $this->assertTrue($device->showsAlertsOnLockScreen(), 'the report must survive every other write');
    $this->assertTrue($device->hasKeyFault(), 'and so must the key fault');
});

// ── the handsets refresh ──────────────────────────────────────────
test('the handsets table is wrapped for swapping on its own', function () {
    // Reloading the screen to sort would throw away the handsets an
    // admin has just ticked, which is the whole point of the selection.
    $html = ($this->renderList)(($this->page)());

    $this->assertStringContainsString('id="reach-handsets"', $html);
    $this->assertStringContainsString('data-action="reach_handsets"', $html);
});

test('the refresh answers with the handsets table and nothing else', function () {
    $devices = ($this->devicesWith)(($this->device)(id: 7, label: 'Duty phone'));

    $html = handsetsFragment(($this->page)(devices: $devices));

    $this->assertStringContainsString('Duty phone', $html);
    $this->assertStringNotContainsString('Recent alerts', $html, 'the alerts table is not part of it');
    $this->assertStringNotContainsString('<h1>', $html, 'nor is the rest of the screen');
});

test('the refresh is refused without the personal data capability', function () {
    WpState::$deniedCaps = [PersonalDataPolicy::VIEW_CAPABILITY];

    $this->expectException(WpDieException::class);
    ($this->page)()->handleHandsets();
});

test('the fragment points its links back at the screen not at admin ajax', function () {
    // Core builds every sort and page link out of REQUEST_URI, which
    // during an admin-ajax request is admin-ajax.php. Left alone, the
    // first sort would replace the table with one whose links answer
    // with a bare fragment instead of the screen. This asserts the URI
    // the fragment installs rather than the links core makes from it —
    // the list-table stub does not reproduce core's link building, so
    // asserting on the rendered hrefs would be asserting on the stub.
    $_GET = ['orderby' => 'platform', 'order' => 'asc', 'paged' => '3', 's' => 'bristol'];

    $uri = handsetsUri(($this->page)());

    $this->assertStringContainsString('page=reach-devices', $uri);
    $this->assertStringNotContainsString('admin-ajax', $uri);
});

test('the fragment carries the sort page and search through', function () {
    // A sort that dropped the search would silently widen the list
    // back to every handset, and a search that dropped the page would
    // send an admin back to the first one on every click.
    $_GET = ['orderby' => 'platform', 'order' => 'asc', 'paged' => '3', 's' => 'bristol'];

    $uri = handsetsUri(($this->page)());

    $this->assertStringContainsString('orderby=platform', $uri);
    $this->assertStringContainsString('order=asc', $uri);
    $this->assertStringContainsString('paged=3', $uri);
    $this->assertStringContainsString('s=bristol', $uri);
});

// ── search ────────────────────────────────────────────────────────
test('the table offers a search box', function () {
    $html = ($this->renderList)(($this->page)(devices: ($this->devicesWith)(($this->device)(id: 7))));

    $this->assertStringContainsString('class="search-box"', $html);
    $this->assertStringContainsString('name="s"', $html);
});

test('a search narrows the list to matching handsets', function () {
    $devices = ($this->devicesWith)(
        ($this->device)(id: 7, memberEmail: 'jo@example.test', label: 'Duty phone'),
        ($this->device)(id: 8, memberEmail: 'sam@example.test', label: 'Spare tablet'),
    );
    $_GET = ['s' => 'sam'];

    $html = ($this->renderList)(($this->page)(devices: $devices));

    $this->assertStringContainsString('Spare tablet', $html);
    $this->assertStringNotContainsString('Duty phone', $html);
});

test('a search that matches nothing says which kind of empty it is', function () {
    // An intergroup with no handsets at all has a setup problem; one
    // whose search matched nothing has a typo. Saying "no handsets
    // enrolled" to the second is a small lie that wastes someone's
    // afternoon.
    $_GET = ['s' => 'nobody'];

    $html = ($this->renderList)(($this->page)(devices: ($this->devicesWith)(($this->device)(id: 7))));

    $this->assertStringContainsString('No handsets match that search.', $html);
    $this->assertStringNotContainsString('No handsets have been enrolled yet.', $html);
});

test('the search term reaches the repository rather than being filtered after', function () {
    // Filtering a page after fetching it would page over the whole
    // table and then show a short page, with a count that described
    // neither.
    $devices = new PagingDeviceRepository();
    $devices->total = 3;
    $_GET = ['s' => 'bristol'];

    ($this->renderList)(($this->page)(devices: $devices));

    $this->assertContains('bristol', $devices->searches);
});

// ── the scoped test alert ─────────────────────────────────────────
test('a selected handset gets an alert addressed to it alone', function () {
    // The point of the selection: ringing one phone on its own is the
    // only way to find out which handset is deaf.
    ($this->signedInAs)('Site Admin');
    $alerts = new InMemoryAlertRepository();
    $_POST = ['reach_bulk' => 'reach_test', 'device_ids' => ['7']];

    $target = testAlertFromRequest(($this->page)(
        devices: ($this->devicesWith)(($this->device)(id: 7)),
        alerts: $alerts,
    ));

    $this->assertCount(1, $alerts->alerts);
    $this->assertSame(7, $alerts->alerts[0]->targetDeviceId);
    $this->assertFalse($alerts->alerts[0]->isBroadcast());
    $this->assertStringContainsString('reach_result=test_sent_selected', $target);
});

test('each selected handset gets its own alert', function () {
    // One alert per handset, not one shared between them: each then
    // carries its own acknowledgement, so a silent phone cannot hide
    // behind a colleague's answer.
    ($this->signedInAs)('Site Admin');
    $alerts = new InMemoryAlertRepository();
    $_POST = ['reach_bulk' => 'reach_test', 'device_ids' => ['7', '8']];

    testAlertFromRequest(($this->page)(
        devices: ($this->devicesWith)(($this->device)(id: 7), ($this->device)(id: 8)),
        alerts: $alerts,
    ));

    $this->assertCount(2, $alerts->alerts);
    $this->assertSame([7, 8], array_map(
        static fn($alert): int => $alert->targetDeviceId,
        $alerts->alerts,
    ));
});

test('a selected test is still a short lived test alert', function () {
    // Nothing about the scoped path is special-cased — same kind,
    // same lifetime, same lack of personal data.
    ($this->signedInAs)('Site Admin');
    $alerts = new InMemoryAlertRepository();
    $_POST = ['reach_bulk' => 'reach_test', 'device_ids' => ['7']];

    testAlertFromRequest(($this->page)(
        devices: ($this->devicesWith)(($this->device)(id: 7)),
        alerts: $alerts,
    ));

    $alert = $alerts->alerts[0];
    $this->assertSame('test', $alert->kind);
    $this->assertSame(300, $alert->expiresAt - $alert->createdAt);
    $this->assertStringNotContainsString('@', $alert->body);
});

test('ticking handsets and pressing broadcast still broadcasts', function () {
    // The button says what it does. Inferring the scope from whatever
    // happens to be ticked would make the broadcast button lie.
    ($this->signedInAs)('Site Admin');
    $alerts = new InMemoryAlertRepository();
    $_POST = ['reach_scope_all' => 'Send test to all live handsets', 'device_ids' => ['7']];

    $target = testAlertFromRequest(($this->page)(
        devices: ($this->devicesWith)(($this->device)(id: 7)),
        alerts: $alerts,
    ));

    $this->assertCount(1, $alerts->alerts);
    $this->assertSame(0, $alerts->alerts[0]->targetDeviceId);
    $this->assertStringContainsString('reach_result=test_sent', $target);
});

test('sending to an empty selection raises nothing and says so', function () {
    ($this->signedInAs)('Site Admin');
    $alerts = new InMemoryAlertRepository();
    $_POST = ['reach_bulk' => 'reach_test'];

    $target = testAlertFromRequest(($this->page)(alerts: $alerts));

    $this->assertSame([], $alerts->alerts);
    $this->assertStringContainsString('reach_result=test_none_selected', $target);
});

test('an unusable selection raises nothing', function (mixed $posted) {
    // Every id is resolved against the repository rather than trusted
    // from the form: a posted id is only a row number a browser sent
    // back, and a revoked handset — which has no checkbox to tick —
    // must not become testable by editing one in.
    ($this->signedInAs)('Site Admin');
    $alerts = new InMemoryAlertRepository();
    $_POST = ['reach_bulk' => 'reach_test', 'device_ids' => $posted];

    $target = testAlertFromRequest(($this->page)(
        devices: ($this->devicesWith)(
            ($this->device)(id: 7),
            ($this->device)(id: 9, revokedAt: revokedAt()),
        ),
        alerts: $alerts,
    ));

    $this->assertSame([], $alerts->alerts);
    $this->assertStringContainsString('reach_result=test_none_selected', $target);
})->with('unusableSelections');

/** @return array<string, array{0: mixed}> */
dataset('unusableSelections', function (): array {
    return [
        'not an array'  => ['7'],
        'empty'         => [[]],
        'unknown id'    => [['999']],
        'a revoked row' => [['9']],
        'zero'          => [['0']],
        'negative'      => [['-3']],
        'words'         => [['nonsense']],
    ];
});

test('the same handset ticked twice is tested once', function () {
    ($this->signedInAs)('Site Admin');
    $alerts = new InMemoryAlertRepository();
    $_POST = ['reach_bulk' => 'reach_test', 'device_ids' => ['7', '7']];

    testAlertFromRequest(($this->page)(
        devices: ($this->devicesWith)(($this->device)(id: 7)),
        alerts: $alerts,
    ));

    $this->assertCount(1, $alerts->alerts);
});

// ── removing a handset ────────────────────────────────────────────
test('removing a handset notifies it and then deletes the row', function () {
    // Both halves, in that order. Once the row is gone the handset has
    // no token left to poll with, so a notice sent afterwards could
    // never arrive.
    $devices = ($this->devicesWith)(($this->device)(id: 7));
    $alerts = new InMemoryAlertRepository();
    $_POST = ['device_id' => '7'];

    $target = removeFromRequest(($this->page)(devices: $devices, alerts: $alerts));

    $this->assertCount(1, $alerts->alerts);
    $this->assertSame('device_removed', $alerts->alerts[0]->kind);
    $this->assertSame(7, $alerts->alerts[0]->targetDeviceId);
    $this->assertNull($devices->findById(7), 'the row is deleted, not revoked');
    $this->assertStringContainsString('reach_result=removed', $target);
});

test('the removal notice carries no personal data', function () {
    // Its text travels through Google's push infrastructure and onto a
    // lock screen, same as any other alert.
    $devices = ($this->devicesWith)(($this->device)(id: 7));
    $alerts = new InMemoryAlertRepository();
    $_POST = ['device_id' => '7'];

    removeFromRequest(($this->page)(devices: $devices, alerts: $alerts));

    $alert = $alerts->alerts[0];
    $this->assertStringNotContainsString('@', $alert->body);
    $this->assertSame('', $alert->reference);
    $this->assertSame([], $alert->payload);
    $this->assertFalse($alert->isUrgent());
});

test('a removed handset leaves no record behind', function () {
    // The difference from revoking, which keeps the row as history.
    $devices = ($this->devicesWith)(($this->device)(id: 7));
    $_POST = ['device_id' => '7'];

    removeFromRequest(($this->page)(devices: $devices));

    $this->assertSame(0, $devices->countAll());
    $this->assertSame([], $devices->findAllLive());
});

test('an already revoked handset can still be removed', function () {
    // Revoking and removing answer different questions, so having done
    // one must not block the other.
    $devices = ($this->devicesWith)(($this->device)(id: 7, revokedAt: revokedAt()));
    $_POST = ['device_id' => '7'];

    $target = removeFromRequest(($this->page)(devices: $devices));

    $this->assertNull($devices->findById(7));
    $this->assertStringContainsString('reach_result=removed', $target);
});

/**
 * @param array<string, string> $post
 */
test('a remove without a usable device id touches nothing', function (array $post) {
    $_POST = $post;
    $devices = ($this->devicesWith)(($this->device)(id: 7));
    $alerts = new InMemoryAlertRepository();

    $target = removeFromRequest(($this->page)(devices: $devices, alerts: $alerts));

    $this->assertStringContainsString('reach_result=remove_failed', $target);
    $this->assertNotNull($devices->findById(7));
    $this->assertSame([], $alerts->alerts, 'nothing is told about a removal that did not happen');
})->with('missingDeviceIds');

test('removing an unknown handset reports the failure', function () {
    $_POST = ['device_id' => '999'];
    $alerts = new InMemoryAlertRepository();

    $target = removeFromRequest(($this->page)(
        devices: ($this->devicesWith)(($this->device)(id: 7)),
        alerts: $alerts,
    ));

    $this->assertStringContainsString('reach_result=remove_failed', $target);
    $this->assertSame([], $alerts->alerts);
});

test('removing without the manage capability dies', function () {
    WpState::$deniedCaps = [Capabilities::MANAGE_DEVICES];

    $_POST = ['device_id' => '7'];
    $devices = ($this->devicesWith)(($this->device)(id: 7));

    try {
        ($this->page)(devices: $devices)->handleRemove();
        $this->fail('expected wp_die() for a user without the capability');
    } catch (WpDieException) {
        $this->assertNotNull($devices->findById(7), 'nothing may be removed behind the guard');
    }
});

test('every live row offers both revoke and remove', function () {
    $html = ($this->renderList)(($this->page)(devices: ($this->devicesWith)(($this->device)(id: 7))));

    $this->assertStringContainsString('Revoke</button>', $html);
    $this->assertStringContainsString('Remove</button>', $html);
});

test('a revoked row can be removed but not re revoked', function () {
    $html = ($this->renderList)(($this->page)(devices: ($this->devicesWith)(
        ($this->device)(id: 7, revokedAt: revokedAt()),
    )));

    $this->assertStringNotContainsString('Revoke</button>', $html);
    $this->assertStringContainsString('Remove</button>', $html);
});

/**
 * Records the paging it was asked for, so the page-number arithmetic can
 * be asserted on without seeding fifty rows.
 *
 * Implements the interface rather than extending the in-memory fixture,
 * which is final — and there is nothing to inherit here in any case,
 * since every method but list() is unreachable from this screen.
 */
final class PagingDeviceRepository implements DeviceRepository
{
    /** @var array<int, array{limit: int, offset: int}> */
    public array $paging = [];

    /** @var array<int, array{orderBy: string, order: string}> */
    public array $sorting = [];

    /** What countAll() reports — the Responder sort loops until it has this many. */
    public int $total = 0;

    /** @var array<int, string> Search terms this repository was asked for. */
    public array $searches = [];

    public function list(
        int $limit,
        int $offset,
        string $orderBy = '',
        string $order = 'desc',
        string $search = '',
    ): array {
        $this->paging[] = ['limit' => $limit, 'offset' => $offset];
        $this->sorting[] = ['orderBy' => $orderBy, 'order' => $order];
        $this->searches[] = $search;

        return [];
    }

    public function countAll(string $search = ''): int
    {
        return $this->total;
    }

    public function create(
        string $tokenHash,
        string $memberEmail,
        int $memberId,
        string $label,
        string $platform,
        string $pushProvider,
        string $pushToken,
        int $now,
        string $payloadKey = '',
    ): Device {
        throw new LogicException('not reachable from the devices screen');
    }

    public function payloadKeyFor(int $id): string
    {
        return '';
    }

    public function markKeyFault(int $id, int $now): bool
    {
        return false;
    }

    public function recordLockScreen(int $id, string $lockScreen): bool
    {
        return false;
    }

    public function findByTokenHash(string $tokenHash): ?Device
    {
        return null;
    }

    public function findById(int $id): ?Device
    {
        return null;
    }

    public function findByMemberEmail(string $memberEmail): array
    {
        return [];
    }

    public function findAllLive(): array
    {
        return [];
    }

    public function touch(int $id, int $now): bool
    {
        return false;
    }

    public function updatePushToken(int $id, string $pushProvider, string $pushToken): bool
    {
        return false;
    }

    public function revoke(int $id, int $now): bool
    {
        return false;
    }

    public function delete(int $id): bool
    {
        return false;
    }

    public function revokeAllForMember(string $memberEmail, int $now): int
    {
        return 0;
    }
}
