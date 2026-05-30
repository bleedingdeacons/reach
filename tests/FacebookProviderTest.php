<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\Auth\JwtVerifier;
use Reach\Auth\Providers\FacebookProvider;
use Reach\Core\Settings;

/**
 * The Facebook provider has four behaviours we genuinely care about:
 *
 *  1. The authorise URL must carry an S256 `code_challenge` derived
 *     from the verifier — it's the headline reason Facebook needs its
 *     own provider class and not just a Settings entry pointing at the
 *     Google one.
 *  2. Calling getAuthorizationUrl without a verifier must blow up,
 *     not silently produce a URL that the token endpoint will later
 *     reject.
 *  3. handleCallback hits the token endpoint as a GET (Facebook's
 *     wart), forwards the verifier, verifies the returned ID token,
 *     and yields a VerifiedIdentity with the email.
 *  4. handleCallback enforces `email_verified` — the entire point of
 *     the flow is a proven email, and an unverified one is a refusal.
 *
 * The test reuses the JwtVerifierTest pattern: real RSA keypair, real
 * RS256 signing, a fake JWKS served via the wp_remote_* stub. The
 * token endpoint is served by the same stub, dispatched by URL.
 */
final class FacebookProviderTest extends TestCase
{
    private string $privateKey = '';
    private string $jwks = '';
    private string $kid = 'fb-test-kid';
    private string $clientId = 'fb-app-id-123';
    private string $clientSecret = 'fb-app-secret';

    protected function setUp(): void
    {
        $GLOBALS['__reach_transients'] = [];
        $GLOBALS['__reach_options'] = [];

        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($res, 'Failed to generate test RSA key');

        openssl_pkey_export($res, $privateKey);
        $details = openssl_pkey_get_details($res);
        $this->privateKey = $privateKey;

        $this->jwks = json_encode([
            'keys' => [[
                'kty' => 'RSA',
                'alg' => 'RS256',
                'use' => 'sig',
                'kid' => $this->kid,
                'n'   => self::base64Url($details['rsa']['n']),
                'e'   => self::base64Url($details['rsa']['e']),
            ]],
        ]);
    }

