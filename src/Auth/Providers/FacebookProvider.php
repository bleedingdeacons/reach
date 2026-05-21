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
 * Facebook Login via the OpenID Connect authorisation-code flow with PKCE.
 *
 * Facebook is closer to Google than to Apple — it's a server-side
 * redirect, the token endpoint returns an `id_token`, and we verify
 * that token against Facebook's JWKS. The differences from Google are
 * worth flagging:
 *
 *   - Facebook *requires* PKCE on the web flow. Without a
 *     `code_challenge` on the authorise leg and a matching
 *     `code_verifier` on the token leg, the token endpoint returns
 *     "No code_verifier specified when a code challenge is provided".
 *     We still send the client secret too (Facebook is a confidential
 *     client from our side); PKCE is layered on top.
 *
 *   - Facebook's token endpoint historically accepted both GET and
 *     POST; we use POST so the client secret travels in the request
 *     body rather than the URL query string. Secrets in URLs leak
 *     into outbound proxy logs, server access logs, and tracing
 *     systems — POST avoids all of that with no behavioural change
 *     from Facebook's side.
 *
 *   - The authorise endpoint lives under `/v21.0/dialog/oauth` on
 *     www.facebook.com; the token endpoint under `/v21.0/oauth/...`
 *     on graph.facebook.com. The version segment is required.
 *     v21 is a long-lived API version; bumping it is a one-line
 *     change here when Facebook eventually deprecates it.
 *
 * Scopes are minimal — `openid email`, same as Google. We do not ask
 * for `public_profile`. The point of the flow is a verified email,
 * not a Facebook profile we have nowhere to store.
 *
 * Facebook does set `email_verified` on the ID token, and we enforce
 * it — an unverified email here defeats the entire purpose.
 */
final class FacebookProvider implements OAuthProvider
{
    public const PROVIDER_NAME = 'facebook';
    private const ISSUER = 'https://www.facebook.com';
    private const AUTH_URL = 'https://www.facebook.com/v21.0/dialog/oauth';
    private const TOKEN_URL = 'https://graph.facebook.com/v21.0/oauth/access_token';
    private const JWKS_URL = 'https://www.facebook.com/.well-known/oauth/openid/jwks/';

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
        if ($codeVerifier === null || $codeVerifier === '') {
            // Defensive: the controller is responsible for minting one
            // and stashing it in the StateStore. If we got here without
            // one, Facebook's token endpoint will reject the callback,
            // so fail fast rather than building a URL that can't work.
            throw new \LogicException('Facebook requires a PKCE code verifier.');
        }

        $challenge = $this->codeChallenge($codeVerifier);
        $params = [
            'client_id'             => $this->settings->getClientId(self::PROVIDER_NAME),
            'redirect_uri'          => $redirectUri,
            'response_type'         => 'code',
            'scope'                 => 'openid email',
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ];
        return self::AUTH_URL . '?' . http_build_query($params);
    }

    public function handleCallback(string $code, string $nonce, string $redirectUri, ?string $codeVerifier = null): ?VerifiedIdentity
    {
        if ($codeVerifier === null || $codeVerifier === '') {
            return null;
        }

        $tokens = $this->exchangeCode($code, $redirectUri, $codeVerifier);
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

        if (empty($claims['email']) || !is_string($claims['email'])) {
            return null;
        }
        // Facebook does populate email_verified on the ID token; if it
        // says anything other than true, refuse the sign-in.
        if (isset($claims['email_verified']) && $claims['email_verified'] !== true) {
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
        throw new \LogicException('Facebook uses the server-side flow; verifyIdToken does not apply.');
    }

    /**
     * Exchange the authorisation code for tokens.
     *
     * Uses POST with the credentials in the request body — Facebook
     * accepts both GET and POST on this endpoint, but POST keeps the
     * client secret out of URLs (and therefore out of any logging or
     * tracing layer that records request lines).
     *
     * @return array<string, mixed>|null
     */
    private function exchangeCode(string $code, string $redirectUri, string $codeVerifier): ?array
    {
        $response = wp_remote_post(self::TOKEN_URL, [
            'timeout' => 10,
            'headers' => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'client_id'     => $this->settings->getClientId(self::PROVIDER_NAME),
                'client_secret' => $this->settings->getClientSecret(self::PROVIDER_NAME),
                'redirect_uri'  => $redirectUri,
                'code'          => $code,
                'code_verifier' => $codeVerifier,
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

    /**
     * Base64url-encoded SHA-256 of the verifier — the S256 method from
     * RFC 7636. Trailing '=' padding is stripped, as required.
     */
    private function codeChallenge(string $verifier): string
    {
        $hash = hash('sha256', $verifier, true);
        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }
}
