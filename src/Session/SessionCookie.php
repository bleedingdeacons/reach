<?php

declare(strict_types=1);

namespace Reach\Session;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Auth\Base64Url;

/**
 * HMAC-signed session cookie.
 *
 * The cookie body is a base64url JSON payload concatenated with an
 * HMAC-SHA256 signature, separated by a dot — the same shape as a
 * JWS compact serialisation but without the header field, because
 * we use exactly one algorithm and one key and don't need a header
 * to negotiate either.
 *
 * The signing key is derived from WordPress's logged_in salt — the
 * same salt that protects WP's own auth cookies — so rotating salts
 * invalidates all Reach sessions in lockstep with WP sessions, which
 * is the desired behaviour after a key compromise.
 *
 * The cookie is HttpOnly, Secure (over HTTPS), SameSite=Lax (so the
 * OAuth callback redirect from the provider domain still arrives
 * with the cookie attached for any existing session), and scoped to
 * the site path. No information about the session is exposed to
 * JavaScript on the page — the find page asks the server via REST
 * whether a session exists.
 */
final class SessionCookie
{
    use Base64Url;

    public const COOKIE_NAME = 'reach_session';

    /** Sessions expire after 12 hours; 12th-step calls are made on the day, not days later. */
    public const TTL_SECONDS = 12 * HOUR_IN_SECONDS;

    /**
     * Issue a signed cookie for the given session.
     *
     * Called from the OAuth callback handler after a provider has
     * confirmed the email address.
     */
    public function issue(Session $session): void
    {
        $token = $this->sign($session);
        $this->writeCookie($token, $session->expiresAt);
    }

    /**
     * Clear the cookie. Used by the sign-out endpoint.
     */
    public function clear(): void
    {
        $this->writeCookie('', time() - 3600);
    }

    /**
     * Read and verify the cookie. Returns null if absent, tampered,
     * malformed, or expired.
     */
    public function read(): ?Session
    {
        $raw = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $parts = explode('.', $raw, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$payload64, $sig64] = $parts;

        $expectedSig = $this->hmac($payload64);
        $providedSig = $this->base64UrlDecode($sig64);
        if ($providedSig === '' || !hash_equals($expectedSig, $providedSig)) {
            return null;
        }

        $payloadJson = $this->base64UrlDecode($payload64);
        if ($payloadJson === '') {
            return null;
        }

        $data = json_decode($payloadJson, true);
        if (!is_array($data)) {
            return null;
        }

        $session = Session::fromArray($data);
        if ($session === null) {
            return null;
        }

        if ($session->isExpired(time())) {
            return null;
        }

        return $session;
    }

    /**
     * Build the signed token string. Public so tests can verify the
     * format without going through the cookie jar.
     */
    public function sign(Session $session): string
    {
        $payload64 = $this->base64UrlEncode(
            (string) wp_json_encode($session->toArray())
        );
        $sig64 = $this->base64UrlEncode($this->hmac($payload64));
        return $payload64 . '.' . $sig64;
    }

    private function hmac(string $payload64): string
    {
        return hash_hmac('sha256', $payload64, wp_salt('logged_in'), true);
    }

    private function writeCookie(string $value, int $expires): void
    {
        if (headers_sent()) {
            return; // Defensive — should never happen on the OAuth callback path.
        }

        $isHttps = is_ssl();
        setcookie(
            self::COOKIE_NAME,
            $value,
            [
                'expires'  => $expires,
                'path'     => COOKIEPATH ?: '/',
                'domain'   => COOKIE_DOMAIN ?: '',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
        // Reflect into $_COOKIE so the same request can read it back
        // (e.g. when handling the OAuth callback and immediately
        // redirecting through code that reads the session).
        $_COOKIE[self::COOKIE_NAME] = $value;
    }
}
