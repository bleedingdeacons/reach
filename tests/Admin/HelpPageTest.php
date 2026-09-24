<?php

declare(strict_types=1);

namespace Reach\Tests\Admin;

use BleedingDeacons\WpMocks\WpState;
use Reach\Admin\CallAttemptsPage;
use Reach\Admin\HelpPage;
use Scrutiny\Privacy\PersonalDataPolicy;

/**
 * Tests for the Help submenu and the footer script that hijacks its click.
 *
 * register() runs for real against WpState's menu recorder and Brain Monkey's
 * hook store. Both render paths emit markup and are captured in an output
 * buffer: render() is the no-JavaScript fallback, and enqueueHelpTabScript()
 * prints an inline <script> whose selectors and window names are the contract
 * that lets the guide's back button refocus the admin tab instead of
 * reloading it.
 */

covers(\Reach\Admin\HelpPage::class);

function capture(callable $render): string
{
    ob_start();

    try {
        $render();
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
}

beforeEach(function () {
    $this->page = new HelpPage();
});

// ── registration ──────────────────────────────────────────────────
it('registers a help submenu under the reach menu', function () {
    $this->page->register();

    $this->assertCount(1, WpState::$menus);

    $menu = WpState::$menus[0];

    $this->assertSame('submenu', $menu['type']);
    $this->assertSame(CallAttemptsPage::MENU_SLUG, $menu['parent']);
    $this->assertSame(HelpPage::SLUG, $menu['slug']);
    $this->assertSame('Help', $menu['title']);
});

/**
 * The guide documents responder and handset administration, which is
 * exactly what somebody with the personal-data capability but without
 * manage_options is here to do — so Help must not inherit Settings'
 * stricter gate.
 */
test('the submenu sits behind the same capability as the parent menu', function () {
    $this->page->register();

    $this->assertSame(PersonalDataPolicy::VIEW_CAPABILITY, WpState::$menus[0]['cap']);
});

/**
 * The click interceptor has to be printed on every admin screen, not just
 * this one — the Help link lives in the sidebar and is clicked from
 * wherever the user happens to be.
 */
test('registering also hooks the footer script', function () {
    $this->page->register();

    $this->assertActionAdded(
        'admin_footer',
        [$this->page, 'enqueueHelpTabScript'],
        'the click interceptor must be printed in the admin footer'
    );
});

// ── the no-JavaScript fallback ────────────────────────────────────
test('the fallback page links straight to the bundled guide', function () {
    $html = capture(fn () => $this->page->render());

    $this->assertStringContainsString('<h1>Reach Help</h1>', $html);
    $this->assertStringContainsString('assets/docs/reach.html', $html);
    $this->assertStringContainsString('Open the guide', $html);
});

/**
 * The fallback opens a new tab, so it needs rel="noopener" — without it
 * the guide gets a handle on wp-admin through window.opener.
 */
test('the fallback link opens safely in a new tab', function () {
    $html = capture(fn () => $this->page->render());

    $this->assertStringContainsString('target="_blank"', $html);
    $this->assertStringContainsString('rel="noopener"', $html);
});

// ── the click interceptor ─────────────────────────────────────────
test('the footer script is emitted as an inline script block', function () {
    $html = capture(fn () => $this->page->enqueueHelpTabScript());

    $this->assertStringContainsString('<script>', $html);
    $this->assertStringContainsString('</script>', $html);
});

/**
 * The script finds the Help link by its exact admin URL and falls back to
 * a slug match if WordPress rendered the href differently — both selectors
 * are load-bearing.
 */
test('the script matches the help link by url and by slug', function () {
    $html = capture(fn () => $this->page->enqueueHelpTabScript());

    $this->assertStringContainsString(
        'a[href="https://example.test/wp-admin/admin.php?page=' . HelpPage::SLUG . '"]',
        $html
    );
    $this->assertStringContainsString('a[href*="page=' . HelpPage::SLUG . '"]', $html);
});

/**
 * The two window names are how the guide gets back: the admin tab is named
 * so the guide can refocus it, and the guide tab is named so a second click
 * reuses it rather than piling up tabs.
 */
test('the script names both tabs and passes the admin url back', function () {
    $html = capture(fn () => $this->page->enqueueHelpTabScript());

    $this->assertStringContainsString("window.name = 'reach-admin'", $html);
    $this->assertStringContainsString("window.open('', 'reach-help')", $html);
    $this->assertStringContainsString("'?back=' + encodeURIComponent(window.location.href)", $html);
    $this->assertStringContainsString('assets/docs/reach.html', $html);
});

/**
 * window.open() returns null when a popup blocker or an extension refuses
 * the window. preventDefault() has already run by then, so without an
 * explicit fallback the Help link would be inert — and the next line would
 * throw on the null handle rather than failing quietly.
 */
test('the script falls back to the current tab when the window is blocked', function () {
    $html = capture(fn () => $this->page->enqueueHelpTabScript());

    $this->assertStringContainsString('if (!existing) {', $html);
    $this->assertStringContainsString('window.location.href = helpUrl;', $html);
});

/**
 * preventDefault() is what stops WordPress navigating to the fallback page;
 * without it the named-tab trick never runs.
 */
test('the script suppresses the default navigation', function () {
    $html = capture(fn () => $this->page->enqueueHelpTabScript());

    $this->assertStringContainsString('e.preventDefault()', $html);
    $this->assertStringContainsString("addEventListener('click'", $html);
});
