<?php

declare(strict_types=1);

namespace Reach\Auth\Providers;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Auth\VerifiedIdentity;

/**
 * One implementation per OAuth provider Reach supports.
 *
 * Providers come in two shapes:
 *
 *   - Server-side providers (Google, Microsoft) implement the full
 *     authorisation-code flow: getAuthorizationUrl() builds the URL
 *     the browser bounces to, handleCallback() exchanges the code for
 *     tokens and verifies the result.
 *
 *   - Client-side providers (Apple) bypass the redirect altogether;
 *     the browser obtains an ID token via the provider's JS SDK and
 *     POSTs it to our verify endpoint. For these, getAuthorizationUrl
 *     throws — they aren't part of the redirect flow.
 *
 * Either way the surface area is the same: at some point the provider
 * yields a {@see VerifiedIdentity} or it doesn't.
 */
interface OAuthProvider
{
    public function name(): string;

    /**
     * Whether this provider uses the server-side redirect flow.
     */
    public function isServerSide(): bool;

    /**
     * Build the URL the browser should be sent to. Server-side flow only.
     *
     * `$codeVerifier` is the PKCE verifier minted by the controller for
     * this sign-in attempt. Providers that require PKCE (e.g. Facebook)
     * derive an S256 challenge from it and include the challenge on the
     * authorisation URL. Providers that don't use PKCE simply ignore it.
     *
     * @throws \LogicException If called on a client-side provider.
     */
    public function getAuthorizationUrl(string $state, string $nonce, string $redirectUri, ?string $codeVerifier = null): string;

    /**
     * Exchange the `code` query parameter for tokens, verify the ID token,
     * and return the proven identity. Server-side flow only.
     *
     * `$codeVerifier` is the original PKCE verifier — providers that
     * required a `code_challenge` on the authorise leg must present the
     * matching verifier on the token leg. Ignored by providers that
     * don't use PKCE.
     */
    public function handleCallback(string $code, string $nonce, string $redirectUri, ?string $codeVerifier = null): ?VerifiedIdentity;

    /**
     * Verify an ID token submitted by the browser. Client-side flow only.
     */
    public function verifyIdToken(string $idToken, string $nonce): ?VerifiedIdentity;
}
