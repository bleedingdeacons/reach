<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
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

const GOOGLE_ISS = 'https://accounts.google.com';

const GOOGLE_JWKS = 'https://www.googleapis.com/oauth2/v3/certs';

const GOOGLE_TOKEN = 'https://oauth2.googleapis.com/token';

const MS_ISS = 'https://login.microsoftonline.com/9188040d-6c67-4c5b-b112-36a304b66dad/v2.0';

const MS_JWKS = 'https://login.microsoftonline.com/consumers/discovery/v2.0/keys';

const MS_TOKEN = 'https://login.microsoftonline.com/consumers/oauth2/v2.0/token';

const APPLE_ISS = 'https://appleid.apple.com';

const APPLE_JWKS = 'https://appleid.apple.com/auth/keys';

beforeEach(function () {
    $this->privateKey = '';

    $this->jwks = '';

    $this->kid = 'oauth-test-kid';

    $this->clientId = 'test-client-id';

    // --- factories / helpers ---------------------------------------------
    $this->google = function (): GoogleProvider {
        return new GoogleProvider(($this->settings)(), new JwtVerifier());
    };

    $this->microsoft = function (): MicrosoftProvider {
        return new MicrosoftProvider(($this->settings)(), new JwtVerifier());
    };

    $this->apple = function (): AppleProvider {
        return new AppleProvider(($this->settings)(), new JwtVerifier());
    };

    $this->settings = function (): Settings {
        $s = new Settings();
        $s->setClientId('google', $this->clientId);
        $s->setClientId('microsoft', $this->clientId);
        $s->setClientId('apple', $this->clientId);
        $s->setClientSecret('google', 'secret');
        $s->setClientSecret('microsoft', 'secret');
        return $s;
    };

    /** Serve the JWKS at $jwksUrl and an id_token wrapper at $tokenUrl. */
    $this->stub = function (string $jwksUrl, string $tokenUrl, string $idToken): void {
        $jwks = $this->jwks;
        $this->stubHttp(static function (string $url, array $args = []) use ($jwks, $jwksUrl, $tokenUrl, $idToken) {
            if (str_starts_with($url, $jwksUrl)) {
                return ['response' => ['code' => 200], 'body' => $jwks];
            }
            if (str_starts_with($url, $tokenUrl)) {
                return ['response' => ['code' => 200], 'body' => (string) json_encode(['id_token' => $idToken])];
            }
            return new \WP_Error('no_stub', 'No stub for ' . $url);
        });
    };

    /** Serve only the JWKS — for the client-side Apple flow (no token leg). */
    $this->stubJwks = function (string $jwksUrl): void {
        $jwks = $this->jwks;
        $this->stubHttp(static function (string $url, array $args = []) use ($jwks, $jwksUrl) {
            if (str_starts_with($url, $jwksUrl)) {
                return ['response' => ['code' => 200], 'body' => $jwks];
            }
            return new \WP_Error('no_stub', 'No stub for ' . $url);
        });
    };

    /** @param array<string, mixed> $claims */
    $this->mint = function (array $claims): string {
        $header = ($this->b64url)((string) json_encode(['alg' => 'RS256', 'kid' => $this->kid, 'typ' => 'JWT']));
        $payload = ($this->b64url)((string) json_encode($claims));
        $signed = $header . '.' . $payload;
        openssl_sign($signed, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        return $signed . '.' . ($this->b64url)($signature);
    };

    $this->b64url = function (string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    };

    WpState::$transients = [];
    WpState::$options = [];

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
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'kid' => $this->kid,
            'n'   => ($this->b64url)($details['rsa']['n']),
            'e'   => ($this->b64url)($details['rsa']['e']),
        ]],
    ]);
});

afterEach(function () {
});

// --- Google -----------------------------------------------------------
test('google authorization url carries minimal scope and params', function () {
    $url = ($this->google)()->getAuthorizationUrl('st', 'no', 'https://example.test/cb');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

    $this->assertStringStartsWith('https://accounts.google.com/', $url);
    $this->assertSame('openid email', $q['scope'] ?? null, 'Google must request only openid+email');
    $this->assertSame('code', $q['response_type'] ?? null);
    $this->assertSame('st', $q['state'] ?? null);
    $this->assertSame('no', $q['nonce'] ?? null);
    $this->assertSame('select_account', $q['prompt'] ?? null);
});

