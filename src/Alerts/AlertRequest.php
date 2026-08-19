<?php

declare(strict_types=1);

namespace Reach\Alerts;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Error;

/**
 * A validated request to raise an alert.
 *
 * This is the contract other plugins are actually held to. They call
 * {@see AlertApi::send()} (or `reach_send_alert()`) with a loose array;
 * this turns that into something the dispatcher can trust, or into a
 * WP_Error explaining what was wrong. Nothing reaches the database or a
 * handset without passing through here.
 *
 * <b>Everything is capped, and the caps are not negotiable.</b> A
 * title that overflows a lock screen is merely untidy; a body that
 * overflows the FCM payload limit is an alert that silently fails to
 * deliver for one handset and not another, which is the worst kind of
 * bug in a system whose entire job is to be reliable. So values are
 * truncated on the way in, at widths that fit both the columns and the
 * push payload, rather than being rejected — a slightly clipped alert
 * still rings the phone, and ringing the phone is the point.
 *
 * The one thing that *is* rejected is a missing kind or title: an
 * alert nobody can identify or read is not a degraded alert, it is
 * noise.
 *
 * <b>`target_device_id` is Reach's own, and other plugins should leave
 * it alone.</b> A device id is an internal row number that means
 * nothing outside this plugin, and a caller that guessed one would be
 * addressing a handset it cannot know the owner of. It exists for the
 * two things that are genuinely about a handset rather than a person —
 * the admin test alert and a removal notice — and is validated here
 * only so a stray value cannot become a negative id.
 *
 * <b>`contact` is the one field that may hold personal data, and it is
 * handled completely differently from the rest.</b> Everything else
 * here ends up in the alerts table, in the push payload, and on a lock
 * screen. The contact goes to none of those: it is encrypted into a
 * separate table (see {@see AlertContactRepository}) and handed only to
 * an authenticated responder who opens the alert, with a Scrutiny audit
 * entry for the read. Put the caller's name and number there and
 * nowhere else — a phone number in `title` or `body` is a phone number
 * on a lock screen and in Google's logs. {@see Alert} has the longer
 * version.
 */
final class AlertRequest
{
    /**
     * Widths chosen to fit the columns in
     * {@see WpdbAlertRepository::install()} and to leave the combined
     * push payload well inside FCM's 4KB limit.
     */
    private const KIND_MAX = 32;
    private const SOURCE_MAX = 64;
    private const TITLE_MAX = 200;
    private const BODY_MAX = 1000;
    private const REFERENCE_MAX = 64;
    private const PAYLOAD_MAX_BYTES = 2000;

    /**
     * Cap on the contact line. Matches the column in
     * {@see WpdbAlertContactRepository}.
     */
    private const CONTACT_MAX = 500;

    /** How long an unactioned alert stays live, and the outer bound. */
    public const DEFAULT_TTL_SECONDS = 3600;
    private const MAX_TTL_SECONDS = 86400;

    /**
     * @param array<string, string> $payload
     */
    private function __construct(
        public readonly string $kind,
        public readonly string $source,
        public readonly string $priority,
        public readonly string $title,
        public readonly string $body,
        public readonly string $reference,
        public readonly array $payload,
        public readonly string $targetEmail,
        public readonly int $targetDeviceId,
        public readonly int $ttlSeconds,
        public readonly string $contact,
    ) {
    }

