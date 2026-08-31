<?php

declare(strict_types=1);

namespace Reach\Alerts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One responder's reply to an alert.
 *
 * <b>Why a reply is a row and not merely another alert.</b> It is also
 * another alert — see {@see Alert::KIND_REPLY} — but that copy only
 * exists where there is a handset to send it to. An alert raised from
 * wp-admin has an administrator behind it, and an administrator has no
 * handset; one raised by a plugin has nobody behind it at all. Storing
 * the reply is what lets those be read where they were sent from, and
 * it is what survives the daily purge of the delivered copy.
 *
 * <b>It carries the same warning as the alert it answers.</b> The body
 * is typed by a responder and dispatched onward through FCM, so it
 * reaches a lock screen exactly as an alert's own text does. Callers'
 * names and numbers do not belong in it. {@see Alert} has the long
 * version of why.
 *
 * `responder` is the Unity anonymous name, denormalised deliberately:
 * the admin table reads it long after the fact, and a member record can
 * be deleted between the reply and somebody reading it. The address
 * beside it is the diagnostic, and never leaves wp-admin.
 */
final class AlertReply
{
    public function __construct(
        public readonly int $id,
        public readonly int $alertId,
        /**
         * The message the answered alert belonged to. Kept so replies to
         * a message delivered as several rows — a responder holding a
         * phone and a tablet — read as answers to one thing.
         */
        public readonly string $messageUuid,
        public readonly int $deviceId,
        public readonly string $memberEmail,
        public readonly string $responder,
        public readonly string $body,
        public readonly int $createdAt,
    ) {
    }
}
