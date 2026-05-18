<?php

declare(strict_types=1);

namespace Reach\Auth;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A provider's confirmation that the caller controls the given email.
 *
 * Carries the provider's stable user id (`sub`) alongside the email so
 * a future change to how emails are spelt (Google sometimes returns
 * different casings) can still join back to the same person. The
 * provider string identifies which OAuth provider issued the proof.
 *
 * `providerEmail` is the email as the provider actually delivered it,
 * which only differs from `email` when the provider anonymised the
 * value (Facebook relay addresses; in the future possibly Apple
 * private relay too). It's kept on the identity so the controller
 * can detect anonymisation and route the user to type a real address,
 * and so the audit trail records what the provider actually sent.
 * Null for providers that didn't anonymise — the common case.
 */
final class VerifiedIdentity
{
    public function __construct(
        public readonly string $email,
        public readonly string $provider,
        public readonly string $sub,
        public readonly ?string $providerEmail = null,
    ) {
    }
}
