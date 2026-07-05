<?php

declare(strict_types=1);

namespace Reach\Frontend;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Session\CurrentSession;

/**
 * Wire the Reach front-end pages into WordPress.
 *
 * Three routes are added via the rewrite API and dispatched through a
 * `template_redirect` handler:
 *
 *   - `/reach/` (the bare entry point) renders nothing — it just
 *     redirects to the right place based on sign-in status: signed-in
 *     visitors go to `/reach/find`, everyone else to `/reach/signin`.
 *   - `/reach/signin` and `/reach/find` render their templates. The
 *     handler short-circuits WordPress's normal template hierarchy
 *     (the page hasn't got a real WP_Post behind it) and renders one
 *     of the PHP templates in templates/ directly.
 *
 * The find page is session-gated: anyone hitting it without a valid
 * Reach session cookie is bounced to the sign-in page. The reverse
 * isn't enforced — landing on /reach/signin while already signed in
 * is harmless and the sign-in page itself nudges through to /find.
 *
 * A query var `reach_page` carries the chosen page through the
 * rewrite, which is cheaper than parsing $wp->request a second time.
 */
final class PageRouter
{
    public const QUERY_VAR = 'reach_page';
    public const SIGNIN_SLUG = 'reach/signin';
    public const FIND_SLUG = 'reach/find';
    public const HOME_SLUG = 'reach/home';
    public const SHIFTS_SLUG = 'reach/shifts';
    public const REQUEST_SLUG = 'reach/request';
    public const RESET_SLUG = 'reach/reset';
    public const SET_PASSWORD_SLUG = 'reach/set-password';

    public function __construct(
        private readonly CurrentSession $session,
    ) {
    }

    /** Option holding the plugin version the rewrite rules were last
     *  flushed for. When it lags REACH_VERSION the routes have changed
     *  (e.g. a new page slug) but the cached rewrite_rules haven't caught
     *  up yet — so flush once and record the new version. */
    private const REWRITE_VERSION_OPTION = 'reach_rewrite_version';

    public function register(): void
    {
        add_action('init', [self::class, 'addRewriteRules']);
        // Self-heal the cached rewrite rules after an update that added or
        // changed a route, without needing a manual permalink flush or a
        // full reactivate. Runs after addRewriteRules (priority 10) so the
        // rules are registered before the flush regenerates the cache.
        add_action('init', [self::class, 'maybeFlushRewriteRules'], 11);
        add_filter('query_vars', [self::class, 'addQueryVar']);
        add_action('template_redirect', [$this, 'renderPage']);
    }

    /**
     * Flush the rewrite rules once per plugin version. Cheap on the steady
     * state (a single option read); only does the heavier flush the first
     * request after the version changes.
     */
    public static function maybeFlushRewriteRules(): void
    {
        $current = defined('REACH_VERSION') ? REACH_VERSION : '';
        if (get_option(self::REWRITE_VERSION_OPTION) === $current) {
            return;
        }
        flush_rewrite_rules(false);
        update_option(self::REWRITE_VERSION_OPTION, $current, false);
    }

    public static function addRewriteRules(): void
    {
        // Bare entry point. Anchored to end so it never shadows the
        // /reach/signin and /reach/find rules above it.
        add_rewrite_rule('^reach/?$',        'index.php?' . self::QUERY_VAR . '=index',  'top');
        add_rewrite_rule('^reach/signin/?$', 'index.php?' . self::QUERY_VAR . '=signin', 'top');
        add_rewrite_rule('^reach/home/?$',   'index.php?' . self::QUERY_VAR . '=home',   'top');
        add_rewrite_rule('^reach/find/?$',   'index.php?' . self::QUERY_VAR . '=find',   'top');
        add_rewrite_rule('^reach/shifts/?$', 'index.php?' . self::QUERY_VAR . '=shifts', 'top');
        add_rewrite_rule('^reach/request/?$', 'index.php?' . self::QUERY_VAR . '=request', 'top');
        // Password sign-in support pages. Public (not session-gated): a
        // signed-out member must be able to reach them to set/reset a password.
        add_rewrite_rule('^reach/reset/?$', 'index.php?' . self::QUERY_VAR . '=reset', 'top');
        add_rewrite_rule('^reach/set-password/?$', 'index.php?' . self::QUERY_VAR . '=set-password', 'top');
    }

    /**
     * @param string[] $vars
     * @return string[]
     */
    public static function addQueryVar(array $vars): array
    {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public function renderPage(): void
    {
        $page = get_query_var(self::QUERY_VAR);
        if (!in_array($page, ['signin', 'home', 'find', 'shifts', 'request', 'reset', 'set-password', 'index'], true)) {
            return;
        }

        // Bare /reach/ — no template of its own. Send the visitor to
        // the right page based on whether they already hold a valid
        // Reach session: signed in → the finder, otherwise → sign-in.
        if ($page === 'index') {
            nocache_headers();
            wp_safe_redirect(home_url(self::landingPath($this->session->isAuthenticated())));
            exit;
        }

        // Tell WP this isn't a 404 — we're handling the request ourselves.
        status_header(200);
        nocache_headers();

        // Cookie check failed on the gated page — render the sign-in
        // template in place rather than bouncing the visitor through a
        // redirect. The URL stays at /reach/find, which means after a
        // successful sign-in the visitor lands back where they meant
        // to go without us having to thread a `?return_to` through the
        // OAuth flow.
        // All the signed-in pages bounce to sign-in when there's no session.
        if (in_array($page, ['home', 'find', 'shifts', 'request'], true) && !$this->session->isAuthenticated()) {
            $page = 'signin';
        }

        // The templates handle their own <html> shell so we don't pick
        // up theme chrome — Reach pages are intentionally standalone
        // mobile views, not theme-wrapped WordPress pages.
        $template = match ($page) {
            'signin'       => REACH_PLUGIN_DIR . 'templates/signin.php',
            'home'         => REACH_PLUGIN_DIR . 'templates/home.php',
            'shifts'       => REACH_PLUGIN_DIR . 'templates/shifts.php',
            'request'      => REACH_PLUGIN_DIR . 'templates/request.php',
            'reset'        => REACH_PLUGIN_DIR . 'templates/reset.php',
            'set-password' => REACH_PLUGIN_DIR . 'templates/set-password.php',
            default        => REACH_PLUGIN_DIR . 'templates/find.php',
        };

        $session = $this->session->get(); // available inside template
        require $template;
        exit;
    }

    /**
     * The path the bare /reach/ entry point should land on, given
     * whether the visitor is signed in. Pure so the routing decision
     * can be tested without driving the full redirect/exit path.
     */
    public static function landingPath(bool $isAuthenticated): string
    {
        return $isAuthenticated ? '/reach/home' : '/reach/signin';
    }
}
