<?php

declare(strict_types=1);

namespace Reach\Devices;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * An enrolled Hand handset.
 *
 * A device is the pairing of one installation of the Hand app with one
 * certified telephone responder. It is what an alert is delivered *to*,
 * and what a poll or an acknowledgement is authenticated *as*.
 *
 * The bearer token Hand actually holds is never stored here — only its
 * SHA-256 hash (see {@see \Reach\Auth\DeviceTokenMinter}). A database
 * dump therefore yields no usable credentials, which matters more for a
 * device token than for the session cookie it replaces: the cookie
 * expires in 12 hours, this is long-lived by design so a handset on the
 * duty rota isn't signed out mid-shift.
 *
 * `memberEmail` is the identity the token was minted for. It is
 * re-resolved to a member and re-checked against the certification gate
 * on every authenticated request rather than trusted from this row, so
 * revoking someone's responder role or letting their certification lapse
 * stops their handset at the next call without anyone remembering to
 * revoke the device too.
 *
 * `pushToken` is the FCM registration token for the mobile heads, and
 * empty on the desktop heads — Windows and macOS have no FCM coverage
 * and pull instead. An empty push token is therefore normal, not a
 * fault: it means "this device collects its own alerts".
 */
final class Device
{
    /** Platforms a device may report. Anything else is refused at enrolment. */
    public const PLATFORMS = ['android', 'ios', 'maccatalyst', 'windows'];

    /** Push transports. The empty string means "pull only" — see the class docblock. */
    public const PUSH_NONE = '';
    public const PUSH_FCM = 'fcm';

    public function __construct(
        public readonly int $id,
        public readonly string $memberEmail,
        public readonly int $memberId,
        public readonly string $label,
        public readonly string $platform,
        public readonly string $pushProvider,
        public readonly string $pushToken,
        public readonly int $createdAt,
        public readonly int $lastSeenAt = 0,
        public readonly ?int $revokedAt = null,
        /**
         * When this handset last reported it could not read an alert, or
         * null if it never has.
         *
         * Reported by the handset rather than inferred: Reach can see
         * that a row has no key, but not that a handset has lost its own
         * copy. From here such a handset looks healthy right up until an
         * alert it cannot open.
         */
        public readonly ?int $keyFaultAt = null,
    ) {
    }

    /**
     * Whether this device has been revoked — by an admin from the
     * Devices page, or by the handset itself signing out.
     *
     * A revoked row is kept rather than deleted so the admin list can
     * still show that a handset was once enrolled and when it was cut
     * off. Nothing authenticates against it: the repository's lookup by
     * token hash refuses revoked rows outright.
     */
    /** Whether this handset has told us it cannot read its alerts. */
    public function hasKeyFault(): bool
    {
        return $this->keyFaultAt !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    /**
     * Whether an alert for this device should be pushed through FCM.
     *
     * Both halves are required: a device can claim the FCM transport but
     * have no token yet (the app enrols before Firebase hands one over),
     * and that combination must fall back to pulling rather than
     * producing a push to nowhere.
     */
    public function wantsPush(): bool
    {
        return $this->pushProvider === self::PUSH_FCM && $this->pushToken !== '';
    }

    /**
     * Normalise a claimed platform to one of {@see PLATFORMS}, or '' if
     * it is not one we recognise. Callers treat '' as a bad request —
     * the platform decides the delivery path, so guessing would mean
     * silently enrolling a handset that never receives anything.
     */
    public static function normalisePlatform(string $platform): string
    {
        $platform = strtolower(trim($platform));
        return in_array($platform, self::PLATFORMS, true) ? $platform : '';
    }
}
