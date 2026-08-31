<?php

declare(strict_types=1);

namespace Reach\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Alerts\Alert;
use Reach\Alerts\AlertApi;
use Reach\Alerts\MessageUuid;
use Reach\Core\Capabilities;
use Reach\Devices\Device;
use Reach\Devices\DeviceRepository;
use Scrutiny\Privacy\PersonalDataPolicy;
use Unity\Committees\Interfaces\Committee;
use Unity\Committees\Interfaces\CommitteeRepository;
use Unity\Members\Interfaces\MemberRepository;
use WP_Error;

/**
 * Sending an administrator's own message to the handsets.
 *
 * <b>Why this is its own screen.</b> It used to be a second form on
 * {@see DevicesPage}, sharing that screen's form element so it could
 * read the handsets ticked in the table — a checkbox may name exactly
 * one form, so the test alert and the message had to be one form told
 * apart by the `formaction` of the button pressed. That worked, but it
 * put a free-text box a keystroke away from a list of destructive row
 * actions and made both halves harder to explain. Choosing the
 * recipient here instead of inheriting a tick-box selection is what
 * makes the split possible.
 *
 * <b>The test alert stays where it was.</b> It is not a message: it has
 * no text, it says only that the chain works, and its whole value is
 * being sent to a named handset from the table listing them. See
 * {@see DevicesPage} on why ringing one phone on its own is how you find
 * out which one is deaf.
 *
 * <b>Addressed by responder, not by handset.</b> A message is something
 * one says to a person; which of their phones is in their pocket is not
 * the sender's business. A responder with a phone and a tablet gets it
 * on both — as separate alerts, so each carries its own acknowledgement
 * and a silent handset cannot hide behind the other one answering.
 *
 * Capability
 * ----------
 * scrutiny_view_personal_data to reach the screen, matching
 * {@see DevicesPage}: the recipient list names responders. Sending is
 * gated separately on {@see Capabilities::SEND_ALERTS}, because making
 * every handset on the rota ring at 3am is not implied by being allowed
 * to read an address.
 */
final class SendMessagePage
{
    public const PAGE_SLUG = 'reach-send-message';

    /**
     * Reading the screen. The recipient control lists the responders who
     * have handsets enrolled, which is a personal-data read.
     */
    private const CAPABILITY = PersonalDataPolicy::VIEW_CAPABILITY;

    /** Actually sending. See the class docblock on why it is separate. */
    private const SEND_CAPABILITY = Capabilities::SEND_ALERTS;

    /**
     * Unchanged from when this lived on the devices screen. The action
     * name is what `admin_post_` hooks on and what any bookmarked form
     * would post to, and renaming it would buy nothing.
     */
    private const MESSAGE_ACTION = 'reach_send_message';

    private const NONCE = 'reach_send_message';

    /** How long an admin's own message stays live. */
    private const MESSAGE_TTL = 3600;

    /**
     * Who the message is for.
     *
     * <b>Both are explicit, and that is deliberate.</b> This form has
     * text boxes in it, so the Enter key can submit it without any
     * button being pressed. If an empty recipient meant "everybody",
     * that keystroke would broadcast to the whole rota. So the scope
     * comes from the button, never from whether the recipient box
     * happens to be filled in.
     */
    private const SCOPE_ALL = 'all';
    private const SCOPE_RESPONDER = 'responder';

    /**
     * Everyone on a committee, and on the committees under it.
     *
     * <b>Descendants are included.</b> Messaging Public Information and
     * not reaching Health or Employment would be a trap: the tree says
     * they are part of it, and an admin picking the parent is picking
     * the branch. It is also what CommitteeRepository does by default,
     * so the screen and the data agree.
     */
    private const SCOPE_COMMITTEE = 'committee';

    /** Id of the datalist backing the recipient box. */
    private const RESPONDER_LIST_ID = 'reach-responder-options';

