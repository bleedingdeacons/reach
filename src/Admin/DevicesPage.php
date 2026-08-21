<?php

declare(strict_types=1);

namespace Reach\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Alerts\Alert;
use Reach\Alerts\AlertApi;
use Reach\Alerts\AlertRepository;
use Reach\Devices\Device;
use Reach\Devices\DeviceRepository;
use Scrutiny\Privacy\PersonalDataPolicy;
use Unity\Members\Interfaces\MemberRepository;
use WP_Error;

/**
 * Admin view of the Hand handsets enrolled against this site, and the
 * alerts that have been sent to them.
 *
 * Two things an admin needs and cannot get anywhere else:
 *
 * <b>Revoking a handset.</b> A phone is lost, or replaced, or a
 * responder has left. Revoking cuts its token dead immediately. This is
 * a belt-and-braces control rather than the primary one — eligibility is
 * re-checked against Unity on every single request, so a responder who
 * loses their certification stops receiving alerts whether or not
 * anybody remembers to come here — but "that handset, now" is a
 * question the automatic path cannot answer.
 *
 * <b>Removing a handset.</b> The harder-edged sibling of revoking, and
 * two things rather than one: the handset is sent a notice telling it
 * it has been taken off the rota, and then its row is deleted outright.
 * Revoking leaves a record; removing is for a pairing that should not
 * be in the record at all — enrolled by mistake, or a responder asking
 * for it to be erased rather than merely disabled. The notice goes
 * first and by push only: once the row is gone the handset has no token
 * left to poll with, so a notice sent afterwards could never arrive.
 *
 * <b>Sending a test alert.</b> The whole delivery chain — service
 * account, push token, notification channel, the handset's own alarm —
 * has a lot of links, most of them on someone else's infrastructure,
 * and the failure mode is silence. A button that rings handsets on
 * demand is the only practical way to know the rota is actually covered
 * before relying on it at 3am. The test alert is a real alert through
 * the real path; nothing about it is special-cased.
 *
 * The test can go to every live handset at once, or to a ticked
 * selection. The selection is what makes the button diagnostic rather
 * than merely reassuring: a broadcast that six handsets answer and a
 * seventh does not looks much like a broadcast that seven answer, and
 * ringing one phone on its own is how you find out which one is deaf.
 * Selected handsets each get their own alert rather than sharing one,
 * so the Recent alerts table answers per handset instead of hiding a
 * silent phone behind a colleague's acknowledgement.
 *
 * Rows are shown revoked as well as live, so the list is a record of
 * what has been enrolled rather than only what is enrolled now.
 *
 * Capability
 * ----------
 * scrutiny_view_personal_data, matching the sibling pages: the list
 * shows which responder each handset belongs to.
 */
final class DevicesPage
{
    public const PAGE_SLUG = 'reach-devices';
    private const CAPABILITY = PersonalDataPolicy::VIEW_CAPABILITY;
    /**
     * The two row actions are public because the rows are rendered by
     * {@see DevicesListTable}, while the handlers stay here with the
     * rest of the screen's POST plumbing. Same reasoning as
     * {@see PAGE_SLUG}.
     */
    public const REVOKE_ACTION = 'reach_revoke_device';
    public const REMOVE_ACTION = 'reach_remove_device';
    private const TEST_ALERT_ACTION = 'reach_send_test_alert';

    /**
     * Id of the test-alert form.
     *
     * The row checkboxes live inside the handsets table and are bound to
     * this form by their `form` attribute rather than by being nested in
     * it: the rows already carry their own Revoke and Remove forms, and
     * a form inside a form is not something a browser will parse.
     */
    private const TEST_FORM_ID = 'reach-test-alert';

