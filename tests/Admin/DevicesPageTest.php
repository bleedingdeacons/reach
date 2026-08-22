<?php

declare(strict_types=1);

namespace Reach\Tests\Admin;

use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use LogicException;
use Reach\Admin\DevicesPage;
use Reach\Alerts\AlertApi;
use Reach\Alerts\AlertDispatcher;
use Reach\Alerts\AlertRequest;
use Reach\Core\Capabilities;
use Reach\Devices\Device;
use Reach\Devices\DeviceRepository;
use Reach\Devices\ResponderGate;
use Reach\Tests\Fixtures\InMemoryAlertContactRepository;
use Reach\Tests\Fixtures\InMemoryAlertRepository;
use Reach\Tests\Fixtures\InMemoryDeviceRepository;
use Reach\Tests\Fixtures\MemberStub;
use Reach\Tests\ReachTestCase;
use ReflectionMethod;
use Scrutiny\Privacy\PersonalDataPolicy;
use Unity\Members\Interfaces\Member;
use Unity\Members\ResponderCertification;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use WP_User;

/**
 * Tests for the Hand devices admin screen.
 *
 * Same three techniques as the sibling pages: the list runs for real
 * inside an output buffer, the capability guards are plain
 * expectException because wp_die() throws, and the two POST handlers end
 * `wp_safe_redirect(); exit;` so their bodies were split into
 * revokeFromRequest()/testAlertFromRequest() and are driven through
 * those. That split is the only production change these tests needed and
 * it is behaviour-identical — the precedent is
 * {@see \Reach\Admin\CallRequestsPage::completeFromRequest()}.
 *
 * The screen shows which responder each handset belongs to, so it is
 * gated on {@see PersonalDataPolicy::VIEW_CAPABILITY} like the rest of
 * Reach's admin.
 *
 * @covers \Reach\Admin\DevicesPage
 */
