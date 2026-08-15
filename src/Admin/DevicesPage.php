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
 * <b>Sending a test alert.</b> The whole delivery chain — service
 * account, push token, notification channel, the handset's own alarm —
 * has a lot of links, most of them on someone else's infrastructure,
 * and the failure mode is silence. A button that rings every enrolled
 * handset on demand is the only practical way to know the rota is
 * actually covered before relying on it at 3am. The test alert is a
 * real alert through the real path; nothing about it is special-cased.
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
    private const REVOKE_ACTION = 'reach_revoke_device';
    private const TEST_ALERT_ACTION = 'reach_send_test_alert';
    private const PER_PAGE = 50;

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

        $page   = max(1, (int) ($_GET['paged'] ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $rows     = $this->devices->list(self::PER_PAGE, $offset);
        $total    = $this->devices->countAll();
        $recent   = $this->alerts->list(10, 0);
        $notice   = $this->notice();
        ?>
        <div class="wrap">
            <h1>Hand devices</h1>
            <?php echo $notice; ?>

            <p class="description">
                Handsets running the Hand app, each paired to one certified telephone responder.
                Alerts raised through Reach&rsquo;s alerting API are delivered to every live handset
                here. Eligibility is re-checked against Unity on every request, so a responder whose
                certification lapses stops receiving alerts automatically &mdash; revoking is for
                handsets that are lost, replaced, or no longer wanted.
            </p>

            <h2 class="title">Send a test alert</h2>
            <p class="description">
                Rings every live handset now, through the real delivery path. Use it after changing
                the Firebase credentials, after enrolling a handset, and before relying on the rota.
                The test alert carries no personal data.
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::TEST_ALERT_ACTION); ?>">
                <?php wp_nonce_field(self::TEST_ALERT_ACTION); ?>
                <p><button type="submit" class="button button-secondary">Send test alert</button></p>
            </form>

            <h2 class="title">Enrolled handsets</h2>
            <p class="description"><?php echo (int) $total; ?> in total.</p>

            <table class="wp-list-table widefat fixed striped" style="width: auto;">
                <thead>
                    <tr>
                        <th scope="col" style="width: 200px;">Responder</th>
                        <th scope="col" style="width: 200px;">Device</th>
                        <th scope="col" style="width: 110px;">Platform</th>
                        <th scope="col" style="width: 110px;">Delivery</th>
                        <th scope="col" style="width: 150px;">Enrolled</th>
                        <th scope="col" style="width: 150px;">Last seen</th>
                        <th scope="col" style="width: 140px;">Status</th>
                        <th scope="col" style="width: 100px;">&nbsp;</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows === []) : ?>
                        <tr><td colspan="8">No handsets have been enrolled yet.</td></tr>
                    <?php else :
                        foreach ($rows as $device) : ?>
                        <tr>
                            <td><?php echo esc_html($this->responderName($device)); ?></td>
                            <td><?php echo esc_html($device->label !== '' ? $device->label : '—'); ?></td>
                            <td><?php echo esc_html($device->platform); ?></td>
                            <td>
                                <?php echo $device->wantsPush()
                                    ? 'Push'
                                    : '<span title="This handset collects alerts by polling.">Poll</span>'; ?>
                            </td>
                            <td><?php echo esc_html($this->when($device->createdAt)); ?></td>
                            <td><?php echo esc_html($device->lastSeenAt > 0 ? $this->when($device->lastSeenAt) : '—'); ?></td>
                            <td>
                                <?php if ($device->isRevoked()) : ?>
                                    <span style="color:#b32d2e;">Revoked</span>
                                <?php else : ?>
                                    <span style="color:#008a20;">Live</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$device->isRevoked()) : ?>
                                <form method="post"
                                      action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                      onsubmit="return confirm('Revoke this handset? It will stop receiving alerts immediately and the responder will need to sign in again.');">
                                    <input type="hidden" name="action" value="<?php echo esc_attr(self::REVOKE_ACTION); ?>">
                                    <input type="hidden" name="device_id" value="<?php echo (int) $device->id; ?>">
                                    <?php wp_nonce_field(self::REVOKE_ACTION . '_' . $device->id); ?>
                                    <button type="submit" class="button button-small">Revoke</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach;
                    endif; ?>
                </tbody>
            </table>

            <h2 class="title">Recent alerts</h2>
            <table class="wp-list-table widefat fixed striped" style="width: auto;">
                <thead>
                    <tr>
                        <th scope="col" style="width: 150px;">When</th>
                        <th scope="col" style="width: 140px;">Kind</th>
                        <th scope="col" style="width: 120px;">Source</th>
                        <th scope="col" style="width: 300px;">Title</th>
                        <th scope="col" style="width: 200px;">Acknowledged by</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($recent === []) : ?>
                        <tr><td colspan="5">No alerts have been raised yet.</td></tr>
                    <?php else :
                        foreach ($recent as $alert) : ?>
                        <tr>
                            <td><?php echo esc_html($this->when($alert->createdAt)); ?></td>
                            <td><code><?php echo esc_html($alert->kind); ?></code></td>
                            <td><?php echo esc_html($alert->source); ?></td>
                            <td>
                                <?php echo esc_html($alert->title); ?>
                                <?php if ($alert->isUrgent()) : ?>
                                    <strong style="color:#b32d2e;">(urgent)</strong>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($this->acknowledgedBy($alert)); ?></td>
                        </tr>
                        <?php endforeach;
                    endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function handleRevoke(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('You are not allowed to do that.', 'Forbidden', ['response' => 403]);
        }

        $deviceId = (int) ($_POST['device_id'] ?? 0);
        check_admin_referer(self::REVOKE_ACTION . '_' . $deviceId);

        $revoked = $deviceId > 0 && $this->devices->revoke($deviceId, time());

        $this->redirectBack($revoked ? 'revoked' : 'revoke_failed');
    }

    public function handleTestAlert(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('You are not allowed to do that.', 'Forbidden', ['response' => 403]);
        }

        check_admin_referer(self::TEST_ALERT_ACTION);

        $user = wp_get_current_user();
        $who = $user instanceof \WP_User && $user->display_name !== '' ? $user->display_name : 'an administrator';

        $result = $this->alertApi->send([
            'kind'     => 'test',
            'source'   => 'reach',
            'title'    => 'Hand test alert',
            'body'     => 'This is a test sent from the Reach admin by ' . $who . '. No action is needed.',
            'priority' => Alert::PRIORITY_NORMAL,
            // Short: a test that is still ringing handsets ten minutes
            // later is a nuisance, and its only job is to arrive now.
            'ttl'      => 300,
        ]);

        $this->redirectBack(is_wp_error($result) ? 'test_failed' : 'test_sent');
    }

    /**
     * The responder a handset belongs to, by name where Unity knows one
     * and by email otherwise — matching how the call-requests list
     * identifies people.
     */
    private function responderName(Device $device): string
    {
        $member = $this->members->findByEmail($device->memberEmail);
        if ($member !== null) {
            $name = trim($member->getAnonymousName());
            if ($name !== '') {
                return $name;
            }
        }

        return $device->memberEmail;
    }

    /**
     * A short summary of who has alarmed for an alert — the answer to
     * "did this reach anybody".
     */
    private function acknowledgedBy(Alert $alert): string
    {
        $acks = $this->alerts->acknowledgementsFor($alert->id);
        if ($acks === []) {
            return 'Nobody yet';
        }

        $names = [];
        foreach ($acks as $ack) {
            $member = $this->members->findByEmail($ack['member_email']);
            $name = $member !== null ? trim($member->getAnonymousName()) : '';
            $names[] = $name !== '' ? $name : $ack['member_email'];
        }

        return implode(', ', array_unique($names));
    }

    private function when(int $timestamp): string
    {
        return function_exists('wp_date')
            ? (string) wp_date('Y-m-d H:i', $timestamp)
            : gmdate('Y-m-d H:i', $timestamp) . ' UTC';
    }

    /**
     * The admin notice for whatever the last action did, if anything.
     */
    private function notice(): string
    {
        $messages = [
            'revoked'       => ['success', 'Handset revoked. It will stop receiving alerts immediately.'],
            'revoke_failed' => ['error', 'That handset could not be revoked — it may already have been.'],
            'test_sent'     => ['success', 'Test alert sent. Every live handset should be ringing.'],
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

    private function redirectBack(string $result): void
    {
        wp_safe_redirect(add_query_arg(
            ['page' => self::PAGE_SLUG, 'reach_result' => $result],
            admin_url('admin.php'),
        ));
        exit;
    }
}