    /** Which handsets a test alert is for. */
    private const SCOPE_SELECTED = 'selected';

    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly AlertRepository $alerts,
        private readonly AlertApi $alertApi,
        private readonly MemberRepository $members,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_post_' . self::REVOKE_ACTION, [$this, 'handleRevoke']);
        add_action('admin_post_' . self::REMOVE_ACTION, [$this, 'handleRemove']);
        add_action('admin_post_' . self::TEST_ALERT_ACTION, [$this, 'handleTestAlert']);
    }

    public function addMenu(): void
    {
        // Parent menu ("Reach") is registered by CallAttemptsPage.
        add_submenu_page(
            CallAttemptsPage::MENU_SLUG,
            'Hand devices',
            'Hand devices',
            self::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'renderList'],
        );
    }

    public function renderList(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        // WP_List_Table lives in wp-admin/includes and is not loaded on
        // every admin request — only on the core screens that use it. A
        // plugin screen has to ask for it, and has to ask before the two
        // subclasses are autoloaded, or they extend a class that is not
        // there yet.
        if (!class_exists('WP_List_Table')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }

        $handsets = new DevicesListTable($this->devices, $this->members, self::TEST_FORM_ID);
        $handsets->prepare_items();

        $alerts = new AlertsListTable($this->alerts, $this->members);
        $alerts->prepare_items();

        $notice = $this->notice();
        ?>
        <div class="wrap">
            <h1>Hand devices</h1>
            <?php echo $notice; ?>

            <p class="description">
                Handsets running the Hand app, each paired to one certified telephone responder.
                Alerts raised through Reach&rsquo;s alerting API are delivered to every live handset
                here. Eligibility is re-checked against Unity on every request, so a responder whose
                certification lapses stops receiving alerts automatically &mdash; revoking is for
                handsets that are lost, replaced, or no longer wanted. Removing goes further:
                it tells the handset it is off the rota and then deletes its record here
                altogether, rather than keeping it as history.
            </p>

            <h2 class="title">Send a test alert</h2>
            <p class="description">
                Rings handsets now, through the real delivery path. Use it after changing the
                Firebase credentials, after enrolling a handset, and before relying on the rota.
                Tick handsets in the table below and send to the selection to ring one phone on
                its own &mdash; which is how you find out <em>which</em> handset is deaf, rather
                than only that one of them is. The test alert carries no personal data.
            </p>
            <form id="<?php echo esc_attr(self::TEST_FORM_ID); ?>"
                  method="post"
                  action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::TEST_ALERT_ACTION); ?>">
                <?php wp_nonce_field(self::TEST_ALERT_ACTION); ?>
                <p>
                    <button type="submit" name="reach_scope" value="all" class="button button-secondary">
                        Send to every live handset
                    </button>
                    <button type="submit"
                            name="reach_scope"
                            value="<?php echo esc_attr(self::SCOPE_SELECTED); ?>"
                            class="button button-secondary">
                        Send to the selected handsets
                    </button>
                </p>
            </form>

            <h2 class="title">Enrolled handsets</h2>
            <p class="description"><?php echo (int) $handsets->get_pagination_arg('total_items'); ?> in total.</p>

            <?php $handsets->display(); ?>

            <script>
                // Tick-all for the handset selection. Written here rather
                // than left to core's list-table JS, which this screen
                // cannot rely on: the boxes belong to the test-alert form
                // by their `form` attribute and sit in one of two tables
                // on the page, so the binding is scoped to this table by
                // class rather than to every list table on the screen.
                (function () {
                    var table = document.querySelector('table.reach-handsets');
                    if (!table) {
                        return;
                    }

                    var boxes = table.querySelectorAll('.reach-device-select');
                    // Core renders one tick-all in the head and another in
                    // the foot, and they have to agree with each other.
                    var alls = table.querySelectorAll('.check-column input[type="checkbox"]:not(.reach-device-select)');
                    if (boxes.length === 0 || alls.length === 0) {
                        return;
                    }

                    function setAll(checked) {
                        alls.forEach(function (all) {
                            all.checked = checked;
                        });
                    }

                    alls.forEach(function (all) {
                        all.addEventListener('change', function () {
                            boxes.forEach(function (box) {
                                box.checked = all.checked;
                            });
                            setAll(all.checked);
                        });
                    });

                    boxes.forEach(function (box) {
                        box.addEventListener('change', function () {
                            setAll(Array.prototype.every.call(boxes, function (b) {
                                return b.checked;
                            }));
                        });
                    });
                })();
            </script>

            <h2 class="title">Recent alerts</h2>
            <?php $alerts->display(); ?>
        </div>
        <?php
    }

    public function handleRevoke(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('You are not allowed to do that.', 'Forbidden', ['response' => 403]);
        }

        wp_safe_redirect($this->revokeFromRequest());
        exit;
    }

    /**
     * Apply the revoke POST and return where the browser goes next.
     *
     * Split out of {@see handleRevoke()} for the reason
     * {@see CallRequestsPage::completeFromRequest()} documents: everything
     * above is a guard, everything below is `wp_safe_redirect(); exit;`,
     * and the `exit` takes the test runner with it. Same body, same order,
     * with the target returned rather than issued.
     */
    private function revokeFromRequest(): string
    {
        $deviceId = (int) ($_POST['device_id'] ?? 0);
        check_admin_referer(self::REVOKE_ACTION . '_' . $deviceId);

        $revoked = $deviceId > 0 && $this->devices->revoke($deviceId, time());

        return $this->resultUrl($revoked ? 'revoked' : 'revoke_failed');
    }

    public function handleRemove(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('You are not allowed to do that.', 'Forbidden', ['response' => 403]);
        }

        wp_safe_redirect($this->removeFromRequest());
        exit;
    }

    /**
     * Notify the handset, delete its row, and return where the browser
     * goes next. Split out of {@see handleRemove()} for the same reason
     * as {@see revokeFromRequest()}.
     *
     * <b>The order is the whole design.</b> The notice can only reach a
     * removed handset by push: deleting the row destroys the token it
     * would poll with, so anything sent afterwards has nowhere to go and
     * nothing to authenticate. Sending first also means the dispatcher
     * can still resolve the device it is addressed to.
     *
     * The delete happens whatever the notice did. A handset that is out
     * of signal, or whose owner has already lost their certification,
     * will not hear about it — but an admin who asked for a pairing to
     * be erased asked for that, not for it to be erased only if Google
     * was reachable.
     */
    private function removeFromRequest(): string
    {
        $deviceId = (int) ($_POST['device_id'] ?? 0);
        check_admin_referer(self::REMOVE_ACTION . '_' . $deviceId);

        $device = $deviceId > 0 ? $this->devices->findById($deviceId) : null;
        if ($device === null) {
            return $this->resultUrl('remove_failed');
        }

        $this->alertApi->send([
            'kind'     => 'device_removed',
            'source'   => 'reach',
            'title'    => 'This handset has been removed',
            'body'     => 'An administrator has taken this handset off the alert rota. '
                . 'It will stop receiving alerts. Sign in again to enrol it afresh.',
            'priority' => Alert::PRIORITY_NORMAL,
            // Long enough to survive a phone that is briefly asleep,
            // short enough that a handset switched on next week is not
            // told about a removal it has long since noticed.
            'ttl'      => 3600,
            'target_device_id' => $device->id,
        ]);

        return $this->resultUrl($this->devices->delete($device->id) ? 'removed' : 'remove_failed');
    }

    public function handleTestAlert(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('You are not allowed to do that.', 'Forbidden', ['response' => 403]);
        }

        wp_safe_redirect($this->testAlertFromRequest());
        exit;
    }

    /**
     * Raise the test alert(s) and return where the browser goes next.
     * Split out of {@see handleTestAlert()} for the same reason as
     * {@see revokeFromRequest()}.
     *
     * Two scopes behind one nonce, chosen by which button was pressed
     * rather than inferred from whether anything happens to be ticked:
     * an admin who ticks three handsets and then presses "every live
     * handset" gets what the button says, not what the checkboxes hint.
     */
    private function testAlertFromRequest(): string
    {
        check_admin_referer(self::TEST_ALERT_ACTION);

        $who = $this->adminName();

        $scope = isset($_POST['reach_scope']) && is_string($_POST['reach_scope'])
            ? sanitize_key($_POST['reach_scope'])
            : '';

        if ($scope !== self::SCOPE_SELECTED) {
            return $this->resultUrl(
                is_wp_error($this->sendTestAlert($who)) ? 'test_failed' : 'test_sent',
            );
        }

        $devices = $this->selectedLiveDevices();
        if ($devices === []) {
            return $this->resultUrl('test_none_selected');
        }

        // One alert per handset rather than one shared between them. Each
        // then carries its own acknowledgement, so the Recent alerts
        // table answers "did this handset ring" for every phone in the
        // selection instead of letting a silent one hide behind a
        // colleague's answer.
        $failed = false;
        foreach ($devices as $device) {
            if (is_wp_error($this->sendTestAlert($who, $device->id))) {
                $failed = true;
            }
        }

        return $this->resultUrl($failed ? 'test_failed' : 'test_sent_selected');
    }

    /**
     * Raise one test alert, for a named handset or for the whole rota.
     *
     * @return int|WP_Error The alert's id, or why it was refused.
     */
    private function sendTestAlert(string $who, int $deviceId = 0): int|WP_Error
    {
        return $this->alertApi->send([
            'kind'     => 'test',
            'source'   => 'reach',
            'title'    => 'Hand test alert',
            'body'     => 'This is a test sent from the Reach admin by ' . $who . '. No action is needed.',
            'priority' => Alert::PRIORITY_NORMAL,
            // Short: a test that is still ringing handsets ten minutes
            // later is a nuisance, and its only job is to arrive now.
            'ttl'      => 300,
            'target_device_id' => $deviceId,
        ]);
    }

    /**
     * The live handsets ticked in the list, deduplicated and in the
     * order they were posted.
     *
     * Every id is resolved against the repository rather than trusted
     * from the form. A posted id is only ever a row number an admin's
     * browser sent back, and a revoked handset — whose row has no
     * checkbox to tick — must not become testable by editing one in.
     *
     * @return array<int, Device>
     */
    private function selectedLiveDevices(): array
    {
        $posted = $_POST['device_ids'] ?? [];
        if (!is_array($posted)) {
            return [];
        }

        $devices = [];
        foreach ($posted as $value) {
            $id = is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : 0;
            if ($id <= 0 || isset($devices[$id])) {
                continue;
            }

            $device = $this->devices->findById($id);
            if ($device !== null && !$device->isRevoked()) {
                $devices[$id] = $device;
            }
        }

        return array_values($devices);
    }

    /** Who to say sent a test alert, without naming an account nobody set up. */
    private function adminName(): string
    {
        $user = wp_get_current_user();

        return $user instanceof \WP_User && $user->display_name !== ''
            ? $user->display_name
            : 'an administrator';
    }





    /**
     * The admin notice for whatever the last action did, if anything.
     */
    private function notice(): string
    {
        $messages = [
            'revoked'       => ['success', 'Handset revoked. It will stop receiving alerts immediately.'],
            'revoke_failed' => ['error', 'That handset could not be revoked — it may already have been.'],
            'removed'       => ['success', 'Handset removed. Its record here is gone, and a notice has been sent telling it so &mdash; a handset that is out of signal will simply stop authenticating instead.'],
            'remove_failed' => ['error', 'That handset could not be removed — it may already have been.'],
            'test_sent'     => ['success', 'Test alert sent. Every live handset should be ringing.'],
            'test_sent_selected' => ['success', 'Test alert sent. The selected handsets should be ringing.'],
            'test_none_selected' => ['warning', 'Tick at least one live handset before sending to a selection.'],
            'test_failed'   => ['error', 'The test alert could not be sent. Check the Reach log for the reason.'],
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

    /** The list URL, flagged with what the last action did. */
    private function resultUrl(string $result): string
    {
        return (string) add_query_arg(
            ['page' => self::PAGE_SLUG, 'reach_result' => $result],
            admin_url('admin.php'),
        );
    }
}