test('google handle callback returns lowercased email identity', function () {
    ($this->stub)(GOOGLE_JWKS, GOOGLE_TOKEN, ($this->mint)([
        'iss' => GOOGLE_ISS, 'aud' => $this->clientId, 'sub' => 'g-1',
        'email' => 'Alice@Example.com', 'email_verified' => true, 'nonce' => 'no',
        'iat' => time(), 'exp' => time() + 3600,
    ]));

    $id = ($this->google)()->handleCallback('code', 'no', 'https://example.test/cb');

    $this->assertInstanceOf(VerifiedIdentity::class, $id);
    $this->assertSame('alice@example.com', $id->email);
    $this->assertSame('google', $id->provider);
    $this->assertSame('g-1', $id->sub);
});

test('google rejects unverified email', function () {
    ($this->stub)(GOOGLE_JWKS, GOOGLE_TOKEN, ($this->mint)([
        'iss' => GOOGLE_ISS, 'aud' => $this->clientId, 'sub' => 'g-1',
        'email' => 'a@example.com', 'email_verified' => false, 'nonce' => 'no',
        'iat' => time(), 'exp' => time() + 3600,
    ]));

    $this->assertNull(($this->google)()->handleCallback('code', 'no', 'https://example.test/cb'));
});

test('google rejects missing email verified claim', function () {
    // Claim absent entirely — must fail closed, not assume verified.
    ($this->stub)(GOOGLE_JWKS, GOOGLE_TOKEN, ($this->mint)([
        'iss' => GOOGLE_ISS, 'aud' => $this->clientId, 'sub' => 'g-1',
        'email' => 'a@example.com', 'nonce' => 'no',
        'iat' => time(), 'exp' => time() + 3600,
    ]));

    $this->assertNull(($this->google)()->handleCallback('code', 'no', 'https://example.test/cb'));
});

test('google returns null when token endpoint returns non2xx', function () {
    $this->stubHttp(static fn(string $url, array $args = [])
        => ['response' => ['code' => 400], 'body' => '{"error":"invalid_grant"}']);

    $this->assertNull(($this->google)()->handleCallback('bad-code', 'no', 'https://example.test/cb'));
});

test('google returns null when token response has no id token', function () {
    $this->stubHttp(static fn(string $url, array $args = [])
        => ['response' => ['code' => 200], 'body' => '{"access_token":"x"}']);

    $this->assertNull(($this->google)()->handleCallback('code', 'no', 'https://example.test/cb'));
});

test('google returns null on network error', function () {
    $this->stubHttp(static fn(string $url, array $args = [])
        => new \WP_Error('http_request_failed', 'boom'));

    $this->assertNull(($this->google)()->handleCallback('code', 'no', 'https://example.test/cb'));
});

test('google verify id token throws', function () {
    $this->expectException(\LogicException::class);
    ($this->google)()->verifyIdToken('tok', 'no');
});

test('google metadata', function () {
    $this->assertSame('google', ($this->google)()->name());
    $this->assertTrue(($this->google)()->isServerSide());
});

// --- Microsoft --------------------------------------------------------
test('microsoft authorization url requests profile scope and query mode', function () {
    $url = ($this->microsoft)()->getAuthorizationUrl('st', 'no', 'https://example.test/cb');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

    $this->assertStringStartsWith('https://login.microsoftonline.com/consumers/', $url);
    $this->assertSame('openid email profile', $q['scope'] ?? null);
    $this->assertSame('query', $q['response_mode'] ?? null);
});

test('microsoft handle callback uses email claim', function () {
    ($this->stub)(MS_JWKS, MS_TOKEN, ($this->mint)([
        'iss' => MS_ISS, 'aud' => $this->clientId, 'sub' => 'm-1',
        'email' => 'Bob@Outlook.com', 'nonce' => 'no',
        'iat' => time(), 'exp' => time() + 3600,
    ]));

    $id = ($this->microsoft)()->handleCallback('code', 'no', 'https://example.test/cb');
    $this->assertNotNull($id);
    $this->assertSame('bob@outlook.com', $id->email);
    $this->assertSame('microsoft', $id->provider);
});

