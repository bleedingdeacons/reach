<?php

declare(strict_types=1);

namespace Reach\Auth\Providers;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Auth\Base64Url;
use Reach\Auth\JwtVerifier;
use Reach\Auth\VerifiedIdentity;
use Reach\Core\Settings;

/**
 * Microsoft sign-in via the Entra v2.0 endpoint, common tenant.
 *
 * The `common` tenant means any work, school, or personal Microsoft
 * account can sign in — which is what we want. A multi-tenant
 * registration in Entra (App registrations → Supported account types
 * → "Accounts in any organizational directory and personal Microsoft
 * accounts") is the matching configuration on the provider side.
 *
 * Issuer verification is tricky on the common tenant because the
 * actual `iss` claim is the *tenant-specific* issuer, not the common
 * one. We verify against the pattern documented by Microsoft rather
 * than a fixed string.
 */
final class MicrosoftProvider implements OAuthProvider
{
    use Base64Url;

    public const PROVIDER_NAME = 'microsoft';
    private const AUTH_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';
    private const TOKEN_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
    private const JWKS_URL = 'https://login.microsoftonline.com/common/discovery/v2.0/keys';

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
        $claims = $this->verifyMicrosoftToken($idToken, $nonce);
        if ($claims === null) {
            return null;
        }

        // Microsoft accounts: prefer `email`; fall back to `preferred_username`
        // which on personal MSAs *is* the email. Verify it looks like an email
        // before using it. Microsoft doesn't issue an email_verified claim —
        // the issuer is itself the verification (you can only sign in as an
        // address you control), so we don't enforce one here.
        $email = '';
        if (!empty($claims['email']) && is_string($claims['email'])) {
            $email = $claims['email'];
        } elseif (!empty($claims['preferred_username']) && is_string($claims['preferred_username'])) {
            $candidate = $claims['preferred_username'];
            if (is_email($candidate)) {
                $email = $candidate;
            }
        }
        if ($email === '') {
            return null;
        }

        return new VerifiedIdentity(
            strtolower($email),
            self::PROVIDER_NAME,
            (string) ($claims['sub'] ?? ''),
        );
    }

    public function verifyIdToken(string $idToken, string $nonce): ?VerifiedIdentity
    {
        throw new \LogicException('Microsoft uses the server-side flow; verifyIdToken does not apply.');
    }

    /**
     * Two-step verification: (1) parse to get the real tenant-specific
     * issuer, (2) re-verify with that issuer fixed. The verifier needs
     * to know the exact issuer to compare, and on the common endpoint
     * the issuer is per-tenant.
     *
     * @return array<string, mixed>|null
     */
    private function verifyMicrosoftToken(string $idToken, string $nonce): ?array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }
        $rawPayload = $this->base64UrlDecode($parts[1]);
        if ($rawPayload === '') {
            return null;
        }
        $preview = json_decode($rawPayload, true);
        if (!is_array($preview) || !isset($preview['iss']) || !is_string($preview['iss'])) {
            return null;
        }
        $issuer = $preview['iss'];

        // The expected issuer pattern for Microsoft Entra v2.0. The
        // tenant is an 8-4-4-4-12 GUID in lower-case hex — the loose
        // `[0-9a-f\-]+` from older versions also accepted strings like
        // "------" which obviously aren't tenants. Match the actual
        // shape Microsoft documents.
        if (!preg_match(
            '#^https://login\.microsoftonline\.com/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/v2\.0$#',
            $issuer
        )) {
            return null;
        }

        return $this->verifier->verify(
            $idToken,
            self::JWKS_URL,
            $issuer,
            $this->settings->getClientId(self::PROVIDER_NAME),
            $nonce
        );
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
