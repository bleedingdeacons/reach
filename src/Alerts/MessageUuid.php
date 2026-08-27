<?php

declare(strict_types=1);

namespace Reach\Alerts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The identifier one *message* is known by, as distinct from one alert.
 *
 * <b>An alert row is a delivery; a message is what somebody sent.</b>
 * Most of the time the two are the same thing — a broadcast is one row
 * addressed to everybody — but not always. An administrator messaging a
 * responder who holds a phone and a tablet raises two alerts on purpose,
 * so each handset carries its own acknowledgement and a silent one
 * cannot hide behind the other answering (see
 * {@see \Reach\Admin\SendMessagePage}). Those two rows are one message,
 * and nothing before this could say so.
 *
 * That is what the uuid is for. Every alert gets one; every alert raised
 * by the same send gets the *same* one. Downstream — Hand's alert list,
 * an acknowledgement notice naming who picked something up — that is the
 * only thing tying the copies together, because ids cannot: they are per
 * row, and the point is to talk about the thing above the row.
 *
 * Generated here rather than by `wp_generate_uuid4()` so the value does
 * not depend on a WordPress function being loaded — this is reached from
 * {@see AlertRequest}, which the unit tests exercise without a
 * WordPress. `random_bytes()` is the same source every other secret in
 * this plugin comes from.
 */
final class MessageUuid
{
    /** Length of the canonical 8-4-4-4-12 hyphenated form. */
    public const LENGTH = 36;

    /**
     * A fresh RFC 4122 version 4 uuid, lower case and hyphenated.
     */
    public static function generate(): string
    {
        $bytes = random_bytes(16);

        // Version 4 in the high nibble of byte 6, variant 10xx in the two
        // high bits of byte 8. Without these the value is 128 random bits
        // that merely look like a uuid, and would fail every validator
        // that checks — including {@see isValid()} below.
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Whether a caller-supplied value is a usable message id.
     *
     * Strict about the shape and the variant nibble, and deliberately
     * <b>not</b> about the version. This began as a version-4 check, on
     * the reasoning that a caller's uuid should be one we would have
     * generated — which had the failure mode exactly backwards. The
     * value here is an opaque grouping key and nothing reads meaning out
     * of it, so a caller minting version 1 or version 7 ids (as plenty
     * do) is not making a mistake. But {@see AlertRequest} replaces
     * anything this refuses rather than raising, so refusing those would
     * have quietly given every row of their message a *different* id —
     * splitting the group they were joining, silently, which is the one
     * outcome the check exists to prevent.
     *
     * So the version nibble accepts 1-8, which is every version RFC 9562
     * defines. What is still refused is a value that is not a uuid at
     * all: a truncated string, a bare hex blob, something with the
     * hyphens in the wrong places. Those are mistakes, and a fresh id is
     * the right answer to them.
     */
    public static function isValid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $value,
        ) === 1;
    }
}
