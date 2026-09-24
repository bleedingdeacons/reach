<?php

declare(strict_types=1);

namespace Reach\Tests;

use Reach\CallAttempts\CallAttempt;
use Reach\CallAttempts\ResponsivenessScorer;

/**
 * Exercises the scoring policy directly. The rules are encoded as
 * private constants in the scorer, so these tests are the closest we
 * get to a written-down spec.
 */

const RESPONSIVENESS_SCORER_NOW = 1_700_000_000;

function callAttempt(int $memberId, string $email, string $outcome, int $at): CallAttempt
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

test('no attempts yields no badge', function () {
    $scorer = new ResponsivenessScorer();
    $this->assertSame(
        [1 => null, 2 => null],
        $scorer->scoreMany([1, 2], []),
    );
});

test('reached recently wins over everything else', function () {
    // Member has many no-answers and a wrong-number report — but a
    // single recent reach should override all of it. A successful
    // contact is the strongest signal.
    $attempts = [
        callAttempt(1, 'a@example.com', CallAttempt::OUTCOME_NO_ANSWER, RESPONSIVENESS_SCORER_NOW - 5000),
        callAttempt(1, 'b@example.com', CallAttempt::OUTCOME_NO_ANSWER, RESPONSIVENESS_SCORER_NOW - 4000),
        callAttempt(1, 'c@example.com', CallAttempt::OUTCOME_NO_ANSWER, RESPONSIVENESS_SCORER_NOW - 3000),
        callAttempt(1, 'd@example.com', CallAttempt::OUTCOME_WRONG_OR_BAD, RESPONSIVENESS_SCORER_NOW - 2000),
        callAttempt(1, 'e@example.com', CallAttempt::OUTCOME_WRONG_OR_BAD, RESPONSIVENESS_SCORER_NOW - 1500),
        callAttempt(1, 'f@example.com', CallAttempt::OUTCOME_REACHED, RESPONSIVENESS_SCORER_NOW - 1000),
    ];

    $scorer = new ResponsivenessScorer();
    $this->assertSame(
        [1 => ResponsivenessScorer::BADGE_REACHED],
        $scorer->scoreMany([1], $attempts),
    );
});

test('quiet requires multiple distinct viewers', function () {
    // Five no-answers from a single frustrated caller should NOT
    // trigger "quiet". Otherwise one user can stamp anyone as
    // unresponsive.
    $attempts = [];
    for ($i = 0; $i < 5; $i++) {
        $attempts[] = callAttempt(
            1,
            'alice@example.com',
            CallAttempt::OUTCOME_NO_ANSWER,
            RESPONSIVENESS_SCORER_NOW - ($i * 1000)
        );
    }
    $scorer = new ResponsivenessScorer();
    $this->assertSame(
        [1 => null],
        $scorer->scoreMany([1], $attempts),
        'Single viewer should never trigger the quiet badge',
    );
});

test('quiet requires minimum count', function () {
    // Two no-answers from two distinct viewers — below the count
    // threshold. Two no-answers in a fortnight isn't enough to
    // call someone unresponsive.
    $attempts = [
        callAttempt(1, 'a@example.com', CallAttempt::OUTCOME_NO_ANSWER, RESPONSIVENESS_SCORER_NOW - 2000),
        callAttempt(1, 'b@example.com', CallAttempt::OUTCOME_NO_ANSWER, RESPONSIVENESS_SCORER_NOW - 1000),
    ];
    $scorer = new ResponsivenessScorer();
    $this->assertSame(
        [1 => null],
        $scorer->scoreMany([1], $attempts),
    );
});

