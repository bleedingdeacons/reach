<?php

declare(strict_types=1);

namespace Reach\Admin;

if (!defined('ABSPATH')) {
    exit;
}

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
 *     Apple, Facebook). Each provider gets a client ID field and a
 *     write-only client secret field: the existing secret is
 *     displayed as a fixed-width placeholder so an admin can see it's
 *     set without it being readable from the form.
 *
 * Secrets are AES-256-GCM encrypted at rest by the Settings class
 * (see Reach\Core\Settings::encrypt) and never come back to the
 * browser. Submitting an empty secret field leaves the stored value
 * untouched — clearing requires checking the explicit "remove"
 * checkbox.
 */
final class SettingsPage
{
    private const OPTION_GROUP = 'reach_settings_group';
    private const PAGE_SLUG = 'reach-settings';
    private const CAPABILITY = 'manage_options';

    /** @var array<int, array{name: string, label: string, redirect_help: string}> */
    private const PROVIDERS = [
        ['name' => 'google',    'label' => 'Google',    'redirect_help' => 'wp-json/reach/v1/oauth/callback'],
        ['name' => 'microsoft', 'label' => 'Microsoft', 'redirect_help' => 'wp-json/reach/v1/oauth/callback'],
        ['name' => 'apple',     'label' => 'Apple',     'redirect_help' => 'reach/signin (page URL, used for popup)'],
        ['name' => 'facebook',  'label' => 'Facebook',  'redirect_help' => 'wp-json/reach/v1/oauth/callback'],
    ];

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

        $callbackUrl = rest_url('reach/v1/oauth/callback');
        $appleRedirectUrl = home_url('/reach/signin');

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

                <h2>Authentication</h2>
                <p>Configure the OAuth providers that Reach uses to verify a visitor&rsquo;s email address. Each provider needs a client ID and (except Apple) a client secret. Secrets are encrypted at rest.</p>

                <h3>Redirect URIs</h3>
                <p>Register these with each provider. The domain matches your WordPress Site Address &mdash; change it under <em>Settings &rarr; General</em> if it&rsquo;s wrong.</p>
                <table class="form-table">
                    <tr>
                        <th>Google / Microsoft / Facebook callback</th>
                        <td>
                            <code class="reach-copyable" id="reach-callback-url"><?php echo esc_html($callbackUrl); ?></code>
                            <button type="button"
                                    class="button button-secondary reach-copy-btn"
                                    data-clipboard-target="reach-callback-url">Copy</button>
                        </td>
                    </tr>
                    <tr>
                        <th>Apple redirect (popup)</th>
                        <td>
                            <code class="reach-copyable" id="reach-apple-url"><?php echo esc_html($appleRedirectUrl); ?></code>
                            <button type="button"
                                    class="button button-secondary reach-copy-btn"
                                    data-clipboard-target="reach-apple-url">Copy</button>
                        </td>
                    </tr>
                </table>

                <style>
                    .reach-copyable {
                        display: inline-block;
                        padding: 4px 8px;
                        background: #f0f0f1;
                        border: 1px solid #c3c4c7;
                        border-radius: 3px;
                        margin-right: 6px;
                        user-select: all;
                    }
                    .reach-copy-btn[data-copied="1"] {
                        color: #00713c;
                        border-color: #00713c;
                    }
                </style>
                <script>
                    (function () {
                        document.querySelectorAll('.reach-copy-btn').forEach(function (btn) {
                            btn.addEventListener('click', function () {
                                var targetId = btn.getAttribute('data-clipboard-target');
                                var target = document.getElementById(targetId);
                                if (!target) {
                                    return;
                                }
                                var text = target.textContent.trim();
                                var done = function () {
                                    var original = btn.textContent;
                                    btn.textContent = 'Copied';
                                    btn.setAttribute('data-copied', '1');
                                    setTimeout(function () {
                                        btn.textContent = original;
                                        btn.removeAttribute('data-copied');
                                    }, 1500);
                                };
                                if (navigator.clipboard && window.isSecureContext) {
                                    navigator.clipboard.writeText(text).then(done, function () {
                                        fallbackCopy(text, done);
                                    });
                                } else {
                                    fallbackCopy(text, done);
                                }
                            });
                        });

                        function fallbackCopy(text, done) {
                            var ta = document.createElement('textarea');
                            ta.value = text;
                            ta.setAttribute('readonly', '');
                            ta.style.position = 'absolute';
                            ta.style.left = '-9999px';
                            document.body.appendChild(ta);
                            ta.select();
                            try {
                                document.execCommand('copy');
                                done();
                            } catch (e) {
                                // Last resort: leave the value selected so the
                                // admin can Ctrl/Cmd-C manually.
                            }
                            document.body.removeChild(ta);
                        }
                    })();
                </script>

