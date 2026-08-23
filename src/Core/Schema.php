<?php

declare(strict_types=1);

namespace Reach\Core;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Alerts\WpdbAlertContactRepository;
use Reach\Alerts\WpdbAlertRepository;
use Reach\Auth\WpdbPasswordCredentialRepository;
use Reach\CallAttempts\WpdbCallAttemptRepository;
use Reach\CallRequests\WpdbCallRequestRepository;
use Reach\Devices\WpdbDeviceRepository;
use Reach\Logger\HasLogger;
use wpdb;

/**
 * Owns every table Reach creates, and makes sure they exist.
 *
 * <b>Why this is not just the activation hook.</b> It used to be. That
 * works exactly once — the first time a site activates the plugin — and
 * then quietly stops being true. A site that *updates* Reach never runs
 * `register_activation_hook`: WordPress fires it on activation, and an
 * update over the top of an active plugin is not an activation. Neither
 * is a `GitHub Plugin URI` auto-update, which is how these sites take
 * new versions.
 *
 * So a release that adds a table shipped a plugin whose code expected a
 * table that was never created. That is not hypothetical — the Hand
 * tables went out that way, and the failure was ugly: enrolment appeared
 * to succeed and returned a device token, because `$wpdb->insert()`
 * reports a missing table by returning false rather than raising, and
 * nothing checked. The handset then 401'd on its very next request and
 * bounced its responder back to the sign-in screen, with an empty device
 * list on the admin side and nothing anywhere saying why.
 *
 * The fix is the ordinary WordPress one: keep a schema version in an
 * option, compare it on load, and run dbDelta when it has moved.
 * dbDelta is idempotent and diffs against the live schema, so running it
 * again costs one query when nothing has changed.
 *
 * <b>Bump {@see VERSION} whenever a table is added or a column
 * changes.</b> Nothing detects that for you; an unbumped version means
 * the change reaches new installs and silently skips every existing one.
 */
final class Schema
{
    use HasLogger;

    /**
     * Schema version. Bump on any change to a CREATE TABLE above.
     *
     * 6 — added devices.lock_screen, so a handset that has been set to
     *     display alert text on its lock screen can say so. Not a fault
     *     the handset can fix on its own — it is its owner's setting —
     *     which is exactly why the intergroup needs to be able to see it.
     * 5 — added devices.key_fault_at, so a handset that cannot read its
     *     own alerts can say so and the admin list can show which one.
     * 4 — added devices.payload_key, the per-handset secret alert
     *     payloads will be encrypted to. Written at enrolment from this
     *     version on; nothing reads it yet.
     * 3 — added alerts.target_device_id, so an alert can be addressed to
     *     one handset rather than to a responder or to everyone.
     * 2 — added the Hand tables: devices, alerts, alert acknowledgements
     *     and alert contacts.
     * 1 — everything before schema versioning existed.
     */
    public const VERSION = 6;

    public const OPTION = 'reach_schema_version';

    protected static function logChannel(): string
    {
        return 'reach';
    }

    /**
     * Create or upgrade every table if the stored version is behind.
     *
     * Cheap on the common path: one option read and nothing else. Safe to
     * call on every request, and called from {@see \Reach\Plugin::init()}
     * so an updated site repairs itself on its next page load rather than
     * waiting for someone to think of reactivating the plugin.
     */
    public static function ensureInstalled(): void
    {
        $installed = (int) get_option(self::OPTION, 0);
        if ($installed >= self::VERSION) {
            return;
        }

        global $wpdb;

        // Guarded rather than assumed. This runs from Plugin::init(), and
        // a TypeError there would take the whole site down - a far worse
        // outcome than a schema install that waits for the next request.
        // In a real WordPress load $wpdb is always present; this is about
        // what happens when it is not.
        if (!$wpdb instanceof wpdb) {
            self::logWarning('Schema install skipped: $wpdb is not available yet');
            return;
        }

        self::install($wpdb);

        update_option(self::OPTION, self::VERSION, true);

        self::logInfo('Schema installed or upgraded', [
            'from' => $installed,
            'to'   => self::VERSION,
        ]);
    }

    /**
     * Record the schema as current without running the installers.
     *
     * For the activation hook, which has just called {@see install()}
     * directly. Without this the option would still say "behind" and the
     * next page load would run every dbDelta again — harmless, since they
     * are idempotent, but a pointless round of queries on the one request
     * that has certainly just done them.
     */
    public static function markInstalled(): void
    {
        update_option(self::OPTION, self::VERSION, true);
    }

    /**
     * Run every table's installer. Each is an idempotent dbDelta, so this
     * is safe to call repeatedly and is what the activation hook uses too.
     */
    public static function install(wpdb $wpdb): void
    {
        WpdbCallAttemptRepository::install($wpdb);
        WpdbCallRequestRepository::install($wpdb);
        WpdbPasswordCredentialRepository::install($wpdb);
        WpdbDeviceRepository::install($wpdb);
        WpdbAlertRepository::install($wpdb);
        WpdbAlertContactRepository::install($wpdb);
    }
}
