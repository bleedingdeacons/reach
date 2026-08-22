<?php

declare(strict_types=1);

namespace Reach\Core;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Role;

/**
 * Reach's own capabilities.
 *
 * <b>Why they exist at all.</b> The Hand devices screen used to gate
 * everything on {@see \Scrutiny\Privacy\PersonalDataPolicy::VIEW_CAPABILITY},
 * because the list names the responder each handset belongs to and that
 * is a personal-data read. Nothing else on that screen is a read.
 * Sending makes every enrolled handset on the rota ring, wherever those
 * phones are and whatever time it is; revoking cuts one off, possibly
 * mid-shift; removing erases its record. "May see an unmasked email
 * address" is not the same permission as any of those. Scrutiny's other
 * capabilities are no better a fit — they are about editing personal
 * data and changing a responder's certification — so these are Reach's
 * own.
 *
 * <b>Granted on load, not only on activation.</b> The same trap
 * {@see Schema} documents: WordPress fires the activation hook on
 * activation, and updating a plugin that is already active is not an
 * activation — neither is the GitHub Plugin URI auto-update these sites
 * take. A capability granted only at activation would therefore never
 * reach an existing install, and the release that introduced it would
 * silently take the send buttons away from every administrator until
 * somebody deactivated and reactivated the plugin. So
 * {@see ensureAssigned()} runs on every load, and is a no-op once the
 * role already has the capability.
 */
final class Capabilities
{
    /**
     * May raise an alert from the admin — the test alert and the
     * administrator's own message.
     */
    public const SEND_ALERTS = 'reach_send_alerts';

    /**
     * May revoke or remove an enrolled handset.
     *
     * Separate from {@see SEND_ALERTS} because they are different powers
     * over different things: one rings phones, the other takes a
     * responder off the rota. Someone trusted to tell the duty team the
     * line is down is not automatically someone who should be able to
     * cut a handset off mid-shift, and the reverse holds too.
     *
     * Both are separate from Scrutiny's view capability, which is what
     * these used to sit on. Revoking is not a personal-data read; the
     * screen happens to be gated on one because it names responders.
     */
    public const MANAGE_DEVICES = 'reach_manage_devices';

    /** Every capability this plugin defines. */
    public const ALL = [self::SEND_ALERTS, self::MANAGE_DEVICES];

    /**
     * Give administrators the capabilities they should have.
     *
     * Cheap enough for every request: has_cap() is an array lookup on a
     * role already in memory, and add_cap() — the only part that writes —
     * runs once, on the first load after an update.
     */
    public static function ensureAssigned(): void
    {
        $role = get_role('administrator');
        if (!$role instanceof WP_Role) {
            return;
        }

        foreach (self::ALL as $capability) {
            if (!$role->has_cap($capability)) {
                $role->add_cap($capability);
            }
        }
    }
}
