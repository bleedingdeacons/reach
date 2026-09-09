<?php

declare(strict_types=1);

namespace Reach\Tests;

use Reach\Auth\ResetTokenCookie;

/**
 * Moving the emailed reset token out of the URL.
 *
 * The token is 32 random bytes, single-use, with a 60-minute expiry, and
 * every asset on the set-password page is same-origin — so there was never a
 * Referer leak to a third party. What remained is that it sat in the address
 * bar and browser history for the life of the page, and would ride along in
 * the Referer of anything that page linked out to.
 *
 * Note what this does *not* fix: the emailed link still has to carry the
 * token, so the first request is still one line in the access log. Nothing
 * that keeps a link clickable avoids that, and core does not either.
 */
final class ResetTokenCookieTest extends ReachTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_GET = [];
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_COOKIE = [];
        parent::tearDown();
    }

    /** @test */
    public function it_captures_a_token_from_the_query_string(): void
    {
        $_GET['token'] = 'a-reset-token';

        $this->assertTrue((new ResetTokenCookie())->captureFromQuery());
    }

    /** @test */
    public function a_captured_token_is_readable_on_the_same_request(): void
    {
        // headers_sent() can be true under test, and in production a
        // redirect could in principle be impossible. Reflecting into
        // $_COOKIE means the form still renders with a working token
        // instead of telling the member their link is invalid.
        $_GET['token'] = 'a-reset-token';

        $cookie = new ResetTokenCookie();
        $cookie->captureFromQuery();

        $this->assertSame('a-reset-token', $cookie->read());
    }

    /** @test */
    public function it_captures_nothing_when_the_query_string_is_bare(): void
    {
        // The post-redirect load. Nothing to move, so the page falls
        // straight through to reading the cookie.
        $this->assertFalse((new ResetTokenCookie())->captureFromQuery());
    }

    /** @test */
    public function it_captures_nothing_from_an_empty_token(): void
    {
        $_GET['token'] = '';

        $this->assertFalse((new ResetTokenCookie())->captureFromQuery());
    }

    /** @test */
    public function it_reads_the_token_back_from_the_cookie(): void
    {
        // The post-redirect load proper: no query string, token in the
        // cookie the previous request set.
        $_COOKIE[ResetTokenCookie::COOKIE_NAME] = 'a-reset-token';

        $this->assertSame('a-reset-token', (new ResetTokenCookie())->read());
    }

    /** @test */
    public function it_reads_an_empty_string_when_there_is_no_token_anywhere(): void
    {
        $this->assertSame('', (new ResetTokenCookie())->read());
    }

    /** @test */
    public function the_redirect_target_carries_no_token(): void
    {
        $_GET['token'] = 'a-reset-token';

        $cookie = new ResetTokenCookie();
        $cookie->captureFromQuery();

        $bare = $cookie->bareUrl();

        $this->assertStringNotContainsString('a-reset-token', $bare);
        $this->assertStringNotContainsString('token', $bare);
        $this->assertStringNotContainsString('?', $bare);
    }

    /**
     * @test
     */
    public function the_set_password_template_takes_the_token_from_the_cookie(): void
    {
        // The class above is only useful if the page actually uses it, and a
        // template is not reachable from a unit test — it renders a whole
        // HTML document and exits. So this asserts the wiring structurally:
        // the template must go through ResetTokenCookie and must not read
        // $_GET['token'] itself, which is the line the finding was about.
        $template = (string) file_get_contents(dirname(__DIR__) . '/templates/set-password.php');

        $this->assertStringContainsString(
            'ResetTokenCookie',
            $template,
            'The set-password page should take its token from ResetTokenCookie.'
        );
        $this->assertStringNotContainsString(
            "\$_GET['token']",
            $template,
            'Reading the token straight from the query string is the thing this fix removed.'
        );
    }

    /** @test */
    public function the_cookie_is_scoped_to_the_page_that_needs_it(): void
    {
        // Narrower than the session cookie's path on purpose: this
        // credential is for one form on one page, so nothing else on the
        // site should ever be sent it.
        $this->assertSame('/reach/set-password', ResetTokenCookie::COOKIE_PATH);
    }
}
