<?php

declare(strict_types=1);

namespace Reach\CallAttempts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Turns a member's recent call-attempt history into a coarse badge to
 * surface on the find page.
 *
 * Design notes
 * ------------
 * Why is this its own class? Because the rules are policy, not data
 * access. The repository fetches rows; the scorer decides what the
 * rows *mean*. Keeping the policy here lets us tune thresholds, add
 * new badges, or A/B them without touching SQL or the REST layer.
 *
 * Why are the badges coarse?
 * - "Reached recently" is a strong, fair signal: at least one person
 *   got through in the recent past.
 * - "No reply in last X tries" is a weak signal — a flat battery, a
 *   shift change, or being on holiday can explain a run of misses.
 *   We require multiple distinct viewers and no successful reach
 *   in the window before showing it, to avoid one frustrated caller
 *   tarring a member as unresponsive.
 * - Wrong-number reports are surfaced *only* when corroborated. One
 *   user mis-typing or hitting the wrong button shouldn't flag a
 *   member's number as bad.
 *
 * No badge is also a perfectly normal output — most members will have
 * no recent attempts at all, and we render them plainly.
 */
final class ResponsivenessScorer
{
    public const BADGE_REACHED   = 'reached_recently';
    public const BADGE_QUIET     = 'quiet';
    public const BADGE_BAD_NUMBER = 'bad_number_reported';

    /**
     * How far back to look. 14 days is long enough that a member who
     * checks their phone every few days will land in the "reached"
     * bucket if they pick up, but short enough that a member who has
     * since become responsive isn't permanently labelled "quiet".
     */
    public const LOOKBACK_SECONDS = 1209600; // 14 days

    /** Minimum no-answer attempts to trigger the "quiet" badge. */
    private const QUIET_MIN_NO_ANSWERS = 3;

    /** "Quiet" needs no-answers from at least this many distinct viewers. */
    private const QUIET_MIN_DISTINCT_VIEWERS = 2;

    /** Wrong-number reports needed from distinct viewers to flag it. */
    private const BAD_NUMBER_MIN_DISTINCT = 2;

    /**
     * Group attempts by member id and compute one badge per member.
     *
     * @param array<int, int> $memberIds  Members in the current result set.
     * @param array<int, CallAttempt> $attempts  Recent attempts for those members.
     * @return array<int, string|null>  Map of member_id → badge constant or null.
     */
    public function scoreMany(array $memberIds, array $attempts): array
    {
        $byMember = [];
        foreach ($attempts as $a) {
            $byMember[$a->memberId][] = $a;
        }

        $out = [];
        foreach ($memberIds as $id) {
            $out[$id] = $this->scoreOne($byMember[$id] ?? []);
        }
        return $out;
    }

    /**
     * Apply the badge rules to one member's attempts.
     *
     * Order matters:
     * 1. "Reached recently" wins outright — a successful contact is
     *    the strongest, freshest signal.
     * 2. Otherwise, if multiple viewers report a bad number, we flag
     *    it. This is rare but operationally critical.
     * 3. Otherwise, "quiet" if the no-answer evidence is solid.
     * 4. Otherwise, nothing.
     *
     * @param array<int, CallAttempt> $attempts
     */
    private function scoreOne(array $attempts): ?string
    {
        if ($attempts === []) {
            return null;
        }

        $reached    = [];
        $noAnswers  = [];
        $badNumbers = [];

        foreach ($attempts as $a) {
            switch ($a->outcome) {
                case CallAttempt::OUTCOME_REACHED:
                    $reached[] = $a;
                    break;
                case CallAttempt::OUTCOME_NO_ANSWER:
                    $noAnswers[] = $a;
                    break;
                case CallAttempt::OUTCOME_WRONG_OR_BAD:
                    $badNumbers[] = $a;
                    break;
            }
        }

        // Rule 1: any successful reach in the window wins.
        if ($reached !== []) {
            return self::BADGE_REACHED;
        }

        // Rule 2: bad-number flag requires corroboration.
        $distinctBadReporters = $this->distinctViewers($badNumbers);
        if ($distinctBadReporters >= self::BAD_NUMBER_MIN_DISTINCT) {
            return self::BADGE_BAD_NUMBER;
        }

        // Rule 3: quiet requires both a minimum *count* of no-answers
        // (so one user tapping no-answer three times in a row doesn't
        // trigger it — the repo collapses those anyway) and a minimum
        // number of *distinct* viewers (so it isn't one frustrated
        // caller's verdict).
        if (
            count($noAnswers) >= self::QUIET_MIN_NO_ANSWERS
            && $this->distinctViewers($noAnswers) >= self::QUIET_MIN_DISTINCT_VIEWERS
        ) {
            return self::BADGE_QUIET;
        }

        return null;
    }

    /**
     * @param array<int, CallAttempt> $attempts
     */
    private function distinctViewers(array $attempts): int
    {
        $seen = [];
        foreach ($attempts as $a) {
            $seen[strtolower($a->viewerEmail)] = true;
        }
        return count($seen);
    }
}
