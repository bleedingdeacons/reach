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
 * Google sign-in via OpenID Connect authorisation-code flow.
 *
 * Scopes are deliberately minimal — `openid email` only. We do not
 * request `profile` or any API scope; the entire purpose of the
 * provider is to obtain a verified email address, and asking for
 * anything more would force users through a consent screen that
 * suggests Reach can read their data when it can't.
 *
 * The ID token returned by Google's token endpoint is the trust
 * anchor here. We verify its signature against Google's JWKS,
 * confirm the issuer, audience, and nonce, and only then trust the
 * `email` claim. The `email_verified` claim must also be true —
 * Google can return an unverified email in edge cases (e.g.
 * institutional accounts), and an unverified email defeats the
 * point of the whole exercise.
 *
 * The verified-email check defaults to *reject* when the claim is
 * absent rather than treating absence as "probably fine". OIDC
 * requires the claim, but a misconfigured or future provider could
 * omit it; failing closed costs us nothing on the happy path and
 * removes a category of "what if" worry.
 */
final class GoogleProvider implements OAuthProvider
{
    public const PROVIDER_NAME = 'google';
    private const ISSUER = 'https://accounts.google.com';
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

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
            'client_id'     => $this->settings->getClientId(self::PROVIDER_NAME),
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => 'openid email',
            'state'         => $state,
            'nonce'         => $nonce,
            'prompt'        => 'select_account',
        ];
        return self::AUTH_URL . '?' . http_build_query($params);
    }

    public function handleCallback(string $code, string $nonce, string $redirectUri, ?string $codeVerifier = null): ?VerifiedIdentity
    {
        $tokens = $this->exchangeCode($code, $redirectUri);
        if ($tokens === null || empty($tokens['id_token']) || !is_string($tokens['id_token'])) {
            return null;
        }

        $claims = $this->verifier->verify(
            $tokens['id_token'],
            self::JWKS_URL,
            self::ISSUER,
            $this->settings->getClientId(self::PROVIDER_NAME),
            $nonce
        );
        if ($claims === null) {
            return null;
        }

        // The whole point of this flow is a verified email.
        if (empty($claims['email']) || !is_string($claims['email'])) {
            return null;
        }
        // Reject when the claim is missing as well as when it's false.
        // OIDC requires `email_verified`; a token without it is
        // either non-compliant or doctored, and either way we don't
        // want to trust the address.
        if (($claims['email_verified'] ?? null) !== true) {
            return null;
        }

        return new VerifiedIdentity(
            strtolower($claims['email']),
            self::PROVIDER_NAME,
            (string) ($claims['sub'] ?? ''),
        );
    }

    public function verifyIdToken(string $idToken, string $nonce): ?VerifiedIdentity
    {
        throw new \LogicException('Google uses the server-side flow; verifyIdToken does not apply.');
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
                'code'          => $code,
                'client_id'     => $this->settings->getClientId(self::PROVIDER_NAME),
                'client_secret' => $this->settings->getClientSecret(self::PROVIDER_NAME),
                'redirect_uri'  => $redirectUri,
                'grant_type'    => 'authorization_code',
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
