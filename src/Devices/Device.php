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
        /**
         * What this handset says its lock screen does with alert text.
         *
         * One of {@see LOCK_SCREEN_UNKNOWN}, {@see LOCK_SCREEN_HIDDEN} or
         * {@see LOCK_SCREEN_SHOWN}. Reported by the handset, because it
         * is the only party that can see it: this is the phone owner's
         * own Android setting, not anything Reach or the app controls.
         */
        public readonly string $lockScreen = self::LOCK_SCREEN_UNKNOWN,
    ) {
    }

    /**
     * Never reported — a handset enrolled before this existed, one
     * running an older build, or one whose read of the setting failed.
     *
     * <b>Not the same as "safe".</b> Unknown is the default precisely
     * because the honest answer is that nobody has looked; presenting it
     * as reassurance would be the whole problem this column exists to
     * fix, restated.
     */
    public const LOCK_SCREEN_UNKNOWN = '';

    /** Sensitive content is hidden; a stranger sees the redacted line. */
    public const LOCK_SCREEN_HIDDEN = 'hidden';

    /**
     * The handset displays alert text on its lock screen.
     *
     * Hand asks Android to redact — it marks the notification private
     * and supplies a public version reading "Helpline alert / Unlock to
     * read" — but that substitution only happens when the phone's owner
     * has chosen "Hide sensitive content". Where they have chosen to
     * show everything, Android shows everything, and no app can override
     * it. So this is a fact about a handset that somebody has to decide
     * about, not a bug to be fixed in code.
     */
    public const LOCK_SCREEN_SHOWN = 'shown';

    /** The values a handset may report. Anything else is not stored. */
    public const LOCK_SCREEN_STATES = [
        self::LOCK_SCREEN_UNKNOWN,
        self::LOCK_SCREEN_HIDDEN,
        self::LOCK_SCREEN_SHOWN,
    ];

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

    /**
     * Whether this handset has told us it puts alert text on its lock
     * screen, where anyone standing near it can read it.
     *
     * False for a handset that has never said — see
     * {@see LOCK_SCREEN_UNKNOWN}. That is not a claim it is safe; it is
     * the absence of a claim either way, and the admin list shows the
     * two differently for that reason.
     */
    public function showsAlertsOnLockScreen(): bool
    {
        return $this->lockScreen === self::LOCK_SCREEN_SHOWN;
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