    public function testAuthorizationUrlIncludesS256ChallengeFromVerifier(): void
    {
        $provider = new FacebookProvider($this->makeSettings(), new JwtVerifier());

        // RFC 7636 appendix-B test vector: a known verifier and its
        // expected challenge. If our implementation drifts from S256,
        // this fails immediately.
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $expectedChallenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

        $url = $provider->getAuthorizationUrl('state-1', 'nonce-1', 'https://example.test/cb', $verifier);

        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('https://www.facebook.com', parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST));
        $this->assertSame($expectedChallenge, $query['code_challenge'] ?? null);
        $this->assertSame('S256', $query['code_challenge_method'] ?? null);
        $this->assertSame('state-1', $query['state'] ?? null);
        $this->assertSame('nonce-1', $query['nonce'] ?? null);
        $this->assertSame('code', $query['response_type'] ?? null);
        $this->assertSame('openid email', $query['scope'] ?? null);
    }

    public function testAuthorizationUrlRequiresVerifier(): void
    {
        $provider = new FacebookProvider($this->makeSettings(), new JwtVerifier());

        $this->expectException(\LogicException::class);
        $provider->getAuthorizationUrl('state-1', 'nonce-1', 'https://example.test/cb', null);
    }

    public function testHandleCallbackReturnsIdentityOnHappyPath(): void
    {
        $provider = new FacebookProvider($this->makeSettings(), new JwtVerifier());

        $idToken = $this->mint([
            'iss'            => 'https://www.facebook.com',
            'aud'            => $this->clientId,
            'sub'            => 'fb-user-789',
            'email'          => 'Alice@Example.com',
            'email_verified' => true,
            'nonce'          => 'nonce-1',
            'iat'            => time(),
            'exp'            => time() + 3600,
        ]);

        $this->stubHttp($idToken);

        $identity = $provider->handleCallback('the-code', 'nonce-1', 'https://example.test/cb', 'verifier-xyz');

        $this->assertNotNull($identity);
        // Email is lowercased on the way out — case-folding belongs in
        // the provider, not the caller.
        $this->assertSame('alice@example.com', $identity->email);
        $this->assertSame('facebook', $identity->provider);
        $this->assertSame('fb-user-789', $identity->sub);
    }

    public function testHandleCallbackForwardsVerifierToTokenEndpoint(): void
    {
        $provider = new FacebookProvider($this->makeSettings(), new JwtVerifier());

        $idToken = $this->mint([
            'iss'            => 'https://www.facebook.com',
            'aud'            => $this->clientId,
            'sub'            => 'fb-user-789',
            'email'          => 'a@example.com',
            'email_verified' => true,
            'nonce'          => 'nonce-1',
            'iat'            => time(),
            'exp'            => time() + 3600,
        ]);

        $capturedUrl = null;
        $capturedBody = null;
        $this->stubHttp($idToken, $capturedUrl, $capturedBody);

        $provider->handleCallback('the-code', 'nonce-1', 'https://example.test/cb', 'verifier-xyz');

        // Facebook's token exchange is a POST with the parameters in
        // the request body (application/x-www-form-urlencoded), not on
        // the query string. code_verifier in particular must travel in
        // the body — that's what satisfies the PKCE challenge raised on
        // the authorise leg.
        $this->assertNotNull($capturedBody);
        $this->assertSame('verifier-xyz', $capturedBody['code_verifier'] ?? null);
        $this->assertSame('the-code', $capturedBody['code'] ?? null);
        $this->assertSame('https://example.test/cb', $capturedBody['redirect_uri'] ?? null);
        $this->assertSame($this->clientId, $capturedBody['client_id'] ?? null);
        $this->assertSame($this->clientSecret, $capturedBody['client_secret'] ?? null);
    }

    public function testHandleCallbackWithoutVerifierFails(): void
    {
        $provider = new FacebookProvider($this->makeSettings(), new JwtVerifier());

        // No HTTP stub installed: if the provider tried to call the
        // token endpoint, we'd see WP_Error noise. It shouldn't even
        // get that far — missing verifier is a hard fail before HTTP.
        $identity = $provider->handleCallback('the-code', 'nonce-1', 'https://example.test/cb', null);
        $this->assertNull($identity);
    }

    public function testHandleCallbackRejectsUnverifiedEmail(): void
    {
        $provider = new FacebookProvider($this->makeSettings(), new JwtVerifier());

        $idToken = $this->mint([
            'iss'            => 'https://www.facebook.com',
            'aud'            => $this->clientId,
            'sub'            => 'fb-user-789',
            'email'          => 'a@example.com',
            'email_verified' => false,
            'nonce'          => 'nonce-1',
            'iat'            => time(),
            'exp'            => time() + 3600,
        ]);

        $this->stubHttp($idToken);

        $identity = $provider->handleCallback('the-code', 'nonce-1', 'https://example.test/cb', 'verifier-xyz');
        $this->assertNull($identity);
    }

    public function testHandleCallbackRejectsWrongIssuer(): void
    {
        $provider = new FacebookProvider($this->makeSettings(), new JwtVerifier());

        $idToken = $this->mint([
            'iss'            => 'https://impostor.example',
            'aud'            => $this->clientId,
            'sub'            => 'fb-user-789',
            'email'          => 'a@example.com',
            'email_verified' => true,
            'nonce'          => 'nonce-1',
            'iat'            => time(),
            'exp'            => time() + 3600,
        ]);

        $this->stubHttp($idToken);

        $this->assertNull($provider->handleCallback('the-code', 'nonce-1', 'https://example.test/cb', 'verifier-xyz'));
    }

    public function testHandleCallbackRejectsWrongNonce(): void
    {
        $provider = new FacebookProvider($this->makeSettings(), new JwtVerifier());

        $idToken = $this->mint([
            'iss'            => 'https://www.facebook.com',
            'aud'            => $this->clientId,
            'sub'            => 'fb-user-789',
            'email'          => 'a@example.com',
            'email_verified' => true,
            'nonce'          => 'attacker-nonce',
            'iat'            => time(),
            'exp'            => time() + 3600,
        ]);

        $this->stubHttp($idToken);

        $this->assertNull($provider->handleCallback('the-code', 'nonce-1', 'https://example.test/cb', 'verifier-xyz'));
    }

    public function testHandleCallbackReturnsIdentityForRelayEmail(): void
    {
        // Facebook handed us a relay address. The provider's job is
        // to confirm the ID token is valid and the email claim is
        // verified; it must NOT decide policy on whether the address
        // is one Reach considers "real". That's the controller's job,
        // and pre-empting it here would break the typed-email flow.
        $provider = new FacebookProvider($this->makeSettings(), new JwtVerifier());

        $idToken = $this->mint([
            'iss'            => 'https://www.facebook.com',
            'aud'            => $this->clientId,
            'sub'            => 'fb-user-789',
            'email'          => 'abc@privaterelay.facebook.com',
            'email_verified' => true,
            'nonce'          => 'nonce-1',
            'iat'            => time(),
            'exp'            => time() + 3600,
        ]);

        $this->stubHttp($idToken);

        $identity = $provider->handleCallback('the-code', 'nonce-1', 'https://example.test/cb', 'verifier-xyz');

        $this->assertNotNull($identity);
        $this->assertSame('abc@privaterelay.facebook.com', $identity->email);
    }

    public function testVerifyIdTokenIsNotApplicable(): void
    {
        $provider = new FacebookProvider($this->makeSettings(), new JwtVerifier());
        $this->expectException(\LogicException::class);
        $provider->verifyIdToken('whatever', 'nonce');
    }

    public function testProviderMetadata(): void
    {
        $provider = new FacebookProvider($this->makeSettings(), new JwtVerifier());
        $this->assertSame('facebook', $provider->name());
        $this->assertTrue($provider->isServerSide());
    }

    /**
     * Install an HTTP stub that serves:
     *   - the JWKS at Facebook's well-known JWKS URL,
     *   - a token response containing $idToken at the token URL.
     *
     * $capturedUrl and $capturedBody are by-reference slots recording
     * the token URL and the POST body (the wp_remote_post `body` array)
     * we were called with, for tests that need to assert what we sent.
     *
     * @param array<string, mixed>|null $capturedBody
     */
    private function stubHttp(string $idToken, ?string &$capturedUrl = null, ?array &$capturedBody = null): void
    {
        $jwks = $this->jwks;
        $GLOBALS['__reach_http_stub'] = static function (string $url, array $args = []) use ($jwks, $idToken, &$capturedUrl, &$capturedBody) {
            if (str_starts_with($url, 'https://www.facebook.com/.well-known/oauth/openid/jwks/')) {
                return ['response' => ['code' => 200], 'body' => $jwks];
            }
            if (str_starts_with($url, 'https://graph.facebook.com/') && strpos($url, '/oauth/access_token') !== false) {
                $capturedUrl = $url;
                $capturedBody = is_array($args['body'] ?? null) ? $args['body'] : null;
                return [
                    'response' => ['code' => 200],
                    'body'     => json_encode([
                        'access_token' => 'irrelevant',
                        'token_type'   => 'bearer',
                        'id_token'     => $idToken,
                    ]),
                ];
            }
            return new \WP_Error('no_stub', 'No stub for ' . $url);
        };
    }

    private function makeSettings(): Settings
    {
        $settings = new Settings();
        $settings->setClientId('facebook', $this->clientId);
        $settings->setClientSecret('facebook', $this->clientSecret);
        return $settings;
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
