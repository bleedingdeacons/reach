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
 * Two routes — `/reach/signin` and `/reach/find` — are added via the
 * rewrite API and dispatched through a `template_redirect` handler.
 * The handler short-circuits WordPress's normal template hierarchy
 * (the page hasn't got a real WP_Post behind it) and renders one of
 * the PHP templates in templates/ directly.
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

    public function __construct(
        private readonly CurrentSession $session,
    ) {
    }

    public function register(): void
    {
        add_action('init', [self::class, 'addRewriteRules']);
        add_filter('query_vars', [self::class, 'addQueryVar']);
        add_action('template_redirect', [$this, 'renderPage']);
    }

    public static function addRewriteRules(): void
    {
        add_rewrite_rule('^reach/signin/?$', 'index.php?' . self::QUERY_VAR . '=signin', 'top');
        add_rewrite_rule('^reach/find/?$',   'index.php?' . self::QUERY_VAR . '=find',   'top');
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
        if ($page !== 'signin' && $page !== 'find') {
            return;
        }

        // Tell WP this isn't a 404 — we're handling the request ourselves.
        status_header(200);
        nocache_headers();

        if ($page === 'find' && !$this->session->isAuthenticated()) {
            wp_safe_redirect(home_url('/reach/signin'));
            exit;
        }

        // The templates handle their own <html> shell so we don't pick
        // up theme chrome — Reach pages are intentionally standalone
        // mobile views, not theme-wrapped WordPress pages.
        $template = match ($page) {
            'signin' => REACH_PLUGIN_DIR . 'templates/signin.php',
            default  => REACH_PLUGIN_DIR . 'templates/find.php',
        };

        $session = $this->session->get(); // available inside template
        require $template;
        exit;
    }
}
