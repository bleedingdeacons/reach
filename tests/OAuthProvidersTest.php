<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\Auth\JwtVerifier;
use Reach\Auth\Providers\AppleProvider;
use Reach\Auth\Providers\GoogleProvider;
use Reach\Auth\Providers\MicrosoftProvider;
use Reach\Auth\VerifiedIdentity;
use Reach\Core\Settings;

/**
 * End-to-end cover for the Google, Microsoft and Apple providers, driven the
 * same way as {@see FacebookProviderTest}: a real RSA keypair, real RS256
 * signing, and a fake JWKS + token endpoint served through the wp_remote_*
 * stub. The emphasis is the security contract each provider enforces before
 * trusting an email — signature, issuer, audience, nonce, and the
 * email_verified gate — plus the flow-shape guards (which methods throw for
 * the wrong flow).
 */
final class OAuthProvidersTest extends TestCase
{
    private string $privateKey = '';
    private string $jwks = '';
    private string $kid = 'oauth-test-kid';
    private string $clientId = 'test-client-id';

    private const GOOGLE_ISS = 'https://accounts.google.com';
    private const GOOGLE_JWKS = 'https://www.googleapis.com/oauth2/v3/certs';
    private const GOOGLE_TOKEN = 'https://oauth2.googleapis.com/token';

    private const MS_ISS = 'https://login.microsoftonline.com/9188040d-6c67-4c5b-b112-36a304b66dad/v2.0';
    private const MS_JWKS = 'https://login.microsoftonline.com/consumers/discovery/v2.0/keys';
    private const MS_TOKEN = 'https://login.microsoftonline.com/consumers/oauth2/v2.0/token';

    private const APPLE_ISS = 'https://appleid.apple.com';
    private const APPLE_JWKS = 'https://appleid.apple.com/auth/keys';

