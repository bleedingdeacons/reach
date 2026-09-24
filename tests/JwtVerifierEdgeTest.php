<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
use Reach\Auth\JwtVerifier;

/**
 * Negative-space cover for {@see JwtVerifier} beyond the core happy/negative
 * cases in {@see JwtVerifierTest}: malformed tokens, missing key id, an
 * unresolvable kid (which forces the cache-busting refetch), the mandatory
 * exp/iat claims, a future-dated iat, array-form audience, and each JWKS
 * transport failure. These are the paths an attacker probes, so failing
 * closed on every one of them is the security contract under test.
 */

beforeEach(function () {
    $this->privateKey = '';

    $this->jwks = '';

    $this->kid = 'edge-kid';

    $this->jwksUrl = 'https://example.test/jwks.json';

    $this->iss = 'https://issuer.test';

    $this->aud = 'client-abc';

    // --- helpers ----------------------------------------------------------
    $this->verify = function (string $jwt): ?array {
        return (new JwtVerifier())->verify($jwt, $this->jwksUrl, $this->iss, $this->aud, null);
    };

    /** @return array<string, mixed> */
    $this->baseClaims = function (): array {
        return [
            'iss' => $this->iss,
            'aud' => $this->aud,
            'sub' => 'sub-1',
            'email' => 'a@example.com',
            'iat' => time(),
            'exp' => time() + 3600,
        ];
    };

    $this->serveJwks = function (string $jwks): void {
        $url = $this->jwksUrl;
        $this->stubHttp(static function (string $u, array $args = []) use ($jwks, $url) {
            return str_starts_with($u, $url)
                ? ['response' => ['code' => 200], 'body' => $jwks]
                : new \WP_Error('no_stub', 'No stub for ' . $u);
        });
    };

    /** @param array<string, mixed> $response */
    $this->serveRaw = function (array $response): void {
        $this->stubHttp(static fn(string $u, array $args = []) => $response);
    };

    /** @param array<string, mixed> $claims */
    $this->sign = function (array $claims): string {
        return ($this->mint)(['alg' => 'RS256', 'kid' => $this->kid, 'typ' => 'JWT'], $claims);
    };

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $claims
     */
    $this->mint = function (array $header, array $claims): string {
        $h = ($this->b64url)((string) json_encode($header));
        $p = ($this->b64url)((string) json_encode($claims));
        $signed = $h . '.' . $p;
        openssl_sign($signed, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        return $signed . '.' . ($this->b64url)($signature);
    };

    $this->b64url = function (string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    };

    WpState::$transients = [];

    $res = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if ($res === false) {
        $this->markTestSkipped('openssl_pkey_new() unavailable: ' . (openssl_error_string() ?: 'unknown error'));
    }

    openssl_pkey_export($res, $privateKey);
    $details = openssl_pkey_get_details($res);
    $this->privateKey = $privateKey;
    $this->jwks = (string) json_encode([
        'keys' => [[
            'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => $this->kid,
            'n' => ($this->b64url)($details['rsa']['n']),
            'e' => ($this->b64url)($details['rsa']['e']),
        ]],
    ]);
    ($this->serveJwks)($this->jwks);
});

afterEach(function () {
});

test('malformed token with wrong segment count is rejected', function () {
    $this->assertNull(($this->verify)('only.two'));
    $this->assertNull(($this->verify)('a.b.c.d'));
});

test('non decodable header or payload is rejected', function () {
    // Valid structure, but the header/payload aren't base64url JSON.
    $this->assertNull(($this->verify)('%%%.%%%.sig'));
});

test('missing kid is rejected', function () {
    $token = ($this->mint)(
        ['alg' => 'RS256', 'typ' => 'JWT'], // no kid
        ($this->baseClaims)(),
    );
    $this->assertNull(($this->verify)($token));
});

test('unresolvable kid fails after refetch', function () {
    // Header names a kid the JWKS doesn't contain. The verifier retries
    // once with the cache busted, then gives up — null, not a crash.
    $token = ($this->mint)(
        ['alg' => 'RS256', 'kid' => 'no-such-kid', 'typ' => 'JWT'],
        ($this->baseClaims)(),
    );
    $this->assertNull(($this->verify)($token));
});

test('missing exp claim is rejected', function () {
    $claims = ($this->baseClaims)();
    unset($claims['exp']);
    $this->assertNull(($this->verify)(($this->sign)($claims)));
});

test('missing iat claim is rejected', function () {
    $claims = ($this->baseClaims)();
    unset($claims['iat']);
    $this->assertNull(($this->verify)(($this->sign)($claims)));
});

test('future issued at beyond skew is rejected', function () {
    $claims = ($this->baseClaims)();
    $claims['iat'] = time() + 3600; // an hour in the future
    $this->assertNull(($this->verify)(($this->sign)($claims)));
});

test('audience as array is accepted', function () {
    $claims = ($this->baseClaims)();
    $claims['aud'] = ['someone-else', $this->aud];
    $result = ($this->verify)(($this->sign)($claims));
    $this->assertIsArray($result);
    $this->assertSame($this->iss, $result['iss']);
});

test('jwks non2xx response yields null', function () {
    ($this->serveRaw)(['response' => ['code' => 500], 'body' => 'oops']);
    $this->assertNull(($this->verify)(($this->sign)(($this->baseClaims)())));
});

test('jwks invalid json yields null', function () {
    ($this->serveRaw)(['response' => ['code' => 200], 'body' => 'not json']);
    $this->assertNull(($this->verify)(($this->sign)(($this->baseClaims)())));
});

test('jwks network error yields null', function () {
    $this->stubHttp(static fn(string $url, array $args = [])
        => new \WP_Error('http_request_failed', 'down'));
    $this->assertNull(($this->verify)(($this->sign)(($this->baseClaims)())));
});
