<?php

declare(strict_types=1);

namespace Reach\CallAttempts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One attempt by a Reach visitor to contact a member, with how it went.
 *
 * Stored verbatim in wp_reach_call_attempts. Read by the scorer to
 * decide what (if anything) to show alongside a member in the find
 * results — it's a behavioural signal, not an authoritative record
 * of the member's availability. The member never sees these directly.
 *
 * Outcome vocabulary is deliberately small: three values that are
 * meaningful to *the next caller*. A free-text note exists for the
 * caller's own context but is never surfaced to other users.
 */
final class CallAttempt
{
    public const OUTCOME_REACHED        = 'reached';
    public const OUTCOME_NO_ANSWER      = 'no_answer';
    public const OUTCOME_WRONG_OR_BAD   = 'wrong_or_bad_number';

    public const OUTCOMES = [
        self::OUTCOME_REACHED,
        self::OUTCOME_NO_ANSWER,
        self::OUTCOME_WRONG_OR_BAD,
    ];

    public function __construct(
        public readonly int $id,
        public readonly int $memberId,
        public readonly string $viewerEmail,
        public readonly string $viewerProvider,
        public readonly string $outcome,
        public readonly ?string $note,
        public readonly int $createdAt,
    ) {
    }

    public static function isValidOutcome(string $outcome): bool
    {
        return in_array($outcome, self::OUTCOMES, true);
    }
}
