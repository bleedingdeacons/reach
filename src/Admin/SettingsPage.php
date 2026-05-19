<?php

declare(strict_types=1);

namespace Reach\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Core\Settings;

/**
 * Admin settings page for OAuth credentials.
 *
 * Sits as the "Authentication" submenu under the top-level Reach menu
 * (registered by CallAttemptsPage). Hosts the four providers
 * (Google, Microsoft, Apple, Facebook) and the require-Scrutiny-
 * capability toggle. Each provider gets a client ID field and a write-
 * only client secret field: the existing secret is displayed as a
 * fixed-width placeholder so an admin can see it's set without it
 * being readable from the form.
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
            'Authentication',
            'Authentication',
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

            <p>Configure the OAuth providers that Reach uses to verify a visitor&rsquo;s email address. Each provider needs a client ID and (except Apple) a client secret. Secrets are encrypted at rest.</p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="reach_save_settings">
                <?php wp_nonce_field('reach_save_settings'); ?>

                <h2>Redirect URIs</h2>
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
                    <h2><?php echo esc_html($provider['label']); ?></h2>
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