    protected function setUp(): void
    {
        $GLOBALS['__reach_transients'] = [];
        $GLOBALS['__reach_options'] = [];

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
                'kty' => 'RSA',
                'alg' => 'RS256',
                'use' => 'sig',
                'kid' => $this->kid,
                'n'   => self::b64url($details['rsa']['n']),
                'e'   => self::b64url($details['rsa']['e']),
            ]],
        ]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__reach_http_stub']);
    }

    // --- Google -----------------------------------------------------------

    public function testGoogleAuthorizationUrlCarriesMinimalScopeAndParams(): void
    {
        $url = $this->google()->getAuthorizationUrl('st', 'no', 'https://example.test/cb');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $this->assertStringStartsWith('https://accounts.google.com/', $url);
        $this->assertSame('openid email', $q['scope'] ?? null, 'Google must request only openid+email');
        $this->assertSame('code', $q['response_type'] ?? null);
        $this->assertSame('st', $q['state'] ?? null);
        $this->assertSame('no', $q['nonce'] ?? null);
        $this->assertSame('select_account', $q['prompt'] ?? null);
    }

    public function testGoogleHandleCallbackReturnsLowercasedEmailIdentity(): void
    {
        $this->stub(self::GOOGLE_JWKS, self::GOOGLE_TOKEN, $this->mint([
            'iss' => self::GOOGLE_ISS, 'aud' => $this->clientId, 'sub' => 'g-1',
            'email' => 'Alice@Example.com', 'email_verified' => true, 'nonce' => 'no',
            'iat' => time(), 'exp' => time() + 3600,
        ]));

        $id = $this->google()->handleCallback('code', 'no', 'https://example.test/cb');

        $this->assertInstanceOf(VerifiedIdentity::class, $id);
        $this->assertSame('alice@example.com', $id->email);
        $this->assertSame('google', $id->provider);
        $this->assertSame('g-1', $id->sub);
    }

    public function testGoogleRejectsUnverifiedEmail(): void
    {
        $this->stub(self::GOOGLE_JWKS, self::GOOGLE_TOKEN, $this->mint([
            'iss' => self::GOOGLE_ISS, 'aud' => $this->clientId, 'sub' => 'g-1',
            'email' => 'a@example.com', 'email_verified' => false, 'nonce' => 'no',
            'iat' => time(), 'exp' => time() + 3600,
        ]));

        $this->assertNull($this->google()->handleCallback('code', 'no', 'https://example.test/cb'));
    }

    public function testGoogleRejectsMissingEmailVerifiedClaim(): void
    {
        // Claim absent entirely — must fail closed, not assume verified.
        $this->stub(self::GOOGLE_JWKS, self::GOOGLE_TOKEN, $this->mint([
            'iss' => self::GOOGLE_ISS, 'aud' => $this->clientId, 'sub' => 'g-1',
            'email' => 'a@example.com', 'nonce' => 'no',
            'iat' => time(), 'exp' => time() + 3600,
        ]));

        $this->assertNull($this->google()->handleCallback('code', 'no', 'https://example.test/cb'));
    }

    public function testGoogleReturnsNullWhenTokenEndpointReturnsNon2xx(): void
    {
        $GLOBALS['__reach_http_stub'] = static fn(string $url, array $args = [])
            => ['response' => ['code' => 400], 'body' => '{"error":"invalid_grant"}'];

        $this->assertNull($this->google()->handleCallback('bad-code', 'no', 'https://example.test/cb'));
    }

    public function testGoogleReturnsNullWhenTokenResponseHasNoIdToken(): void
    {
        $GLOBALS['__reach_http_stub'] = static fn(string $url, array $args = [])
            => ['response' => ['code' => 200], 'body' => '{"access_token":"x"}'];

        $this->assertNull($this->google()->handleCallback('code', 'no', 'https://example.test/cb'));
    }

    public function testGoogleReturnsNullOnNetworkError(): void
    {
        $GLOBALS['__reach_http_stub'] = static fn(string $url, array $args = [])
            => new \WP_Error('http_request_failed', 'boom');

        $this->assertNull($this->google()->handleCallback('code', 'no', 'https://example.test/cb'));
    }

    public function testGoogleVerifyIdTokenThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->google()->verifyIdToken('tok', 'no');
    }

    public function testGoogleMetadata(): void
    {
        $this->assertSame('google', $this->google()->name());
        $this->assertTrue($this->google()->isServerSide());
    }

    // --- Microsoft --------------------------------------------------------

    public function testMicrosoftAuthorizationUrlRequestsProfileScopeAndQueryMode(): void
    {
        $url = $this->microsoft()->getAuthorizationUrl('st', 'no', 'https://example.test/cb');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $this->assertStringStartsWith('https://login.microsoftonline.com/consumers/', $url);
        $this->assertSame('openid email profile', $q['scope'] ?? null);
        $this->assertSame('query', $q['response_mode'] ?? null);
    }

    public function testMicrosoftHandleCallbackUsesEmailClaim(): void
    {
        $this->stub(self::MS_JWKS, self::MS_TOKEN, $this->mint([
            'iss' => self::MS_ISS, 'aud' => $this->clientId, 'sub' => 'm-1',
            'email' => 'Bob@Outlook.com', 'nonce' => 'no',
            'iat' => time(), 'exp' => time() + 3600,
        ]));

        $id = $this->microsoft()->handleCallback('code', 'no', 'https://example.test/cb');
        $this->assertNotNull($id);
        $this->assertSame('bob@outlook.com', $id->email);
        $this->assertSame('microsoft', $id->provider);
    }

    public function testMicrosoftFallsBackToPreferredUsernameWhenItIsAnEmail(): void
    {
        $this->stub(self::MS_JWKS, self::MS_TOKEN, $this->mint([
            'iss' => self::MS_ISS, 'aud' => $this->clientId, 'sub' => 'm-2',
            'preferred_username' => 'carol@live.com', 'nonce' => 'no',
            'iat' => time(), 'exp' => time() + 3600,
        ]));

        $id = $this->microsoft()->handleCallback('code', 'no', 'https://example.test/cb');
        $this->assertNotNull($id);
        $this->assertSame('carol@live.com', $id->email);
    }

    public function testMicrosoftReturnsNullWhenNoUsableEmail(): void
    {
        // No email, and preferred_username is not an email address.
        $this->stub(self::MS_JWKS, self::MS_TOKEN, $this->mint([
            'iss' => self::MS_ISS, 'aud' => $this->clientId, 'sub' => 'm-3',
            'preferred_username' => 'not-an-email', 'nonce' => 'no',
            'iat' => time(), 'exp' => time() + 3600,
        ]));

        $this->assertNull($this->microsoft()->handleCallback('code', 'no', 'https://example.test/cb'));
    }

    public function testMicrosoftVerifyIdTokenThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->microsoft()->verifyIdToken('tok', 'no');
    }

    public function testMicrosoftMetadata(): void
    {
        $this->assertSame('microsoft', $this->microsoft()->name());
        $this->assertTrue($this->microsoft()->isServerSide());
    }

    // --- Apple ------------------------------------------------------------

    public function testAppleIsClientSideAndRejectsRedirectFlowMethods(): void
    {
        $apple = $this->apple();
        $this->assertSame('apple', $apple->name());
        $this->assertFalse($apple->isServerSide());

        $threw = 0;
        foreach (
            [
                fn() => $apple->getAuthorizationUrl('s', 'n', 'https://example.test/cb'),
                fn() => $apple->handleCallback('c', 'n', 'https://example.test/cb'),
            ] as $call
        ) {
            try {
                $call();
            } catch (\LogicException) {
                $threw++;
            }
        }
        $this->assertSame(2, $threw, 'both server-side methods must throw for the client-side Apple flow');
    }

    public function testAppleVerifyIdTokenReturnsIdentityForVerifiedEmail(): void
    {
        $this->stubJwks(self::APPLE_JWKS);
        $token = $this->mint([
            'iss' => self::APPLE_ISS, 'aud' => $this->clientId, 'sub' => 'a-1',
            'email' => 'Dave@icloud.com', 'email_verified' => true, 'nonce' => 'no',
            'iat' => time(), 'exp' => time() + 3600,
        ]);

        $id = $this->apple()->verifyIdToken($token, 'no');
        $this->assertNotNull($id);
        $this->assertSame('dave@icloud.com', $id->email);
        $this->assertSame('apple', $id->provider);
    }

    public function testAppleAcceptsStringTrueEmailVerified(): void
    {
        // Apple returns email_verified as the string "true" on some legs.
        $this->stubJwks(self::APPLE_JWKS);
        $token = $this->mint([
            'iss' => self::APPLE_ISS, 'aud' => $this->clientId, 'sub' => 'a-2',
            'email' => 'e@privaterelay.appleid.com', 'email_verified' => 'true', 'nonce' => 'no',
            'iat' => time(), 'exp' => time() + 3600,
        ]);

        $this->assertNotNull($this->apple()->verifyIdToken($token, 'no'));
    }

    public function testAppleRejectsUnverifiedEmail(): void
    {
        $this->stubJwks(self::APPLE_JWKS);
        $token = $this->mint([
            'iss' => self::APPLE_ISS, 'aud' => $this->clientId, 'sub' => 'a-3',
            'email' => 'e@icloud.com', 'email_verified' => 'false', 'nonce' => 'no',
            'iat' => time(), 'exp' => time() + 3600,
        ]);

        $this->assertNull($this->apple()->verifyIdToken($token, 'no'));
    }

    public function testAppleRejectsWrongIssuer(): void
    {
        $this->stubJwks(self::APPLE_JWKS);
        $token = $this->mint([
            'iss' => 'https://impostor.example', 'aud' => $this->clientId, 'sub' => 'a-4',
            'email' => 'e@icloud.com', 'email_verified' => true, 'nonce' => 'no',
            'iat' => time(), 'exp' => time() + 3600,
        ]);

        $this->assertNull($this->apple()->verifyIdToken($token, 'no'));
    }

    public function testAppleRejectsMissingEmail(): void
    {
        $this->stubJwks(self::APPLE_JWKS);
        $token = $this->mint([
            'iss' => self::APPLE_ISS, 'aud' => $this->clientId, 'sub' => 'a-5',
            'email_verified' => true, 'nonce' => 'no',
            'iat' => time(), 'exp' => time() + 3600,
        ]);

        $this->assertNull($this->apple()->verifyIdToken($token, 'no'));
    }

    // --- factories / helpers ---------------------------------------------

    private function google(): GoogleProvider
    {
        return new GoogleProvider($this->settings(), new JwtVerifier());
    }

    private function microsoft(): MicrosoftProvider
    {
        return new MicrosoftProvider($this->settings(), new JwtVerifier());
    }

    private function apple(): AppleProvider
    {
        return new AppleProvider($this->settings(), new JwtVerifier());
    }

    private function settings(): Settings
    {
        $s = new Settings();
        $s->setClientId('google', $this->clientId);
        $s->setClientId('microsoft', $this->clientId);
        $s->setClientId('apple', $this->clientId);
        $s->setClientSecret('google', 'secret');
        $s->setClientSecret('microsoft', 'secret');
        return $s;
    }

    /** Serve the JWKS at $jwksUrl and an id_token wrapper at $tokenUrl. */
    private function stub(string $jwksUrl, string $tokenUrl, string $idToken): void
    {
        $jwks = $this->jwks;
        $GLOBALS['__reach_http_stub'] = static function (string $url, array $args = []) use ($jwks, $jwksUrl, $tokenUrl, $idToken) {
            if (str_starts_with($url, $jwksUrl)) {
                return ['response' => ['code' => 200], 'body' => $jwks];
            }
            if (str_starts_with($url, $tokenUrl)) {
                return ['response' => ['code' => 200], 'body' => (string) json_encode(['id_token' => $idToken])];
            }
            return new \WP_Error('no_stub', 'No stub for ' . $url);
        };
    }

    /** Serve only the JWKS — for the client-side Apple flow (no token leg). */
    private function stubJwks(string $jwksUrl): void
    {
        $jwks = $this->jwks;
        $GLOBALS['__reach_http_stub'] = static function (string $url, array $args = []) use ($jwks, $jwksUrl) {
            if (str_starts_with($url, $jwksUrl)) {
                return ['response' => ['code' => 200], 'body' => $jwks];
            }
            return new \WP_Error('no_stub', 'No stub for ' . $url);
        };
    }

    /** @param array<string, mixed> $claims */
    private function mint(array $claims): string
    {
        $header = self::b64url((string) json_encode(['alg' => 'RS256', 'kid' => $this->kid, 'typ' => 'JWT']));
        $payload = self::b64url((string) json_encode($claims));
        $signed = $header . '.' . $payload;
        openssl_sign($signed, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        return $signed . '.' . self::b64url($signature);
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
