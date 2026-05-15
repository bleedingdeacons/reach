<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\CallAttempts\CallAttempt;
use Reach\CallAttempts\ResponsivenessScorer;

/**
 * Exercises the scoring policy directly. The rules are encoded as
 * private constants in the scorer, so these tests are the closest we
 * get to a written-down spec.
 */
final class ResponsivenessScorerTest extends TestCase
{
    private const NOW = 1_700_000_000;

    public function testNoAttemptsYieldsNoBadge(): void
    {
        $scorer = new ResponsivenessScorer();
        $this->assertSame(
            [1 => null, 2 => null],
            $scorer->scoreMany([1, 2], []),
        );
    }

    public function testReachedRecentlyWinsOverEverythingElse(): void
    {
        // Member has many no-answers and a wrong-number report — but a
        // single recent reach should override all of it. A successful
        // contact is the strongest signal.
        $attempts = [
            $this->attempt(1, 'a@example.com', CallAttempt::OUTCOME_NO_ANSWER, self::NOW - 5000),
            $this->attempt(1, 'b@example.com', CallAttempt::OUTCOME_NO_ANSWER, self::NOW - 4000),
            $this->attempt(1, 'c@example.com', CallAttempt::OUTCOME_NO_ANSWER, self::NOW - 3000),
            $this->attempt(1, 'd@example.com', CallAttempt::OUTCOME_WRONG_OR_BAD, self::NOW - 2000),
            $this->attempt(1, 'e@example.com', CallAttempt::OUTCOME_WRONG_OR_BAD, self::NOW - 1500),
            $this->attempt(1, 'f@example.com', CallAttempt::OUTCOME_REACHED, self::NOW - 1000),
        ];

        $scorer = new ResponsivenessScorer();
        $this->assertSame(
            [1 => ResponsivenessScorer::BADGE_REACHED],
            $scorer->scoreMany([1], $attempts),
        );
    }

    public function testQuietRequiresMultipleDistinctViewers(): void
    {
        // Five no-answers from a single frustrated caller should NOT
        // trigger "quiet". Otherwise one user can stamp anyone as
        // unresponsive.
        $attempts = [];
        for ($i = 0; $i < 5; $i++) {
            $attempts[] = $this->attempt(
                1, 'alice@example.com', CallAttempt::OUTCOME_NO_ANSWER, self::NOW - ($i * 1000)
            );
        }
        $scorer = new ResponsivenessScorer();
        $this->assertSame(
            [1 => null],
            $scorer->scoreMany([1], $attempts),
            'Single viewer should never trigger the quiet badge',
        );
    }

    public function testQuietRequiresMinimumCount(): void
    {
        // Two no-answers from two distinct viewers — below the count
        // threshold. Two no-answers in a fortnight isn't enough to
        // call someone unresponsive.
        $attempts = [
            $this->attempt(1, 'a@example.com', CallAttempt::OUTCOME_NO_ANSWER, self::NOW - 2000),
            $this->attempt(1, 'b@example.com', CallAttempt::OUTCOME_NO_ANSWER, self::NOW - 1000),
        ];
        $scorer = new ResponsivenessScorer();
        $this->assertSame(
            [1 => null],
            $scorer->scoreMany([1], $attempts),
        );
    }

    public function testQuietTriggersWhenBothThresholdsMet(): void
    {
        // 3 no-answers, 2 distinct viewers, no successful reach.
        $attempts = [
            $this->attempt(1, 'a@example.com', CallAttempt::OUTCOME_NO_ANSWER, self::NOW - 3000),
            $this->attempt(1, 'b@example.com', CallAttempt::OUTCOME_NO_ANSWER, self::NOW - 2000),
            $this->attempt(1, 'a@example.com', CallAttempt::OUTCOME_NO_ANSWER, self::NOW - 1000),
        ];
        $scorer = new ResponsivenessScorer();
        $this->assertSame(
            [1 => ResponsivenessScorer::BADGE_QUIET],
            $scorer->scoreMany([1], $attempts),
        );
    }

