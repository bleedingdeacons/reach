<?php

declare(strict_types=1);

namespace Reach\Tests;

use Reach\CallAttempts\AttemptTokenMinter;

/**
 * Verifies the attempt-token contract that the REST layer relies on:
 * round-trips succeed, tampering fails, and binding swaps fail. The
 * token is integrity-only — these tests describe what "integrity"
 * means for this surface.
 */

const ATTEMPT_TOKEN_MINTER_NOW = 1_700_000_000;

test('round trip verifies', function () {
    $minter = new AttemptTokenMinter();
    $token = $minter->mint('alice@example.com', 123, ATTEMPT_TOKEN_MINTER_NOW);
    $this->assertTrue($minter->verify($token, 'alice@example.com', 123, ATTEMPT_TOKEN_MINTER_NOW));
});

test('case insensitive email match', function () {
    // Sessions store emails as-issued by the provider, which may
    // differ in case run-to-run. The mint/verify pair normalises.
    $minter = new AttemptTokenMinter();
    $token = $minter->mint('Alice@Example.com', 123, ATTEMPT_TOKEN_MINTER_NOW);
    $this->assertTrue($minter->verify($token, 'alice@example.com', 123, ATTEMPT_TOKEN_MINTER_NOW));
    $this->assertTrue($minter->verify($token, 'ALICE@EXAMPLE.COM', 123, ATTEMPT_TOKEN_MINTER_NOW));
});

test('rejects different member', function () {
    // The whole point: you can't take a token issued for member
    // 123 and log an attempt against member 456.
    $minter = new AttemptTokenMinter();
    $token = $minter->mint('alice@example.com', 123, ATTEMPT_TOKEN_MINTER_NOW);
    $this->assertFalse($minter->verify($token, 'alice@example.com', 456, ATTEMPT_TOKEN_MINTER_NOW));
});

test('rejects different viewer', function () {
    $minter = new AttemptTokenMinter();
    $token = $minter->mint('alice@example.com', 123, ATTEMPT_TOKEN_MINTER_NOW);
    $this->assertFalse($minter->verify($token, 'bob@example.com', 123, ATTEMPT_TOKEN_MINTER_NOW));
});

test('rejects expired token', function () {
    $minter = new AttemptTokenMinter();
    $token = $minter->mint('alice@example.com', 123, ATTEMPT_TOKEN_MINTER_NOW);
    $future = ATTEMPT_TOKEN_MINTER_NOW + AttemptTokenMinter::TTL_SECONDS + 1;
    $this->assertFalse($minter->verify($token, 'alice@example.com', 123, $future));
});

test('accepts token at ttl boundary', function () {
    // Right at the boundary should still be valid — the contract
    // is "within TTL", not "strictly less than".
    $minter = new AttemptTokenMinter();
    $token = $minter->mint('alice@example.com', 123, ATTEMPT_TOKEN_MINTER_NOW);
    $atBoundary = ATTEMPT_TOKEN_MINTER_NOW + AttemptTokenMinter::TTL_SECONDS;
    $this->assertTrue($minter->verify($token, 'alice@example.com', 123, $atBoundary));
});

test('rejects future dated token', function () {
    // A token claiming to be issued more than a minute in the
    // future is almost certainly forged or replayed with an
    // attacker-controlled clock — refuse.
    $minter = new AttemptTokenMinter();
    $token = $minter->mint('alice@example.com', 123, ATTEMPT_TOKEN_MINTER_NOW + 120);
    $this->assertFalse($minter->verify($token, 'alice@example.com', 123, ATTEMPT_TOKEN_MINTER_NOW));
});

test('rejects tampered signature', function () {
    $minter = new AttemptTokenMinter();
    $token = $minter->mint('alice@example.com', 123, ATTEMPT_TOKEN_MINTER_NOW);
    // Flip the last char of the signature.
    $tampered = substr($token, 0, -1) . (substr($token, -1) === 'a' ? 'b' : 'a');
    $this->assertFalse($minter->verify($tampered, 'alice@example.com', 123, ATTEMPT_TOKEN_MINTER_NOW));
});

test('rejects tampered payload', function () {
    // Replace the payload with one binding a different member id,
    // keep the original signature. The HMAC was computed over the
    // *original* payload, so verify must reject.
    $minter = new AttemptTokenMinter();
    $token = $minter->mint('alice@example.com', 123, ATTEMPT_TOKEN_MINTER_NOW);
    [, $sig] = explode('.', $token, 2);
    $forgedPayload = rtrim(strtr(base64_encode(
        (string) json_encode(['v' => 'alice@example.com', 'm' => 999, 't' => ATTEMPT_TOKEN_MINTER_NOW])
    ), '+/', '-_'), '=');
    $forged = $forgedPayload . '.' . $sig;
    $this->assertFalse($minter->verify($forged, 'alice@example.com', 999, ATTEMPT_TOKEN_MINTER_NOW));
});

test('rejects malformed tokens', function () {
    $minter = new AttemptTokenMinter();
    $this->assertFalse($minter->verify('', 'a@example.com', 1, ATTEMPT_TOKEN_MINTER_NOW));
    $this->assertFalse($minter->verify('not-a-token', 'a@example.com', 1, ATTEMPT_TOKEN_MINTER_NOW));
    $this->assertFalse($minter->verify('one.two.three', 'a@example.com', 1, ATTEMPT_TOKEN_MINTER_NOW));
    $this->assertFalse($minter->verify('!!!.???', 'a@example.com', 1, ATTEMPT_TOKEN_MINTER_NOW));
});
