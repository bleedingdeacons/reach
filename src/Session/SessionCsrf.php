<?php

declare(strict_types=1);

namespace Reach\Session;

if (!defined('ABSPATH')) {
    exit;
}

use WP_REST_Request;

use function wp_salt;

/**
 * The anti-CSRF token for Reach's cookie-authenticated writes.
 *
 * <b>Why WordPress's own nonce is not what goes here.</b> Reach
 * visitors have no WordPress account, so `rest_cookie_check_errors()`
 * — the check that makes `X-WP-Nonce` mandatory for cookie-authenticated
 * REST calls — never fires for them, and `wp_create_nonce()` would tie
 * the token to user id 0, which is the same value for every visitor and
 * therefore no protection at all. The find page's own comment records
 * the second reason: WP nonces carry a ~12-hour tick of their own, and a
 * tab left open across it turns a still-valid session into a confusing
 * "Cookie check failed."
 *
 * So the token is derived from the session it belongs to. It changes
 * when the session does, it cannot be computed without the site's salt,
 * and it expires exactly when the session expires — no second clock.
 *
 * <b>What it defends.</b> The session cookie is `SameSite=Lax`, which
 * already stops a cross-site form POST from carrying it in a current
 * browser. That is one control, in one place, that the plugin does not
 * own: it is defeated by an older browser, by Chrome's two-minute
 * "Lax + POST" grace period on a freshly-set cookie, and by anything
 * hosted on a sibling subdomain, which is same-site as far as the
 * attribute is concerned. This token is the control Reach does own, and
 * it is checked on every state-changing endpoint that authenticates by
 * cookie — logging a call attempt, raising a call request (which mails
 * caller-supplied text to the intergroup), and signing out.
 *
 * Device-token endpoints need none of this: a bearer token in an
 * `Authorization` header is not attached by the browser automatically,
 * so there is nothing to forge.
 */
final class SessionCsrf
{
    /** The header a client presents the token in. */
    public const HEADER = 'X-Reach-Token';

    /**
     * The token for a session.
     *
     * Bound to the session id where there is one, and to the identity
     * and issue time otherwise, so a token minted for one session is
     * never accepted for another. Sessions predating ids fall back
     * rather than being locked out mid-shift by an upgrade.
     */
    public function mint(Session $session): string
    {
        return hash_hmac('sha256', $this->binding($session), wp_salt('nonce'));
    }

    /**
     * Whether this request carries the right token for this session.
     *
     * Compared with {@see hash_equals()} — the values are equal-length
     * hex digests, so a byte-by-byte comparison would leak the position
     * of the first difference.
     */
    public function verify(WP_REST_Request $request, Session $session): bool
    {
        $presented = trim((string) $request->get_header(self::HEADER));
        if ($presented === '') {
            return false;
        }

        return hash_equals($this->mint($session), $presented);
    }

    private function binding(Session $session): string
    {
        if ($session->id !== '') {
            return 'sid|' . $session->id;
        }

        return 'legacy|' . $session->email . '|' . $session->issuedAt . '|' . $session->expiresAt;
    }
}
