<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
use Reach\Auth\StateStore;

/**
 * The state store is the CSRF anchor for the whole OAuth flow.
 * Its non-negotiable behaviours: unknown state → null, valid state
 * → returns payload exactly once and then disappears.
 */

beforeEach(function () {
    WpState::$transients = [];
});

test('issue and consume round trip', function () {
    $store = new StateStore();
    $tokens = $store->issue('google', 'https://example.com/reach/find');

    $this->assertNotEmpty($tokens['state']);
    $this->assertNotEmpty($tokens['nonce']);
    $this->assertNotSame($tokens['state'], $tokens['nonce']);

    $consumed = $store->consume($tokens['state']);
    $this->assertNotNull($consumed);
    $this->assertSame('google', $consumed['provider']);
    $this->assertSame($tokens['nonce'], $consumed['nonce']);
    $this->assertSame('https://example.com/reach/find', $consumed['return_to']);
});

test('consume is single use', function () {
    $store = new StateStore();
    $tokens = $store->issue('apple', '/reach/find');

    $this->assertNotNull($store->consume($tokens['state']));
    // Second call must miss — replay attack defence.
    $this->assertNull($store->consume($tokens['state']));
});

test('unknown state rejected', function () {
    $store = new StateStore();
    $this->assertNull($store->consume('never-issued'));
});

test('state tokens are unpredictable', function () {
    $store = new StateStore();
    $seen = [];
    for ($i = 0; $i < 32; $i++) {
        $tokens = $store->issue('google', '/');
        $this->assertNotContains($tokens['state'], $seen, 'State token collision after ' . count($seen) . ' issues');
        $this->assertSame(32, strlen($tokens['state'])); // 16 bytes -> 32 hex chars
        $seen[] = $tokens['state'];
    }
});

test('code verifier round trips when provided', function () {
    $store = new StateStore();
    $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    $tokens = $store->issue('facebook', '/reach/find', $verifier);

    $this->assertSame($verifier, $tokens['code_verifier']);

    $consumed = $store->consume($tokens['state']);
    $this->assertNotNull($consumed);
    $this->assertSame($verifier, $consumed['code_verifier']);
});

test('code verifier defaults to null for non pkce providers', function () {
    $store = new StateStore();
    $tokens = $store->issue('google', '/reach/find');

    $this->assertNull($tokens['code_verifier']);

    $consumed = $store->consume($tokens['state']);
    $this->assertNotNull($consumed);
    $this->assertNull($consumed['code_verifier']);
});
