<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\Frontend\PageRouter;

/**
 * The bare /reach/ entry point doesn't render a page of its own — it
 * redirects to the right one based on sign-in status. The redirect
 * itself ends in exit(), which can't run inside a test, so the routing
 * decision is factored into the pure {@see PageRouter::landingPath()}
 * and asserted here.
 */
final class PageRouterTest extends TestCase
{
    public function testSignedInVisitorLandsOnFind(): void
    {
        $this->assertSame('/reach/find', PageRouter::landingPath(true));
    }

    public function testSignedOutVisitorLandsOnSignin(): void
    {
        $this->assertSame('/reach/signin', PageRouter::landingPath(false));
    }

    public function testLandingTargetsAreTheKnownPageSlugs(): void
    {
        // Guard against the landing paths drifting away from the slugs
        // the rewrite rules and templates actually serve.
        $this->assertSame('reach/find', PageRouter::FIND_SLUG);
        $this->assertSame('reach/signin', PageRouter::SIGNIN_SLUG);
        $this->assertSame('/' . PageRouter::FIND_SLUG, PageRouter::landingPath(true));
        $this->assertSame('/' . PageRouter::SIGNIN_SLUG, PageRouter::landingPath(false));
    }
}
