<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
use Reach\Core\RateLimiter;

/**
 * Unit tests for {@see RateLimiter} — the transient-backed per-key throttle
 * guarding the login and request-reset endpoints.
 */

beforeEach(function () {
    WpState::$transients = [];
});

test('allows up to the limit then refuses', function () {
    $rl = new RateLimiter();

    // First three hits are allowed (under the limit of 3)...
    $this->assertFalse($rl->overLimit('k', 3, 3600));
    $this->assertFalse($rl->overLimit('k', 3, 3600));
    $this->assertFalse($rl->overLimit('k', 3, 3600));
    // ...the fourth is over the limit.
    $this->assertTrue($rl->overLimit('k', 3, 3600));
});

test('counts are independent per key', function () {
    $rl = new RateLimiter();

    $this->assertFalse($rl->overLimit('a', 1, 3600));
    $this->assertTrue($rl->overLimit('a', 1, 3600));
    // A different key has its own budget.
    $this->assertFalse($rl->overLimit('b', 1, 3600));
});

test('client ip falls back to unknown for invalid address', function () {
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
});