                <?php foreach (self::PROVIDERS as $provider): ?>
                    <h3><?php echo esc_html($provider['label']); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th><label for="reach_client_id_<?php echo esc_attr($provider['name']); ?>">Client ID</label></th>
                            <td>
                                <input type="text"
                                       id="reach_client_id_<?php echo esc_attr($provider['name']); ?>"
                                       name="client_id_<?php echo esc_attr($provider['name']); ?>"
                                       value="<?php echo esc_attr($this->settings->getClientId($provider['name'])); ?>"
                                       class="regular-text"
                                       autocomplete="off">
                            </td>
                        </tr>
                        <?php if ($provider['name'] !== 'apple'): ?>
                        <tr>
                            <th><label for="reach_client_secret_<?php echo esc_attr($provider['name']); ?>">Client Secret</label></th>
                            <td>
                                <?php $hasSecret = $this->settings->getClientSecret($provider['name']) !== ''; ?>
                                <input type="password"
                                       id="reach_client_secret_<?php echo esc_attr($provider['name']); ?>"
                                       name="client_secret_<?php echo esc_attr($provider['name']); ?>"
                                       value=""
                                       placeholder="<?php echo $hasSecret ? '•••••••• (saved — leave blank to keep)' : ''; ?>"
                                       class="regular-text"
                                       autocomplete="new-password">
                                <?php if ($hasSecret): ?>
                                    <p>
                                        <label>
                                            <input type="checkbox"
                                                   name="remove_secret_<?php echo esc_attr($provider['name']); ?>"
                                                   value="1">
                                            Remove the stored secret
                                        </label>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>
                <?php endforeach; ?>

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

        foreach (self::PROVIDERS as $provider) {
            $name = $provider['name'];

            // Client ID — straightforward write.
            $idKey = 'client_id_' . $name;
            $clientId = isset($_POST[$idKey]) && is_string($_POST[$idKey])
                ? sanitize_text_field(wp_unslash($_POST[$idKey]))
                : '';
            $this->settings->setClientId($name, $clientId);

            // Apple has no client secret in the client-side flow.
            if ($name === 'apple') {
                continue;
            }

            $secretKey = 'client_secret_' . $name;
            $removeKey = 'remove_secret_' . $name;

            if (!empty($_POST[$removeKey])) {
                $this->settings->setClientSecret($name, '');
                continue;
            }

            $newSecret = isset($_POST[$secretKey]) && is_string($_POST[$secretKey])
                ? trim(wp_unslash($_POST[$secretKey]))
                : '';
            if ($newSecret !== '') {
                $this->settings->setClientSecret($name, $newSecret);
            }
            // Empty + no remove flag → leave existing secret untouched.
        }

        // The page moved from "Settings → Reach" (options-general.php)
        // to "Reach → Authentication" (admin.php) when the top-level
        // Reach menu was introduced. Redirect target must match.
        wp_safe_redirect(add_query_arg(['page' => self::PAGE_SLUG, 'updated' => '1'], admin_url('admin.php')));
        exit;
    }
}
