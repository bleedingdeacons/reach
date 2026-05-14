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
 * A single page under "Settings → Reach" with the three providers
 * (Google, Microsoft, Apple) and the require-Scrutiny-capability
 * toggle. Each provider gets a client ID field and a write-only
 * client secret field: the existing secret is displayed as a fixed-
 * width placeholder so an admin can see it's set without it being
 * readable from the form.
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
        add_options_page(
            'Reach',
            'Reach',
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
                <p>Register these with each provider:</p>
                <table class="form-table">
                    <tr>
                        <th>Google / Microsoft callback</th>
                        <td><code><?php echo esc_html($callbackUrl); ?></code></td>
                    </tr>
                    <tr>
                        <th>Apple redirect (popup)</th>
                        <td><code><?php echo esc_html($appleRedirectUrl); ?></code></td>
                    </tr>
                </table>

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

                <h2>Access</h2>
                <table class="form-table">
                    <tr>
                        <th>Require Scrutiny capability</th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="require_scrutiny_capability"
                                       value="1"
                                       <?php checked($this->settings->requireScrutinyCapability()); ?>>
                                Also require visitors to be logged-in WP users with <code>scrutiny_view_personal_data</code>.
                            </label>
                            <p class="description">Off by default. Turn on if Reach should be limited to staff/officers in addition to email verification.</p>
                        </td>
                    </tr>
                </table>

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

        $this->settings->setRequireScrutinyCapability(!empty($_POST['require_scrutiny_capability']));

        wp_safe_redirect(add_query_arg(['page' => self::PAGE_SLUG, 'updated' => '1'], admin_url('options-general.php')));
        exit;
    }
}
