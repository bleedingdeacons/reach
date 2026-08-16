<?php

declare(strict_types=1);

namespace Reach\Tests\Admin;

use BleedingDeacons\WpMocks\WpState;
use Reach\Admin\CallAttemptsPage;
use Reach\Admin\HelpPage;
use Reach\Tests\ReachTestCase;
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
 *
 * @covers \Reach\Admin\HelpPage
 */
final class HelpPageTest extends ReachTestCase
{
    private HelpPage $page;

    protected function setUp(): void
    {
        parent::setUp();

        $this->page = new HelpPage();
    }

    private function capture(callable $render): string
    {
        ob_start();

        try {
            $render();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    // ── registration ──────────────────────────────────────────────────

    /** @test */
    public function it_registers_a_help_submenu_under_the_reach_menu(): void
    {
        $this->page->register();

        $this->assertCount(1, WpState::$menus);

        $menu = WpState::$menus[0];

        $this->assertSame('submenu', $menu['type']);
        $this->assertSame(CallAttemptsPage::MENU_SLUG, $menu['parent']);
        $this->assertSame(HelpPage::SLUG, $menu['slug']);
        $this->assertSame('Help', $menu['title']);
    }

    /**
     * The guide documents responder and handset administration, which is
     * exactly what somebody with the personal-data capability but without
     * manage_options is here to do — so Help must not inherit Settings'
     * stricter gate.
     *
     * @test
     */
    public function the_submenu_sits_behind_the_same_capability_as_the_parent_menu(): void
    {
        $this->page->register();

        $this->assertSame(PersonalDataPolicy::VIEW_CAPABILITY, WpState::$menus[0]['cap']);
    }

    /**
     * The click interceptor has to be printed on every admin screen, not just
     * this one — the Help link lives in the sidebar and is clicked from
     * wherever the user happens to be.
     *
     * @test
     */
    public function registering_also_hooks_the_footer_script(): void
    {
        $this->page->register();

        $this->assertActionAdded(
            'admin_footer',
            [$this->page, 'enqueueHelpTabScript'],
            'the click interceptor must be printed in the admin footer'
        );
    }

    // ── the no-JavaScript fallback ────────────────────────────────────

    /** @test */
    public function the_fallback_page_links_straight_to_the_bundled_guide(): void
    {
        $html = $this->capture(fn () => $this->page->render());

        $this->assertStringContainsString('<h1>Reach Help</h1>', $html);
        $this->assertStringContainsString('assets/docs/reach.html', $html);
        $this->assertStringContainsString('Open the guide', $html);
    }

    /**
     * The fallback opens a new tab, so it needs rel="noopener" — without it
     * the guide gets a handle on wp-admin through window.opener.
     *
     * @test
     */
    public function the_fallback_link_opens_safely_in_a_new_tab(): void
    {
        $html = $this->capture(fn () => $this->page->render());

        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener"', $html);
    }

    // ── the click interceptor ─────────────────────────────────────────

    /** @test */
    public function the_footer_script_is_emitted_as_an_inline_script_block(): void
    {
        $html = $this->capture(fn () => $this->page->enqueueHelpTabScript());

        $this->assertStringContainsString('<script>', $html);
        $this->assertStringContainsString('</script>', $html);
    }

    /**
     * The script finds the Help link by its exact admin URL and falls back to
     * a slug match if WordPress rendered the href differently — both selectors
     * are load-bearing.
     *
     * @test
     */
    public function the_script_matches_the_help_link_by_url_and_by_slug(): void
    {
        $html = $this->capture(fn () => $this->page->enqueueHelpTabScript());

        $this->assertStringContainsString(
            'a[href="https://example.test/wp-admin/admin.php?page=' . HelpPage::SLUG . '"]',
            $html
        );
        $this->assertStringContainsString('a[href*="page=' . HelpPage::SLUG . '"]', $html);
    }

    /**
     * The two window names are how the guide gets back: the admin tab is named
     * so the guide can refocus it, and the guide tab is named so a second click
     * reuses it rather than piling up tabs.
     *
     * @test
     */
    public function the_script_names_both_tabs_and_passes_the_admin_url_back(): void
    {
        $html = $this->capture(fn () => $this->page->enqueueHelpTabScript());

        $this->assertStringContainsString("window.name = 'reach-admin'", $html);
        $this->assertStringContainsString("window.open('', 'reach-help')", $html);
        $this->assertStringContainsString("'?back=' + encodeURIComponent(window.location.href)", $html);
        $this->assertStringContainsString('assets/docs/reach.html', $html);
    }

    /**
     * preventDefault() is what stops WordPress navigating to the fallback page;
     * without it the named-tab trick never runs.
     *
     * @test
     */
    public function the_script_suppresses_the_default_navigation(): void
    {
        $html = $this->capture(fn () => $this->page->enqueueHelpTabScript());

        $this->assertStringContainsString('e.preventDefault()', $html);
        $this->assertStringContainsString("addEventListener('click'", $html);
    }
}