    /**
     * Build a request from a caller's array, or explain the refusal.
     *
     * @param array<string, mixed> $args
     */
    public static function fromArray(array $args): self|WP_Error
    {
        $kind = self::text($args['kind'] ?? '', self::KIND_MAX);
        if ($kind === '') {
            return new WP_Error(
                'reach_alert_missing_kind',
                'An alert needs a "kind" identifying what it is.',
                ['status' => 400],
            );
        }

        // `subject` and `message` are the names a caller naturally
        // reaches for; `title` and `body` are what the wire and the
        // database have always called them. Both are accepted, with the
        // explicit wire names winning when a caller sends both, so an
        // existing integration cannot be changed underneath it.
        $title = self::text($args['title'] ?? ($args['subject'] ?? ''), self::TITLE_MAX);
        if ($title === '') {
            return new WP_Error(
                'reach_alert_missing_title',
                'An alert needs a "title" (or "subject") for the responder to read.',
                ['status' => 400],
            );
        }

        $targetEmail = strtolower(self::text($args['target_email'] ?? '', 254));
        if ($targetEmail !== '' && !is_email($targetEmail)) {
            return new WP_Error(
                'reach_alert_bad_target',
                'The "target_email" is not a valid address.',
                ['status' => 400],
            );
        }

        return new self(
            kind: $kind,
            source: self::text($args['source'] ?? 'unknown', self::SOURCE_MAX),
            priority: Alert::normalisePriority(self::text($args['priority'] ?? '', 16)),
            title: $title,
            body: self::text($args['body'] ?? ($args['message'] ?? ''), self::BODY_MAX),
            reference: self::text($args['reference'] ?? '', self::REFERENCE_MAX),
            payload: self::payload($args['payload'] ?? []),
            targetEmail: $targetEmail,
            targetDeviceId: self::deviceId($args['target_device_id'] ?? null),
            ttlSeconds: self::ttl($args['ttl'] ?? null),
            contact: self::text($args['contact'] ?? '', self::CONTACT_MAX),
        );
    }

    public function expiresAt(int $now): int
    {
        return $now + $this->ttlSeconds;
    }

    /**
     * Coerce a value to a trimmed, tag-free single-line string capped at
     * $max bytes without splitting a UTF-8 sequence.
     *
     * Non-strings become '' rather than being cast: a caller passing an
     * array where a title belongs has made a mistake, and "Array" on a
     * lock screen would hide it.
     */
    private static function text(mixed $value, int $max): string
    {
        if (!is_string($value)) {
            return '';
        }

        // wp_strip_all_tags rather than sanitize_text_field: this text is
        // rendered by a native app, not a browser, so entity-encoding it
        // would put visible &amp; sequences on a lock screen. Stripping
        // markup is the part that matters.
        $value = trim(wp_strip_all_tags($value));
        if (strlen($value) > $max) {
            $value = trim((string) mb_strcut($value, 0, $max, 'UTF-8'));
        }

        return $value;
    }

    /**
     * Normalise the structured extras to a flat string map.
     *
     * Flat and string-valued because that is what the push transports
     * can carry: FCM's data block is a string→string map, and anything
     * nested would have to be re-encoded per transport with a different
     * shape on each. Callers needing structure encode it themselves and
     * hand over the string.
     *
     * @return array<string, string>
     */
    private static function payload(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        $out = [];
        $bytes = 0;
        foreach ($payload as $key => $value) {
            if (!is_string($key) || is_array($value) || is_object($value)) {
                continue;
            }

            $key = self::text($key, 64);
            if ($key === '') {
                continue;
            }

            $stringValue = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $stringValue = self::text($stringValue, 256);

            // Stop at the budget rather than truncating the map's last
            // entry to a fragment — a half-written value is worse than an
            // absent one for a caller reading it back.
            $bytes += strlen($key) + strlen($stringValue);
            if ($bytes > self::PAYLOAD_MAX_BYTES) {
                break;
            }

            $out[$key] = $stringValue;
        }

        return $out;
    }

    /**
     * Coerce a device target to a usable row id, or 0 for "any handset".
     *
     * Anything that is not plainly a positive integer becomes 0 rather
     * than raising. Getting this wrong in the permissive direction would
     * be a broadcast where one handset was meant, so the coercion only
     * ever widens to the default and never invents an id.
     */
    private static function deviceId(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * Clamp a requested lifetime into the supported range, defaulting
     * when absent or unusable.
     */
    private static function ttl(mixed $ttl): int
    {
        if (!is_int($ttl) && !(is_string($ttl) && ctype_digit($ttl))) {
            return self::DEFAULT_TTL_SECONDS;
        }

        $seconds = (int) $ttl;
        if ($seconds < 60) {
            return self::DEFAULT_TTL_SECONDS;
        }

        return min($seconds, self::MAX_TTL_SECONDS);
    }
}
