<?php

declare(strict_types=1);

namespace Reach\Auth;

if (!defined('ABSPATH')) {
    exit;
}

use Guardian\State\StateStore as GuardianStateStore;

/**
 * Reach's view of the single-use OAuth state: what Guardian's
 * {@see GuardianStateStore} stores, plus the two things Reach needs back
 * after the provider returns.
 *
 * The state, nonce and PKCE verifier — and their single-use semantics — are
 * Guardian's. What this adds:
 *
 * - `return_to`, where a web sign-in lands afterwards.
 * - `device_redirect`, set only when the Hand app started the flow, carrying
 *   the app URI the callback must bounce back to. The callback branches on
 *   it: a device flow ends in a one-time exchange code sent to the app (see
 *   {@see DeviceCodeStore}), a web flow ends in a session cookie. Storing it
 *   here rather than accepting it on the callback is what makes it
 *   trustworthy — the value was validated once when the flow began, and the
 *   provider cannot influence what comes back out.
 *
 * The key prefix is the one this class always used, and Guardian stores the
 * two fields flat beside its own, exactly as this class used to. So a
 * sign-in in flight across the upgrade still completes.
 */
final class StateStore
{
    private const PREFIX = 'reach_oauth_state_';

    private readonly GuardianStateStore $store;

    public function __construct(?GuardianStateStore $store = null)
    {
        $this->store = $store ?? new GuardianStateStore(self::PREFIX);
    }

    /**
     * @return array{state: string, nonce: string, code_verifier: ?string}
     */
    public function issue(
        string $provider,
        string $returnTo,
        ?string $codeVerifier = null,
        ?string $deviceRedirect = null
    ): array {
        return $this->store->issue($provider, $codeVerifier, [
            'return_to'       => $returnTo,
            'device_redirect' => $deviceRedirect,
        ]);
    }

    /**
     * Consume a state. Returns the stored payload and deletes it —
     * single-use, so replaying the callback URL does not work.
     *
     * @return array{provider: string, nonce: string, return_to: string, code_verifier: ?string, device_redirect: ?string}|null
     */
    public function consume(string $state): ?array
    {
        $stored = $this->store->consume($state);
        if ($stored === null) {
            return null;
        }

        $returnTo = $stored['extra']['return_to'] ?? '';
        // Absent on states issued before device flows existed, so read
        // defensively: a state in flight across an upgrade must still
        // complete as a web sign-in.
        $deviceRedirect = $stored['extra']['device_redirect'] ?? null;

        return [
            'provider'        => $stored['provider'],
            'nonce'           => $stored['nonce'],
            'return_to'       => is_string($returnTo) ? $returnTo : '',
            'code_verifier'   => $stored['code_verifier'],
            'device_redirect' => is_string($deviceRedirect) && $deviceRedirect !== '' ? $deviceRedirect : null,
        ];
    }
}