test('quiet triggers when both thresholds met', function () {
    // 3 no-answers, 2 distinct viewers, no successful reach.
    $attempts = [
        callAttempt(1, 'a@example.com', CallAttempt::OUTCOME_NO_ANSWER, RESPONSIVENESS_SCORER_NOW - 3000),
        callAttempt(1, 'b@example.com', CallAttempt::OUTCOME_NO_ANSWER, RESPONSIVENESS_SCORER_NOW - 2000),
        callAttempt(1, 'a@example.com', CallAttempt::OUTCOME_NO_ANSWER, RESPONSIVENESS_SCORER_NOW - 1000),
    ];
    $scorer = new ResponsivenessScorer();
    $this->assertSame(
        [1 => ResponsivenessScorer::BADGE_QUIET],
        $scorer->scoreMany([1], $attempts),
    );
});

test('bad number requires corroboration', function () {
    // One person reports a bad number → no badge. Could be a typo,
    // could be a wrong button tap.
    $attempts = [
        callAttempt(1, 'a@example.com', CallAttempt::OUTCOME_WRONG_OR_BAD, RESPONSIVENESS_SCORER_NOW - 1000),
    ];
    $scorer = new ResponsivenessScorer();
    $this->assertSame(
        [1 => null],
        $scorer->scoreMany([1], $attempts),
    );
});

test('bad number wins over quiet when both apply', function () {
    // Two distinct bad-number reports plus enough no-answers to
    // also be "quiet". The bad-number flag is more actionable, so
    // it should surface.
    $attempts = [
        callAttempt(1, 'a@example.com', CallAttempt::OUTCOME_WRONG_OR_BAD, RESPONSIVENESS_SCORER_NOW - 5000),
        callAttempt(1, 'b@example.com', CallAttempt::OUTCOME_WRONG_OR_BAD, RESPONSIVENESS_SCORER_NOW - 4000),
        callAttempt(1, 'c@example.com', CallAttempt::OUTCOME_NO_ANSWER, RESPONSIVENESS_SCORER_NOW - 3000),
        callAttempt(1, 'd@example.com', CallAttempt::OUTCOME_NO_ANSWER, RESPONSIVENESS_SCORER_NOW - 2000),
        callAttempt(1, 'e@example.com', CallAttempt::OUTCOME_NO_ANSWER, RESPONSIVENESS_SCORER_NOW - 1000),
    ];
    $scorer = new ResponsivenessScorer();
    $this->assertSame(
        [1 => ResponsivenessScorer::BADGE_BAD_NUMBER],
        $scorer->scoreMany([1], $attempts),
    );
});

test('viewers compared case insensitively', function () {
    // The same person signing in via two providers shouldn't count
    // as two distinct viewers — emails are normalised by case.
    $attempts = [
        callAttempt(1, 'Alice@Example.com', CallAttempt::OUTCOME_NO_ANSWER, RESPONSIVENESS_SCORER_NOW - 3000),
        callAttempt(1, 'alice@example.com', CallAttempt::OUTCOME_NO_ANSWER, RESPONSIVENESS_SCORER_NOW - 2000),
        callAttempt(1, 'ALICE@EXAMPLE.COM', CallAttempt::OUTCOME_NO_ANSWER, RESPONSIVENESS_SCORER_NOW - 1000),
    ];
    $scorer = new ResponsivenessScorer();
    $this->assertSame(
        [1 => null],
        $scorer->scoreMany([1], $attempts),
        'Same email across casings must be one viewer',
    );
});

test('scores members independently', function () {
    $attempts = [
        // member 1: reached
        callAttempt(1, 'a@example.com', CallAttempt::OUTCOME_REACHED, RESPONSIVENESS_SCORER_NOW - 1000),
        // member 2: quiet
        callAttempt(2, 'a@example.com', CallAttempt::OUTCOME_NO_ANSWER, RESPONSIVENESS_SCORER_NOW - 3000),
        callAttempt(2, 'b@example.com', CallAttempt::OUTCOME_NO_ANSWER, RESPONSIVENESS_SCORER_NOW - 2000),
        callAttempt(2, 'c@example.com', CallAttempt::OUTCOME_NO_ANSWER, RESPONSIVENESS_SCORER_NOW - 1000),
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
});
