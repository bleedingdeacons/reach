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

beforeEach(function () {
    $_GET = [];
    $_COOKIE = [];
});

afterEach(function () {
    $_GET = [];
    $_COOKIE = [];
});

it('captures a token from the query string', function () {
    $_GET['token'] = 'a-reset-token';

    $this->assertTrue((new ResetTokenCookie())->captureFromQuery());
});

test('a captured token is readable on the same request', function () {
    // headers_sent() can be true under test, and in production a
    // redirect could in principle be impossible. Reflecting into
    // $_COOKIE means the form still renders with a working token
    // instead of telling the member their link is invalid.
    $_GET['token'] = 'a-reset-token';

    $cookie = new ResetTokenCookie();
    $cookie->captureFromQuery();

    $this->assertSame('a-reset-token', $cookie->read());
});

it('captures nothing when the query string is bare', function () {
    // The post-redirect load. Nothing to move, so the page falls
    // straight through to reading the cookie.
    $this->assertFalse((new ResetTokenCookie())->captureFromQuery());
});

it('captures nothing from an empty token', function () {
    $_GET['token'] = '';

    $this->assertFalse((new ResetTokenCookie())->captureFromQuery());
});

it('reads the token back from the cookie', function () {
    // The post-redirect load proper: no query string, token in the
    // cookie the previous request set.
    $_COOKIE[ResetTokenCookie::COOKIE_NAME] = 'a-reset-token';

    $this->assertSame('a-reset-token', (new ResetTokenCookie())->read());
});

it('reads an empty string when there is no token anywhere', function () {
    $this->assertSame('', (new ResetTokenCookie())->read());
});

test('the redirect target carries no token', function () {
    $_GET['token'] = 'a-reset-token';

    $cookie = new ResetTokenCookie();
    $cookie->captureFromQuery();

    $bare = $cookie->bareUrl();

    $this->assertStringNotContainsString('a-reset-token', $bare);
    $this->assertStringNotContainsString('token', $bare);
    $this->assertStringNotContainsString('?', $bare);
});

test('the set password template takes the token from the cookie', function () {
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
});

test('the cookie is scoped to the page that needs it', function () {
    // Narrower than the session cookie's path on purpose: this
    // credential is for one form on one page, so nothing else on the
    // site should ever be sent it.
    $this->assertSame('/reach/set-password', ResetTokenCookie::COOKIE_PATH);
});
