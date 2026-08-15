<?php

declare(strict_types=1);

namespace Reach\Auth;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Mints and verifies the long-lived bearer tokens Hand handsets hold.
 *
 * A device token is 32 random bytes, hex-encoded and prefixed. Only its
 * keyed hash is stored (see {@see \Reach\Devices\WpdbDeviceRepository}),
 * so the plaintext exists exactly twice: once in the response that
 * enrols the handset, and thereafter only in that handset's secure
 * storage. Reach cannot show an admin an existing token, and does not
 * try to — the Devices page revokes and re-enrols instead.
 *
 * <b>Why HMAC rather than a bare hash.</b> 256 bits of entropy is far
 * past brute-forcing, so a plain SHA-256 would be safe against a
 * database dump on its own. Keying it by `wp_salt('auth')` buys a
 * different property: rotating the salt invalidates every enrolled
 * handset at once. That is the same deliberate behaviour
 * {@see \Reach\Session\SessionCookie} documents for sessions, and it is
 * the recovery action a site takes after a suspected breach — at which
 * point "every handset must re-enrol" is the outcome you want, not a
 * regression. Responders re-enrol by signing in again.
 *
 * <b>Why a prefix.</b> `rdt_` makes a leaked token recognisable on
 * sight — in a log, a bug report, a screenshot — so it can be traced to
 * this system and revoked, rather than looking like anonymous hex.
 */
final class DeviceTokenMinter
{
    /** Marks a string as a Reach device token. See the class docblock. */
    public const TOKEN_PREFIX = 'rdt_';

    /** Entropy per token, in bytes, before hex encoding. */
    private const TOKEN_BYTES = 32;

    /**
     * A fresh device token. The caller stores only {@see hash()} of this
     * and returns the plaintext to the enrolling handset once.
     */
    public function mint(): string
    {
        return self::TOKEN_PREFIX . bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    /**
     * The stored form of a token: HMAC-SHA256, hex, keyed by the auth
     * salt. 64 hex characters, which is what the token_hash column is
     * sized for.
     */
    public function hash(string $token): string
    {
        return hash_hmac('sha256', $token, $this->key());
    }

    /**
     * Whether a string is shaped like a device token.
     *
     * A cheap structural check, not an authentication decision — it lets
     * the REST layer reject obvious rubbish without a database round
     * trip. A well-formed but unknown token still fails at the
     * repository lookup, and both failures return the same 401.
     */
    public function looksLikeToken(string $candidate): bool
    {
        return (bool) preg_match(
            '/^' . preg_quote(self::TOKEN_PREFIX, '/') . '[0-9a-f]{' . (self::TOKEN_BYTES * 2) . '}$/',
            $candidate,
        );
    }

    /**
     * Pull the bearer token out of an Authorization header value.
     *
     * Returns '' when the header is absent, is not a Bearer credential,
     * or carries nothing after the scheme. The scheme match is
     * case-insensitive because RFC 7235 makes it so, and clients differ.
     */
    public function bearerFrom(string $authorizationHeader): string
    {
        if (!preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $authorizationHeader, $matches)) {
            return '';
        }

        return $matches[1];
    }

    /**
     * Derive the HMAC key from WordPress's auth salt, domain-separated
     * so a device token's hash can never collide with any other use of
     * the same salt elsewhere in the plugin.
     */
    private function key(): string
    {
        return hash('sha256', wp_salt('auth') . '|reach-device-token', true);
    }
}
