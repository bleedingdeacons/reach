<?php

declare(strict_types=1);

namespace Reach\Session;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Immutable proof-of-email session.
 *
 * A Session represents "this browser proved control of this email
 * address via this provider at this time". It carries no privileges
 * beyond that — capability checks happen separately at the REST layer.
 *
 * Fields are minimal on purpose: anything else (name, picture, locale)
 * would be personal data we don't need and shouldn't ship around in a
 * cookie. We stash the provider's stable user id (`sub`) only to give
 * future audit/forensics a key, not for any application logic.
 *
 * `providerEmail` records what the OAuth provider actually delivered,
 * which only differs from `email` when the provider anonymised the
 * value (Facebook relay) and the user supplied a real address. It's
 * carried so the audit trail can answer "Facebook said this was X,
 * but the user told us they're reachable at Y". Null for the common
 * case where the provider gave us a real email.
 */
final class Session
{
    public function __construct(
        public readonly string $email,
        public readonly string $provider,
        public readonly string $sub,
        public readonly int $issuedAt,
        public readonly int $expiresAt,
        public readonly ?string $providerEmail = null,
    ) {
    }

    public function isExpired(int $now): bool
    {
        return $now >= $this->expiresAt;
    }

    /**
     * @return array<string, scalar>
     */
    public function toArray(): array
    {
        $out = [
            'email' => $this->email,
            'provider' => $this->provider,
            'sub' => $this->sub,
            'iat' => $this->issuedAt,
            'exp' => $this->expiresAt,
        ];
        // Emit only when populated — keeps old cookies byte-identical and
        // means sessions from before this field existed remain valid.
        if ($this->providerEmail !== null) {
            $out['pem'] = $this->providerEmail;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $required = ['email', 'provider', 'sub', 'iat', 'exp'];
        foreach ($required as $k) {
            if (!isset($data[$k])) {
                return null;
            }
        }
        $providerEmail = null;
        if (isset($data['pem']) && is_string($data['pem']) && $data['pem'] !== '') {
            $providerEmail = $data['pem'];
        }
        return new self(
            (string) $data['email'],
            (string) $data['provider'],
            (string) $data['sub'],
            (int) $data['iat'],
            (int) $data['exp'],
            $providerEmail,
        );
    }
}
