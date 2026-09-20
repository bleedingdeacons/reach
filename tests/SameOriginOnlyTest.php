<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\Attributes\Test;
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
final class SameOriginOnlyTest extends ReachTestCase
{
    /**
     * The site's own origin, as the shared stub's home_url() reports it.
     * Taken from there rather than asserted as a literal so this keeps
     * testing the comparison rather than the stub.
     */
    private const SITE = 'https://example.test';

    #[Test]
    public function a_request_with_no_origin_is_allowed(): void
    {
        // Browsers omit Origin on ordinary same-origin GETs, and curl, the
        // handsets and monitoring send none either. Refusing on absence
        // would break all of them and stop nothing: the attack needs a
        // browser, and a browser sending a cross-origin credentialed
        // request always sets the header.
        $this->assertTrue(SameOriginOnly::allows(new WP_REST_Request([], '/reach/v1/session')));
    }

    #[Test]
    public function this_sites_own_origin_is_allowed(): void
    {
        $this->assertTrue(SameOriginOnly::allows($this->requestFrom(self::SITE)));
    }

    #[Test]
    public function a_sibling_subdomain_is_refused(): void
    {
        // The finding exactly: same-site for the Lax cookie, so the cookie
        // is attached — and without this check, CORS would let it read the
        // token back.
        $this->assertFalse(SameOriginOnly::allows($this->requestFrom('https://blog.example.test')));
    }

    #[Test]
    public function an_unrelated_origin_is_refused(): void
    {
        $this->assertFalse(SameOriginOnly::allows($this->requestFrom('https://evil.example')));
    }

    #[Test]
    public function the_scheme_must_match(): void
    {
        $this->assertFalse(SameOriginOnly::allows($this->requestFrom('http://example.test')));
    }

    #[Test]
    public function a_prefix_of_the_host_is_not_the_host(): void
    {
        // example.test.evil.example is a different site entirely, but a naive
        // str_starts_with or str_contains would wave both of these through.
        $this->assertFalse(SameOriginOnly::allows($this->requestFrom('https://example.test.evil.example')));
        $this->assertFalse(SameOriginOnly::allows($this->requestFrom('https://evil-example.test')));
    }

    #[Test]
    public function trailing_slashes_and_case_do_not_make_the_site_a_stranger(): void
    {
        $this->assertTrue(SameOriginOnly::allows($this->requestFrom('https://EXAMPLE.TEST')));
        $this->assertTrue(SameOriginOnly::allows($this->requestFrom(self::SITE . '/')));
        $this->assertTrue(SameOriginOnly::allows($this->requestFrom(self::SITE . ':443')));
    }

    #[Test]
    public function the_null_origin_is_treated_as_absent(): void
    {
        // Sent by sandboxed iframes and some redirects. It carries no
        // cookie-bearing site, so there is nothing to refuse.
        $this->assertTrue(SameOriginOnly::allows($this->requestFrom('null')));
    }

    #[Test]
    public function junk_in_the_header_is_refused(): void
    {
        $this->assertFalse(SameOriginOnly::allows($this->requestFrom('not a url')));
    }

    private function requestFrom(string $origin): WP_REST_Request
    {
        $request = new WP_REST_Request([], '/reach/v1/session');
        $request->set_header('Origin', $origin);

        return $request;
    }
}
