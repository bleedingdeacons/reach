<?php

declare(strict_types=1);

namespace Reach;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Admin\CallAttemptsPage;
use Reach\Admin\SettingsPage;
use Reach\Core\ReachServiceProvider;
use Reach\Frontend\PageRouter;
use Reach\Rest\CallAttemptController;
use Reach\Rest\NearestMembersController;
use Reach\Rest\OAuthController;
use Reach\Session\CurrentSession;
use Psr\Container\ContainerInterface;
use Unity\Core\Interfaces\Container;
use Unity\Members\Interfaces\MemberRepository;

use function add_filter;

use RuntimeException;

use function add_action;
use function is_admin;

/**
 * Main Reach Plugin Class
 *
 * Wires Reach services into Unity's container, registers the two REST
 * controllers (OAuth + nearest-members), the frontend rewrite rules
 * for /reach/signin and /reach/find, and the admin settings page for
 * OAuth client credentials.
 */
class Plugin
{
    use \Reach\Logger\HasLogger;

    protected static function logChannel(): string
    {
        return 'reach';
    }

    private static ?ContainerInterface $container = null;
    private static bool $initialized = false;

    /**
     * Cached build date read from readme.txt. Null means "not looked up
     * yet"; the empty string is a valid cached result (no Build date line)
     * and stops us re-reading the file on every page render.
     */
    private static ?string $buildDate = null;

    public static function init(Container $unityContainer): void
    {
        if (self::$initialized) {
            return;
        }

        self::$container = $unityContainer;

        (new ReachServiceProvider())->register($unityContainer);

        self::$initialized = true;

        // REST controllers.
        self::$container->get(OAuthController::class)->register();
        self::$container->get(NearestMembersController::class)->register();
        self::$container->get(CallAttemptController::class)->register();

        // Frontend pages (rewrite rules + template_redirect).
        self::$container->get(PageRouter::class)->register();

        // Bridge Reach's authenticated member to Trusted's sign-up endpoints.
        // Trusted's /signup REST resolves the acting member ONLY via this filter,
        // so a member's browser (carrying the Reach session cookie) can sign up
        // for shifts without Trusted trusting any request-supplied identity.
        // Inert when Trusted isn't installed (nothing fires the filter).
        $session = self::$container->get(CurrentSession::class);
        $members = self::$container->get(MemberRepository::class);
        add_filter('trusted_signup_member', static function ($member) use ($session, $members) {
            if ($member !== null) {
                return $member;
            }
            $current = $session->get();
            if ($current === null || $current->email === '') {
                return null;
            }
            // Trusted re-checks the member is a telephone responder before access.
            return $members->findByEmail($current->email);
        }, 10, 1);

        // Admin settings page.
        if (is_admin()) {
            // Order matters: CallAttemptsPage registers the top-level
            // "Reach" menu, and SettingsPage attaches as a submenu to
            // that slug. Both use the same admin_menu hook, so callbacks
            // fire in registration order — if Settings goes first,
            // add_submenu_page('reach', ...) runs before the parent
            // exists and the link silently falls back to a non-routable
            // URL ("page goes nowhere").
            self::$container->get(CallAttemptsPage::class)->register();
            self::$container->get(SettingsPage::class)->register();
        }

        self::logDebug('Initialised', ['version' => defined('REACH_VERSION') ? REACH_VERSION : 'unknown']);
    }

    public static function getContainer(): ContainerInterface
    {
        if (self::$container === null) {
            throw new RuntimeException('Reach Plugin not initialized');
        }
        return self::$container;
    }

    /**
     * The build date stamped into readme.txt by the build script, e.g.
     * "2026/06/14 13:45:36". Empty string if no Build date line is found
     * (for instance running straight from a working checkout). The result
     * is cached for the lifetime of the request so the footer of every
     * Reach page can call this without re-reading the file each time.
     */
    public static function buildDate(): string
    {
        if (self::$buildDate === null) {
            $dir = defined('REACH_PLUGIN_DIR') ? REACH_PLUGIN_DIR : __DIR__ . '/../';
            self::$buildDate = self::readBuildDateFromReadme($dir);
        }
        return self::$buildDate;
    }

    private static function readBuildDateFromReadme(string $pluginDir): string
    {
        foreach (['readme.txt', 'README.txt'] as $name) {
            $readme = rtrim($pluginDir, '/\\') . '/' . $name;
            if (!is_readable($readme)) {
                continue;
            }

            // The build date lives in the header block at the top of the
            // file; read only the first chunk to avoid loading large readmes.
            $contents = file_get_contents($readme, false, null, 0, 8192);
            if ($contents === false) {
                continue;
            }

            if (preg_match('/^[ \t]*Build date[ \t]*:[ \t]*(.+?)[ \t]*$/mi', $contents, $matches)) {
                return trim($matches[1]);
            }
        }

        return '';
    }
}
