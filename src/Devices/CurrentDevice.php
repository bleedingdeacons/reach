<?php

declare(strict_types=1);

namespace Reach\Devices;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Auth\DeviceTokenMinter;
use Unity\Members\Interfaces\Member;
use WP_REST_Request;

/**
 * Resolves the handset behind an authenticated Hand request.
 *
 * The device-side counterpart to {@see \Reach\Session\CurrentSession}:
 * where that reads a browser's signed cookie, this reads an app's
 * bearer token. Every authenticated Hand endpoint goes through here, so
 * it is also where the two things that must happen on *every* call
 * happen — the eligibility re-check, and the last-seen stamp.
 *
 * <b>Why re-check eligibility on every call.</b> A device token is
 * long-lived by design; a handset on the duty rota should not be signed
 * out mid-shift. That makes the token a poor place to have frozen an
 * authorisation decision. Roles change: certification lapses, a
 * responder steps down, a member is removed. Resolving the member and
 * re-running {@see ResponderGate} on each request means those changes
 * take effect at the next call rather than whenever someone remembers
 * to revoke the handset. The cost is one member lookup per request,
 * which lands on WordPress's object cache.
 *
 * A device whose member no longer passes the gate is revoked outright
 * rather than merely refused. It cannot become valid again without a
 * fresh sign-in, and leaving a live row for an ineligible responder
 * would mean the dispatcher still counted them as a target.
 */
final class CurrentDevice
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly DeviceTokenMinter $minter,
        private readonly ResponderGate $gate,
    ) {
    }

    /**
     * The live, authorised device behind this request, or null.
     *
     * Null means "not authenticated" for every cause — no header, a
     * malformed token, an unknown or revoked device, or a device whose
     * responder is no longer eligible. Callers turn all of them into
     * the same 401.
     */
    public function fromRequest(WP_REST_Request $request, int $now): ?Device
    {
        $header = (string) $request->get_header('authorization');
        if ($header === '') {
            return null;
        }

        $token = $this->minter->bearerFrom($header);
        // Structural check first: it costs nothing and keeps rubbish
        // out of the database round trip below.
        if ($token === '' || !$this->minter->looksLikeToken($token)) {
            return null;
        }

        $device = $this->devices->findByTokenHash($this->minter->hash($token));
        if ($device === null) {
            return null;
        }

        if ($this->gate->authorisedMember($device->memberEmail) === null) {
            // See the class docblock: an ineligible responder's handset
            // is cut off, not just turned away.
            $this->devices->revoke($device->id, $now);
            return null;
        }

        $this->devices->touch($device->id, $now);

        return $device;
    }

    /**
     * The member a device belongs to, or null if they are no longer
     * eligible. Callers that have already been handed a Device by
     * {@see fromRequest()} know this is non-null; it is separate for
     * the paths that need the member itself rather than the handset.
     */
    public function memberFor(Device $device): ?Member
    {
        return $this->gate->authorisedMember($device->memberEmail);
    }
}