    /**
     * The level control, as label and one-line explanation.
     *
     * Written out here rather than derived from {@see Alert::LEVELS} so
     * the order is the ladder — loudest first — and so each level is
     * described in terms of what the handset does, which is the thing an
     * admin is actually choosing between.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const LEVEL_LABELS = [
        Alert::LEVEL_RED => [
            'Red',
            'takes the screen over and rings until somebody answers',
        ],
        Alert::LEVEL_YELLOW => [
            'Yellow',
            'makes a noise and shows a banner, but can be missed',
        ],
        Alert::LEVEL_BLUE => [
            'Blue',
            'sits in the tray as a reminder; wakes nobody',
        ],
    ];

    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly AlertApi $alertApi,
        private readonly MemberRepository $members,
        private readonly CommitteeRepository $committees,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_post_' . self::MESSAGE_ACTION, [$this, 'handleMessage']);
    }

    public function addMenu(): void
    {
        // Parent menu ("Reach") is registered by CallAttemptsPage.
        add_submenu_page(
            CallAttemptsPage::MENU_SLUG,
            'Send Message',
            'Send Message',
            self::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render'],
        );
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        // A reader who cannot send is shown no form rather than buttons
        // that answer 403. The handler checks again regardless: what the
        // page chose to render is not a permission check.
        $canSend = current_user_can(self::SEND_CAPABILITY);
        $responders = $canSend ? $this->responders() : [];
        $committees = $canSend ? $this->committees() : [];

        $notice = $this->notice();
        ?>
        <div class="wrap">
            <h1>Send Message</h1>
            <?php echo $notice; ?>

            <p class="description">
                Sends your own message to Hand handsets, through the same delivery path an
                alert takes &mdash; so it rings, wherever the phone is and whatever time it is.
                To check that the chain works without saying anything, use the test alert on
                <a href="<?php echo esc_url($this->devicesUrl()); ?>">Hand devices</a> instead.
            </p>

            <?php if (!$canSend) : ?>
                <div class="notice notice-info inline">
                    <p>You do not have permission to send messages to handsets.</p>
                </div>
            <?php else : ?>
            <div class="notice notice-warning inline" style="margin: 0 0 12px;">
                <p>
                    <strong>Whatever you type here goes where an alert goes:</strong> through
                    Google&rsquo;s servers, onto a lock screen anyone standing nearby can read,
                    and into the handset&rsquo;s notification history. Keep callers&rsquo; names
                    and numbers out of it &mdash; those belong in the email that already carries
                    them. Say what happened and give a reference to look it up by.
                </p>
            </div>

            <form method="post" action="<?php echo esc_url($this->postUrl()); ?>">
                <?php wp_nonce_field(self::NONCE); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="reach-message-subject">Subject</label></th>
                        <td>
                            <input type="text"
                                   id="reach-message-subject"
                                   name="reach_subject"
                                   class="regular-text"
                                   maxlength="200"
                                   autocomplete="off">
                            <p class="description">The line the responder sees first. Required.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="reach-message-body">Message</label></th>
                        <td>
                            <textarea id="reach-message-body"
                                      name="reach_body"
                                      rows="3"
                                      class="large-text"
                                      maxlength="1000"></textarea>
                            <p class="description">Optional. Shown under the subject when the handset expands the notification.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Level</th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text">Level</legend>
                                <?php foreach (self::LEVEL_LABELS as $level => [$label, $hint]) : ?>
                                    <label style="display:block; margin-bottom:6px;">
                                        <input type="radio"
                                               name="reach_level"
                                               value="<?php echo esc_attr($level); ?>"
                                               <?php checked($level, Alert::LEVEL_YELLOW); ?>>
                                        <strong><?php echo esc_html($label); ?></strong>
                                        &mdash; <?php echo esc_html($hint); ?>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Response</th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="reach_first_to_respond"
                                       value="1"
                                       checked>
                                The first responder to acknowledge deals with it
                            </label>
                            <p class="description">
                                Ticked, the first person to acknowledge takes it on: everyone else is
                                told who answered and it clears off their handsets. Unticked, it is
                                information &mdash; everybody reads it and closes their own copy, and
                                their button says Close rather than Acknowledge.
                            </p>
                        </td>
                    </tr>
                    <!--
                        Responder and committee share one row. They are the two
                        halves of the same question — who is this for — and are
                        mutually exclusive in practice, since the button chooses
                        between them. Stacked, the second read like a further
                        step rather than an alternative.

                        The fieldset wraps them because they are one choice; its
                        legend is the row heading, which is why the th is empty
                        rather than carrying a label of its own.
                    -->
                    <tr>
                        <th scope="row">
                            <span id="reach-recipient-heading">Recipient</span>
                        </th>
                        <td>
                            <fieldset class="reach-recipient" aria-labelledby="reach-recipient-heading">
                                <div class="reach-recipient-field">
                                    <label for="reach-message-responder"><strong>Responder</strong></label>
                                    <input type="text"
                                           id="reach-message-responder"
                                           name="reach_responder"
                                           class="regular-text"
                                           list="<?php echo esc_attr(self::RESPONDER_LIST_ID); ?>"
                                           autocomplete="off"
                                           <?php echo $responders === [] ? 'disabled' : ''; ?>
                                           placeholder="Start typing a name, or pick from the list">
                                    <datalist id="<?php echo esc_attr(self::RESPONDER_LIST_ID); ?>">
                                        <?php foreach ($responders as $email => $label) : ?>
                                            <option value="<?php echo esc_attr($email); ?>"
                                                    label="<?php echo esc_attr($label); ?>"></option>
                                        <?php endforeach; ?>
                                    </datalist>
                                    <p class="description">
                                        <?php if ($responders === []) : ?>
                                            No handsets are enrolled, so there is nobody to address a
                                            message to.
                                        <?php else : ?>
                                            Only needed for the second button. Type to narrow the list, or
                                            open it and choose. A responder with more than one handset gets
                                            the message on all of them, each acknowledged separately.
                                        <?php endif; ?>
                                    </p>
                                </div>

                                <div class="reach-recipient-field">
                                    <label for="reach-message-committee"><strong>Committee</strong></label>
                                    <select id="reach-message-committee"
                                            name="reach_committee"
                                            <?php echo $committees === [] ? 'disabled' : ''; ?>>
                                        <option value="">Choose a committee</option>
                                        <?php foreach ($committees as $slug => $label) : ?>
                                            <option value="<?php echo esc_attr($slug); ?>">
                                                <?php echo esc_html($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        <?php if ($committees === []) : ?>
                                            No committees exist yet, so there is no committee to address.
                                        <?php else : ?>
                                            Only needed for the third button. Sending to a committee also
                                            reaches the committees under it, and the count is how many
                                            handsets that works out to. Somebody on two of them still gets
                                            one message.
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <style>
                    /*
                        Wrapping rather than a fixed pair of columns: this sits
                        inside WordPress's form-table, which is already narrow on
                        a laptop and narrower again with the admin menu open. At
                        the point the two would be squeezed, they stack — which
                        is what the mobile admin does to every other row here.
                    */
                    .reach-recipient {
                        display: flex;
                        flex-wrap: wrap;
                        gap: 0 2em;
                    }
                    .reach-recipient-field {
                        flex: 1 1 22em;
                        min-width: 0;
                    }
                    .reach-recipient-field label { display: block; margin-bottom: .25em; }
                    .reach-recipient-field select { max-width: 100%; }
                </style>

