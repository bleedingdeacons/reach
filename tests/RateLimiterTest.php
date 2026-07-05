<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\Core\RateLimiter;

/**
 * Unit tests for {@see RateLimiter} — the transient-backed per-key throttle
 * guarding the login and request-reset endpoints.
 */
final class RateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__reach_transients'] = [];
    }

    public function testAllowsUpToTheLimitThenRefuses(): void
    {
        $rl = new RateLimiter();

        // First three hits are allowed (under the limit of 3)...
        $this->assertFalse($rl->overLimit('k', 3, 3600));
        $this->assertFalse($rl->overLimit('k', 3, 3600));
        $this->assertFalse($rl->overLimit('k', 3, 3600));
        // ...the fourth is over the limit.
        $this->assertTrue($rl->overLimit('k', 3, 3600));
    }

    public function testCountsAreIndependentPerKey(): void
    {
        $rl = new RateLimiter();

        $this->assertFalse($rl->overLimit('a', 1, 3600));
        $this->assertTrue($rl->overLimit('a', 1, 3600));
        // A different key has its own budget.
        $this->assertFalse($rl->overLimit('b', 1, 3600));
    }

    public function testClientIpFallsBackToUnknownForInvalidAddress(): void
    {
        $rl = new RateLimiter();

        $prev = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = 'not-an-ip';
        $this->assertSame('unknown', $rl->clientIp());

        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $this->assertSame('203.0.113.7', $rl->clientIp());

        if ($prev === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $prev;
        }
    }
}
