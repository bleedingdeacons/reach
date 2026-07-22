<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\Auth\JwtVerifier;

/**
 * Negative-space cover for {@see JwtVerifier} beyond the core happy/negative
 * cases in {@see JwtVerifierTest}: malformed tokens, missing key id, an
 * unresolvable kid (which forces the cache-busting refetch), the mandatory
 * exp/iat claims, a future-dated iat, array-form audience, and each JWKS
 * transport failure. These are the paths an attacker probes, so failing
 * closed on every one of them is the security contract under test.
 */
final class JwtVerifierEdgeTest extends TestCase
{
    private string $privateKey = '';
    private string $jwks = '';
    private string $kid = 'edge-kid';
    private string $jwksUrl = 'https://example.test/jwks.json';
    private string $iss = 'https://issuer.test';
    private string $aud = 'client-abc';

    protected function setUp(): void
    {
        $GLOBALS['__reach_transients'] = [];

        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($res === false) {
            self::markTestSkipped('openssl_pkey_new() unavailable: ' . (openssl_error_string() ?: 'unknown error'));
        }

        openssl_pkey_export($res, $privateKey);
        $details = openssl_pkey_get_details($res);
        $this->privateKey = $privateKey;
        $this->jwks = (string) json_encode([
            'keys' => [[
                'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => $this->kid,
                'n' => self::b64url($details['rsa']['n']),
                'e' => self::b64url($details['rsa']['e']),
            ]],
        ]);
        $this->serveJwks($this->jwks);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__reach_http_stub']);
    }

    public function testMalformedTokenWithWrongSegmentCountIsRejected(): void
    {
        $this->assertNull($this->verify('only.two'));
        $this->assertNull($this->verify('a.b.c.d'));
    }

    public function testNonDecodableHeaderOrPayloadIsRejected(): void
    {
        // Valid structure, but the header/payload aren't base64url JSON.
        $this->assertNull($this->verify('%%%.%%%.sig'));
    }

    public function testMissingKidIsRejected(): void
    {
        $token = $this->mint(
            ['alg' => 'RS256', 'typ' => 'JWT'], // no kid
            $this->baseClaims(),
        );
        $this->assertNull($this->verify($token));
    }

    public function testUnresolvableKidFailsAfterRefetch(): void
    {
        // Header names a kid the JWKS doesn't contain. The verifier retries
        // once with the cache busted, then gives up — null, not a crash.
        $token = $this->mint(
            ['alg' => 'RS256', 'kid' => 'no-such-kid', 'typ' => 'JWT'],
            $this->baseClaims(),
        );
        $this->assertNull($this->verify($token));
    }

    public function testMissingExpClaimIsRejected(): void
    {
        $claims = $this->baseClaims();
        unset($claims['exp']);
        $this->assertNull($this->verify($this->sign($claims)));
    }

    public function testMissingIatClaimIsRejected(): void
    {
        $claims = $this->baseClaims();
        unset($claims['iat']);
        $this->assertNull($this->verify($this->sign($claims)));
    }

    public function testFutureIssuedAtBeyondSkewIsRejected(): void
    {
        $claims = $this->baseClaims();
        $claims['iat'] = time() + 3600; // an hour in the future
        $this->assertNull($this->verify($this->sign($claims)));
    }

    public function testAudienceAsArrayIsAccepted(): void
    {
        $claims = $this->baseClaims();
        $claims['aud'] = ['someone-else', $this->aud];
        $result = $this->verify($this->sign($claims));
        $this->assertIsArray($result);
        $this->assertSame($this->iss, $result['iss']);
    }

    public function testJwksNon2xxResponseYieldsNull(): void
    {
        $this->serveRaw(['response' => ['code' => 500], 'body' => 'oops']);
        $this->assertNull($this->verify($this->sign($this->baseClaims())));
    }

    public function testJwksInvalidJsonYieldsNull(): void
    {
        $this->serveRaw(['response' => ['code' => 200], 'body' => 'not json']);
        $this->assertNull($this->verify($this->sign($this->baseClaims())));
    }

    public function testJwksNetworkErrorYieldsNull(): void
    {
        $GLOBALS['__reach_http_stub'] = static fn(string $url, array $args = [])
            => new \WP_Error('http_request_failed', 'down');
        $this->assertNull($this->verify($this->sign($this->baseClaims())));
    }

    // --- helpers ----------------------------------------------------------

    private function verify(string $jwt): ?array
    {
        return (new JwtVerifier())->verify($jwt, $this->jwksUrl, $this->iss, $this->aud, null);
    }

    /** @return array<string, mixed> */
    private function baseClaims(): array
    {
        return [
            'iss' => $this->iss,
            'aud' => $this->aud,
            'sub' => 'sub-1',
            'email' => 'a@example.com',
            'iat' => time(),
            'exp' => time() + 3600,
        ];
    }

    private function serveJwks(string $jwks): void
    {
        $url = $this->jwksUrl;
        $GLOBALS['__reach_http_stub'] = static function (string $u, array $args = []) use ($jwks, $url) {
            return str_starts_with($u, $url)
                ? ['response' => ['code' => 200], 'body' => $jwks]
                : new \WP_Error('no_stub', 'No stub for ' . $u);
        };
    }

    /** @param array<string, mixed> $response */
    private function serveRaw(array $response): void
    {
        $GLOBALS['__reach_http_stub'] = static fn(string $u, array $args = []) => $response;
    }

    /** @param array<string, mixed> $claims */
    private function sign(array $claims): string
    {
        return $this->mint(['alg' => 'RS256', 'kid' => $this->kid, 'typ' => 'JWT'], $claims);
    }

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $claims
     */
    private function mint(array $header, array $claims): string
    {
        $h = self::b64url((string) json_encode($header));
        $p = self::b64url((string) json_encode($claims));
        $signed = $h . '.' . $p;
        openssl_sign($signed, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        return $signed . '.' . self::b64url($signature);
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
