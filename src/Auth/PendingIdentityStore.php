<?php

declare(strict_types=1);

namespace Reach\Auth;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stash a half-completed sign-in while the user types a real email.
 *
 * Used when Facebook returns an anonymised relay address: rather than
 * issuing a session immediately, we park the proven {provider, sub,
 * providerEmail} triple in a single-use transient and send the user
 * to `/reach/email` to type a real address. On submit, the controller
 * consumes the transient, validates the typed email, and *then*
 * issues a real session.
 *
 * Mechanics mirror StateStore — single-use, 10-minute TTL, opaque
 * random token as the key. It's a separate class only because the
 * payload and intent are different: StateStore is the CSRF anchor for
 * the outbound OAuth dance; this is the in-flight identity from a
 * successful OAuth dance that hasn't reached "session" yet.
 */
final class PendingIdentityStore
{
    private const PREFIX = 'reach_pending_id_';
    private const TTL_SECONDS = 600; // 10 minutes; user just needs to fill in one field.

    /**
     * Stash an identity and return the opaque token that addresses it.
     */
    public function issue(VerifiedIdentity $identity, string $returnTo): string
    {
        $token = bin2hex(random_bytes(24));
        set_transient(
            self::PREFIX . $token,
            [
                'email'          => $identity->email,
                'provider'       => $identity->provider,
                'sub'            => $identity->sub,
                'provider_email' => $identity->providerEmail,
                'return_to'      => $returnTo,
            ],
            self::TTL_SECONDS
        );
        return $token;
    }

    /**
     * Consume a token. Returns the stored identity and the original
     * return-to URL, or null if the token is unknown or expired.
     *
     * @return array{identity: VerifiedIdentity, return_to: string}|null
     */
    public function consume(string $token): ?array
    {
        $key = self::PREFIX . $token;
        $stored = get_transient($key);
        if (!is_array($stored)) {
            return null;
        }
        delete_transient($key);

        $email = (string) ($stored['email'] ?? '');
        $provider = (string) ($stored['provider'] ?? '');
        $sub = (string) ($stored['sub'] ?? '');
        $providerEmail = $stored['provider_email'] ?? null;
        if ($email === '' || $provider === '' || $sub === '') {
            return null;
        }

        return [
            'identity'  => new VerifiedIdentity(
                $email,
                $provider,
                $sub,
                is_string($providerEmail) ? $providerEmail : null,
            ),
            'return_to' => (string) ($stored['return_to'] ?? ''),
        ];
    }
}
