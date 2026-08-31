<?php

declare(strict_types=1);

namespace Reach\Tests\Admin;

use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use BleedingDeacons\WpMocks\WpState;
use Reach\Admin\SendMessagePage;
use Reach\Alerts\Alert;
use Reach\Alerts\AlertApi;
use Reach\Alerts\AlertDispatcher;
use Reach\Core\Capabilities;
use Reach\Devices\Device;
use Reach\Devices\DeviceRepository;
use Reach\Devices\ResponderGate;
use Reach\Tests\Fixtures\CommitteeStub;
use Reach\Tests\Fixtures\InMemoryAlertContactRepository;
use Reach\Tests\Fixtures\InMemoryAlertRepository;
use Reach\Tests\Fixtures\InMemoryDeviceRepository;
use Reach\Tests\Fixtures\MemberStub;
use Reach\Tests\ReachTestCase;
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
 *
 * @covers \Reach\Admin\SendMessagePage
 */
final class SendMessagePageTest extends ReachTestCase
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
    public function register_hooks_the_menu_and_the_post_handler(): void
    {
        $this->page()->register();

        $this->assertActionAdded('admin_menu', false, 'the page should register its menu on admin_menu');
        $this->assertActionAdded(
            'admin_post_reach_send_message',
            false,
            'the send buttons post to admin-post.php and need their handler hooked',
        );
    }

    /** @test */
    public function add_menu_attaches_under_the_reach_menu_as_send_message(): void
    {
        $this->page()->addMenu();

        $this->assertCount(1, WpState::$menus);
        $this->assertSame('submenu', WpState::$menus[0]['type']);
        $this->assertSame('reach', WpState::$menus[0]['parent']);
        $this->assertSame(SendMessagePage::PAGE_SLUG, WpState::$menus[0]['slug']);
        $this->assertSame('Send Message', WpState::$menus[0]['title']);
        // The recipient list names responders, so reaching the screen is
        // a personal-data read like its siblings.
        $this->assertSame(PersonalDataPolicy::VIEW_CAPABILITY, WpState::$menus[0]['cap']);
    }

    // ── capability guards ─────────────────────────────────────────────

    /** @test */
    public function the_page_renders_nothing_without_the_personal_data_capability(): void
    {
        WpState::$deniedCaps = [PersonalDataPolicy::VIEW_CAPABILITY];

        $this->assertSame('', $this->render($this->page()));
    }

    /** @test */
    public function a_reader_who_cannot_send_is_shown_no_form(): void
    {
        // Not buttons that answer 403. The handler checks again anyway.
        WpState::$deniedCaps = [Capabilities::SEND_ALERTS];

        $html = $this->render($this->page(devices: $this->devicesWith($this->device())));

        $this->assertStringNotContainsString('reach_subject', $html);
        $this->assertStringContainsString('do not have permission', $html);
    }

    /** @test */
    public function sending_is_refused_without_the_send_capability(): void
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

    // ── the form ──────────────────────────────────────────────────────

    /** @test */
    public function the_page_offers_a_message_form_and_says_where_the_text_ends_up(): void
    {
        $html = $this->render($this->page(devices: $this->devicesWith($this->device())));

        $this->assertStringContainsString('reach_subject', $html);
        $this->assertStringContainsString('reach_body', $html);
        $this->assertStringContainsString('Send to every live handset', $html);
        $this->assertStringContainsString('Send to the chosen responder', $html);

        // The warning is the whole reason the screen is safe to have.
        $this->assertStringContainsString('goes where an alert goes', $html);
        $this->assertStringContainsString('lock screen', $html);

        // It posts to admin-post.php under its own action.
        $this->assertStringContainsString('action=reach_send_message', $html);
    }

    /** @test */
    public function the_recipient_is_a_text_box_backed_by_a_datalist(): void
    {
        // Text *and* dropdown: an admin can type a few letters or open
        // the list, which is what makes a long rota usable.
        $devices = $this->devicesWith(
            $this->device(id: 7, memberEmail: 'jo@example.test'),
        );

        $html = $this->render($this->page(
            devices: $devices,
            members: [new MemberStub(id: 7, personalEmail: 'jo@example.test', anonymousName: 'Jo M.')],
        ));

        $this->assertStringContainsString('list="reach-responder-options"', $html);
        $this->assertStringContainsString('<datalist id="reach-responder-options">', $html);
        $this->assertStringContainsString('value="jo@example.test"', $html);
        $this->assertStringContainsString('Jo M.', $html);
    }

    /** @test */
    public function a_responder_with_two_handsets_is_offered_once(): void
    {
        // The list is of people, not of phones.
        $devices = $this->devicesWith(
            $this->device(id: 7, memberEmail: 'jo@example.test', label: 'Phone'),
            $this->device(id: 8, memberEmail: 'jo@example.test', label: 'Tablet'),
        );

        $html = $this->render($this->page(devices: $devices));

        $this->assertSame(1, substr_count($html, 'value="jo@example.test"'));
    }

    /** @test */
    public function a_responder_with_only_a_revoked_handset_is_not_offered(): void
    {
        // There is nothing to send to, so offering them would be an
        // invitation to a message that silently reaches nobody.
        $devices = $this->devicesWith(
            $this->device(id: 7, memberEmail: 'gone@example.test', revokedAt: 1_000),
        );

        $html = $this->render($this->page(devices: $devices));

        $this->assertStringNotContainsString('gone@example.test', $html);
        $this->assertStringContainsString('No handsets are enrolled', $html);
    }

    // ── sending ───────────────────────────────────────────────────────

    /** @test */
    public function a_message_to_every_handset_is_raised_as_one_broadcast_alert(): void
    {
        $_POST = [
            'reach_scope'   => 'all',
            'reach_subject' => 'Line down until 18:00',
            'reach_body'    => 'Use the mobile rota.',
        ];
        $alerts = new InMemoryAlertRepository();

        $target = $this->messageFromRequest($this->page(alerts: $alerts));

        $this->assertStringContainsString('reach_result=message_sent', $target);
        $this->assertCount(1, $alerts->alerts);
        $this->assertSame('admin_message', $alerts->alerts[0]->kind);
        $this->assertSame('Line down until 18:00', $alerts->alerts[0]->title);
    }

    /** @test */
    public function the_form_offers_all_three_levels_and_defaults_to_yellow(): void
    {
        $html = $this->render($this->page());

        $this->assertStringContainsString('value="red"', $html);
        $this->assertStringContainsString('value="yellow"', $html);
        $this->assertStringContainsString('value="blue"', $html);
        // Yellow: an admin who does not choose has not thereby declared an
        // emergency, and red takes over somebody's screen.
        $this->assertMatchesRegularExpression('/value="yellow"[^>]*checked/', $html);
    }

    /** @test */
    public function the_form_offers_first_to_respond_and_starts_ticked(): void
    {
        // Ticked, because that is what every message did before the
        // control existed.
        $html = $this->render($this->page());

        $this->assertMatchesRegularExpression(
            '/name="reach_first_to_respond"[^>]*checked/',
            $html,
        );
    }

    /** @test */
    public function the_chosen_level_reaches_the_alert(): void
    {
        $_POST = [
            'reach_scope'   => 'all',
            'reach_subject' => 'Everybody out',
            'reach_level'   => 'red',
        ];
        $alerts = new InMemoryAlertRepository();

        $this->messageFromRequest($this->page(alerts: $alerts));

        $this->assertSame(Alert::LEVEL_RED, $alerts->alerts[0]->level);
    }

    /** @test */
    public function an_unticked_box_makes_the_message_informational(): void
    {
        // An unticked checkbox posts nothing at all, so absent has to mean
        // informational: the tick is the affirmative claim that somebody
        // is meant to take this on.
        $_POST = [
            'reach_scope'   => 'all',
            'reach_subject' => 'The office is shut on Monday',
        ];
        $alerts = new InMemoryAlertRepository();

        $this->messageFromRequest($this->page(alerts: $alerts));

        $this->assertTrue($alerts->alerts[0]->isInformational());
    }

    /** @test */
    public function a_ticked_box_makes_the_message_first_to_respond(): void
    {
        $_POST = [
            'reach_scope'            => 'all',
            'reach_subject'          => 'Callback wanted',
            'reach_first_to_respond' => '1',
        ];
        $alerts = new InMemoryAlertRepository();

        $this->messageFromRequest($this->page(alerts: $alerts));

        $this->assertTrue($alerts->alerts[0]->isFirstToRespond());
    }

    /** @test */
    public function a_message_that_names_no_level_is_yellow(): void
    {
        $_POST = ['reach_scope' => 'all', 'reach_subject' => 'Anything'];
        $alerts = new InMemoryAlertRepository();

        $this->messageFromRequest($this->page(alerts: $alerts));

        $this->assertSame(Alert::LEVEL_YELLOW, $alerts->alerts[0]->level);
    }

    /** @test */
    public function both_of_a_responders_handsets_get_the_same_level_and_response(): void
    {
        // One message told two ways would be a responder whose phone
        // sirened and whose tablet did not.
        $devices = $this->devicesWith(
            $this->device(id: 7, memberEmail: 'jo@example.test', label: 'Phone'),
            $this->device(id: 8, memberEmail: 'jo@example.test', label: 'Tablet'),
        );
        $_POST = [
            'reach_scope'     => 'responder',
            'reach_responder' => 'jo@example.test',
            'reach_subject'   => 'Can you cover tonight?',
            'reach_level'     => 'blue',
        ];
        $alerts = new InMemoryAlertRepository();

        $this->messageFromRequest($this->page(devices: $devices, alerts: $alerts));

        $this->assertCount(2, $alerts->alerts);
        $this->assertSame(Alert::LEVEL_BLUE, $alerts->alerts[0]->level);
        $this->assertSame(Alert::LEVEL_BLUE, $alerts->alerts[1]->level);
        $this->assertTrue($alerts->alerts[0]->isInformational());
        $this->assertTrue($alerts->alerts[1]->isInformational());
    }

    /** @test */
    public function a_message_to_a_responder_is_raised_once_per_handset(): void
    {
        // One alert per handset, so each carries its own acknowledgement
        // and a silent phone cannot hide behind the other one answering.
        $devices = $this->devicesWith(
            $this->device(id: 7, memberEmail: 'jo@example.test', label: 'Phone'),
            $this->device(id: 8, memberEmail: 'jo@example.test', label: 'Tablet'),
            $this->device(id: 9, memberEmail: 'sam@example.test'),
        );
        $_POST = [
            'reach_scope'     => 'responder',
            'reach_responder' => 'jo@example.test',
            'reach_subject'   => 'Can you cover tonight?',
        ];
        $alerts = new InMemoryAlertRepository();

        $target = $this->messageFromRequest($this->page(devices: $devices, alerts: $alerts));

        $this->assertStringContainsString('reach_result=message_sent_responder', $target);
        $this->assertCount(2, $alerts->alerts, 'one per handset that responder holds, and nobody else');
    }

    /** @test */
    public function a_responder_is_matched_without_regard_to_case(): void
    {
        // An admin typing an address by hand should not have to match the
        // capitalisation Unity happens to hold.
        $devices = $this->devicesWith($this->device(id: 7, memberEmail: 'jo@example.test'));
        $_POST = [
            'reach_scope'     => 'responder',
            'reach_responder' => 'Jo@Example.Test',
            'reach_subject'   => 'Can you cover tonight?',
        ];
        $alerts = new InMemoryAlertRepository();

        $target = $this->messageFromRequest($this->page(devices: $devices, alerts: $alerts));

        $this->assertStringContainsString('reach_result=message_sent_responder', $target);
        $this->assertCount(1, $alerts->alerts);
    }

    /**
     * Intergroup
     * └── Telephones
     *
     * Jo is on Telephones, Sam is on Intergroup, Kit is on neither.
     */
    private function committees(): InMemoryCommitteeRepository
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
    private function committeeMembers(): array
    {
        return [
            new MemberStub('sam@example.test', id: 10),
            new MemberStub('jo@example.test', id: 11),
            new MemberStub('kit@example.test', id: 12),
        ];
    }

    /** @test */
    public function a_message_to_a_committee_reaches_its_members_handsets(): void
    {
        $devices = $this->devicesWith(
            $this->device(id: 7, memberEmail: 'jo@example.test'),
            $this->device(id: 8, memberEmail: 'kit@example.test'),
        );
        $_POST = [
            'reach_scope'     => 'committee',
            'reach_committee' => 'telephones',
            'reach_subject'   => 'Rota change',
        ];
        $alerts = new InMemoryAlertRepository();

        $target = $this->messageFromRequest($this->page(
            devices: $devices,
            alerts: $alerts,
            members: $this->committeeMembers(),
            committees: $this->committees(),
        ));

        $this->assertStringContainsString('reach_result=message_sent_committee', $target);
        $this->assertCount(1, $alerts->alerts, 'Jo is on Telephones; Kit is on no committee');
        $this->assertSame(7, $alerts->alerts[0]->targetDeviceId, 'Jo’s handset, not Kit’s');
    }

    /**
     * Messaging a parent and not reaching the committees under it would be a
     * trap: the tree says they are part of it.
     *
     * @test
     */
    public function a_message_to_a_committee_reaches_the_committees_under_it(): void
    {
        $devices = $this->devicesWith(
            $this->device(id: 7, memberEmail: 'jo@example.test'),
            $this->device(id: 8, memberEmail: 'sam@example.test'),
        );
        $_POST = [
            'reach_scope'     => 'committee',
            'reach_committee' => 'intergroup',
            'reach_subject'   => 'Rota change',
        ];
        $alerts = new InMemoryAlertRepository();

        $this->messageFromRequest($this->page(
            devices: $devices,
            alerts: $alerts,
            members: $this->committeeMembers(),
            committees: $this->committees(),
        ));

        $this->assertCount(2, $alerts->alerts, 'Sam on Intergroup and Jo on Telephones beneath it');
    }

    /**
     * Splitting by handset is a delivery decision. Ten people on a committee
     * were sent one message, and an acknowledgement from any of them has to be
     * able to find the rest.
     *
     * @test
     */
    public function a_message_to_a_committee_is_one_message_across_every_handset(): void
    {
        $devices = $this->devicesWith(
            $this->device(id: 7, memberEmail: 'jo@example.test', label: 'Phone'),
            $this->device(id: 8, memberEmail: 'jo@example.test', label: 'Tablet'),
            $this->device(id: 9, memberEmail: 'sam@example.test'),
        );
        $_POST = [
            'reach_scope'     => 'committee',
            'reach_committee' => 'intergroup',
            'reach_subject'   => 'Rota change',
        ];
        $alerts = new InMemoryAlertRepository();

        $this->messageFromRequest($this->page(
            devices: $devices,
            alerts: $alerts,
            members: $this->committeeMembers(),
            committees: $this->committees(),
        ));

        $this->assertCount(3, $alerts->alerts);

        $uuids = array_unique(array_map(
            static fn ($alert): string => $alert->messageUuid,
            $alerts->alerts,
        ));
        $this->assertCount(1, $uuids, 'one message uuid across the whole committee');
    }

    /**
     * A member can hold the parent and the child, and two paths must not ring
     * the same phone twice.
     *
     * @test
     */
    public function a_member_on_two_committees_in_the_branch_is_only_sent_one_copy(): void
    {
        $committees = new InMemoryCommitteeRepository(
            [
                new CommitteeStub('intergroup', 'Intergroup', id: 1),
                new CommitteeStub('telephones', 'Telephones', id: 2, parentId: 1),
            ],
            ['intergroup' => [11], 'telephones' => [11]],
        );
        $devices = $this->devicesWith($this->device(id: 7, memberEmail: 'jo@example.test'));
        $_POST = [
            'reach_scope'     => 'committee',
            'reach_committee' => 'intergroup',
            'reach_subject'   => 'Rota change',
        ];
        $alerts = new InMemoryAlertRepository();

        $this->messageFromRequest($this->page(
            devices: $devices,
            alerts: $alerts,
            members: $this->committeeMembers(),
            committees: $committees,
        ));

        $this->assertCount(1, $alerts->alerts);
    }

    /** @test */
    public function a_committee_message_never_reaches_a_revoked_handset(): void
    {
        $devices = $this->devicesWith(
            $this->device(id: 7, memberEmail: 'jo@example.test'),
            $this->device(id: 8, memberEmail: 'jo@example.test', revokedAt: 1_000),
        );
        $_POST = [
            'reach_scope'     => 'committee',
            'reach_committee' => 'telephones',
            'reach_subject'   => 'Rota change',
        ];
        $alerts = new InMemoryAlertRepository();

        $this->messageFromRequest($this->page(
            devices: $devices,
            alerts: $alerts,
            members: $this->committeeMembers(),
            committees: $this->committees(),
        ));

        $this->assertCount(1, $alerts->alerts, 'the revoked handset is not a destination');
    }

    /**
     * Saying "sent" when nothing was sent is a lie an admin acts on.
     *
     * @test
     */
    public function a_committee_whose_members_have_no_handsets_is_reported_not_swallowed(): void
    {
        $devices = $this->devicesWith($this->device(id: 7, memberEmail: 'kit@example.test'));
        $_POST = [
            'reach_scope'     => 'committee',
            'reach_committee' => 'telephones',
            'reach_subject'   => 'Rota change',
        ];
        $alerts = new InMemoryAlertRepository();

        $target = $this->messageFromRequest($this->page(
            devices: $devices,
            alerts: $alerts,
            members: $this->committeeMembers(),
            committees: $this->committees(),
        ));

        $this->assertStringContainsString('reach_result=message_committee_silent', $target);
        $this->assertCount(0, $alerts->alerts);
    }

    /** @test */
    public function a_committee_message_needs_a_committee(): void
    {
        $_POST = [
            'reach_scope'     => 'committee',
            'reach_committee' => '',
            'reach_subject'   => 'Rota change',
        ];
        $alerts = new InMemoryAlertRepository();

        $target = $this->messageFromRequest($this->page(
            alerts: $alerts,
            committees: $this->committees(),
        ));

        $this->assertStringContainsString('reach_result=message_no_committee', $target);
        $this->assertCount(0, $alerts->alerts);
    }

    /**
     * The control posts a slug, and a slug is only ever a string somebody's
     * browser sent back.
     *
     * @test
     */
    public function an_unknown_committee_is_told_so_rather_than_silently_sending_nothing(): void
    {
        $_POST = [
            'reach_scope'     => 'committee',
            'reach_committee' => 'no-such-committee',
            'reach_subject'   => 'Rota change',
        ];
        $alerts = new InMemoryAlertRepository();

        $target = $this->messageFromRequest($this->page(
            alerts: $alerts,
            committees: $this->committees(),
        ));

        $this->assertStringContainsString('reach_result=message_unknown_committee', $target);
        $this->assertCount(0, $alerts->alerts);
    }
    /** @test */
    public function a_message_never_reaches_a_revoked_handset(): void
    {
        $devices = $this->devicesWith(
            $this->device(id: 7, memberEmail: 'jo@example.test'),
            $this->device(id: 8, memberEmail: 'jo@example.test', revokedAt: 1_000),
        );
        $_POST = [
            'reach_scope'     => 'responder',
            'reach_responder' => 'jo@example.test',
            'reach_subject'   => 'Can you cover tonight?',
        ];
        $alerts = new InMemoryAlertRepository();

        $this->messageFromRequest($this->page(devices: $devices, alerts: $alerts));

        $this->assertCount(1, $alerts->alerts, 'the revoked handset is not a destination');
    }

    /** @test */
    public function a_subject_and_body_are_unslashed_before_they_are_sent(): void
    {
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

        $this->messageFromRequest($this->page(alerts: $alerts));

        $this->assertCount(1, $alerts->alerts);
        $this->assertSame("Jo's phone is down", $alerts->alerts[0]->title);
        $this->assertSame("Use Sam's instead", $alerts->alerts[0]->body);
    }

    // ── refusals ──────────────────────────────────────────────────────

    /** @test */
    public function a_message_with_no_subject_is_refused(): void
    {
        $_POST = ['reach_scope' => 'all', 'reach_body' => 'Body only'];
        $alerts = new InMemoryAlertRepository();

        $target = $this->messageFromRequest($this->page(alerts: $alerts));

        $this->assertStringContainsString('reach_result=message_no_subject', $target);
        $this->assertSame([], $alerts->alerts);
    }

    /** @test */
    public function a_message_with_no_scope_is_refused_rather_than_broadcast(): void
    {
        // The form has text boxes in it, so Enter can submit it with no
        // button pressed. If that were read as "everybody", a keystroke
        // would ring the whole rota.
        $_POST = ['reach_subject' => 'Typed and then Entered'];
        $alerts = new InMemoryAlertRepository();

        $target = $this->messageFromRequest($this->page(alerts: $alerts));

        $this->assertStringContainsString('reach_result=message_no_scope', $target);
        $this->assertSame([], $alerts->alerts);
    }

    /** @test */
    public function a_message_to_nobody_in_particular_is_refused(): void
    {
        $_POST = ['reach_scope' => 'responder', 'reach_subject' => 'Who is this for?'];
        $alerts = new InMemoryAlertRepository();

        $target = $this->messageFromRequest($this->page(alerts: $alerts));

        $this->assertStringContainsString('reach_result=message_no_responder', $target);
        $this->assertSame([], $alerts->alerts);
    }

    /** @test */
    public function a_typed_responder_who_matches_nothing_is_refused(): void
    {
        // The datalist offers the list; it does not confine anyone to it.
        // A posted address is only ever a string a browser sent back, so
        // it is resolved against the enrolled handsets rather than
        // trusted — and an address matching nothing must not quietly
        // become a broadcast.
        $devices = $this->devicesWith($this->device(id: 7, memberEmail: 'jo@example.test'));
        $_POST = [
            'reach_scope'     => 'responder',
            'reach_responder' => 'nobody@example.test',
            'reach_subject'   => 'Can you cover tonight?',
        ];
        $alerts = new InMemoryAlertRepository();

        $target = $this->messageFromRequest($this->page(devices: $devices, alerts: $alerts));

        $this->assertStringContainsString('reach_result=message_unknown_responder', $target);
        $this->assertSame([], $alerts->alerts, 'nothing may be sent to an address nobody holds');
    }

    // ── notices ───────────────────────────────────────────────────────

    /** @test */
    public function notices_are_plain_text_because_they_are_escaped_on_the_way_out(): void
    {
        // The whole string goes through esc_html(), so an HTML entity
        // written into the table arrives as the literal characters.
        $_GET = ['reach_result' => 'message_no_subject'];

        $html = $this->render($this->page());

        $this->assertStringNotContainsString('&amp;mdash;', $html);
        $this->assertStringContainsString('A message needs a subject', $html);
    }

    /** @test */
    public function an_unknown_result_shows_no_notice(): void
    {
        $_GET = ['reach_result' => 'something_else'];

        $this->assertStringNotContainsString('notice-success', $this->render($this->page()));
    }

    // ── helpers ───────────────────────────────────────────────────────

    /** @param array<int, MemberStub> $members */
    private function page(
        ?DeviceRepository $devices = null,
        ?InMemoryAlertRepository $alerts = null,
        array $members = [],
        ?InMemoryCommitteeRepository $committees = null,
    ): SendMessagePage {
        $devices ??= new InMemoryDeviceRepository();
        $alerts ??= new InMemoryAlertRepository();

        // A real AlertApi over a real dispatcher, for the reason
        // DevicesPageTest gives: the send path is only worth asserting on
        // if it goes through the machinery an ordinary alert does.
        $api = new AlertApi(new AlertDispatcher(
            $alerts,
            new InMemoryAlertContactRepository(),
            $devices,
            new ResponderGate(new InMemoryMemberRepository($members)),
            [],
        ));

        return new SendMessagePage(
            $devices,
            $api,
            new InMemoryMemberRepository($members),
            $committees ?? new InMemoryCommitteeRepository(),
        );
    }

    private function devicesWith(Device ...$devices): InMemoryDeviceRepository
    {
        return new InMemoryDeviceRepository($devices);
    }

    private function device(
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

    private function messageFromRequest(SendMessagePage $page): string
    {
        return (string) (new ReflectionMethod(SendMessagePage::class, 'messageFromRequest'))->invoke($page);
    }

    private function render(SendMessagePage $page): string
    {
        ob_start();
        try {
            $page->render();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }
}
