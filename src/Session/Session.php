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
 *
 * `id` names this particular sign-in. It is what
 * {@see SessionRevocationList} records when a session is signed out and
 * what {@see SessionCsrf} binds its token to — both of which need to
 * distinguish two sessions belonging to the same person, which nothing
 * else here does. It is random rather than derived so that neither use
 * leaks anything about the identity behind it. Empty for sessions
 * issued before ids existed; see {@see fromArray()}.
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
        public readonly string $id = '',
    ) {
    }

    /**
     * A fresh session id. 128 bits is past guessing, and the value is
     * never a secret in its own right — it identifies a session rather
     * than authenticating one, which the cookie's signature does.
     */
    public static function newId(): string
    {
        return bin2hex(random_bytes(16));
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
        if ($this->id !== '') {
            $out['sid'] = $this->id;
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
        // Absent on cookies issued before sessions had ids. Those stay
        // valid until they expire rather than being refused en masse by
        // an upgrade — they simply cannot be revoked individually, which
        // is bounded by the 12-hour TTL.
        $id = isset($data['sid']) && is_string($data['sid']) ? $data['sid'] : '';

        return new self(
            (string) $data['email'],
            (string) $data['provider'],
            (string) $data['sub'],
            (int) $data['iat'],
            (int) $data['exp'],
            $providerEmail,
            $id,
        );
    }
}
