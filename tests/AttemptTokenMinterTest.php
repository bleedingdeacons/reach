<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\CallAttempts\AttemptTokenMinter;

/**
 * Verifies the attempt-token contract that the REST layer relies on:
 * round-trips succeed, tampering fails, and binding swaps fail. The
 * token is integrity-only — these tests describe what "integrity"
 * means for this surface.
 */
final class AttemptTokenMinterTest extends TestCase
{
    private const NOW = 1_700_000_000;

    public function testRoundTripVerifies(): void
    {
        $minter = new AttemptTokenMinter();
        $token = $minter->mint('alice@example.com', 123, self::NOW);
        $this->assertTrue($minter->verify($token, 'alice@example.com', 123, self::NOW));
    }

    public function testCaseInsensitiveEmailMatch(): void
    {
        // Sessions store emails as-issued by the provider, which may
        // differ in case run-to-run. The mint/verify pair normalises.
        $minter = new AttemptTokenMinter();
        $token = $minter->mint('Alice@Example.com', 123, self::NOW);
        $this->assertTrue($minter->verify($token, 'alice@example.com', 123, self::NOW));
        $this->assertTrue($minter->verify($token, 'ALICE@EXAMPLE.COM', 123, self::NOW));
    }

    public function testRejectsDifferentMember(): void
    {
        // The whole point: you can't take a token issued for member
        // 123 and log an attempt against member 456.
        $minter = new AttemptTokenMinter();
        $token = $minter->mint('alice@example.com', 123, self::NOW);
        $this->assertFalse($minter->verify($token, 'alice@example.com', 456, self::NOW));
    }

    public function testRejectsDifferentViewer(): void
    {
        $minter = new AttemptTokenMinter();
        $token = $minter->mint('alice@example.com', 123, self::NOW);
        $this->assertFalse($minter->verify($token, 'bob@example.com', 123, self::NOW));
    }

    public function testRejectsExpiredToken(): void
    {
        $minter = new AttemptTokenMinter();
        $token = $minter->mint('alice@example.com', 123, self::NOW);
        $future = self::NOW + AttemptTokenMinter::TTL_SECONDS + 1;
        $this->assertFalse($minter->verify($token, 'alice@example.com', 123, $future));
    }

    public function testAcceptsTokenAtTtlBoundary(): void
    {
        // Right at the boundary should still be valid — the contract
        // is "within TTL", not "strictly less than".
        $minter = new AttemptTokenMinter();
        $token = $minter->mint('alice@example.com', 123, self::NOW);
        $atBoundary = self::NOW + AttemptTokenMinter::TTL_SECONDS;
        $this->assertTrue($minter->verify($token, 'alice@example.com', 123, $atBoundary));
    }

    public function testRejectsFutureDatedToken(): void
    {
        // A token claiming to be issued more than a minute in the
        // future is almost certainly forged or replayed with an
        // attacker-controlled clock — refuse.
        $minter = new AttemptTokenMinter();
        $token = $minter->mint('alice@example.com', 123, self::NOW + 120);
        $this->assertFalse($minter->verify($token, 'alice@example.com', 123, self::NOW));
    }

    public function testRejectsTamperedSignature(): void
    {
        $minter = new AttemptTokenMinter();
        $token = $minter->mint('alice@example.com', 123, self::NOW);
        // Flip the last char of the signature.
        $tampered = substr($token, 0, -1) . (substr($token, -1) === 'a' ? 'b' : 'a');
        $this->assertFalse($minter->verify($tampered, 'alice@example.com', 123, self::NOW));
    }

    public function testRejectsTamperedPayload(): void
    {
        // Replace the payload with one binding a different member id,
        // keep the original signature. The HMAC was computed over the
        // *original* payload, so verify must reject.
        $minter = new AttemptTokenMinter();
        $token = $minter->mint('alice@example.com', 123, self::NOW);
        [, $sig] = explode('.', $token, 2);
        $forgedPayload = rtrim(strtr(base64_encode(
            (string) json_encode(['v' => 'alice@example.com', 'm' => 999, 't' => self::NOW])
        ), '+/', '-_'), '=');
        $forged = $forgedPayload . '.' . $sig;
        $this->assertFalse($minter->verify($forged, 'alice@example.com', 999, self::NOW));
    }

    public function testRejectsMalformedTokens(): void
    {
        $minter = new AttemptTokenMinter();
        $this->assertFalse($minter->verify('', 'a@example.com', 1, self::NOW));
        $this->assertFalse($minter->verify('not-a-token', 'a@example.com', 1, self::NOW));
        $this->assertFalse($minter->verify('one.two.three', 'a@example.com', 1, self::NOW));
        $this->assertFalse($minter->verify('!!!.???', 'a@example.com', 1, self::NOW));
    }
}
