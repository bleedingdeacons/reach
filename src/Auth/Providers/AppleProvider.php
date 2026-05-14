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
 * Apple Sign In via the client-side flow.
 *
 * Apple's server-side flow is uniquely painful: the `client_secret` is
 * itself a JWT signed with a downloaded .p8 key that must be re-minted
 * every six months. The client-side flow (AppleID.auth.signIn() in
 * Apple's JS SDK) avoids all of that: the browser obtains an ID token
 * directly from Apple, POSTs it to our verify endpoint, and we
 * authenticate the user by checking the JWT signature, issuer,
 * audience, and nonce.
 *
 * The trade-off is that we never see Apple's refresh token or
 * authorization code, which we have no use for anyway — we only need
 * a proven email, once, at sign-in time.
 *
 * Apple's `email` claim is present on first sign-in but may be absent
 * on subsequent ones. That's not a problem for Reach: a session lasts
 * 12 hours and is re-issued from scratch each time, so the first-
 * sign-in token (which carries the email) is the only one we ever see.
 */
final class AppleProvider implements OAuthProvider
{
    public const PROVIDER_NAME = 'apple';
    private const ISSUER = 'https://appleid.apple.com';
    private const JWKS_URL = 'https://appleid.apple.com/auth/keys';

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
        return false;
    }

    public function getAuthorizationUrl(string $state, string $nonce, string $redirectUri): string
    {
        throw new \LogicException('Apple uses the client-side flow; getAuthorizationUrl does not apply.');
    }

    public function handleCallback(string $code, string $nonce, string $redirectUri): ?VerifiedIdentity
    {
        throw new \LogicException('Apple uses the client-side flow; handleCallback does not apply.');
    }

    public function verifyIdToken(string $idToken, string $nonce): ?VerifiedIdentity
    {
        $claims = $this->verifier->verify(
            $idToken,
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
        // Apple sets email_verified to true for emails it has verified
        // (which is every email it issues, including private relay).
        if (isset($claims['email_verified']) && $claims['email_verified'] !== true && $claims['email_verified'] !== 'true') {
            return null;
        }

        return new VerifiedIdentity(
            strtolower($claims['email']),
            self::PROVIDER_NAME,
            (string) ($claims['sub'] ?? ''),
        );
    }
}
