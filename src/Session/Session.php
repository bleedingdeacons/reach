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
 */
final class Session
{
    public function __construct(
        public readonly string $email,
        public readonly string $provider,
        public readonly string $sub,
        public readonly int $issuedAt,
        public readonly int $expiresAt,
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
        return [
            'email' => $this->email,
            'provider' => $this->provider,
            'sub' => $this->sub,
            'iat' => $this->issuedAt,
            'exp' => $this->expiresAt,
        ];
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
        return new self(
            (string) $data['email'],
            (string) $data['provider'],
            (string) $data['sub'],
            (int) $data['iat'],
            (int) $data['exp'],
        );
    }
}
