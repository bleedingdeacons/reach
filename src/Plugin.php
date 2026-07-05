<?php

declare(strict_types=1);

namespace Reach;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Admin\CallAttemptsPage;
use Reach\Admin\CallRequestsPage;
use Reach\Admin\MemberSearchPage;
use Reach\Admin\SettingsPage;
use Reach\Auth\PasswordCredentialRepository;
use Reach\Core\ReachServiceProvider;
use Reach\Frontend\PageRouter;
use Reach\Rest\CallAttemptController;
use Reach\Rest\CallRequestController;
use Reach\Rest\NearestMembersController;
use Reach\Rest\OAuthController;
use Reach\Rest\PasswordAuthController;
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

    /**
     * Legacy WP-Cron hook that used to purge call requests past a
     * retention window. Call requests are now durable history with no
     * caller PII, so nothing schedules this any more; the name is kept
     * only so init() and the deactivation hook can clear any event left
     * scheduled by an earlier version.
     */
    public const PURGE_CRON_HOOK = 'reach_purge_call_requests';

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
        self::$container->get(PasswordAuthController::class)->register();
        self::$container->get(NearestMembersController::class)->register();
        self::$container->get(CallAttemptController::class)->register();
        self::$container->get(CallRequestController::class)->register();

        // Call requests are now durable history holding no caller PII, so
        // the old daily retention purge is gone. Clear any event left
        // scheduled by an earlier version so upgraded installs stop
        // purging their request history.
        if (wp_next_scheduled(self::PURGE_CRON_HOOK)) {
            wp_clear_scheduled_hook(self::PURGE_CRON_HOOK);
        }

        // Everything under reach/v1 is per-member and authorised by the Reach
        // session cookie, which shared caches (SiteGround, Cloudflare, the
        // browser) don't recognise. WordPress only sends REST no-cache headers
        // for logged-in WP users (`rest_send_nocache_headers` defaults to
        // is_user_logged_in()), so an anonymous member's response — search
        // results, sign-in redirects — could otherwise be cached and served to
        // the next visitor. Force no-store across the namespace.
        add_filter('rest_post_dispatch', static function ($response, $server, $request) {
            if ($response instanceof \WP_REST_Response
                && $request instanceof \WP_REST_Request
                && str_starts_with(ltrim((string) $request->get_route(), '/'), OAuthController::NAMESPACE)
            ) {
                $response->header('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0, private');
            }

            return $response;
        }, 10, 3);

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

        // GDPR erasure: a member's password credential is personal data, so
        // purge it when the member is deleted/trashed. Unity's
        // MemberChangeTracker fires unity/member_deleted with the member as
        // it was at deletion, from which we take the email the row is keyed
        // on. Inert if Unity never fires it (e.g. a direct DB delete).
        $credentials = self::$container->get(PasswordCredentialRepository::class);
        add_action('unity/member_deleted', static function ($postId, $member = null) use ($credentials) {
            if ($member === null) {
                return;
            }
            $email = strtolower(trim((string) $member->getPersonalEmail()));
            if ($email !== '') {
                $credentials->delete($email);
            }
        }, 10, 2);

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
            self::$container->get(CallRequestsPage::class)->register();
            self::$container->get(MemberSearchPage::class)->register();
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
