<?php

declare(strict_types=1);

namespace Reach\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Error;

/**
 * Refuses a request that did not arrive over TLS.
 *
 * <b>What is actually at stake.</b> Every route that uses this either
 * hands a handset a credential or carries one. Enrolment answers with a
 * bearer token and the key alert payloads are encrypted to, both emitted
 * exactly once; the alert routes send that token up on every poll, which
 * on a duty handset is every few seconds for hours. Over plain HTTP any
 * of that is readable by anything between the phone and the server —
 * and a stolen device token is a working impersonation of a certified
 * responder until somebody notices and revokes it.
 *
 * <b>is_ssl(), and nothing cleverer.</b> A site behind a proxy that
 * terminates TLS sees `is_ssl()` answer false unless WordPress has been
 * told otherwise, and the fix for that is the standard one every host
 * documents: set `$_SERVER['HTTPS']` in `wp-config.php`. Reading
 * `X-Forwarded-Proto` here instead would make the guard bypassable by
 * anyone who can set a header, which is a strange trade for a check
 * whose entire job is to be unbypassable. {@see \Reach\Core\RateLimiter}
 * refuses to trust `X-Forwarded-For` for the same reason.
 *
 * <b>The escape hatch, and why it is a constant.</b> Local development
 * runs over http, and a check that cannot be turned off is a check
 * someone works around by deleting it. `REACH_ALLOW_INSECURE_TRANSPORT`
 * in `wp-config.php` allows plain HTTP; it exists to be set on a laptop
 * and nowhere else. It is deliberately not a setting on the admin
 * screen — an option that weakens transport security should take a
 * deliberate edit to a file, not a checkbox somebody ticks while trying
 * to make enrolment work.
 */
trait RequiresSecureTransport
{
    /**
     * Null when the request may proceed, or the refusal to return.
     *
     * Shaped as a nullable rather than a boolean so a caller reads as
     * `if (($error = $this->insecureTransport()) !== null) { return $error; }`
     * — one branch, and the message stays here with the reasoning.
     */
    private function insecureTransport(): ?WP_Error
    {
        if (is_ssl()) {
            return null;
        }

        if (defined('REACH_ALLOW_INSECURE_TRANSPORT') && REACH_ALLOW_INSECURE_TRANSPORT) {
            return null;
        }

        return new WP_Error(
            'reach_insecure_transport',
            'This endpoint requires HTTPS.',
            ['status' => 403],
        );
    }
}
