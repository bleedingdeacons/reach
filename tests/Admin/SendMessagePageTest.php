<?php

declare(strict_types=1);

namespace Reach\Tests\Admin;

use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use BleedingDeacons\WpMocks\WpState;
use Reach\Admin\SendMessagePage;
use Reach\Alerts\AlertApi;
use Reach\Alerts\AlertDispatcher;
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

        return new SendMessagePage($devices, $api, new InMemoryMemberRepository($members));
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
