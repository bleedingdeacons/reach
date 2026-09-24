<?php

declare(strict_types=1);

namespace Reach\Tests;

use Reach\Rest\SameOriginOnly;
use WP_REST_Request;

/**
 * The Origin check guarding the session token.
 *
 * GET /reach/v1/session hands out the token every cookie-authenticated write
 * must present. Its old comment reasoned that "a cross-site caller cannot
 * read the response" — true of a genuinely cross-*site* caller, whose
 * request the SameSite=Lax cookie never reaches, and untrue of a sibling
 * subdomain, which is same-site for the cookie and gets
 * Access-Control-Allow-Credentials from core's own CORS headers.
 */

/**
 * The site's own origin, as the shared stub's home_url() reports it.
 * Taken from there rather than asserted as a literal so this keeps
 * testing the comparison rather than the stub.
 */
const SITE = 'https://example.test';

function requestFrom(string $origin): WP_REST_Request
{
    $request = new WP_REST_Request([], '/reach/v1/session');
    $request->set_header('Origin', $origin);

    return $request;
}

test('a request with no origin is allowed', function () {
    // Browsers omit Origin on ordinary same-origin GETs, and curl, the
    // handsets and monitoring send none either. Refusing on absence
    // would break all of them and stop nothing: the attack needs a
    // browser, and a browser sending a cross-origin credentialed
    // request always sets the header.
    $this->assertTrue(SameOriginOnly::allows(new WP_REST_Request([], '/reach/v1/session')));
});

test('this sites own origin is allowed', function () {
    $this->assertTrue(SameOriginOnly::allows(requestFrom(SITE)));
});

test('a sibling subdomain is refused', function () {
    // The finding exactly: same-site for the Lax cookie, so the cookie
    // is attached — and without this check, CORS would let it read the
    // token back.
    $this->assertFalse(SameOriginOnly::allows(requestFrom('https://blog.example.test')));
});

test('an unrelated origin is refused', function () {
    $this->assertFalse(SameOriginOnly::allows(requestFrom('https://evil.example')));
});

test('the scheme must match', function () {
    $this->assertFalse(SameOriginOnly::allows(requestFrom('http://example.test')));
});

test('a prefix of the host is not the host', function () {
    // example.test.evil.example is a different site entirely, but a naive
    // str_starts_with or str_contains would wave both of these through.
    $this->assertFalse(SameOriginOnly::allows(requestFrom('https://example.test.evil.example')));
    $this->assertFalse(SameOriginOnly::allows(requestFrom('https://evil-example.test')));
});

test('trailing slashes and case do not make the site a stranger', function () {
    $this->assertTrue(SameOriginOnly::allows(requestFrom('https://EXAMPLE.TEST')));
    $this->assertTrue(SameOriginOnly::allows(requestFrom(SITE . '/')));
    $this->assertTrue(SameOriginOnly::allows(requestFrom(SITE . ':443')));
});

test('the null origin is treated as absent', function () {
    // Sent by sandboxed iframes and some redirects. It carries no
    // cookie-bearing site, so there is nothing to refuse.
    $this->assertTrue(SameOriginOnly::allows(requestFrom('null')));
});

test('junk in the header is refused', function () {
    $this->assertFalse(SameOriginOnly::allows(requestFrom('not a url')));
});
