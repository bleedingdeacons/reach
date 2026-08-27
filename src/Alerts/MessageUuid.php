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
     * Whether a caller-supplied value is a uuid we would have generated.
     *
     * Deliberately strict about the version and variant nibbles rather
     * than accepting anything hyphenated in the right places. A caller
     * passing its own uuid is joining alerts into one message, and the
     * cost of accepting a malformed one is two rows that look joined and
     * are not — which is worse than being told plainly to fix it.
     */
    public static function isValid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $value,
        ) === 1;
    }
}