    public function testBadNumberRequiresCorroboration(): void
    {
        // One person reports a bad number → no badge. Could be a typo,
        // could be a wrong button tap.
        $attempts = [
            $this->attempt(1, 'a@example.com', CallAttempt::OUTCOME_WRONG_OR_BAD, self::NOW - 1000),
        ];
        $scorer = new ResponsivenessScorer();
        $this->assertSame(
            [1 => null],
            $scorer->scoreMany([1], $attempts),
        );
    }

    public function testBadNumberWinsOverQuietWhenBothApply(): void
    {
        // Two distinct bad-number reports plus enough no-answers to
        // also be "quiet". The bad-number flag is more actionable, so
        // it should surface.
        $attempts = [
            $this->attempt(1, 'a@example.com', CallAttempt::OUTCOME_WRONG_OR_BAD, self::NOW - 5000),
            $this->attempt(1, 'b@example.com', CallAttempt::OUTCOME_WRONG_OR_BAD, self::NOW - 4000),
            $this->attempt(1, 'c@example.com', CallAttempt::OUTCOME_NO_ANSWER,    self::NOW - 3000),
            $this->attempt(1, 'd@example.com', CallAttempt::OUTCOME_NO_ANSWER,    self::NOW - 2000),
            $this->attempt(1, 'e@example.com', CallAttempt::OUTCOME_NO_ANSWER,    self::NOW - 1000),
        ];
        $scorer = new ResponsivenessScorer();
        $this->assertSame(
            [1 => ResponsivenessScorer::BADGE_BAD_NUMBER],
            $scorer->scoreMany([1], $attempts),
        );
    }

    public function testViewersComparedCaseInsensitively(): void
    {
        // The same person signing in via two providers shouldn't count
        // as two distinct viewers — emails are normalised by case.
        $attempts = [
            $this->attempt(1, 'Alice@Example.com', CallAttempt::OUTCOME_NO_ANSWER, self::NOW - 3000),
            $this->attempt(1, 'alice@example.com', CallAttempt::OUTCOME_NO_ANSWER, self::NOW - 2000),
            $this->attempt(1, 'ALICE@EXAMPLE.COM', CallAttempt::OUTCOME_NO_ANSWER, self::NOW - 1000),
        ];
        $scorer = new ResponsivenessScorer();
        $this->assertSame(
            [1 => null],
            $scorer->scoreMany([1], $attempts),
            'Same email across casings must be one viewer',
        );
    }

    public function testScoresMembersIndependently(): void
    {
        $attempts = [
            // member 1: reached
            $this->attempt(1, 'a@example.com', CallAttempt::OUTCOME_REACHED, self::NOW - 1000),
            // member 2: quiet
            $this->attempt(2, 'a@example.com', CallAttempt::OUTCOME_NO_ANSWER, self::NOW - 3000),
            $this->attempt(2, 'b@example.com', CallAttempt::OUTCOME_NO_ANSWER, self::NOW - 2000),
            $this->attempt(2, 'c@example.com', CallAttempt::OUTCOME_NO_ANSWER, self::NOW - 1000),
            // member 3: nothing in range, but member 4 ids are absent
            // → 3 should come back null.
        ];
        $scorer = new ResponsivenessScorer();
        $this->assertSame(
            [
                1 => ResponsivenessScorer::BADGE_REACHED,
                2 => ResponsivenessScorer::BADGE_QUIET,
                3 => null,
            ],
            $scorer->scoreMany([1, 2, 3], $attempts),
        );
    }

    private function attempt(int $memberId, string $email, string $outcome, int $at): CallAttempt
    {
        static $id = 0;
        return new CallAttempt(
            ++$id,
            $memberId,
            $email,
            'google',
            $outcome,
            null,
            $at,
        );
    }
}
