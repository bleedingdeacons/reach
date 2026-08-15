<?php

declare(strict_types=1);

namespace Reach\Tests;

use Brain\Monkey\Functions;
use Reach\Session\CurrentSession;
use Reach\Session\SessionCookie;
use Reach\Tests\ReachTestCase;
use Reach\Frontend\PageRouter;

/**
 * The bare /reach/ entry point doesn't render a page of its own — it
 * redirects to the right one based on sign-in status. The redirect
 * itself ends in exit(), which can't run inside a test, so the routing
 * decision is factored into the pure {@see PageRouter::landingPath()}
 * and asserted here.
 *
 * {@see PageRouter::templateFor()} is factored out for the same reason
 * and covers the other half of the routing: which template a page
 * renders, and which pages bounce to sign-in without one. That gate is
 * the security-relevant part of this class — the finder is a list of
 * real people — so it gets a case per page rather than one representative
 * one.
 */
final class PageRouterTest extends ReachTestCase
{
    public function testSignedInVisitorLandsOnHome(): void
    {
        $this->assertSame('/reach/home', PageRouter::landingPath(true));
    }

    public function testSignedOutVisitorLandsOnSignin(): void
    {
        $this->assertSame('/reach/signin', PageRouter::landingPath(false));
    }

    public function testLandingTargetsAreTheKnownPageSlugs(): void
    {
        // Guard against the landing paths drifting away from the slugs
        // the rewrite rules and templates actually serve.
        $this->assertSame('reach/home', PageRouter::HOME_SLUG);
        $this->assertSame('reach/signin', PageRouter::SIGNIN_SLUG);
        $this->assertSame('/' . PageRouter::HOME_SLUG, PageRouter::landingPath(true));
        $this->assertSame('/' . PageRouter::SIGNIN_SLUG, PageRouter::landingPath(false));
    }

    public function testPasswordSupportPageSlugs(): void
    {
        // The set/reset pages are public (not session-gated) so a signed-out
        // member can reach them; guard their slugs against drift from the
        // rewrite rules and the links in signin.php / the reset email.
        $this->assertSame('reach/reset', PageRouter::RESET_SLUG);
        $this->assertSame('reach/set-password', PageRouter::SET_PASSWORD_SLUG);
    }

    // ── the session gate ──────────────────────────────────────────────

    /**
     * @dataProvider gatedPages
     */
    public function testAGatedPageRendersSigninWhenThereIsNoSession(string $page): void
    {
        // Rendered in place rather than redirected: the URL stays put, so
        // after signing in the visitor lands where they meant to go
        // without a `?return_to` threaded through the OAuth flow.
        $this->assertSame(
            $this->template('signin'),
            PageRouter::templateFor($page, false),
        );
    }

    /**
     * @dataProvider gatedPages
     */
    public function testAGatedPageRendersItselfForASignedInVisitor(string $page): void
    {
        $this->assertSame(
            $this->template($page),
            PageRouter::templateFor($page, true),
        );
    }

    /** @return array<string, array{0: string}> */
    public static function gatedPages(): array
    {
        return [
            'home'    => ['home'],
            'find'    => ['find'],
            'shifts'  => ['shifts'],
            'request' => ['request'],
            'lookup'  => ['lookup'],
        ];
    }

    /**
     * @dataProvider publicPages
     */
    public function testAPublicPageRendersWithoutASession(string $page): void
    {
        // A signed-out member must be able to reach these, or a forgotten
        // password is unrecoverable.
        $this->assertSame(
            $this->template($page),
            PageRouter::templateFor($page, false),
        );
    }

    /** @return array<string, array{0: string}> */
    public static function publicPages(): array
    {
        return [
            'signin'       => ['signin'],
            'reset'        => ['reset'],
            'set-password' => ['set-password'],
        ];
    }

    public function testEveryRoutedPageHasATemplate(): void
    {
        // Guards the match() against a page being added to the rewrite
        // rules and PAGES without a template, which would silently serve
        // the finder in its place.
        foreach (PageRouter::PAGES as $page) {
            if ($page === 'index') {
                continue; // no template of its own — it redirects
            }
            $this->assertFileExists(PageRouter::templateFor($page, true));
        }
    }

    public function testAnUnknownPageFallsBackToTheFinder(): void
    {
        // Only reachable through a direct call — renderPage() filters the
        // query var against PAGES first — but the default arm has to go
        // somewhere, and the finder is gated so the fallback is closed.
        $this->assertSame($this->template('find'), PageRouter::templateFor('nonsense', true));
    }

    // ── wiring ────────────────────────────────────────────────────────

    public function testRegisterHooksTheRewritesQueryVarAndDispatcher(): void
    {
        $this->router()->register();

        $this->assertActionAdded('init', false, 'the rewrite rules are registered on init');
        $this->assertFilterAdded('query_vars', false, 'the reach_page query var has to be allowed through');
        $this->assertActionAdded('template_redirect', false, 'the dispatcher runs on template_redirect');
    }

    public function testTheQueryVarIsAddedWithoutDisturbingTheOthers(): void
    {
        $vars = PageRouter::addQueryVar(['p', 'page_id']);

        $this->assertSame(['p', 'page_id', PageRouter::QUERY_VAR], $vars);
    }

    public function testEveryRoutedPageHasARewriteRule(): void
    {
        $rules = [];
        Functions\when('add_rewrite_rule')->alias(
            static function (string $regex, string $query) use (&$rules): void {
                $rules[$regex] = $query;
            }
        );

        PageRouter::addRewriteRules();

        // One rule per page: the bare entry point plus each named page.
        $this->assertCount(count(PageRouter::PAGES), $rules);
        foreach ($rules as $query) {
            $this->assertStringStartsWith('index.php?' . PageRouter::QUERY_VAR . '=', $query);
        }
    }

    public function testTheBareEntryPointRuleIsAnchoredSoItCannotShadowTheNamedPages(): void
    {
        // '^reach' unanchored would swallow /reach/find and every other
        // page, sending the whole front end to the redirect.
        $rules = [];
        Functions\when('add_rewrite_rule')->alias(
            static function (string $regex, string $query) use (&$rules): void {
                $rules[$regex] = $query;
            }
        );

        PageRouter::addRewriteRules();

        $this->assertArrayHasKey('^reach/?$', $rules);
        $this->assertSame('index.php?' . PageRouter::QUERY_VAR . '=index', $rules['^reach/?$']);
    }

    public function testTheRewriteRulesAreFlushedOnceAfterAVersionChange(): void
    {
        // Self-heals the cached rules after an update that added a route,
        // without a manual permalink flush or a full reactivate.
        $flushes = 0;
        Functions\when('flush_rewrite_rules')->alias(static function () use (&$flushes): void {
            $flushes++;
        });

        PageRouter::maybeFlushRewriteRules();
        PageRouter::maybeFlushRewriteRules();

        $this->assertSame(1, $flushes, 'the steady state must be a single option read');
    }

    public function testRenderPageIgnoresARequestThatIsNotOurs(): void
    {
        // template_redirect fires on every front-end request, so the
        // overwhelming majority of calls have to fall straight through.
        Functions\when('get_query_var')->justReturn('');

        $this->router()->renderPage();

        // Reaching here at all is the assertion: anything further down
        // renderPage() ends in exit and would take the runner with it.
        $this->assertTrue(true);
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function template(string $page): string
    {
        return REACH_PLUGIN_DIR . 'templates/' . $page . '.php';
    }

    private function router(): PageRouter
    {
        return new PageRouter(new CurrentSession(new SessionCookie()));
    }
}
