<?php

declare(strict_types=1);

namespace Reach\Auth\Providers;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Auth\JwtVerifier;
use Reach\Auth\VerifiedIdentity;
use Reach\Core\Settings;

/**
 * Microsoft sign-in via the Entra v2.0 endpoint, consumers tenant.
 *
 * The `consumers` tenant accepts only personal Microsoft accounts
 * (MSAs) — Outlook.com, Hotmail, Live, etc. — not work or school
 * accounts. The matching Entra registration is "Personal Microsoft
 * accounts only".
 *
 * Because the tenant is fixed, the `iss` claim is a single, well-known
 * constant: the MSA consumer tenant GUID. We pin the issuer rather than
 * parsing it from the token, which removes the per-tenant issuer dance
 * the common endpoint required. Pinning the issuer is what makes
 * matching a member by the token's email safe here — the address is one
 * Microsoft verified the user controls, which is not guaranteed on the
 * common endpoint.
 */
final class MicrosoftProvider implements OAuthProvider
{
    public const PROVIDER_NAME = 'microsoft';
    private const AUTH_URL = 'https://login.microsoftonline.com/consumers/oauth2/v2.0/authorize';
    private const TOKEN_URL = 'https://login.microsoftonline.com/consumers/oauth2/v2.0/token';
    private const JWKS_URL = 'https://login.microsoftonline.com/consumers/discovery/v2.0/keys';

    // The MSA consumer tenant — a fixed, well-known constant, not per-tenant.
    private const ISSUER = 'https://login.microsoftonline.com/9188040d-6c67-4c5b-b112-36a304b66dad/v2.0';

    public function __construct(
        private readonly Settings $settings,
        private readonly JwtVerifier $verifier,
    ) {
    }

    public function name(): string
    {
        return self::PROVIDER_NAME;
    }

    public function isServerSide(): bool
    {
        return true;
    }

    public function getAuthorizationUrl(string $state, string $nonce, string $redirectUri, ?string $codeVerifier = null): string
    {
        $params = [
            'client_id'             => $this->settings->getClientId(self::PROVIDER_NAME),
            'redirect_uri'          => $redirectUri,
            'response_type'         => 'code',
            'response_mode'         => 'query',
            'scope'                 => 'openid email profile',
            'state'                 => $state,
            'nonce'                 => $nonce,
            'prompt'                => 'select_account',
        ];
        return self::AUTH_URL . '?' . http_build_query($params);
    }

    public function handleCallback(string $code, string $nonce, string $redirectUri, ?string $codeVerifier = null): ?VerifiedIdentity
    {
        $tokens = $this->exchangeCode($code, $redirectUri);
        if ($tokens === null || empty($tokens['id_token']) || !is_string($tokens['id_token'])) {
            return null;
        }

        $idToken = $tokens['id_token'];
        $claims = $this->verifier->verify(
            $idToken,
            self::JWKS_URL,
            self::ISSUER,                                   // fixed, not parsed from the token
            $this->settings->getClientId(self::PROVIDER_NAME),
            $nonce
        );
        if ($claims === null) {
            return null;
        }

        // On a consumer token from the pinned issuer, email/preferred_username
        // reflects an address Microsoft verified the user controlled — so matching
        // a member by it is safe here in a way it is NOT on the common endpoint.
        $email = '';
        if (!empty($claims['email']) && is_string($claims['email'])) {
            $email = $claims['email'];
        } elseif (!empty($claims['preferred_username']) && is_string($claims['preferred_username']) && is_email($claims['preferred_username'])) {
            $email = $claims['preferred_username'];
        }
        if ($email === '') {
            return null;
        }

        return new VerifiedIdentity(strtolower($email), self::PROVIDER_NAME, (string) ($claims['sub'] ?? ''));
    }

    public function verifyIdToken(string $idToken, string $nonce): ?VerifiedIdentity
    {
        throw new \LogicException('Microsoft uses the server-side flow; verifyIdToken does not apply.');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function exchangeCode(string $code, string $redirectUri): ?array
    {
        $response = wp_remote_post(self::TOKEN_URL, [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/json'],
            'body'    => [
                'client_id'     => $this->settings->getClientId(self::PROVIDER_NAME),
                'client_secret' => $this->settings->getClientSecret(self::PROVIDER_NAME),
                'code'          => $code,
                'redirect_uri'  => $redirectUri,
                'grant_type'    => 'authorization_code',
                'scope'         => 'openid email profile',
            ],
        ]);
        if (is_wp_error($response)) {
            return null;
        }
        $httpCode = (int) wp_remote_retrieve_response_code($response);
        if ($httpCode < 200 || $httpCode >= 300) {
            return null;
        }
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        return is_array($decoded) ? $decoded : null;
    }
}
