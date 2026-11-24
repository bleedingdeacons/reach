<?php

declare(strict_types=1);

namespace Reach\Tests;

use Reach\Auth\AppleDevice;

/**
 * "Sign in with Apple" is offered only on Apple devices. A false
 * negative hides the button from an iPhone user who would have used
 * it; a false positive shows a Windows or Android visitor a popup
 * asking for an Apple ID they may not have. The user-agents below are
 * real ones, one per browser family that matters.
 */

test('apple devices are recognised', function (string $userAgent) {
    $this->assertTrue(AppleDevice::matches($userAgent), $userAgent);
})->with([
    'iPhone Safari' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1'],
    'iPhone Chrome' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/131.0.6778.73 Mobile/15E148 Safari/604.1'],
    // iPadOS Safari asks for the desktop site by default and says Macintosh.
    'iPad desktop mode' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15'],
    'iPad mobile mode' => ['Mozilla/5.0 (iPad; CPU OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1'],
    'iPod' => ['Mozilla/5.0 (iPod touch; CPU iPhone OS 15_8 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.6 Mobile/15E148 Safari/604.1'],
    'Mac Chrome' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'],
    'Mac Firefox' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14.7; rv:132.0) Gecko/20100101 Firefox/132.0'],
]);

test('other devices are not', function (string $userAgent) {
    $this->assertFalse(AppleDevice::matches($userAgent), $userAgent);
})->with([
    'Android Chrome' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36'],
    'Samsung Internet' => ['Mozilla/5.0 (Linux; Android 14; SM-S921B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36'],
    // "AppleWebKit" and "Safari" are in almost every user-agent; they
    // must not count.
    'Windows Chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'],
    'Windows Edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0'],
    'Linux Firefox' => ['Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0'],
    'ChromeOS' => ['Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'],
    'empty' => [''],
]);

test('the current request is read from the user-agent header', function () {
    $saved = $_SERVER['HTTP_USER_AGENT'] ?? null;

    try {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X)';
        $this->assertTrue(AppleDevice::isCurrentRequest());

        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)';
        $this->assertFalse(AppleDevice::isCurrentRequest());

        unset($_SERVER['HTTP_USER_AGENT']);
        $this->assertFalse(AppleDevice::isCurrentRequest());
    } finally {
        if ($saved === null) {
            unset($_SERVER['HTTP_USER_AGENT']);
        } else {
            $_SERVER['HTTP_USER_AGENT'] = $saved;
        }
    }
});
