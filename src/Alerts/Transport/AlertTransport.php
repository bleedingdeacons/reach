<?php

declare(strict_types=1);

namespace Reach\Alerts\Transport;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Alerts\Alert;
use Reach\Devices\Device;

/**
 * A way of getting an alert onto a handset.
 *
 * The seam that keeps {@see \Reach\Alerts\AlertDispatcher} — and with
 * it the public API other plugins call — independent of how delivery
 * actually happens. Today there is one implementation
 * ({@see FcmTransport}); WNS for the Windows head, or a direct APNs
 * connection if Firebase ever stops being worth the dependency, would
 * be another, and neither would change a line of the calling code.
 *
 * Transports are best-effort by contract. The alert is durably stored
 * before any transport is asked to carry it, and every handset polls as
 * well as listening, so a transport that fails delays an alert rather
 * than losing it. Implementations must therefore never throw: a failure
 * is a `false`, logged, and the next device in the list still gets its
 * turn.
 */
interface AlertTransport
{
    /**
     * Whether this transport is configured and can carry $device's
     * alerts. Checked before {@see deliver()} so the dispatcher can
     * count what it actually attempted.
     */
    public function supports(Device $device): bool;

    /**
     * Attempt delivery. True when the transport accepted the message —
     * which is not a guarantee it was displayed, only that it was
     * handed off successfully.
     */
    public function deliver(Alert $alert, Device $device): bool;
}
