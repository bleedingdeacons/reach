<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
use Reach\Auth\DeviceCodeStore;
use Reach\Auth\DeviceTokenMinter;
use Reach\Auth\VerifiedIdentity;

/**
 * The two credentials in the device flow: the short-lived exchange code
 * that travels through the browser, and the long-lived bearer token that
 * never does.
 */

beforeEach(function () {
    WpState::$transients = [];
});

// --- tokens -----------------------------------------------------------
test('minted tokens are prefixed and unpredictable', function () {
    $minter = new DeviceTokenMinter();
    $seen = [];

    for ($i = 0; $i < 32; $i++) {
        $token = $minter->mint();

        $this->assertStringStartsWith(DeviceTokenMinter::TOKEN_PREFIX, $token);
        $this->assertNotContains($token, $seen, 'Device token collision after ' . count($seen) . ' mints');
        $this->assertTrue($minter->looksLikeToken($token));

        $seen[] = $token;
    }
});

test('hash is stable and not the plaintext', function () {
    $minter = new DeviceTokenMinter();
    $token = $minter->mint();

    $this->assertSame($minter->hash($token), $minter->hash($token));
    $this->assertNotSame($token, $minter->hash($token));
    // 64 hex characters, which is what the CHAR(64) column expects.
    $this->assertSame(64, strlen($minter->hash($token)));
});

test('rotating the auth salt invalidates every token', function () {
    // Documented behaviour, not an accident: rotating salts is the
    // recovery action after a suspected breach, and every handset
    // re-enrolling is the outcome that should have.
    $minter = new DeviceTokenMinter();
    $token = $minter->mint();
    $before = $minter->hash($token);

    $this->salts['auth'] = 'a-completely-different-salt-' . str_repeat('z', 40);

    $this->assertNotSame($before, $minter->hash($token));
});

test('looks like token rejects', function (string $candidate) {
    $this->assertFalse((new DeviceTokenMinter())->looksLikeToken($candidate));
})->with('malformedTokens');

/** @return array<string, array{0: string}> */
dataset('malformedTokens', function (): array {
    return [
        'empty'          => [''],
        'no prefix'      => [str_repeat('a', 64)],
        'too short'      => [DeviceTokenMinter::TOKEN_PREFIX . str_repeat('a', 63)],
        'too long'       => [DeviceTokenMinter::TOKEN_PREFIX . str_repeat('a', 65)],
        'not hex'        => [DeviceTokenMinter::TOKEN_PREFIX . str_repeat('z', 64)],
        'uppercase hex'  => [DeviceTokenMinter::TOKEN_PREFIX . str_repeat('A', 64)],
    ];
});

test('bearer extraction', function (string $header, string $expected) {
    $this->assertSame($expected, (new DeviceTokenMinter())->bearerFrom($header));
})->with('authorizationHeaders');

/** @return array<string, array{0: string, 1: string}> */
dataset('authorizationHeaders', function (): array {
    return [
        'plain'              => ['Bearer rdt_abc', 'rdt_abc'],
        'lowercase scheme'   => ['bearer rdt_abc', 'rdt_abc'],
        'padded'             => ['  Bearer   rdt_abc  ', 'rdt_abc'],
        'absent'             => ['', ''],
        'wrong scheme'       => ['Basic abc', ''],
        'scheme but no value' => ['Bearer ', ''],
    ];
});

// --- exchange codes ---------------------------------------------------
test('code round trips the identity exactly once', function () {
    $store = new DeviceCodeStore();
    $code = $store->issue(new VerifiedIdentity(
        email: 'responder@example.com',
        provider: 'google',
        sub: 'sub-123',
    ));

    $consumed = $store->consume($code);
    $this->assertNotNull($consumed);
    $this->assertSame('responder@example.com', $consumed->email);
    $this->assertSame('google', $consumed->provider);
    $this->assertSame('sub-123', $consumed->sub);

    // Single use: replaying the redirect must not enrol a second
    // handset on the back of one sign-in.
    $this->assertNull($store->consume($code));
});

test('unknown code is refused', function () {
    $this->assertNull((new DeviceCodeStore())->consume('never-issued'));
    $this->assertNull((new DeviceCodeStore())->consume(''));
});

test('codes are unpredictable', function () {
    $store = new DeviceCodeStore();
    $identity = new VerifiedIdentity(email: 'a@example.com', provider: 'google', sub: 's');
    $seen = [];

    for ($i = 0; $i < 16; $i++) {
        $code = $store->issue($identity);
        $this->assertNotContains($code, $seen);
        $seen[] = $code;
    }
});

test('the code itself is not stored as an option name', function () {
    // Option names are not secret — they appear in database dumps and
    // admin tooling — so a live code sitting in one would be a
    // credential in the clear for the length of its window.
    $store = new DeviceCodeStore();
    $code = $store->issue(new VerifiedIdentity(email: 'a@example.com', provider: 'google', sub: 's'));

    foreach (array_keys(WpState::$transients) as $key) {
        $this->assertStringNotContainsString($code, (string) $key);
    }
});
