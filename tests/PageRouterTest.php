<?php

declare(strict_types=1);

namespace Reach\Tests;

use function Brain\Monkey\Functions\when;
use Reach\Session\CurrentSession;
use Reach\Session\SessionCookie;
use Reach\Session\SessionCsrf;
use Reach\Session\SessionRevocationList;
use Unity\Testing\Doubles\InMemoryMemberRepository;
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

// ── helpers ───────────────────────────────────────────────────────
function template(string $page): string
{
    return REACH_PLUGIN_DIR . 'templates/' . $page . '.php';
}

function router(): PageRouter
{
    return new PageRouter(
        new CurrentSession(
            new SessionCookie(),
            new InMemoryMemberRepository([]),
            new SessionRevocationList(),
        ),
        new SessionCsrf(),
    );
}

test('signed in visitor lands on home', function () {
    $this->assertSame('/reach/home', PageRouter::landingPath(true));
});

test('signed out visitor lands on signin', function () {
    $this->assertSame('/reach/signin', PageRouter::landingPath(false));
});

test('landing targets are the known page slugs', function () {
    // Guard against the landing paths drifting away from the slugs
    // the rewrite rules and templates actually serve.
    $this->assertSame('reach/home', PageRouter::HOME_SLUG);
    $this->assertSame('reach/signin', PageRouter::SIGNIN_SLUG);
    $this->assertSame('/' . PageRouter::HOME_SLUG, PageRouter::landingPath(true));
    $this->assertSame('/' . PageRouter::SIGNIN_SLUG, PageRouter::landingPath(false));
});

test('password support page slugs', function () {
    // The set/reset pages are public (not session-gated) so a signed-out
    // member can reach them; guard their slugs against drift from the
    // rewrite rules and the links in signin.php / the reset email.
    $this->assertSame('reach/reset', PageRouter::RESET_SLUG);
    $this->assertSame('reach/set-password', PageRouter::SET_PASSWORD_SLUG);
});

// ── the session gate ──────────────────────────────────────────────
test('a gated page renders signin when there is no session', function (string $page) {
    // Rendered in place rather than redirected: the URL stays put, so
    // after signing in the visitor lands where they meant to go
    // without a `?return_to` threaded through the OAuth flow.
    $this->assertSame(
        template('signin'),
        PageRouter::templateFor($page, false),
    );
})->with('gatedPages');

test('a gated page renders itself for a signed in visitor', function (string $page) {
    $this->assertSame(
        template($page),
        PageRouter::templateFor($page, true),
    );
})->with('gatedPages');

/** @return array<string, array{0: string}> */
dataset('gatedPages', function (): array {
    return [
        'home'    => ['home'],
        'find'    => ['find'],
        'shifts'  => ['shifts'],
        'request' => ['request'],
        'lookup'  => ['lookup'],
    ];
});

test('a public page renders without a session', function (string $page) {
    // A signed-out member must be able to reach these, or a forgotten
    // password is unrecoverable.
    $this->assertSame(
        template($page),
        PageRouter::templateFor($page, false),
    );
})->with('publicPages');

/** @return array<string, array{0: string}> */
dataset('publicPages', function (): array {
    return [
        'signin'       => ['signin'],
        'reset'        => ['reset'],
        'set-password' => ['set-password'],
    ];
});

test('every routed page has a template', function () {
    // Guards the match() against a page being added to the rewrite
    // rules and PAGES without a template, which would silently serve
    // the finder in its place.
    foreach (PageRouter::PAGES as $page) {
        if ($page === 'index') {
            continue; // no template of its own — it redirects
        }
        $this->assertFileExists(PageRouter::templateFor($page, true));
    }
});

test('an unknown page falls back to the finder', function () {
    // Only reachable through a direct call — renderPage() filters the
    // query var against PAGES first — but the default arm has to go
    // somewhere, and the finder is gated so the fallback is closed.
    $this->assertSame(template('find'), PageRouter::templateFor('nonsense', true));
});

// ── wiring ────────────────────────────────────────────────────────
test('register hooks the rewrites query var and dispatcher', function () {
    router()->register();

    $this->assertActionAdded('init', false, 'the rewrite rules are registered on init');
    $this->assertFilterAdded('query_vars', false, 'the reach_page query var has to be allowed through');
    $this->assertActionAdded('template_redirect', false, 'the dispatcher runs on template_redirect');
});

test('the query var is added without disturbing the others', function () {
    $vars = PageRouter::addQueryVar(['p', 'page_id']);

    $this->assertSame(['p', 'page_id', PageRouter::QUERY_VAR], $vars);
});

test('every routed page has a rewrite rule', function () {
    $rules = [];
    when('add_rewrite_rule')->alias(
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
});

test('the bare entry point rule is anchored so it cannot shadow the named pages', function () {
    // '^reach' unanchored would swallow /reach/find and every other
    // page, sending the whole front end to the redirect.
    $rules = [];
    when('add_rewrite_rule')->alias(
        static function (string $regex, string $query) use (&$rules): void {
            $rules[$regex] = $query;
        }
    );

    PageRouter::addRewriteRules();

    $this->assertArrayHasKey('^reach/?$', $rules);
    $this->assertSame('index.php?' . PageRouter::QUERY_VAR . '=index', $rules['^reach/?$']);
});

test('the rewrite rules are flushed once after a version change', function () {
    // Self-heals the cached rules after an update that added a route,
    // without a manual permalink flush or a full reactivate.
    $flushes = 0;
    when('flush_rewrite_rules')->alias(static function () use (&$flushes): void {
        $flushes++;
    });

    PageRouter::maybeFlushRewriteRules();
    PageRouter::maybeFlushRewriteRules();

    $this->assertSame(1, $flushes, 'the steady state must be a single option read');
});

test('render page ignores a request that is not ours', function () {
    // template_redirect fires on every front-end request, so the
    // overwhelming majority of calls have to fall straight through.
    when('get_query_var')->justReturn('');

    router()->renderPage();

    // Reaching here at all is the assertion: anything further down
    // renderPage() ends in exit and would take the runner with it.
    $this->assertTrue(true);
});
