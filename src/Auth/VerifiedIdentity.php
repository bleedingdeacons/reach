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
 */
final class VerifiedIdentity
{
    public function __construct(
        public readonly string $email,
        public readonly string $provider,
        public readonly string $sub,
    ) {
    }
}
