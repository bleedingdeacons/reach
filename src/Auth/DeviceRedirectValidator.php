<?php

declare(strict_types=1);

namespace Reach\Auth;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides which URIs a device sign-in may be bounced back to.
 *
 * This is the single most security-sensitive check in the device flow.
 * The callback sends a one-time exchange code to whatever URI the app
 * nominated when the flow began; a URI an attacker can influence is a
 * URI an attacker can have the code delivered to, and the code buys a
 * long-lived device token. So the answer is an allow-list with exactly
 * two entries and no configuration surface — nothing an admin can widen
 * by mistake, and nothing a request parameter can extend.
 *
 * <b>1. The app's private scheme</b> — `hand://auth`. This is what the
 * mobile and Mac heads use: the OS routes it back to Hand from the
 * in-app browser tab. Private-scheme redirects are the RFC 8252 §7.1
 * pattern for native apps.
 *
 * <b>2. Loopback</b> — `http://127.0.0.1:{port}/…` and
 * `http://[::1]:{port}/…`. This is the Windows head, which cannot
 * reliably claim a custom scheme unless the app is packaged as MSIX;
 * an unpackaged desktop build instead listens on an ephemeral local
 * port and catches the redirect there. RFC 8252 §7.3 endorses this and
 * requires that *any* port be accepted, because the app cannot reserve
 * one in advance — so the port is deliberately not pinned here.
 *
 * `localhost` is refused even though it looks equivalent. RFC 8252
 * §8.3 spells out why: it resolves through DNS, so a poisoned resolver
 * or a hosts-file entry can point it somewhere else entirely, whereas
 * the literal loopback addresses cannot be redirected. Registered
 * ports below 1024 are refused too — an app running as an ordinary
 * user cannot bind them, so a request for one is not a real client.
 *
 * Every rejection is silent to the caller beyond a 400: an attacker
 * probing for what the allow-list accepts learns nothing from the
 * response.
 */
final class DeviceRedirectValidator
{
    /**
     * The private URI scheme the Hand app registers on Android, iOS and
     * macOS. Changing this means changing the app's platform manifests
     * in step — it is a contract between the two, not a setting.
     */
    public const APP_SCHEME = 'hand';

    /** The only host accepted under {@see APP_SCHEME}. */
    public const APP_HOST = 'auth';

    /** Loopback literals accepted for the desktop flow. See the class docblock. */
    private const LOOPBACK_HOSTS = ['127.0.0.1', '[::1]', '::1'];

    /**
     * The lowest port a loopback redirect may use. Anything below 1024
     * needs privilege to bind, so a client asking for one is not the
     * app.
     */
    private const MIN_LOOPBACK_PORT = 1024;

    /**
     * Whether $uri may receive an exchange code.
     *
     * Total and side-effect free — callers use it as a gate before
     * anything is minted.
     */
    public function isAllowed(string $uri): bool
    {
        $uri = trim($uri);
        if ($uri === '') {
            return false;
        }

        // A fragment can carry a second URI past naive parsing, and we
        // never need one. Same for credentials in the authority.
        if (str_contains($uri, '#') || str_contains($uri, '@')) {
            return false;
        }

        $parts = parse_url($uri);
        if (!is_array($parts) || !isset($parts['scheme'])) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host   = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme === self::APP_SCHEME) {
            // No port and no query: the app's own callback is a fixed
            // address, and we append the code ourselves.
            return $host === self::APP_HOST
                && !isset($parts['port'])
                && !isset($parts['query']);
        }

        if ($scheme === 'http' && in_array($host, self::LOOPBACK_HOSTS, true)) {
            $port = $parts['port'] ?? 0;
            return is_int($port) && $port >= self::MIN_LOOPBACK_PORT && $port <= 65535
                && !isset($parts['query']);
        }

        return false;
    }

    /**
     * Attach an exchange code to an already-allowed redirect URI.
     *
     * add_query_arg() is not used here. It is built for http(s) URLs and
     * does not reliably preserve a private scheme with no path — the
     * exact shape `hand://auth` takes — so the query is composed
     * directly. Values are urlencoded, and {@see isAllowed()} has
     * already established the URI carries no query of its own, so a
     * bare `?` is always correct.
     *
     * @param array<string, string> $params
     */
    public function withParams(string $uri, array $params): string
    {
        if ($params === []) {
            return $uri;
        }

        return rtrim($uri, '?&') . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
