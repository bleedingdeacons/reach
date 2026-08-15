<?php

declare(strict_types=1);

namespace Reach\Auth;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single-use CSRF state + nonce store for OAuth flows.
 *
 * When the user clicks "Sign in with Google", we mint a random state
 * token and a random nonce, stash them in a transient keyed by state,
 * and forward both to the provider. When the provider redirects back,
 * we look up the transient by the state parameter — present means
 * "we did initiate this flow", absent means "drop the request".
 *
 * The nonce is round-tripped through the provider's ID token, which
 * binds the token to *this* sign-in attempt and prevents replay of a
 * previously issued ID token.
 *
 * For providers that require PKCE (Facebook), a `code_verifier` is
 * also stashed alongside the state. It's optional — providers that
 * don't use PKCE simply leave it null. The verifier never leaves the
 * server; only its S256 challenge travels through the redirect.
 *
 * A `device_redirect` may also be stashed. It marks the flow as having
 * been started by the Hand app rather than by a browser, and carries
 * the app URI the callback must bounce back to. It is null for every
 * web sign-in. The callback branches on it: a device flow ends in a
 * one-time exchange code sent to the app (see
 * {@see DeviceCodeStore}), a web flow ends in a session cookie. Storing
 * it here rather than accepting it on the callback is what makes it
 * trustworthy — the value was validated once when the flow began, and
 * the provider cannot influence what comes back out.
 *
 * Transients are used (not a custom table) because the state is
 * inherently short-lived — 10 minutes is plenty for a user to bounce
 * through the provider — and WordPress already has a working
 * transient cleanup story.
 */
final class StateStore
{
    private const PREFIX = 'reach_oauth_state_';
    private const TTL_SECONDS = 600; // 10 minutes

    /**
     * @return array{state: string, nonce: string, code_verifier: ?string}
     */
    public function issue(
        string $provider,
        string $returnTo,
        ?string $codeVerifier = null,
        ?string $deviceRedirect = null
    ): array {
        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        set_transient(
            self::PREFIX . $state,
            [
                'provider'        => $provider,
                'nonce'           => $nonce,
                'return_to'       => $returnTo,
                'code_verifier'   => $codeVerifier,
                'device_redirect' => $deviceRedirect,
            ],
            self::TTL_SECONDS
        );
        return [
            'state'         => $state,
            'nonce'         => $nonce,
            'code_verifier' => $codeVerifier,
        ];
    }

    /**
     * Consume a state. Returns the stored payload and deletes the
     * transient — single-use semantics, so replaying the callback URL
     * does not work.
     *
     * @return array{provider: string, nonce: string, return_to: string, code_verifier: ?string, device_redirect: ?string}|null
     */
    public function consume(string $state): ?array
    {
        $key = self::PREFIX . $state;
        $stored = get_transient($key);
        if (!is_array($stored)) {
            return null;
        }
        delete_transient($key);
        $verifier = $stored['code_verifier'] ?? null;
        // Absent on states issued before device flows existed, so read
        // defensively rather than assuming the key is there: a state in
        // flight across an upgrade must still complete as a web sign-in.
        $deviceRedirect = $stored['device_redirect'] ?? null;
        return [
            'provider'        => (string) ($stored['provider'] ?? ''),
            'nonce'           => (string) ($stored['nonce'] ?? ''),
            'return_to'       => (string) ($stored['return_to'] ?? ''),
            'code_verifier'   => is_string($verifier) ? $verifier : null,
            'device_redirect' => is_string($deviceRedirect) && $deviceRedirect !== '' ? $deviceRedirect : null,
        ];
    }
}
