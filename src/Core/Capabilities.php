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
 * <b>Why one exists at all.</b> The Hand devices screen used to gate
 * everything on {@see \Scrutiny\Privacy\PersonalDataPolicy::VIEW_CAPABILITY},
 * because the list names the responder each handset belongs to and that
 * is a personal-data read. Sending is not a read. Pressing a button here
 * makes every enrolled handset on the rota ring, wherever those phones
 * are and whatever time it is, and "may see an unmasked email address"
 * is not the same permission as that. Scrutiny's other capabilities are
 * no better a fit — they are about editing personal data and changing a
 * responder's certification — so this is Reach's own.
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
     *
     * Deliberately not a capability to revoke or remove a handset. Those
     * stay on Scrutiny's view capability where they have always been;
     * moving them is a separate decision about who administers the rota,
     * not part of separating "may read" from "may ring".
     */
    public const SEND_ALERTS = 'reach_send_alerts';

    /** Every capability this plugin defines. */
    public const ALL = [self::SEND_ALERTS];

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
