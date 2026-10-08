<?php

declare(strict_types=1);

namespace Reach\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use Guardian\Admin\ProviderCredentialsSection;
use Guardian\Admin\ProviderField;
use Reach\Alerts\Fcm\ServiceAccount;
use Reach\Core\Settings;

/**
 * Admin settings page for Reach.
 *
 * Sits as the "Settings" submenu under the top-level Reach menu
 * (registered by CallAttemptsPage). Hosts two groups of settings:
 *
 *   - Find page — the place-name disambiguation bias used when
 *     resolving ambiguous locality names (a single text field,
 *     typically a postcode like "BS5"; see Settings::getPlaceBias).
 *
 *   - Authentication — the four OAuth providers (Google, Microsoft,
 *     Apple, Facebook), rendered and saved by Guardian's
 *     ProviderCredentialsSection: a client ID field and a write-only
 *     client secret field for each, with its redirect URI to copy.
 *
 * Secrets are AES-256-GCM encrypted at rest by the Settings class
 * (see Reach\Core\Settings::encrypt) and never come back to the
 * browser. Submitting an empty secret field leaves the stored value
 * untouched — clearing requires ticking the explicit "clear"
 * checkbox.
 */
final class SettingsPage
{
    // No OPTION_GROUP constant: registerSettings() deliberately doesn't
    // use the Settings API (the secret fields need custom merge logic),
    // so there is no option group to register anything against.
    private const PAGE_SLUG = 'reach-settings';
    private const CAPABILITY = 'manage_options';

    public function __construct(
        private readonly Settings $settings,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_post_reach_save_settings', [$this, 'handleSave']);
    }