                <p>
                    <button type="submit"
                            name="reach_scope"
                            value="<?php echo esc_attr(self::SCOPE_ALL); ?>"
                            class="button button-primary">
                        Send to every live handset
                    </button>
                    <button type="submit"
                            name="reach_scope"
                            value="<?php echo esc_attr(self::SCOPE_RESPONDER); ?>"
                            class="button button-secondary"
                            <?php echo $responders === [] ? 'disabled' : ''; ?>>
                        Send to the chosen responder
                    </button>
                    <button type="submit"
                            name="reach_scope"
                            value="<?php echo esc_attr(self::SCOPE_COMMITTEE); ?>"
                            class="button button-secondary"
                            <?php echo $committees === [] ? 'disabled' : ''; ?>>
                        Send to the chosen committee
                    </button>
                </p>
            </form>

            <p class="description">
                Delivery is confirmed on
                <a href="<?php echo esc_url($this->devicesUrl()); ?>">Hand devices</a>, under
                Recent alerts &mdash; the notice above only means Reach accepted the message.
            </p>

            <?php endif; ?>
        </div>
        <?php
    }

    public function handleMessage(): void
    {
        if (!current_user_can(self::SEND_CAPABILITY)) {
            wp_die('You are not allowed to do that.', 'Forbidden', ['response' => 403]);
        }

        wp_safe_redirect($this->messageFromRequest());
        exit;
    }

    /**
     * Raise the message and return where the browser goes next.
     *
     * Split out of {@see handleMessage()} for the reason
     * {@see DevicesPage::revokeFromRequest()} documents: everything above
     * is a guard, everything below is `wp_safe_redirect(); exit;`, and
     * the `exit` takes the test runner with it.
     *
     * <b>The subject is required and the scope must be explicit.</b> See
     * {@see SCOPE_ALL} on why an assumed scope would be a broadcast
     * nobody asked for. A missing subject is a message nobody can read.
     *
     * Nothing here inspects what was typed. The screen says plainly where
     * the text ends up — see {@see \Reach\Alerts\Alert} on why that
     * matters — and an admin who has read that and typed it anyway has
     * made a decision this code is in no position to second-guess.
     */
    private function messageFromRequest(): string
    {
        check_admin_referer(self::NONCE);

        $subject = $this->posted('reach_subject');
        if ($subject === '') {
            return $this->resultUrl('message_no_subject');
        }

        $body  = $this->posted('reach_body');
        $scope = $this->posted('reach_scope');

        // Read once and passed down rather than re-read per handset: a
        // message that reached a responder's phone as red and their tablet
        // as blue would be one message told two different ways.
        //
        // Neither is validated here. AlertRequest normalises both against
        // their vocabularies, and doing it twice would mean two places to
        // keep in step — the same reasoning as posted() on sanitising.
        $level = $this->posted('reach_level');

        // An unticked checkbox posts nothing at all, so absent means
        // informational. That is the safe direction for this control
        // specifically: the tick is the affirmative claim that somebody is
        // meant to take this on.
        $response = isset($_POST['reach_first_to_respond'])
            ? Alert::RESPONSE_FIRST
            : Alert::RESPONSE_NONE;

        if ($scope === self::SCOPE_RESPONDER) {
            return $this->toResponder(
                $subject,
                $body,
                $this->posted('reach_responder'),
                $level,
                $response,
            );
        }

        if ($scope === self::SCOPE_COMMITTEE) {
            return $this->toCommittee(
                $subject,
                $body,
                $this->posted('reach_committee'),
                $level,
                $response,
            );
        }

        if ($scope !== self::SCOPE_ALL) {
            return $this->resultUrl('message_no_scope');
        }

        return $this->resultUrl(
            is_wp_error($this->sendMessage($subject, $body, $level, $response))
                ? 'message_failed'
                : 'message_sent',
        );
    }

    /**
     * Send to one responder's live handsets.
     *
     * <b>What was typed is resolved against the enrolled handsets, never
     * trusted.</b> The control is a text box with a datalist behind it —
     * it offers the list, it does not confine anyone to it — so the
     * posted value is only ever a string somebody's browser sent back. An
     * address that matches nothing is told so plainly rather than
     * silently becoming a broadcast or a message to nobody.
     */
    private function toResponder(
        string $subject,
        string $body,
        string $responder,
        string $level,
        string $response
    ): string {
        if ($responder === '') {
            return $this->resultUrl('message_no_responder');
        }

        $devices = $this->liveDevicesFor($responder);
        if ($devices === []) {
            return $this->resultUrl('message_unknown_responder');
        }

        // One alert per handset rather than one addressed to the person.
        // Each then carries its own acknowledgement, so Recent alerts
        // answers "did this handset ring" for every phone they hold
        // instead of letting a silent one hide behind the other.
        //
        // <b>One message uuid across all of them.</b> Splitting by
        // handset is a delivery decision, not something the responder
        // did — they were sent one message and it is still one message
        // when it lands on two devices. The uuid is what says so, and it
        // is what lets an acknowledgement from the phone reach the
        // tablet: see {@see \Reach\Alerts\AcknowledgementNotifier},
        // which recovers who a message went to from exactly this.
        $messageUuid = MessageUuid::generate();

        $failed = false;
        foreach ($devices as $device) {
            if (
                is_wp_error(
                    $this->sendMessage($subject, $body, $level, $response, $device->id, $messageUuid),
                )
            ) {
                $failed = true;
            }
        }

        return $this->resultUrl($failed ? 'message_failed' : 'message_sent_responder');
    }

    /**
     * Send to everyone on a committee, and on the committees under it.
     *
     * <b>Resolved by slug, never by term id.</b> The committee tree is
     * built by hand in wp-admin on each site, so the same committee has
     * different term ids on dev, test and production — an id posted back
     * here would be right on one machine and point at something else on
     * the next. See {@see CommitteeRepository} on why slugs are the
     * cross-environment contract.
     *
     * <b>One message uuid across the whole committee.</b> Same reasoning
     * as {@see toResponder()}: splitting by handset is a delivery
     * decision. Ten people on a committee were sent one message, and an
     * acknowledgement from any of them has to be able to find the rest.
     *
     * <b>Silence is reported, not swallowed.</b> A committee whose
     * members have no handsets enrolled is a message that went nowhere,
     * and saying "sent" would be a lie an admin acts on.
     */
    private function toCommittee(
        string $subject,
        string $body,
        string $slug,
        string $level,
        string $response
    ): string {
        if ($slug === '') {
            return $this->resultUrl('message_no_committee');
        }

        if ($this->committees->findBySlug($slug) === null) {
            return $this->resultUrl('message_unknown_committee');
        }

        $devices = $this->liveDevicesForCommittee($slug);
        if ($devices === []) {
            return $this->resultUrl('message_committee_silent');
        }

        $messageUuid = MessageUuid::generate();

        $failed = false;
        foreach ($devices as $device) {
            if (
                is_wp_error(
                    $this->sendMessage($subject, $body, $level, $response, $device->id, $messageUuid),
                )
            ) {
                $failed = true;
            }
        }

        return $this->resultUrl($failed ? 'message_failed' : 'message_sent_committee');
    }

    /**
     * Every live handset belonging to a committee's members.
     *
     * Keyed by device id so a handset is only ever sent one copy. Two
     * paths lead to the same phone: a member can hold more than one
     * committee in the branch being addressed, and two member records
     * can carry the same address. Neither is a reason to ring a phone
     * twice.
     *
     * @return array<int, Device>
     */
    private function liveDevicesForCommittee(string $slug): array
    {
        $memberIds = $this->committees->memberIdsIn($slug);

        if ($memberIds === []) {
            return [];
        }

        $devices = [];

        foreach ($this->members->findAll(['post__in' => $memberIds]) as $member) {
            $email = $member->getPersonalEmail();

            if ($email === '') {
                continue;
            }

            foreach ($this->liveDevicesFor($email) as $device) {
                $devices[$device->id] = $device;
            }
        }

        return array_values($devices);
    }

    /**
     * The committees worth offering, as slug => label.
     *
     * Every committee is listed, including those nobody on them has a
     * handset for, with the reachable count in the label. Hiding them
     * would leave an admin wondering where a committee went; saying
     * "0 handsets" answers it on the spot, and the send is refused
     * plainly if they pick one anyway.
     *
     * The count is the branch, not the node, because that is what the
     * button would send to.
     *
     * @return array<string, string>
     */
    private function committees(): array
    {
        $committees = [];

        foreach ($this->committees->roots() as $root) {
            $this->collectCommittee($root, 0, $committees);
        }

        return $committees;
    }

    /**
     * @param array<string, string> $into
     */
    private function collectCommittee(Committee $committee, int $depth, array &$into): void
    {
        $slug = $committee->getSlug();
        $count = count($this->liveDevicesForCommittee($slug));

        $into[$slug] = str_repeat('— ', $depth)
            . $committee->getName()
            . ' (' . ($count === 1 ? '1 handset' : $count . ' handsets') . ')';

        foreach ($this->committees->childrenOf($slug) as $child) {
            $this->collectCommittee($child, $depth + 1, $into);
        }
    }

    /**
     * The responders with at least one live handset, as address => label.
     *
     * Keyed by address because that is what the form posts back and what
     * the handsets are matched on; the label is only ever shown. Sorted
     * by label so the dropdown reads as a list of people rather than as
     * a list of email addresses.
     *
     * @return array<string, string>
     */
    private function responders(): array
    {
        $presenter = new ResponderPresenter($this->members);

        $responders = [];
        foreach ($this->devices->findAllLive() as $device) {
            if ($device->memberEmail === '' || isset($responders[$device->memberEmail])) {
                continue;
            }

            $name = $presenter->name($device->memberEmail);

            // The address is appended rather than replaced: two
            // responders can share a display name, and the address is
            // what tells them apart — and what the box will hold once
            // one of them is chosen.
            $responders[$device->memberEmail] = $name === $device->memberEmail
                ? $device->memberEmail
                : $name . ' — ' . $device->memberEmail;
        }

        asort($responders, SORT_NATURAL | SORT_FLAG_CASE);

        return $responders;
    }

    /**
     * One responder's live handsets, matched on address without regard
     * to case — an admin who types an address by hand should not have to
     * match the capitalisation Unity happens to hold.
     *
     * @return array<int, Device>
     */
    private function liveDevicesFor(string $responder): array
    {
        $wanted = strtolower($responder);

        $devices = [];
        foreach ($this->devices->findAllLive() as $device) {
            if (strtolower($device->memberEmail) === $wanted) {
                $devices[] = $device;
            }
        }

        return $devices;
    }

    /**
     * Raise one message, for a named handset or for the whole rota.
     *
     * `$messageUuid` is empty for a single send — {@see \Reach\Alerts\AlertRequest}
     * mints one — and supplied only when several alerts are one message.
     *
     * @return int|WP_Error The alert's id, or why it was refused.
     */
    private function sendMessage(
        string $subject,
        string $body,
        string $level,
        string $response,
        int $deviceId = 0,
        string $messageUuid = ''
    ): int|WP_Error {
        return $this->alertApi->send([
            'kind'     => 'admin_message',
            'source'   => 'reach',
            'title'    => $subject,
            'body'     => $body,
            'level'    => $level,
            'response' => $response,
            // An hour: long enough that a handset briefly out of signal
            // still gets it when it comes back, short enough that one
            // switched on tomorrow is not told about a shift change that
            // has been and gone.
            'ttl'      => self::MESSAGE_TTL,
            'target_device_id' => $deviceId,
            'message_uuid'     => $messageUuid,
        ]);
    }

    /** admin-post.php with the handler named in the query string. */
    private function postUrl(): string
    {
        return (string) add_query_arg('action', self::MESSAGE_ACTION, admin_url('admin-post.php'));
    }

    /** The Hand devices screen, where delivery is confirmed. */
    private function devicesUrl(): string
    {
        return (string) add_query_arg(['page' => DevicesPage::PAGE_SLUG], admin_url('admin.php'));
    }

    /**
     * A trimmed, unslashed POST string, or '' for anything that is not
     * one.
     *
     * <b>wp_unslash() is not optional here.</b> WordPress runs
     * `wp_magic_quotes()` on every request, which adds slashes to
     * `$_POST` whatever the PHP configuration says — so a subject typed
     * as "Jo's phone is down" arrives with a backslash before the
     * apostrophe, and would
     * put a literal backslash on a responder's lock screen. The sibling
     * admin screens all unslash; this one inherited a reader that did
     * not.
     *
     * Nothing is sanitised here on purpose: {@see \Reach\Alerts\AlertRequest}
     * caps the length and strips the markup on the way in, and doing it
     * twice would mean two places to keep in step.
     */
    private function posted(string $key): string
    {
        return isset($_POST[$key]) && is_string($_POST[$key])
            ? trim(wp_unslash($_POST[$key]))
            : '';
    }

    /**
     * The admin notice for whatever the last send did, if anything.
     *
     * These are plain text, not markup: the whole string goes through
     * esc_html() below. An HTML entity written here reaches the admin as
     * the literal characters &mdash; so punctuation is the character
     * itself.
     */
    private function notice(): string
    {
        $messages = [
            'message_sent'      => ['success', 'Message sent. Every live handset should be ringing.'],
            'message_sent_responder' => ['success', 'Message sent. That responder\'s handsets should be ringing.'],
            'message_no_subject' => ['warning', 'A message needs a subject — that is the line the responder reads first.'],
            'message_sent_committee' => ['success', 'Message sent. Every handset on that committee should be ringing.'],
            'message_no_scope'  => ['warning', 'Choose who the message goes to: every live handset, one responder, or a committee.'],
            'message_no_committee' => ['warning', 'Choose a committee, or send to every live handset instead.'],
            'message_unknown_committee' => ['warning', 'That committee no longer exists. Pick one from the list.'],
            'message_committee_silent' => ['warning', 'Nobody on that committee has a handset enrolled, so the message was not sent.'],
            'message_no_responder' => ['warning', 'Choose a responder, or send to every live handset instead.'],
            'message_unknown_responder' => ['warning', 'No enrolled handset belongs to that responder. Pick one from the list.'],
            'message_failed'    => ['error', 'The message could not be sent. Check the Reach log for the reason.'],
        ];

        $key = isset($_GET['reach_result']) && is_string($_GET['reach_result'])
            ? sanitize_key($_GET['reach_result'])
            : '';

        if (!isset($messages[$key])) {
            return '';
        }

        [$type, $text] = $messages[$key];

        return '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>'
            . esc_html($text) . '</p></div>';
    }

    /** This screen's URL, flagged with what the last send did. */
    private function resultUrl(string $result): string
    {
        return (string) add_query_arg(
            ['page' => self::PAGE_SLUG, 'reach_result' => $result],
            admin_url('admin.php'),
        );
    }
}