test('microsoft falls back to preferred username when it is an email', function () {
    ($this->stub)(MS_JWKS, MS_TOKEN, ($this->mint)([
        'iss' => MS_ISS, 'aud' => $this->clientId, 'sub' => 'm-2',
        'preferred_username' => 'carol@live.com', 'nonce' => 'no',
        'iat' => time(), 'exp' => time() + 3600,
    ]));

    $id = ($this->microsoft)()->handleCallback('code', 'no', 'https://example.test/cb');
    $this->assertNotNull($id);
    $this->assertSame('carol@live.com', $id->email);
});

test('microsoft returns null when no usable email', function () {
    // No email, and preferred_username is not an email address.
    ($this->stub)(MS_JWKS, MS_TOKEN, ($this->mint)([
        'iss' => MS_ISS, 'aud' => $this->clientId, 'sub' => 'm-3',
        'preferred_username' => 'not-an-email', 'nonce' => 'no',
        'iat' => time(), 'exp' => time() + 3600,
    ]));

    $this->assertNull(($this->microsoft)()->handleCallback('code', 'no', 'https://example.test/cb'));
});

test('microsoft verify id token throws', function () {
    $this->expectException(\LogicException::class);
    ($this->microsoft)()->verifyIdToken('tok', 'no');
});

test('microsoft metadata', function () {
    $this->assertSame('microsoft', ($this->microsoft)()->name());
    $this->assertTrue(($this->microsoft)()->isServerSide());
});

// --- Apple ------------------------------------------------------------
test('apple is client side and rejects redirect flow methods', function () {
    $apple = ($this->apple)();
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
});

test('apple verify id token returns identity for verified email', function () {
    ($this->stubJwks)(APPLE_JWKS);
    $token = ($this->mint)([
        'iss' => APPLE_ISS, 'aud' => $this->clientId, 'sub' => 'a-1',
        'email' => 'Dave@icloud.com', 'email_verified' => true, 'nonce' => 'no',
        'iat' => time(), 'exp' => time() + 3600,
    ]);

    $id = ($this->apple)()->verifyIdToken($token, 'no');
    $this->assertNotNull($id);
    $this->assertSame('dave@icloud.com', $id->email);
    $this->assertSame('apple', $id->provider);
});

test('apple accepts string true email verified', function () {
    // Apple returns email_verified as the string "true" on some legs.
    ($this->stubJwks)(APPLE_JWKS);
    $token = ($this->mint)([
        'iss' => APPLE_ISS, 'aud' => $this->clientId, 'sub' => 'a-2',
        'email' => 'e@privaterelay.appleid.com', 'email_verified' => 'true', 'nonce' => 'no',
        'iat' => time(), 'exp' => time() + 3600,
    ]);

    $this->assertNotNull(($this->apple)()->verifyIdToken($token, 'no'));
});

test('apple rejects unverified email', function () {
    ($this->stubJwks)(APPLE_JWKS);
    $token = ($this->mint)([
        'iss' => APPLE_ISS, 'aud' => $this->clientId, 'sub' => 'a-3',
        'email' => 'e@icloud.com', 'email_verified' => 'false', 'nonce' => 'no',
        'iat' => time(), 'exp' => time() + 3600,
    ]);

    $this->assertNull(($this->apple)()->verifyIdToken($token, 'no'));
});

test('apple rejects wrong issuer', function () {
    ($this->stubJwks)(APPLE_JWKS);
    $token = ($this->mint)([
        'iss' => 'https://impostor.example', 'aud' => $this->clientId, 'sub' => 'a-4',
        'email' => 'e@icloud.com', 'email_verified' => true, 'nonce' => 'no',
        'iat' => time(), 'exp' => time() + 3600,
    ]);

    $this->assertNull(($this->apple)()->verifyIdToken($token, 'no'));
});

test('apple rejects missing email', function () {
    ($this->stubJwks)(APPLE_JWKS);
    $token = ($this->mint)([
        'iss' => APPLE_ISS, 'aud' => $this->clientId, 'sub' => 'a-5',
        'email_verified' => true, 'nonce' => 'no',
        'iat' => time(), 'exp' => time() + 3600,
    ]);

    $this->assertNull(($this->apple)()->verifyIdToken($token, 'no'));
});
