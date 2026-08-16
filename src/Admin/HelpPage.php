<?php

declare(strict_types=1);

namespace Reach\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use Scrutiny\Privacy\PersonalDataPolicy;

/**
 * Adds a "Help" submenu under the Reach menu that opens the standalone
 * Reach user guide (assets/docs/reach.html).
 *
 * Mirrors Trusted's and Amber's HelpPage: clicking Help is intercepted
 * and the guide is opened in a named browser tab, with the current admin
 * URL passed as `?back=`. The guide's back button then refocuses that
 * same tab via its window name rather than reloading it, so the admin
 * page keeps its scroll position.
 *
 * Capability
 * ----------
 * The same one the parent menu uses, so anybody who can see the Reach
 * menu at all can read its guide. Deliberately *not* the stricter
 * manage_options that {@see SettingsPage} carries: the guide covers
 * responder and handset administration, which is precisely the work
 * someone without full administrator rights is doing here.
 */
final class HelpPage
{
    /** Submenu page slug. */
    public const SLUG = 'reach-help';

    private const CAPABILITY = PersonalDataPolicy::VIEW_CAPABILITY;

    /** Window name given to the admin tab so the guide can refocus it. */
    private const ADMIN_WINDOW = 'reach-admin';

    /** Window name the guide tab opens under, so repeat clicks reuse it. */
    private const HELP_WINDOW = 'reach-help';

    /** The guide's URL, resolved against the plugin's own directory. */
    private function helpUrl(): string
    {
        return plugins_url('assets/docs/reach.html', \REACH_PLUGIN_FILE);
    }

    /**
     * Register the Help submenu and the footer script that intercepts its
     * click. Registered from `admin_menu` at a late priority (see
     * Plugin::init) so Help always sits last in the Reach submenu.
     */
    public function register(): void
    {
        add_submenu_page(
            CallAttemptsPage::MENU_SLUG,
            'Help',
            'Help',
            self::CAPABILITY,
            self::SLUG,
            [$this, 'render']
        );

        add_action('admin_footer', [$this, 'enqueueHelpTabScript']);
    }

    /**
     * Fallback page, shown only if the footer script does not intercept the
     * click (e.g. JavaScript disabled). Offers a direct link to the guide.
     */
    public function render(): void
    {
        echo '<div class="wrap">';
        echo '<h1>Reach Help</h1>';
        echo '<p>Open the Reach user guide:</p>';
        echo '<p><a class="button button-primary" target="_blank" rel="noopener" href="'
            . esc_url($this->helpUrl()) . '">Open the guide</a></p>';
        echo '</div>';
    }

    /**
     * Intercept the Help submenu click and open the guide in a named tab,
     * passing the current admin URL as `?back=`. Naming the admin tab lets the
     * guide refocus it on "back" without a reload. window.open() inside a click
     * handler is a user gesture, so browsers don't treat it as a popup.
     */
    public function enqueueHelpTabScript(): void
    {
        $adminUrl = admin_url('admin.php?page=' . self::SLUG);
        ?>
        <script>
            (function () {
                var link = document.querySelector('a[href="<?php echo esc_js($adminUrl); ?>"]');
                if (!link) {
                    link = document.querySelector('a[href*="page=<?php echo esc_js(self::SLUG); ?>"]');
                }
                if (!link) return;
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    window.name = '<?php echo esc_js(self::ADMIN_WINDOW); ?>';
                    var helpUrl = '<?php echo esc_js($this->helpUrl()); ?>' + '?back=' + encodeURIComponent(window.location.href);
                    var existing = window.open('', '<?php echo esc_js(self::HELP_WINDOW); ?>');
                    if (!existing) {
                        // A popup blocker or extension refused the window.
                        // preventDefault() has already run, so without this the
                        // Help link would do nothing at all — not even reach the
                        // fallback page. Open the guide in place instead.
                        window.location.href = helpUrl;
                        return;
                    }
                    try {
                        if (existing && existing.location && existing.location.href && existing.location.href !== 'about:blank') {
                            existing.focus();
                            return;
                        }
                    } catch (ex) {}
                    existing.location.href = helpUrl;
                });
            })();
        </script>
        <?php
    }
}
