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
final class DeviceCredentialsTest extends ReachTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        WpState::$transients = [];
    }

    // --- tokens -----------------------------------------------------------

    public function testMintedTokensArePrefixedAndUnpredictable(): void
    {
        $minter = new DeviceTokenMinter();
        $seen = [];

        for ($i = 0; $i < 32; $i++) {
            $token = $minter->mint();

            $this->assertStringStartsWith(DeviceTokenMinter::TOKEN_PREFIX, $token);
            $this->assertNotContains($token, $seen, 'Device token collision after ' . count($seen) . ' mints');
            $this->assertTrue($minter->looksLikeToken($token));

            $seen[] = $token;
        }
    }

    public function testHashIsStableAndNotThePlaintext(): void
    {
        $minter = new DeviceTokenMinter();
        $token = $minter->mint();

        $this->assertSame($minter->hash($token), $minter->hash($token));
        $this->assertNotSame($token, $minter->hash($token));
        // 64 hex characters, which is what the CHAR(64) column expects.
        $this->assertSame(64, strlen($minter->hash($token)));
    }

    public function testRotatingTheAuthSaltInvalidatesEveryToken(): void
    {
        // Documented behaviour, not an accident: rotating salts is the
        // recovery action after a suspected breach, and every handset
        // re-enrolling is the outcome that should have.
        $minter = new DeviceTokenMinter();
        $token = $minter->mint();
        $before = $minter->hash($token);

        $this->salts['auth'] = 'a-completely-different-salt-' . str_repeat('z', 40);

        $this->assertNotSame($before, $minter->hash($token));
    }

    /**
     * @dataProvider malformedTokens
     */
    public function testLooksLikeTokenRejects(string $candidate): void
    {
        $this->assertFalse((new DeviceTokenMinter())->looksLikeToken($candidate));
    }

    /** @return array<string, array{0: string}> */
    public static function malformedTokens(): array
    {
        return [
            'empty'          => [''],
            'no prefix'      => [str_repeat('a', 64)],
            'too short'      => [DeviceTokenMinter::TOKEN_PREFIX . str_repeat('a', 63)],
            'too long'       => [DeviceTokenMinter::TOKEN_PREFIX . str_repeat('a', 65)],
            'not hex'        => [DeviceTokenMinter::TOKEN_PREFIX . str_repeat('z', 64)],
            'uppercase hex'  => [DeviceTokenMinter::TOKEN_PREFIX . str_repeat('A', 64)],
        ];
    }

    /**
     * @dataProvider authorizationHeaders
     */
    public function testBearerExtraction(string $header, string $expected): void
    {
        $this->assertSame($expected, (new DeviceTokenMinter())->bearerFrom($header));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function authorizationHeaders(): array
    {
        return [
            'plain'              => ['Bearer rdt_abc', 'rdt_abc'],
            'lowercase scheme'   => ['bearer rdt_abc', 'rdt_abc'],
            'padded'             => ['  Bearer   rdt_abc  ', 'rdt_abc'],
            'absent'             => ['', ''],
            'wrong scheme'       => ['Basic abc', ''],
            'scheme but no value' => ['Bearer ', ''],
        ];
    }

    // --- exchange codes ---------------------------------------------------

    public function testCodeRoundTripsTheIdentityExactlyOnce(): void
    {
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
    }

    public function testUnknownCodeIsRefused(): void
    {
        $this->assertNull((new DeviceCodeStore())->consume('never-issued'));
        $this->assertNull((new DeviceCodeStore())->consume(''));
    }

    public function testCodesAreUnpredictable(): void
    {
        $store = new DeviceCodeStore();
        $identity = new VerifiedIdentity(email: 'a@example.com', provider: 'google', sub: 's');
        $seen = [];

        for ($i = 0; $i < 16; $i++) {
            $code = $store->issue($identity);
            $this->assertNotContains($code, $seen);
            $seen[] = $code;
        }
    }

    public function testTheCodeItselfIsNotStoredAsAnOptionName(): void
    {
        // Option names are not secret — they appear in database dumps and
        // admin tooling — so a live code sitting in one would be a
        // credential in the clear for the length of its window.
        $store = new DeviceCodeStore();
        $code = $store->issue(new VerifiedIdentity(email: 'a@example.com', provider: 'google', sub: 's'));

        foreach (array_keys(WpState::$transients) as $key) {
            $this->assertStringNotContainsString($code, (string) $key);
        }
    }
}