final class DevicesPageTest extends ReachTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];

        parent::tearDown();
    }

    // ── registration ──────────────────────────────────────────────────

    /** @test */
    public function register_hooks_the_menu_and_both_post_handlers(): void
    {
        $this->page()->register();

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
            'admin_post_reach_send_message',
            false,
            'the custom-message buttons post to admin-post.php and need their handler hooked',
        );
        $this->assertActionAdded(
            'wp_ajax_reach_recent_alerts',
            false,
            'the Recent alerts table refreshes itself through admin-ajax',
        );
    }

    /** @test */
    public function add_menu_attaches_under_the_reach_menu_behind_the_personal_data_capability(): void
    {
        $this->page()->addMenu();

        $this->assertCount(1, WpState::$menus);
        $this->assertSame('submenu', WpState::$menus[0]['type']);
        $this->assertSame('reach', WpState::$menus[0]['parent']);
        $this->assertSame(DevicesPage::PAGE_SLUG, WpState::$menus[0]['slug']);
        $this->assertSame(PersonalDataPolicy::VIEW_CAPABILITY, WpState::$menus[0]['cap']);
    }

    // ── capability guards ─────────────────────────────────────────────

    /** @test */
    public function the_list_renders_nothing_without_the_personal_data_capability(): void
    {
        WpState::$deniedCaps = [PersonalDataPolicy::VIEW_CAPABILITY];

        $this->assertSame('', $this->renderList($this->page(devices: $this->devicesWith($this->device()))));
    }

    /** @test */
    public function revoking_without_the_personal_data_capability_dies(): void
    {
        WpState::$deniedCaps = [PersonalDataPolicy::VIEW_CAPABILITY];

        $_POST = ['device_id' => '7'];
        $devices = $this->devicesWith($this->device(id: 7));

        try {
            $this->page(devices: $devices)->handleRevoke();
            $this->fail('expected wp_die() for a user without the capability');
        } catch (WpDieException) {
            $this->assertFalse(
                $devices->findById(7)?->isRevoked(),
                'nothing may be revoked behind the guard',
            );
        }
    }

    // ── list rendering ────────────────────────────────────────────────

    /** @test */
    public function an_empty_list_says_so_for_both_tables(): void
    {
        $html = $this->renderList($this->page());

        $this->assertStringContainsString('No handsets have been enrolled yet.', $html);
        $this->assertStringContainsString('No alerts have been raised yet.', $html);
        $this->assertStringContainsString('0 in total.', $html);
    }

    /** @test */
    public function a_live_handset_shows_its_responder_platform_and_a_revoke_button(): void
    {
        $page = $this->page(
            devices: $this->devicesWith($this->device(id: 7, label: 'Duty handset')),
            members: [new MemberStub(personalEmail: 'jo@example.test', anonymousName: 'Jo M.')],
        );

        $html = $this->renderList($page);

        $this->assertStringContainsString('Jo M.', $html);
        $this->assertStringContainsString('Duty handset', $html);
        $this->assertStringContainsString('android', $html);
        $this->assertStringContainsString('Live', $html);
        $this->assertStringContainsString('name="device_id" value="7"', $html);
        $this->assertStringContainsString('Revoke</button>', $html);
    }

    /** @test */
    public function a_responder_name_links_to_their_member_record(): void
    {
        // The screen answers "whose handset is this"; the next question
        // is always "and who are they". Same link the call-attempts list
        // puts under a responder's name.
        $page = $this->page(
            devices: $this->devicesWith($this->device(memberEmail: 'jo@example.test')),
            members: [new MemberStub(id: 7, personalEmail: 'jo@example.test', anonymousName: 'Jo M.')],
        );

        $html = $this->renderList($page);

        $this->assertStringContainsString('post.php?post=7', $html);
        $this->assertStringContainsString('>Jo M.</a>', $html);
    }

    /** @test */
    public function a_responder_the_admin_cannot_edit_is_named_without_a_link(): void
    {
        // get_edit_post_link() answers null when the current user cannot
        // edit the member. The name still has to appear — as plain text
        // rather than a link that only leads to a permissions error.
        Functions\when('get_edit_post_link')->justReturn(null);

        $page = $this->page(
            devices: $this->devicesWith($this->device(memberEmail: 'jo@example.test')),
            members: [new MemberStub(id: 7, personalEmail: 'jo@example.test', anonymousName: 'Jo M.')],
        );

        $html = $this->renderList($page);

        $this->assertStringContainsString('Jo M.', $html);
        $this->assertStringNotContainsString('>Jo M.</a>', $html);
    }

    /** @test */
    public function a_handset_whose_responder_unity_does_not_know_is_not_linked(): void
    {
        // There is no record to link to. The address is the diagnostic:
        // the member was deleted, or the address no longer matches one.
        $page = $this->page(
            devices: $this->devicesWith($this->device(memberEmail: 'unknown@example.test')),
            members: [],
        );

        $html = $this->renderList($page);

        $this->assertStringContainsString('unknown@example.test', $html);
        $this->assertStringNotContainsString('>unknown@example.test</a>', $html);
    }

    /** @test */
    public function a_revoked_handset_is_shown_as_history_with_no_revoke_button(): void
    {
        // Rows are kept rather than deleted so the list is a record of
        // what has been enrolled, not only what is enrolled now.
        $page = $this->page(devices: $this->devicesWith(
            $this->device(id: 7, revokedAt: $this->revokedAt()),
        ));

        $html = $this->renderList($page);

        $this->assertStringContainsString('Revoked', $html);
        $this->assertStringNotContainsString('Revoke</button>', $html, 'history is not re-revocable');
    }

    /** @test */
    public function a_handset_falls_back_to_its_email_when_unity_knows_no_name(): void
    {
        $page = $this->page(
            devices: $this->devicesWith($this->device(memberEmail: 'unknown@example.test')),
            members: [],
        );

        $this->assertStringContainsString('unknown@example.test', $this->renderList($page));
    }

    /** @test */
    public function a_member_with_a_blank_anonymous_name_falls_back_to_the_email(): void
    {
        $page = $this->page(
            devices: $this->devicesWith($this->device(memberEmail: 'jo@example.test')),
            members: [new MemberStub(personalEmail: 'jo@example.test', anonymousName: '  ')],
        );

        $this->assertStringContainsString('jo@example.test', $this->renderList($page));
    }

    /** @test */
    public function an_unlabelled_handset_renders_a_placeholder(): void
    {
        $page = $this->page(devices: $this->devicesWith($this->device(label: '')));

        $this->assertStringContainsString('—', $this->renderList($page));
    }

    /**
     * @test
     * @dataProvider deliveryModes
     */
    public function the_delivery_column_distinguishes_push_from_poll(
        string $pushProvider,
        string $pushToken,
        string $expected
    ): void {
        // A handset can claim FCM but have no token yet — the app enrols
        // before Firebase hands one over — and that gap must read as Poll
        // rather than as a push to nowhere.
        $page = $this->page(devices: $this->devicesWith(
            $this->device(pushProvider: $pushProvider, pushToken: $pushToken),
        ));

        $this->assertStringContainsString($expected, $this->renderList($page));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function deliveryModes(): array
    {
        return [
            'enrolled with a token' => [Device::PUSH_FCM, 'fcm-token', 'Push'],
            'enrolled, token pending' => [Device::PUSH_FCM, '', 'Poll'],
            'no push at all' => [Device::PUSH_NONE, '', 'Poll'],
        ];
    }

    /** @test */
    public function a_handset_never_seen_since_enrolment_shows_a_placeholder(): void
    {
        $page = $this->page(devices: $this->devicesWith($this->device(lastSeenAt: 0)));

        $html = $this->renderList($page);

        $this->assertStringContainsString(gmdate('Y-m-d H:i', $this->createdAt()), $html);
        $this->assertStringContainsString('—', $html);
    }

    /** @test */
    public function the_total_counts_every_row_not_just_the_page(): void
    {
        $devices = $this->devicesWith(
            $this->device(id: 1),
            $this->device(id: 2),
            $this->device(id: 3, revokedAt: $this->revokedAt()),
        );

        $this->assertStringContainsString('3 in total.', $this->renderList($this->page(devices: $devices)));
    }

    /** @test */
    public function the_list_pages_fifty_at_a_time(): void
    {
        $_GET = ['paged' => '3'];
        $devices = new PagingDeviceRepository();

        $this->renderList($this->page(devices: $devices));

        $this->assertSame([['limit' => 50, 'offset' => 100]], $devices->paging);
    }

    /**
     * @test
     * @dataProvider nonsensePages
     */
    public function a_nonsense_page_number_falls_back_to_the_first(string $paged): void
    {
        $_GET = ['paged' => $paged];
        $devices = new PagingDeviceRepository();

        $this->renderList($this->page(devices: $devices));

        $this->assertSame([['limit' => 50, 'offset' => 0]], $devices->paging);
    }

    /** @return array<string, array{0: string}> */
    public static function nonsensePages(): array
    {
        return [
            'zero'     => ['0'],
            'negative' => ['-4'],
            'words'    => ['nonsense'],
        ];
    }

    /** @test */
    public function sorting_a_handset_column_reaches_the_repository_as_its_own_column_name(): void
    {
        // The list is paginated over the whole table, so the sort has to
        // happen in SQL: ordering only the rows in hand would order the
        // page rather than the list.
        $_GET = ['orderby' => 'last_seen', 'order' => 'asc'];
        $devices = new PagingDeviceRepository();

        $this->renderList($this->page(devices: $devices));

        $this->assertSame([['orderBy' => 'last_seen_at', 'order' => 'asc']], $devices->sorting);
    }

    /** @test */
    public function an_unsortable_handset_column_leaves_the_list_in_its_default_order(): void
    {
        // Both tables on this screen read the same `orderby`, so the
        // alerts table's columns arrive here too and must mean nothing.
        $_GET = ['orderby' => 'acknowledged', 'order' => 'asc'];
        $devices = new PagingDeviceRepository();

        $this->renderList($this->page(devices: $devices));

        $this->assertSame([['orderBy' => '', 'order' => 'asc']], $devices->sorting);
    }

    /** @test */
    public function sorting_by_responder_orders_by_the_name_shown_not_the_email_behind_it(): void
    {
        // The name comes from Unity, not from the devices table, so there
        // is no ORDER BY that produces it — and a header that reorders
        // the list by something other than what the column displays is a
        // header that lies. These two rows disagree on purpose: by name
        // Alan comes first, by address Zoe's does.
        $devices = $this->devicesWith(
            $this->device(id: 1, memberEmail: 'zoe@example.test'),
            $this->device(id: 2, memberEmail: 'alan@example.test'),
        );
        $members = [
            new MemberStub(personalEmail: 'zoe@example.test', anonymousName: 'Alan B.'),
            new MemberStub(personalEmail: 'alan@example.test', anonymousName: 'Zoe T.'),
        ];

        $_GET = ['orderby' => 'responder', 'order' => 'asc'];

        $html = $this->renderList($this->page(devices: $devices, members: $members));

        $this->assertLessThan(
            strpos($html, 'Zoe T.'),
            strpos($html, 'Alan B.'),
            'ascending by Responder should put Alan B. above Zoe T.',
        );
    }

    /** @test */
    public function sorting_by_responder_ignores_the_markup_around_the_name(): void
    {
        // The cell is a link, so sorting the rendered cell would sort
        // every linked name under "<" and leave the unlinked ones — the
        // addresses of responders Unity has lost — in a block of their
        // own. Alan's row is linked and Zoe's is not; by name Alan still
        // comes first.
        $devices = $this->devicesWith(
            $this->device(id: 1, memberEmail: 'alan@example.test'),
            $this->device(id: 2, memberEmail: 'zoe@example.test'),
        );

        $_GET = ['orderby' => 'responder', 'order' => 'asc'];

        $html = $this->renderList($this->page(
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
    }

    /** @test */
    public function sorting_by_responder_reverses_on_a_descending_request(): void
    {
        $devices = $this->devicesWith(
            $this->device(id: 1, memberEmail: 'zoe@example.test'),
            $this->device(id: 2, memberEmail: 'alan@example.test'),
        );
        $members = [
            new MemberStub(personalEmail: 'zoe@example.test', anonymousName: 'Alan B.'),
            new MemberStub(personalEmail: 'alan@example.test', anonymousName: 'Zoe T.'),
        ];

        $_GET = ['orderby' => 'responder', 'order' => 'desc'];

        $html = $this->renderList($this->page(devices: $devices, members: $members));

        $this->assertLessThan(
            strpos($html, 'Alan B.'),
            strpos($html, 'Zoe T.'),
            'descending by Responder should put Zoe T. above Alan B.',
        );
    }

    /** @test */
    public function sorting_by_responder_asks_the_repository_for_no_ordering_of_its_own(): void
    {
        // The ordering cannot happen in SQL, so the read is the whole
        // table in the repository's default order and the sort happens
        // after it. Asking for member_email here is what this replaced.
        $devices = new PagingDeviceRepository();
        $devices->total = 2;

        $_GET = ['orderby' => 'responder', 'order' => 'asc'];

        $this->renderList($this->page(devices: $devices));

        $this->assertSame([['orderBy' => '', 'order' => 'desc']], $devices->sorting);
        $this->assertSame([['limit' => 500, 'offset' => 0]], $devices->paging);
    }

    /** @test */
    public function a_sortable_handset_column_is_offered_as_a_link_in_the_header(): void
    {
        $html = $this->renderList($this->page());

        $this->assertStringContainsString('orderby=enrolled', $html);
        $this->assertStringContainsString('orderby=responder', $html);
    }

    // ── the recent-alerts table ───────────────────────────────────────

    /** @test */
    public function a_recent_alert_shows_its_kind_source_and_title(): void
    {
        $alerts = new InMemoryAlertRepository();
        $alerts->create($this->alertRequest(), $this->createdAt());

        $html = $this->renderList($this->page(alerts: $alerts));

        $this->assertStringContainsString('call_request', $html);
        $this->assertStringContainsString('reach', $html);
        $this->assertStringContainsString('Callback wanted', $html);
    }

    /** @test */
    public function an_urgent_alert_is_flagged_as_such(): void
    {
        $alerts = new InMemoryAlertRepository();
        $alerts->create($this->alertRequest(priority: 'urgent'), $this->createdAt());

        $this->assertStringContainsString('(urgent)', $this->renderList($this->page(alerts: $alerts)));
    }

    /** @test */
    public function an_ordinary_alert_is_not_flagged_urgent(): void
    {
        $alerts = new InMemoryAlertRepository();
        $alerts->create($this->alertRequest(), $this->createdAt());

        $this->assertStringNotContainsString('(urgent)', $this->renderList($this->page(alerts: $alerts)));
    }

    /** @test */
    public function an_unacknowledged_alert_says_nobody_yet(): void
    {
        // The answer to "did this reach anybody", which is the whole
        // reason the table is on this screen.
        $alerts = new InMemoryAlertRepository();
        $alerts->create($this->alertRequest(), $this->createdAt());

        $this->assertStringContainsString('Nobody yet', $this->renderList($this->page(alerts: $alerts)));
    }

    /** @test */
    public function an_acknowledged_alert_names_who_answered_it(): void
    {
        $alerts = new InMemoryAlertRepository();
        $alert = $alerts->create($this->alertRequest(), $this->createdAt());
        $alerts->acknowledge($alert->id, 7, 'jo@example.test', $this->createdAt() + 30);

        $html = $this->renderList($this->page(
            alerts: $alerts,
            members: [new MemberStub(personalEmail: 'jo@example.test', anonymousName: 'Jo M.')],
        ));

        $this->assertStringContainsString('Jo M.', $html);
        $this->assertStringNotContainsString('Nobody yet', $html);
    }

    /** @test */
    public function an_acknowledging_responder_is_linked_to_their_member_record(): void
    {
        // "Who answered this" is only half the question; the other half
        // is "and who are they". Same link the Responder column carries.
        $alerts = new InMemoryAlertRepository();
        $alert = $alerts->create($this->alertRequest(), $this->createdAt());
        $alerts->acknowledge($alert->id, 7, 'jo@example.test', $this->createdAt() + 30);

        $html = $this->renderList($this->page(
            alerts: $alerts,
            members: [new MemberStub(id: 7, personalEmail: 'jo@example.test', anonymousName: 'Jo M.')],
        ));

        $this->assertStringContainsString('post.php?post=7', $html);
        $this->assertStringContainsString('>Jo M.</a>', $html);
    }

    /** @test */
    public function an_acknowledging_responder_the_admin_cannot_edit_is_named_without_a_link(): void
    {
        Functions\when('get_edit_post_link')->justReturn(null);

        $alerts = new InMemoryAlertRepository();
        $alert = $alerts->create($this->alertRequest(), $this->createdAt());
        $alerts->acknowledge($alert->id, 7, 'jo@example.test', $this->createdAt() + 30);

        $html = $this->renderList($this->page(
            alerts: $alerts,
            members: [new MemberStub(id: 7, personalEmail: 'jo@example.test', anonymousName: 'Jo M.')],
        ));

        $this->assertStringContainsString('Jo M.', $html);
        $this->assertStringNotContainsString('>Jo M.</a>', $html);
    }

    /** @test */
    public function an_acknowledgement_from_someone_unity_does_not_know_is_not_linked(): void
    {
        $alerts = new InMemoryAlertRepository();
        $alert = $alerts->create($this->alertRequest(), $this->createdAt());
        $alerts->acknowledge($alert->id, 7, 'stranger@example.test', $this->createdAt() + 30);

        $html = $this->renderList($this->page(alerts: $alerts, members: []));

        $this->assertStringContainsString('stranger@example.test', $html);
        $this->assertStringNotContainsString('>stranger@example.test</a>', $html);
    }

    /** @test */
    public function two_responders_sharing_an_anonymous_name_are_both_listed(): void
    {
        // Deduplication is by address, not by name: two people who happen
        // to be called the same thing are two answers, and they link to
        // two different records.
        $alerts = new InMemoryAlertRepository();
        $alert = $alerts->create($this->alertRequest(), $this->createdAt());
        $alerts->acknowledge($alert->id, 7, 'jo.b@example.test', $this->createdAt() + 30);
        $alerts->acknowledge($alert->id, 8, 'jo.c@example.test', $this->createdAt() + 40);

        $html = $this->renderList($this->page(
            alerts: $alerts,
            members: [
                new MemberStub(id: 7, personalEmail: 'jo.b@example.test', anonymousName: 'Jo M.'),
                new MemberStub(id: 8, personalEmail: 'jo.c@example.test', anonymousName: 'Jo M.'),
            ],
        ));

        $this->assertSame(2, substr_count($html, '>Jo M.</a>'));
        $this->assertStringContainsString('post.php?post=7', $html);
        $this->assertStringContainsString('post.php?post=8', $html);
    }

    /** @test */
    public function sorting_by_acknowledged_by_ignores_the_markup_around_the_names(): void
    {
        // The linked names would otherwise all sort under "<", leaving
        // the unanswered alerts and the unlinked strangers in blocks of
        // their own instead of under the text the column shows.
        $alerts = new InMemoryAlertRepository();
        $zoe = $alerts->create($this->alertRequest(kind: 'first'), $this->createdAt());
        $ann = $alerts->create($this->alertRequest(kind: 'second'), $this->createdAt() + 60);
        $alerts->acknowledge($zoe->id, 7, 'zoe@example.test', $this->createdAt() + 30);
        $alerts->acknowledge($ann->id, 8, 'ann@example.test', $this->createdAt() + 90);

        $_GET = ['orderby' => 'acknowledged', 'order' => 'asc'];

        $html = $this->renderList($this->page(
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
    }

    /** @test */
    public function one_responder_answering_on_two_handsets_is_named_once(): void
    {
        // The same person acknowledging from a phone and a tablet is one
        // answer, not two.
        $alerts = new InMemoryAlertRepository();
        $alert = $alerts->create($this->alertRequest(), $this->createdAt());
        $alerts->acknowledge($alert->id, 7, 'jo@example.test', $this->createdAt() + 30);
        $alerts->acknowledge($alert->id, 8, 'jo@example.test', $this->createdAt() + 40);

        $html = $this->renderList($this->page(
            alerts: $alerts,
            members: [new MemberStub(personalEmail: 'jo@example.test', anonymousName: 'Jo M.')],
        ));

        $this->assertSame(1, substr_count($html, 'Jo M.'));
    }

    /** @test */
    public function an_acknowledgement_from_someone_unity_does_not_know_falls_back_to_the_email(): void
    {
        $alerts = new InMemoryAlertRepository();
        $alert = $alerts->create($this->alertRequest(), $this->createdAt());
        $alerts->acknowledge($alert->id, 7, 'stranger@example.test', $this->createdAt() + 30);

        $this->assertStringContainsString(
            'stranger@example.test',
            $this->renderList($this->page(alerts: $alerts, members: [])),
        );
    }

    /** @test */
    public function the_alerts_table_sorts_the_window_it_shows_rather_than_the_whole_table(): void
    {
        // Pushing this sort down to the database would apply it before
        // the limit, so sorting by title would answer with the alerts
        // whose titles start earliest in the alphabet rather than
        // reordering the ones on the screen.
        $alerts = new InMemoryAlertRepository();
        $alerts->create($this->alertRequest(kind: 'aardvark'), $this->createdAt());
        $alerts->create($this->alertRequest(kind: 'zebra'), $this->createdAt() + 60);

        $_GET = ['orderby' => 'kind', 'order' => 'asc'];

        $html = $this->renderList($this->page(alerts: $alerts));

        $this->assertLessThan(
            strpos($html, 'zebra'),
            strpos($html, 'aardvark'),
            'ascending by Kind should put aardvark above zebra',
        );
    }

    /** @test */
    public function the_alerts_table_ignores_a_sort_that_belongs_to_the_handsets_table(): void
    {
        $alerts = new InMemoryAlertRepository();
        $alerts->create($this->alertRequest(kind: 'aardvark'), $this->createdAt());
        $alerts->create($this->alertRequest(kind: 'zebra'), $this->createdAt() + 60);

        $_GET = ['orderby' => 'last_seen', 'order' => 'asc'];

        $html = $this->renderList($this->page(alerts: $alerts));

        $this->assertLessThan(
            strpos($html, 'aardvark'),
            strpos($html, 'zebra'),
            'the repository order — newest first — should survive untouched',
        );
    }

    // ── sending is its own capability ─────────────────────────────────

    /** @test */
    public function a_reader_who_cannot_send_is_offered_no_send_form(): void
    {
        // Buttons that answer 403 are worse than no buttons. The handler
        // checks again anyway — what the page rendered is not a guard.
        WpState::$deniedCaps = [Capabilities::SEND_ALERTS];

        $html = $this->renderList($this->page(devices: $this->devicesWith($this->device(id: 7))));

        $this->assertStringNotContainsString('reach-handset-actions', $html);
        $this->assertStringNotContainsString('name="reach_subject"', $html);
        $this->assertStringNotContainsString('Send a test to every live handset', $html);
    }

    /** @test */
    public function a_reader_who_cannot_send_is_offered_no_tick_boxes(): void
    {
        // The tick column exists to choose who a send goes to. Without
        // the send form it is a column of controls wired to nothing.
        WpState::$deniedCaps = [Capabilities::SEND_ALERTS];

        $html = $this->renderList($this->page(devices: $this->devicesWith($this->device(id: 7))));

        $this->assertStringNotContainsString('name="device_ids[]"', $html);
        $this->assertStringNotContainsString('reach-device-select', $html);
    }

    /** @test */
    public function a_reader_who_cannot_send_still_sees_the_handsets(): void
    {
        // Reading the screen is a personal-data read and stays on
        // Scrutiny's view capability; only sending moved.
        WpState::$deniedCaps = [Capabilities::SEND_ALERTS];

        $html = $this->renderList($this->page(
            devices: $this->devicesWith($this->device(id: 7, label: 'Duty handset')),
            members: [new MemberStub(id: 7, personalEmail: 'jo@example.test', anonymousName: 'Jo M.')],
        ));

        $this->assertStringContainsString('Duty handset', $html);
        $this->assertStringContainsString('Jo M.', $html);
        $this->assertStringContainsString('Revoke</button>', $html);
    }

    /** @test */
    public function a_test_alert_is_refused_without_the_send_capability(): void
    {
        WpState::$deniedCaps = [Capabilities::SEND_ALERTS];
        $alerts = new InMemoryAlertRepository();

        try {
            $this->page(alerts: $alerts)->handleTestAlert();
            $this->fail('expected wp_die() for a user who may read but not send');
        } catch (WpDieException) {
            $this->assertSame([], $alerts->alerts);
        }
    }

    /** @test */
    public function a_message_is_refused_without_the_send_capability(): void
    {
        WpState::$deniedCaps = [Capabilities::SEND_ALERTS];
        $alerts = new InMemoryAlertRepository();

        try {
            $this->page(alerts: $alerts)->handleMessage();
            $this->fail('expected wp_die() for a user who may read but not send');
        } catch (WpDieException) {
            $this->assertSame([], $alerts->alerts);
        }
    }

    /** @test */
    public function revoking_still_needs_only_the_personal_data_capability(): void
    {
        // Deliberately unchanged. Moving revoke and remove is a separate
        // decision about who administers the rota, not part of splitting
        // "may read" from "may ring".
        WpState::$deniedCaps = [Capabilities::SEND_ALERTS];
        $devices = $this->devicesWith($this->device(id: 7));
        $_POST = ['device_id' => '7'];

        $target = $this->revokeFromRequest($this->page(devices: $devices));

        $this->assertStringContainsString('reach_result=revoked', $target);
    }

    // ── the custom message ────────────────────────────────────────────

    /** @test */
    public function the_page_offers_a_message_form_and_says_where_the_text_ends_up(): void
    {
        // The warning is the feature. A free-text box on this screen puts
        // whatever is typed onto a lock screen anyone standing nearby can
        // read, and the only thing standing between that and a caller's
        // name is an admin who has been told.
        $html = $this->renderList($this->page());

        $this->assertStringContainsString('name="reach_subject"', $html);
        $this->assertStringContainsString('name="reach_body"', $html);
        $this->assertStringContainsString('onto a lock screen', $html);
        $this->assertStringContainsString('Send the message to every live handset', $html);
        $this->assertStringContainsString('Send the message to the selected handsets', $html);
    }

    /** @test */
    public function both_send_buttons_live_in_one_form_so_the_selection_reaches_either(): void
    {
        // A checkbox names exactly one form, so a second form for the
        // message would have had no access to the ticked handsets. The
        // two actions share a form and are told apart by formaction.
        $html = $this->renderList($this->page(devices: $this->devicesWith($this->device(id: 7))));

        $this->assertSame(1, substr_count($html, 'id="reach-handset-actions"'));
        $this->assertStringContainsString('action=reach_send_test_alert', $html);
        $this->assertStringContainsString('action=reach_send_message', $html);
    }

    /** @test */
    public function a_message_to_every_handset_is_raised_as_one_broadcast_alert(): void
    {
        $alerts = new InMemoryAlertRepository();
        $_POST = [
            'reach_scope'   => 'all',
            'reach_subject' => 'Line down until 18:00',
            'reach_body'    => 'Do not answer; calls are being diverted.',
        ];

        $target = $this->messageFromRequest($this->page(alerts: $alerts));

        $this->assertStringContainsString('reach_result=message_sent', $target);
        $this->assertCount(1, $alerts->alerts);
        $this->assertSame('admin_message', $alerts->alerts[0]->kind);
        $this->assertSame('Line down until 18:00', $alerts->alerts[0]->title);
        $this->assertStringContainsString('Do not answer', $alerts->alerts[0]->body);
        $this->assertTrue($alerts->alerts[0]->isBroadcast());
    }

    /** @test */
    public function a_message_to_a_selection_is_raised_once_per_handset(): void
    {
        // Same reasoning as the test alert: one alert each, so the Recent
        // alerts table answers per handset rather than letting a silent
        // one hide behind a colleague's acknowledgement.
        $alerts = new InMemoryAlertRepository();
        $devices = $this->devicesWith($this->device(id: 7), $this->device(id: 8));
        $_POST = [
            'reach_scope'   => 'selected',
            'reach_subject' => 'Shift change at 18:00',
            'device_ids'    => ['7', '8'],
        ];

        $target = $this->messageFromRequest($this->page(devices: $devices, alerts: $alerts));

        $this->assertStringContainsString('reach_result=message_sent_selected', $target);
        $this->assertCount(2, $alerts->alerts);
        $this->assertSame([7, 8], [
            $alerts->alerts[0]->targetDeviceId,
            $alerts->alerts[1]->targetDeviceId,
        ]);
    }

    /** @test */
    public function a_message_with_no_subject_is_refused(): void
    {
        // The subject is the line the responder reads first; without one
        // there is nothing to read.
        $alerts = new InMemoryAlertRepository();
        $_POST = ['reach_scope' => 'all', 'reach_subject' => '   '];

        $target = $this->messageFromRequest($this->page(alerts: $alerts));

        $this->assertStringContainsString('reach_result=message_no_subject', $target);
        $this->assertSame([], $alerts->alerts);
    }

    /** @test */
    public function a_message_with_no_scope_is_refused_rather_than_broadcast(): void
    {
        // The form has a text box in it, so Enter can submit it with no
        // button pressed. Assuming "all" there would broadcast to the
        // whole rota something nobody asked to send.
        $alerts = new InMemoryAlertRepository();
        $_POST = ['reach_subject' => 'Typed, then Enter'];

        $target = $this->messageFromRequest($this->page(alerts: $alerts));

        $this->assertStringContainsString('reach_result=message_no_scope', $target);
        $this->assertSame([], $alerts->alerts);
    }

    /** @test */
    public function a_message_to_an_empty_selection_is_refused(): void
    {
        $alerts = new InMemoryAlertRepository();
        $_POST = ['reach_scope' => 'selected', 'reach_subject' => 'Nobody ticked', 'device_ids' => []];

        $target = $this->messageFromRequest($this->page(alerts: $alerts));

        $this->assertStringContainsString('reach_result=message_none_selected', $target);
        $this->assertSame([], $alerts->alerts);
    }

    /** @test */
    public function a_message_never_reaches_a_revoked_handset(): void
    {
        $alerts = new InMemoryAlertRepository();
        $devices = $this->devicesWith($this->device(id: 7, revokedAt: $this->revokedAt()));
        $_POST = [
            'reach_scope'   => 'selected',
            'reach_subject' => 'Not for you',
            'device_ids'    => ['7'],
        ];

        $target = $this->messageFromRequest($this->page(devices: $devices, alerts: $alerts));

        $this->assertStringContainsString('reach_result=message_none_selected', $target);
        $this->assertSame([], $alerts->alerts);
    }

    /** @test */
    public function the_send_form_and_both_its_handlers_agree_on_one_nonce(): void
    {
        // One form, one nonce field, two handlers behind it. When the
        // test-alert handler went on verifying its own action name after
        // the form moved to the shared one, every test alert died on
        // WordPress's "Are you sure you want to do this?" screen.
        //
        // Nothing caught it, because the shared stubs answer
        // check_admin_referer() true whatever action they are handed --
        // a nonce mismatch is invisible to a test that only checks the
        // outcome. This one watches the argument instead.
        // The shared stub renders a nonce field as value="nonce-<action>",
        // so the action the form issued is readable straight off the page.
        $html = $this->renderList($this->page(devices: $this->devicesWith($this->device(id: 7))));

        $verified = [];
        Functions\when('check_admin_referer')->alias(
            static function (string $action = '', string $name = '_wpnonce') use (&$verified): bool {
                $verified[] = $action;

                return true;
            },
        );

        $_POST = ['reach_scope' => 'all'];
        $this->testAlertFromRequest($this->page());

        $_POST = ['reach_scope' => 'all', 'reach_subject' => 'Line down until 18:00'];
        $this->messageFromRequest($this->page());

        $this->assertSame(['reach_handset_actions', 'reach_handset_actions'], $verified);
        $this->assertStringContainsString(
            'value="nonce-reach_handset_actions"',
            $html,
            'the send form must issue the nonce its handlers verify',
        );
    }

    /** @test */
    public function notices_are_plain_text_because_they_are_escaped_on_the_way_out(): void
    {
        // The whole string goes through esc_html(), so an HTML entity
        // written into the table arrives as the literal characters.
        $_GET = ['reach_result' => 'message_no_subject'];

        $html = $this->renderList($this->page());

        $this->assertStringNotContainsString('&amp;mdash;', $html);
        $this->assertStringContainsString('A message needs a subject', $html);
    }

    // ── the Recent alerts refresh ─────────────────────────────────────

    /** @test */
    public function the_recent_alerts_table_is_wrapped_for_refreshing_on_its_own(): void
    {
        // Reloading the whole screen every five seconds would throw away
        // the handset selection and a half-typed message.
        $html = $this->renderList($this->page());

        $this->assertStringContainsString('id="reach-recent-alerts"', $html);
        $this->assertStringContainsString('data-action="reach_recent_alerts"', $html);
        $this->assertStringContainsString('data-nonce="', $html);
    }

    /** @test */
    public function the_refresh_answers_with_the_alerts_table_and_nothing_else(): void
    {
        $alerts = new InMemoryAlertRepository();
        $alerts->create($this->alertRequest(), $this->createdAt());

        $html = $this->recentAlertsFragment($this->page(alerts: $alerts));

        $this->assertStringContainsString('call_request', $html);
        $this->assertStringContainsString('reach-alerts', $html);
        $this->assertStringNotContainsString('Enrolled handsets', $html, 'the handsets table is not part of it');
        $this->assertStringNotContainsString('reach_subject', $html, 'nor is the message form');
    }

    /** @test */
    public function the_refreshed_table_links_its_sort_back_to_the_screen_not_to_admin_ajax(): void
    {
        // Core builds the sort links from REQUEST_URI, which during an
        // admin-ajax request is admin-ajax.php — so without help the first
        // refresh would leave headers pointing at a bare fragment.
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php?action=reach_recent_alerts';

        $this->recentAlertsFragment($this->page());

        $this->assertStringContainsString('page=reach-devices', $_SERVER['REQUEST_URI']);
        $this->assertStringNotContainsString('admin-ajax', $_SERVER['REQUEST_URI']);
    }

    /** @test */
    public function the_refresh_keeps_whatever_sort_the_screen_is_showing(): void
    {
        $_GET = ['orderby' => 'kind', 'order' => 'asc'];
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php?action=reach_recent_alerts';

        $this->recentAlertsFragment($this->page());

        $this->assertStringContainsString('orderby=kind', $_SERVER['REQUEST_URI']);
        $this->assertStringContainsString('order=asc', $_SERVER['REQUEST_URI']);
    }

    /** @test */
    public function refreshing_without_the_personal_data_capability_dies(): void
    {
        // The table names the responders who acknowledged, so the
        // fragment is gated exactly as the screen is.
        WpState::$deniedCaps = [PersonalDataPolicy::VIEW_CAPABILITY];

        $this->expectException(WpDieException::class);

        $this->page()->handleRecentAlerts();
    }

    // ── notices ───────────────────────────────────────────────────────

    /**
     * @test
     * @dataProvider notices
     */
    public function the_result_of_the_last_action_is_reported(string $result, string $class, string $text): void
    {
        $_GET = ['reach_result' => $result];

        $html = $this->renderList($this->page());

        $this->assertStringContainsString('notice-' . $class, $html);
        $this->assertStringContainsString($text, $html);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function notices(): array
    {
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
    }

    /**
     * @test
     * @dataProvider unknownResults
     */
    public function an_unrecognised_result_shows_no_notice(mixed $result): void
    {
        $_GET = ['reach_result' => $result];

        $this->assertStringNotContainsString('is-dismissible', $this->renderList($this->page()));
    }

    /** @return array<string, array{0: mixed}> */
    public static function unknownResults(): array
    {
        return [
            'invented'   => ['something_else'],
            'empty'      => [''],
            'not a string' => [['revoked']],
        ];
    }

    /** @test */
    public function no_notice_is_shown_on_a_plain_visit(): void
    {
        $this->assertStringNotContainsString('is-dismissible', $this->renderList($this->page()));
    }

    // ── revoking ──────────────────────────────────────────────────────

    /** @test */
    public function revoking_cuts_the_handset_off_and_reports_it(): void
    {
        $_POST = ['device_id' => '7'];
        $devices = $this->devicesWith($this->device(id: 7));

        $target = $this->revokeFromRequest($this->page(devices: $devices));

        $this->assertTrue($devices->findById(7)?->isRevoked());
        $this->assertStringContainsString('reach_result=revoked', $target);
        $this->assertStringContainsString('page=' . DevicesPage::PAGE_SLUG, $target);
    }

    /** @test */
    public function a_revoked_handset_stops_appearing_in_the_broadcast_list(): void
    {
        // The point of the button: the handset is cut off immediately
        // rather than at the next eligibility re-check.
        $_POST = ['device_id' => '7'];
        $devices = $this->devicesWith($this->device(id: 7));
        $this->assertCount(1, $devices->findAllLive());

        $this->revokeFromRequest($this->page(devices: $devices));

        $this->assertSame([], $devices->findAllLive());
    }

    /** @test */
    public function revoking_an_already_revoked_handset_reports_the_failure(): void
    {
        $_POST = ['device_id' => '7'];
        $devices = $this->devicesWith($this->device(id: 7, revokedAt: $this->revokedAt()));

        $target = $this->revokeFromRequest($this->page(devices: $devices));

        $this->assertStringContainsString('reach_result=revoke_failed', $target);
    }

    /**
     * @test
     * @dataProvider missingDeviceIds
     * @param array<string, string> $post
     */
    public function a_revoke_without_a_usable_device_id_touches_nothing(array $post): void
    {
        $_POST = $post;
        $devices = $this->devicesWith($this->device(id: 7));

        $target = $this->revokeFromRequest($this->page(devices: $devices));

        $this->assertStringContainsString('reach_result=revoke_failed', $target);
        $this->assertFalse($devices->findById(7)?->isRevoked());
    }

    /** @return array<string, array{0: array<string, string>}> */
    public static function missingDeviceIds(): array
    {
        return [
            'absent'   => [[]],
            'zero'     => [['device_id' => '0']],
            'negative' => [['device_id' => '-3']],
            'words'    => [['device_id' => 'nonsense']],
        ];
    }

    /** @test */
    public function revoking_an_unknown_handset_reports_the_failure(): void
    {
        $_POST = ['device_id' => '999'];

        $target = $this->revokeFromRequest($this->page(devices: $this->devicesWith($this->device(id: 7))));

        $this->assertStringContainsString('reach_result=revoke_failed', $target);
    }

    // ── the test alert ────────────────────────────────────────────────

    /** @test */
    public function the_test_alert_is_a_real_alert_through_the_real_path(): void
    {
        // Nothing about it is special-cased — that is what makes it worth
        // anything as a check of the delivery chain.
        $this->signedInAs('Site Admin');
        $alerts = new InMemoryAlertRepository();

        $target = $this->testAlertFromRequest($this->page(alerts: $alerts));

        $this->assertCount(1, $alerts->alerts);
        $this->assertSame('test', $alerts->alerts[0]->kind);
        $this->assertSame('reach', $alerts->alerts[0]->source);
        $this->assertSame('Hand test alert', $alerts->alerts[0]->title);
        $this->assertStringContainsString('reach_result=test_sent', $target);
    }

    /** @test */
    public function the_test_alert_is_a_broadcast_at_normal_priority(): void
    {
        $this->signedInAs('Site Admin');
        $alerts = new InMemoryAlertRepository();

        $this->testAlertFromRequest($this->page(alerts: $alerts));

        $this->assertTrue($alerts->alerts[0]->isBroadcast());
        $this->assertFalse($alerts->alerts[0]->isUrgent());
    }

    /** @test */
    public function the_test_alert_expires_in_five_minutes(): void
    {
        // A test still ringing handsets ten minutes later is a nuisance;
        // its only job is to arrive now.
        $this->signedInAs('Site Admin');
        $alerts = new InMemoryAlertRepository();

        $this->testAlertFromRequest($this->page(alerts: $alerts));

        $alert = $alerts->alerts[0];
        $this->assertSame(300, $alert->expiresAt - $alert->createdAt);
    }

    /** @test */
    public function the_test_alert_names_the_admin_who_sent_it(): void
    {
        $this->signedInAs('Site Admin');
        $alerts = new InMemoryAlertRepository();

        $this->testAlertFromRequest($this->page(alerts: $alerts));

        $this->assertStringContainsString('Site Admin', $alerts->alerts[0]->body);
    }

    /** @test */
    public function an_admin_with_no_display_name_is_described_generically(): void
    {
        $this->signedInAs('');
        $alerts = new InMemoryAlertRepository();

        $this->testAlertFromRequest($this->page(alerts: $alerts));

        $this->assertStringContainsString('an administrator', $alerts->alerts[0]->body);
    }

    /** @test */
    public function the_test_alert_carries_no_personal_data(): void
    {
        // Its text travels through Google's push infrastructure and onto
        // a lock screen, so it says who sent it and nothing else.
        $this->signedInAs('Site Admin');
        $alerts = new InMemoryAlertRepository();

        $this->testAlertFromRequest($this->page(alerts: $alerts));

        $alert = $alerts->alerts[0];
        $this->assertSame('', $alert->reference);
        $this->assertSame([], $alert->payload);
        $this->assertStringNotContainsString('@', $alert->body);
    }

    // ── selecting handsets ────────────────────────────────────────────

    /** @test */
    public function a_live_handset_can_be_ticked_for_a_test(): void
    {
        // Bound to the test form by its `form` attribute rather than by
        // nesting: the row already carries its own Revoke and Remove
        // forms, and a form inside a form is not parseable.
        $page = $this->page(devices: $this->devicesWith($this->device(id: 7)));

        $html = $this->renderList($page);

        $this->assertStringContainsString('name="device_ids[]"', $html);
        $this->assertStringContainsString('form="reach-handset-actions"', $html);
        $this->assertStringContainsString('value="7"', $html);
    }

    /** @test */
    public function a_revoked_handset_has_no_checkbox(): void
    {
        // Nothing to test on a handset that has already been cut off.
        $page = $this->page(devices: $this->devicesWith(
            $this->device(id: 7, revokedAt: $this->revokedAt()),
        ));

        $this->assertStringNotContainsString('name="device_ids[]"', $this->renderList($page));
    }

    /** @test */
    public function the_page_offers_both_test_scopes(): void
    {
        $html = $this->renderList($this->page());

        $this->assertStringContainsString('Send a test to every live handset', $html);
        $this->assertStringContainsString('Send a test to the selected handsets', $html);
        $this->assertStringContainsString('name="reach_scope"', $html);
    }

    // ── the scoped test alert ─────────────────────────────────────────

    /** @test */
    public function a_selected_handset_gets_an_alert_addressed_to_it_alone(): void
    {
        // The point of the selection: ringing one phone on its own is the
        // only way to find out which handset is deaf.
        $this->signedInAs('Site Admin');
        $alerts = new InMemoryAlertRepository();
        $_POST = ['reach_scope' => 'selected', 'device_ids' => ['7']];

        $target = $this->testAlertFromRequest($this->page(
            devices: $this->devicesWith($this->device(id: 7)),
            alerts: $alerts,
        ));

        $this->assertCount(1, $alerts->alerts);
        $this->assertSame(7, $alerts->alerts[0]->targetDeviceId);
        $this->assertFalse($alerts->alerts[0]->isBroadcast());
        $this->assertStringContainsString('reach_result=test_sent_selected', $target);
    }

    /** @test */
    public function each_selected_handset_gets_its_own_alert(): void
    {
        // One alert per handset, not one shared between them: each then
        // carries its own acknowledgement, so a silent phone cannot hide
        // behind a colleague's answer.
        $this->signedInAs('Site Admin');
        $alerts = new InMemoryAlertRepository();
        $_POST = ['reach_scope' => 'selected', 'device_ids' => ['7', '8']];

        $this->testAlertFromRequest($this->page(
            devices: $this->devicesWith($this->device(id: 7), $this->device(id: 8)),
            alerts: $alerts,
        ));

        $this->assertCount(2, $alerts->alerts);
        $this->assertSame([7, 8], array_map(
            static fn($alert): int => $alert->targetDeviceId,
            $alerts->alerts,
        ));
    }

    /** @test */
    public function a_selected_test_is_still_a_short_lived_test_alert(): void
    {
        // Nothing about the scoped path is special-cased — same kind,
        // same lifetime, same lack of personal data.
        $this->signedInAs('Site Admin');
        $alerts = new InMemoryAlertRepository();
        $_POST = ['reach_scope' => 'selected', 'device_ids' => ['7']];

        $this->testAlertFromRequest($this->page(
            devices: $this->devicesWith($this->device(id: 7)),
            alerts: $alerts,
        ));

        $alert = $alerts->alerts[0];
        $this->assertSame('test', $alert->kind);
        $this->assertSame(300, $alert->expiresAt - $alert->createdAt);
        $this->assertStringNotContainsString('@', $alert->body);
    }

    /** @test */
    public function ticking_handsets_and_pressing_broadcast_still_broadcasts(): void
    {
        // The button says what it does. Inferring the scope from whatever
        // happens to be ticked would make the broadcast button lie.
        $this->signedInAs('Site Admin');
        $alerts = new InMemoryAlertRepository();
        $_POST = ['reach_scope' => 'all', 'device_ids' => ['7']];

        $target = $this->testAlertFromRequest($this->page(
            devices: $this->devicesWith($this->device(id: 7)),
            alerts: $alerts,
        ));

        $this->assertCount(1, $alerts->alerts);
        $this->assertSame(0, $alerts->alerts[0]->targetDeviceId);
        $this->assertStringContainsString('reach_result=test_sent', $target);
    }

    /** @test */
    public function sending_to_an_empty_selection_raises_nothing_and_says_so(): void
    {
        $this->signedInAs('Site Admin');
        $alerts = new InMemoryAlertRepository();
        $_POST = ['reach_scope' => 'selected'];

        $target = $this->testAlertFromRequest($this->page(alerts: $alerts));

        $this->assertSame([], $alerts->alerts);
        $this->assertStringContainsString('reach_result=test_none_selected', $target);
    }

    /**
     * @test
     * @dataProvider unusableSelections
     */
    public function an_unusable_selection_raises_nothing(mixed $posted): void
    {
        // Every id is resolved against the repository rather than trusted
        // from the form: a posted id is only a row number a browser sent
        // back, and a revoked handset — which has no checkbox to tick —
        // must not become testable by editing one in.
        $this->signedInAs('Site Admin');
        $alerts = new InMemoryAlertRepository();
        $_POST = ['reach_scope' => 'selected', 'device_ids' => $posted];

        $target = $this->testAlertFromRequest($this->page(
            devices: $this->devicesWith(
                $this->device(id: 7),
                $this->device(id: 9, revokedAt: $this->revokedAt()),
            ),
            alerts: $alerts,
        ));

        $this->assertSame([], $alerts->alerts);
        $this->assertStringContainsString('reach_result=test_none_selected', $target);
    }

    /** @return array<string, array{0: mixed}> */
    public static function unusableSelections(): array
    {
        return [
            'not an array'  => ['7'],
            'empty'         => [[]],
            'unknown id'    => [['999']],
            'a revoked row' => [['9']],
            'zero'          => [['0']],
            'negative'      => [['-3']],
            'words'         => [['nonsense']],
        ];
    }

    /** @test */
    public function the_same_handset_ticked_twice_is_tested_once(): void
    {
        $this->signedInAs('Site Admin');
        $alerts = new InMemoryAlertRepository();
        $_POST = ['reach_scope' => 'selected', 'device_ids' => ['7', '7']];

        $this->testAlertFromRequest($this->page(
            devices: $this->devicesWith($this->device(id: 7)),
            alerts: $alerts,
        ));

        $this->assertCount(1, $alerts->alerts);
    }

    // ── removing a handset ────────────────────────────────────────────

    /** @test */
    public function removing_a_handset_notifies_it_and_then_deletes_the_row(): void
    {
        // Both halves, in that order. Once the row is gone the handset has
        // no token left to poll with, so a notice sent afterwards could
        // never arrive.
        $devices = $this->devicesWith($this->device(id: 7));
        $alerts = new InMemoryAlertRepository();
        $_POST = ['device_id' => '7'];

        $target = $this->removeFromRequest($this->page(devices: $devices, alerts: $alerts));

        $this->assertCount(1, $alerts->alerts);
        $this->assertSame('device_removed', $alerts->alerts[0]->kind);
        $this->assertSame(7, $alerts->alerts[0]->targetDeviceId);
        $this->assertNull($devices->findById(7), 'the row is deleted, not revoked');
        $this->assertStringContainsString('reach_result=removed', $target);
    }

    /** @test */
    public function the_removal_notice_carries_no_personal_data(): void
    {
        // Its text travels through Google's push infrastructure and onto a
        // lock screen, same as any other alert.
        $devices = $this->devicesWith($this->device(id: 7));
        $alerts = new InMemoryAlertRepository();
        $_POST = ['device_id' => '7'];

        $this->removeFromRequest($this->page(devices: $devices, alerts: $alerts));

        $alert = $alerts->alerts[0];
        $this->assertStringNotContainsString('@', $alert->body);
        $this->assertSame('', $alert->reference);
        $this->assertSame([], $alert->payload);
        $this->assertFalse($alert->isUrgent());
    }

    /** @test */
    public function a_removed_handset_leaves_no_record_behind(): void
    {
        // The difference from revoking, which keeps the row as history.
        $devices = $this->devicesWith($this->device(id: 7));
        $_POST = ['device_id' => '7'];

        $this->removeFromRequest($this->page(devices: $devices));

        $this->assertSame(0, $devices->countAll());
        $this->assertSame([], $devices->findAllLive());
    }

    /** @test */
    public function an_already_revoked_handset_can_still_be_removed(): void
    {
        // Revoking and removing answer different questions, so having done
        // one must not block the other.
        $devices = $this->devicesWith($this->device(id: 7, revokedAt: $this->revokedAt()));
        $_POST = ['device_id' => '7'];

        $target = $this->removeFromRequest($this->page(devices: $devices));

        $this->assertNull($devices->findById(7));
        $this->assertStringContainsString('reach_result=removed', $target);
    }

    /**
     * @test
     * @dataProvider missingDeviceIds
     * @param array<string, string> $post
     */
    public function a_remove_without_a_usable_device_id_touches_nothing(array $post): void
    {
        $_POST = $post;
        $devices = $this->devicesWith($this->device(id: 7));
        $alerts = new InMemoryAlertRepository();

        $target = $this->removeFromRequest($this->page(devices: $devices, alerts: $alerts));

        $this->assertStringContainsString('reach_result=remove_failed', $target);
        $this->assertNotNull($devices->findById(7));
        $this->assertSame([], $alerts->alerts, 'nothing is told about a removal that did not happen');
    }

    /** @test */
    public function removing_an_unknown_handset_reports_the_failure(): void
    {
        $_POST = ['device_id' => '999'];
        $alerts = new InMemoryAlertRepository();

        $target = $this->removeFromRequest($this->page(
            devices: $this->devicesWith($this->device(id: 7)),
            alerts: $alerts,
        ));

        $this->assertStringContainsString('reach_result=remove_failed', $target);
        $this->assertSame([], $alerts->alerts);
    }

    /** @test */
    public function removing_without_the_personal_data_capability_dies(): void
    {
        WpState::$deniedCaps = [PersonalDataPolicy::VIEW_CAPABILITY];

        $_POST = ['device_id' => '7'];
        $devices = $this->devicesWith($this->device(id: 7));

        try {
            $this->page(devices: $devices)->handleRemove();
            $this->fail('expected wp_die() for a user without the capability');
        } catch (WpDieException) {
            $this->assertNotNull($devices->findById(7), 'nothing may be removed behind the guard');
        }
    }

    /** @test */
    public function every_live_row_offers_both_revoke_and_remove(): void
    {
        $html = $this->renderList($this->page(devices: $this->devicesWith($this->device(id: 7))));

        $this->assertStringContainsString('Revoke</button>', $html);
        $this->assertStringContainsString('Remove</button>', $html);
    }

    /** @test */
    public function a_revoked_row_can_be_removed_but_not_re_revoked(): void
    {
        $html = $this->renderList($this->page(devices: $this->devicesWith(
            $this->device(id: 7, revokedAt: $this->revokedAt()),
        )));

        $this->assertStringNotContainsString('Revoke</button>', $html);
        $this->assertStringContainsString('Remove</button>', $html);
    }

    // ── helpers ───────────────────────────────────────────────────────

    /** @param array<int, Member> $members */
    private function page(
        ?DeviceRepository $devices = null,
        ?InMemoryAlertRepository $alerts = null,
        array $members = [],
    ): DevicesPage {
        $devices ??= new InMemoryDeviceRepository();
        $alerts ??= new InMemoryAlertRepository();

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

        return new DevicesPage($devices, $alerts, $api, new InMemoryMemberRepository($members));
    }

    private function devicesWith(Device ...$devices): InMemoryDeviceRepository
    {
        return new InMemoryDeviceRepository($devices);
    }

    private function device(
        int $id = 7,
        string $memberEmail = 'jo@example.test',
        string $label = 'Duty handset',
        string $platform = 'android',
        string $pushProvider = Device::PUSH_FCM,
        string $pushToken = 'fcm-token',
        int $lastSeenAt = 0,
        ?int $revokedAt = null,
    ): Device {
        return new Device(
            id: $id,
            memberEmail: $memberEmail,
            memberId: 42,
            label: $label,
            platform: $platform,
            pushProvider: $pushProvider,
            pushToken: $pushToken,
            createdAt: $this->createdAt(),
            lastSeenAt: $lastSeenAt,
            revokedAt: $revokedAt,
        );
    }

    private function alertRequest(string $priority = 'normal', string $kind = 'call_request'): AlertRequest
    {
        $request = AlertRequest::fromArray([
            'kind'     => $kind,
            'source'   => 'reach',
            'title'    => 'Callback wanted',
            'priority' => $priority,
        ]);

        $this->assertInstanceOf(AlertRequest::class, $request);

        return $request;
    }

    private function createdAt(): int
    {
        return (int) strtotime('2026-07-24 09:15:00 UTC');
    }

    private function revokedAt(): int
    {
        return (int) strtotime('2026-07-24 10:15:00 UTC');
    }

    /**
     * Fix who wp_get_current_user() reports. Patchwork keeps the
     * redefined function's signature — `: \WP_User` — so this has to hand
     * back a real WP_User rather than a convenient stdClass.
     */
    private function signedInAs(string $displayName): void
    {
        $user = new WP_User(['administrator'], 1);
        $user->display_name = $displayName;

        Functions\when('wp_get_current_user')->justReturn($user);
    }

    private function revokeFromRequest(DevicesPage $page): string
    {
        return (string) (new ReflectionMethod(DevicesPage::class, 'revokeFromRequest'))->invoke($page);
    }

    private function testAlertFromRequest(DevicesPage $page): string
    {
        return (string) (new ReflectionMethod(DevicesPage::class, 'testAlertFromRequest'))->invoke($page);
    }

    private function messageFromRequest(DevicesPage $page): string
    {
        return (string) (new ReflectionMethod(DevicesPage::class, 'messageFromRequest'))->invoke($page);
    }

    private function recentAlertsFragment(DevicesPage $page): string
    {
        return (string) (new ReflectionMethod(DevicesPage::class, 'recentAlertsFragment'))->invoke($page);
    }

    private function removeFromRequest(DevicesPage $page): string
    {
        return (string) (new ReflectionMethod(DevicesPage::class, 'removeFromRequest'))->invoke($page);
    }

    private function renderList(DevicesPage $page): string
    {
        ob_start();
        try {
            $page->renderList();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }
}

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

    public function list(int $limit, int $offset, string $orderBy = '', string $order = 'desc'): array
    {
        $this->paging[] = ['limit' => $limit, 'offset' => $offset];
        $this->sorting[] = ['orderBy' => $orderBy, 'order' => $order];

        return [];
    }

    public function countAll(): int
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
    ): Device {
        throw new LogicException('not reachable from the devices screen');
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
