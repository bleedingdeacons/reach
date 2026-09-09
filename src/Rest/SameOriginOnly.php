<?php

declare(strict_types=1);

namespace Reach\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use WP_REST_Request;

/**
 * Whether a request came from this site's own pages.
 *
 * <b>The hole this closes.</b> GET /reach/v1/session returns the session's
 * {@see \Reach\Session\SessionCsrf} token, with a comment reasoning that "a
 * cross-site caller cannot read the response". That is true of a genuinely
 * cross-*site* caller: the session cookie is SameSite=Lax, so it is not
 * attached and the endpoint answers `authenticated: false`.
 *
 * It is not true of a sibling subdomain. Lax treats anything under the same
 * registrable domain as same-site, so the cookie *is* attached — and
 * WordPress core's rest_send_cors_headers() reflects whatever `Origin` it is
 * given back with `Access-Control-Allow-Credentials: true`, so the browser
 * lets that caller read the body. With one host under the registrable
 * domain, an attacker could read the token and forge every cookie-
 * authenticated write in the plugin.
 *
 * This is the residual risk SessionCsrf's own docblock already names. It is
 * closed here rather than left as a note because Reach's pages are
 * same-origin by design, so the check costs nothing that is actually used.
 *
 * <b>Why an absent Origin is allowed.</b> Browsers omit the header on
 * ordinary same-origin GETs, and non-browser callers (curl, the Hand and
 * Link handsets, monitoring) send no Origin either. Refusing on absence
 * would break all of them while stopping nothing: the attack requires a
 * browser, and a browser attaching a cross-origin credential always sends
 * the header. Absence is therefore not the case to defend against.
 */
final class SameOriginOnly
{
    /**
     * True when the request carries no Origin, or one matching this site.
     */
    public static function allows(WP_REST_Request $request): bool
    {
        $origin = trim((string) $request->get_header('Origin'));

        if ($origin === '' || strtolower($origin) === 'null') {
            return true;
        }

        return self::normalise($origin) === self::normalise((string) home_url());
    }

    /**
     * Scheme + host + explicit non-default port, lowercased.
     *
     * Compared as parts rather than as strings so a trailing slash, a
     * differently-cased host or an explicit :443 cannot make this site look
     * like a stranger to itself.
     */
    private static function normalise(string $url): string
    {
        $parts = parse_url($url);

        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host   = strtolower((string) $parts['host']);
        $port   = $parts['port'] ?? null;

        $isDefaultPort = ($scheme === 'https' && $port === 443)
            || ($scheme === 'http' && $port === 80);

        if ($port === null || $isDefaultPort) {
            return $scheme . '://' . $host;
        }

        return $scheme . '://' . $host . ':' . $port;
    }
}
