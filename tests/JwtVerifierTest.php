<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\Auth\JwtVerifier;

/**
 * Generate a real RSA keypair in setup, mint an RS256 JWT signed
 * with the private key, stand up a fake JWKS via a wp_remote_get
 * stub, then verify the JWT through the production code path.
 *
 * This is the only test that touches openssl_sign and the full
 * verify pipeline end-to-end. Issuer, audience, nonce, alg, and
 * expiry are each given their own negative-case test so a future
 * regression in any single check is caught immediately.
 */
final class JwtVerifierTest extends TestCase
{
    private string $privateKey = '';
    private string $jwks = '';
    private string $kid = 'test-kid';
    private string $jwksUrl = 'https://example.test/jwks.json';

    protected function setUp(): void
    {
        $GLOBALS['__reach_transients'] = [];

        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($res, 'Failed to generate test RSA key');

        openssl_pkey_export($res, $privateKey);
        $details = openssl_pkey_get_details($res);
        $this->privateKey = $privateKey;

        // Build a JWKS document with the matching public key.
        $this->jwks = json_encode([
            'keys' => [[
                'kty' => 'RSA',
                'alg' => 'RS256',
                'use' => 'sig',
                'kid' => $this->kid,
                'n' => self::base64Url($details['rsa']['n']),
                'e' => self::base64Url($details['rsa']['e']),
            ]],
        ]);

        // Stub wp_remote_get to return our JWKS for the test URL.
        $jwks = $this->jwks;
        $jwksUrl = $this->jwksUrl;
        $GLOBALS['__reach_http_stub'] = static function (string $url) use ($jwks, $jwksUrl) {
            if ($url !== $jwksUrl) {
                return new \WP_Error('no_stub', 'No stub for ' . $url);
            }
            return [
                'response' => ['code' => 200],
                'body'     => $jwks,
            ];
        };
    }

    public function testValidTokenVerifies(): void
    {
        $claims = [
            'iss'   => 'https://issuer.example',
            'aud'   => 'client-id-123',
            'sub'   => 'user-456',
            'email' => 'a@example.com',
            'email_verified' => true,
            'nonce' => 'expected-nonce',
            'iat'   => time(),
            'exp'   => time() + 3600,
        ];
        $token = $this->mint($claims);

        $verified = (new JwtVerifier())->verify(
            $token,
            $this->jwksUrl,
            'https://issuer.example',
            'client-id-123',
            'expected-nonce'
        );

        $this->assertNotNull($verified);
        $this->assertSame('a@example.com', $verified['email']);
    }

    public function testWrongIssuerRejected(): void
    {
        $token = $this->mint(['iss' => 'https://other.example', 'aud' => 'client-id-123', 'iat' => time(), 'exp' => time() + 3600]);
        $this->assertNull((new JwtVerifier())->verify($token, $this->jwksUrl, 'https://issuer.example', 'client-id-123'));
    }

    public function testWrongAudienceRejected(): void
    {
        $token = $this->mint(['iss' => 'https://issuer.example', 'aud' => 'wrong-client', 'iat' => time(), 'exp' => time() + 3600]);
        $this->assertNull((new JwtVerifier())->verify($token, $this->jwksUrl, 'https://issuer.example', 'client-id-123'));
    }

    public function testWrongNonceRejected(): void
    {
        $token = $this->mint([
            'iss' => 'https://issuer.example', 'aud' => 'client-id-123',
            'nonce' => 'bad', 'iat' => time(), 'exp' => time() + 3600,
        ]);
        $this->assertNull((new JwtVerifier())->verify($token, $this->jwksUrl, 'https://issuer.example', 'client-id-123', 'expected'));
    }

    public function testExpiredTokenRejected(): void
    {
        $token = $this->mint([
            'iss' => 'https://issuer.example', 'aud' => 'client-id-123',
            'iat' => time() - 7200, 'exp' => time() - 3600,
        ]);
        $this->assertNull((new JwtVerifier())->verify($token, $this->jwksUrl, 'https://issuer.example', 'client-id-123'));
    }

    public function testNoneAlgorithmRejected(): void
    {
        $header = self::base64Url(json_encode(['alg' => 'none', 'kid' => $this->kid, 'typ' => 'JWT']));
        $payload = self::base64Url(json_encode([
            'iss' => 'https://issuer.example', 'aud' => 'client-id-123',
            'iat' => time(), 'exp' => time() + 3600,
        ]));
        $token = $header . '.' . $payload . '.';

        $this->assertNull((new JwtVerifier())->verify($token, $this->jwksUrl, 'https://issuer.example', 'client-id-123'));
    }

    public function testTamperedSignatureRejected(): void
    {
        $token = $this->mint(['iss' => 'https://issuer.example', 'aud' => 'client-id-123', 'iat' => time(), 'exp' => time() + 3600]);
        $parts = explode('.', $token);
        $parts[2] = strtr($parts[2], 'a', 'b');
        $tampered = implode('.', $parts);

        $this->assertNull((new JwtVerifier())->verify($tampered, $this->jwksUrl, 'https://issuer.example', 'client-id-123'));
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function mint(array $claims): string
    {
        $header = self::base64Url(json_encode(['alg' => 'RS256', 'kid' => $this->kid, 'typ' => 'JWT']));
        $payload = self::base64Url(json_encode($claims));
        $signed = $header . '.' . $payload;

        openssl_sign($signed, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        return $signed . '.' . self::base64Url($signature);
    }

    private static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