    public function addMenu(): void
    {
        // The top-level "Reach" menu is registered by CallAttemptsPage.
        // We attach as a submenu so OAuth configuration sits next to
        // the operational data view rather than under "Settings".
        //
        // The capability here (manage_options) is intentionally
        // *stricter* than the parent menu's capability
        // (scrutiny_view_personal_data). A user with the parent
        // capability who lacks manage_options simply won't see this
        // submenu item — WP handles that automatically.
        add_submenu_page(
            CallAttemptsPage::MENU_SLUG,
            'Settings',
            'Settings',
            self::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function registerSettings(): void
    {
        // We use the manual admin-post handler rather than the Settings
        // API because the secret fields need custom merge logic — empty
        // means "don't change", which isn't a standard option-update
        // behaviour.
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $notice = '';
        if (isset($_GET['updated']) && $_GET['updated'] === '1') {
            $notice = '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
        }

        // The stored key file is never echoed back — like the OAuth
        // client secrets, it is write-only once saved. What is shown is
        // whether one is present and which Firebase project it belongs
        // to, which is the part an admin actually needs to confirm.
        $fcmAccount = ServiceAccount::fromJson($this->settings->getFcmServiceAccount());
        $hasFcm = $fcmAccount !== null;
        $fcmProject = $fcmAccount !== null ? $fcmAccount->projectId : '';

        ?>
        <div class="wrap">
            <h1>Reach</h1>
            <?php echo $notice; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="reach_save_settings">
                <?php wp_nonce_field('reach_save_settings'); ?>

                <h2>Find page</h2>
                <p>Settings that affect the public <code>/reach/find</code> page.</p>
                <table class="form-table">
                    <tr>
                        <th><label for="reach_place_bias">Default search area</label></th>
                        <td>
                            <input type="text"
                                   id="reach_place_bias"
                                   name="place_bias"
                                   value="<?php echo esc_attr($this->settings->getPlaceBias()); ?>"
                                   class="regular-text"
                                   placeholder="e.g. BS5"
                                   autocomplete="off">
                            <p class="description">
                                Disambiguates place-name searches toward your intergroup&rsquo;s region. When a visitor or member area is a locality name that exists in several places (for example <em>Kingswood</em>, which is both a Bristol suburb and a village in Surrey, Warwickshire and elsewhere), Reach picks the candidate closest to this centre. A postcode or outcode (e.g. <code>BS5</code>) is the most reliable choice; a place name will also work but inherits whatever postcodes.io ranks first for it. Leave blank to disable biasing.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="reach_out_of_hours_start">Out of hours</label></th>
                        <td>
                            <label for="reach_out_of_hours_start" class="screen-reader-text">Out-of-hours start time</label>
                            <input type="time"
                                   id="reach_out_of_hours_start"
                                   name="out_of_hours_start"
                                   value="<?php echo esc_attr($this->settings->getOutOfHoursStart()); ?>">
                            <span aria-hidden="true">&ndash;</span>
                            <label for="reach_out_of_hours_end" class="screen-reader-text">Out-of-hours end time</label>
                            <input type="time"
                                   id="reach_out_of_hours_end"
                                   name="out_of_hours_end"
                                   value="<?php echo esc_attr($this->settings->getOutOfHoursEnd()); ?>">
                            <p class="description">
                                During these hours the find page offers a <em>Request a callback</em> option beside each responder, so the caller&rsquo;s details can be passed on instead of ringing the 12th&#8209;Stepper directly. Times are 24&#8209;hour and use the site timezone; a window may span midnight (e.g. <code>22:00 &ndash; 08:00</code>). Leave either field blank to switch the feature off.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="reach_call_request_email">Call request email</label></th>
                        <td>
                            <input type="email"
                                   id="reach_call_request_email"
                                   name="call_request_email"
                                   value="<?php echo esc_attr($this->settings->getCallRequestEmail()); ?>"
                                   class="regular-text"
                                   placeholder="<?php echo esc_attr((string) get_option('admin_email')); ?>"
                                   autocomplete="off">
                            <p class="description">
                                Where callback requests are emailed. Each <em>Request a callback</em> raised on the find page is sent here with the caller&rsquo;s name, phone, preferred 12th&#8209;Stepper and any note, plus a reference number &mdash; so the caller&rsquo;s details live in this inbox rather than in the database. Leave blank to use the site admin address (<code><?php echo esc_html((string) get_option('admin_email')); ?></code>).
                            </p>
                        </td>
                    </tr>
                </table>

                <h2>Hand push notifications</h2>
                <p>
                    Optional. Reach alerts reach Hand handsets either way &mdash; every handset polls
                    as well as listening &mdash; but without Firebase an alert arrives at the next poll
                    rather than instantly, and a phone with the app closed will not ring at all.
                </p>
                <table class="form-table">
                    <tr>
                        <th><label for="reach_fcm_service_account">Firebase service account</label></th>
                        <td>
                            <textarea id="reach_fcm_service_account"
                                      name="fcm_service_account"
                                      rows="6"
                                      class="large-text code"
                                      autocomplete="off"
                                      placeholder="<?php echo $hasFcm
                                        ? 'A service account is saved. Paste a new key file to replace it.'
                                        : 'Paste the whole service-account JSON key file here.'; ?>"></textarea>
                            <p class="description">
                                From the Firebase console: <em>Project settings &rarr; Service accounts &rarr;
                                Generate new private key</em>. Paste the entire JSON file. It is encrypted at rest,
                                and is never shown again once saved &mdash; leave this blank to keep the existing one.
                                <?php if ($hasFcm) : ?>
                                    <br><strong>Status:</strong> a service account is saved for project
                                    <code><?php echo esc_html($fcmProject); ?></code>.
                                <?php else : ?>
                                    <br><strong>Status:</strong> not configured &mdash; handsets are polling only.
                                <?php endif; ?>
                            </p>
                            <p>
                                <label>
                                    <input type="checkbox" name="fcm_service_account_clear" value="1">
                                    Remove the stored service account
                                </label>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="reach_apns_critical">iOS critical alerts</label></th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       id="reach_apns_critical"
                                       name="apns_critical"
                                       value="1"
                                       <?php checked($this->settings->isApnsCriticalEnabled()); ?>>
                                Send urgent alerts as critical notifications on iOS
                            </label>
                            <p class="description">
                                Critical alerts sound through the ringer switch and Do&nbsp;Not&nbsp;Disturb &mdash;
                                which is what a duty handset wants. Apple gates them behind the
                                <code>com.apple.developer.usernotifications.critical-alerts</code> entitlement, which
                                is granted only on application.
                                <strong>Do not switch this on until that entitlement is in the Hand app&rsquo;s
                                provisioning profile:</strong> without it Apple <em>rejects</em> the notification
                                rather than downgrading it, so this would silence the very alerts it is meant to make
                                louder. Leave it off and urgent alerts still use the time&#8209;sensitive level, which
                                breaks through a Focus mode.
                            </p>
                        </td>
                    </tr>
                </table>

                <h2>Authentication</h2>
                <p>Configure the OAuth providers that Reach uses to verify a visitor&rsquo;s email address. Each provider needs a client ID and (except Apple) a client secret. Secrets are encrypted at rest.</p>

                <?php $this->providerSection()->render(); ?>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function handleSave(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Insufficient permissions', '', ['response' => 403]);
        }
        check_admin_referer('reach_save_settings');

        $this->saveFromRequest();

        // The page moved from "Settings → Reach" (options-general.php)
        // to "Reach → Authentication" (admin.php) when the top-level
        // Reach menu was introduced. Redirect target must match.
        wp_safe_redirect(add_query_arg(['page' => self::PAGE_SLUG, 'updated' => '1'], admin_url('admin.php')));
        exit;
    }

    /**
     * Write the submitted form into {@see Settings}.
     *
     * Split out of {@see handleSave()} so it can be driven in a test:
     * everything above it is a guard and everything below is
     * `wp_safe_redirect(); exit;`, and the `exit` takes the test runner
     * with it. Behaviour is unchanged — the same body in the same order,
     * with the redirect left behind in the caller.
     */
    private function saveFromRequest(): void
    {
        // Find-page settings.
        $placeBias = isset($_POST['place_bias']) && is_string($_POST['place_bias'])
            ? sanitize_text_field(wp_unslash($_POST['place_bias']))
            : '';
        $this->settings->setPlaceBias($placeBias);

        // Out-of-hours window. Settings::setOutOfHours validates the
        // H:i shape itself and blanks anything that doesn't parse, so
        // we only need to unslash and string-guard here.
        $ohStart = isset($_POST['out_of_hours_start']) && is_string($_POST['out_of_hours_start'])
            ? sanitize_text_field(wp_unslash($_POST['out_of_hours_start']))
            : '';
        $ohEnd = isset($_POST['out_of_hours_end']) && is_string($_POST['out_of_hours_end'])
            ? sanitize_text_field(wp_unslash($_POST['out_of_hours_end']))
            : '';
        $this->settings->setOutOfHours($ohStart, $ohEnd);

        // Call-request notification address. setCallRequestEmail blanks
        // anything that isn't a valid email (falling the getter back to
        // the site admin address), so we only unslash and string-guard.
        $callRequestEmail = isset($_POST['call_request_email']) && is_string($_POST['call_request_email'])
            ? sanitize_text_field(wp_unslash($_POST['call_request_email']))
            : '';
        $this->settings->setCallRequestEmail($callRequestEmail);

        // Firebase service account. Same write-only handling as the
        // OAuth client secrets: an empty submission leaves the stored
        // value untouched, and removing one takes an explicit checkbox
        // so a blank textarea can never wipe it by accident.
        //
        // Deliberately not sanitize_text_field: this is a JSON document
        // whose private_key contains newlines, and collapsing those
        // would corrupt the key into something that cannot sign.
        if (!empty($_POST['fcm_service_account_clear'])) {
            $this->settings->setFcmServiceAccount('');
        } elseif (isset($_POST['fcm_service_account']) && is_string($_POST['fcm_service_account'])) {
            $serviceAccount = trim(wp_unslash($_POST['fcm_service_account']));
            if ($serviceAccount !== '') {
                $this->settings->setFcmServiceAccount($serviceAccount);
            }
        }

        $this->settings->setApnsCriticalEnabled(!empty($_POST['apns_critical']));

        $this->providerSection()->save($_POST);
    }

    /**
     * The client id and secret rows for the four providers, from Guardian.
     *
     * Field names carry no prefix, so they are the names this page always
     * used (`client_id_google`, `client_secret_google`); only the clearing
     * checkbox is now `clear_secret_*`.
     */
    private function providerSection(): ProviderCredentialsSection
    {
        $callbackUrl = rest_url('reach/v1/oauth/callback');

        return new ProviderCredentialsSection($this->settings, [
            ProviderField::google($callbackUrl),
            ProviderField::microsoft($callbackUrl),
            ProviderField::apple(
                home_url('/reach/signin'),
                'Apple signs in through its JS SDK in a popup, which returns to the page above — register it as the return URL. No client secret is needed, only the Service ID the ID token is issued for.',
            ),
            ProviderField::facebook($callbackUrl),
        ]);
    }
}
