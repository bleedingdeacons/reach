<?php

declare(strict_types=1);

namespace Reach\Session;

if (!defined('ABSPATH')) {
    exit;
}

use function delete_transient;
use function get_transient;
use function set_transient;

/**
 * The list of session ids that have been signed out before their
 * natural expiry.
 *
 * <b>Why a stateless cookie needs this.</b> {@see SessionCookie} is a
 * signed bearer token: it is valid because it verifies, not because a
 * server-side record says so. That is what makes it cheap, and it is
 * also why clearing the cookie cannot be the whole of signing out.
 * Clearing it removes the browser's copy; it does nothing to the token
 * itself, which stays valid until `exp`. A copy taken from a shared
 * machine, a browser profile backup, or a proxy log therefore
 * re-authenticates after the responder has pressed Sign out and walked
 * away — which is precisely the moment they had reason to believe they
 * were safe.
 *
 * So sign-out records the session's id here, and {@see CurrentSession}
 * refuses any session naming one. Sessions are individually revocable
 * without touching {@see SessionCookie}'s signing key, which is the
 * alternative and takes every WordPress admin session down with it.
 *
 * <b>Why this stays small.</b> An entry is only ever needed for the
 * remainder of the session it revokes — once the token would have been
 * refused for being expired, there is nothing left to revoke — so each
 * one is stored with exactly that TTL and WordPress expires it. The
 * list is bounded by the number of sign-outs in a 12-hour window, not
 * by the number of sessions ever issued.
 *
 * Transients are keyed by a hash of the session id for the reason
 * {@see \Reach\Auth\DeviceCodeStore} gives: option names are not
 * secret, and a live session id sitting in one would be a credential in
 * the clear.
 */
final class SessionRevocationList
{
    private const PREFIX = 'reach_revoked_session_';

    /**
     * Revoke a session for whatever remains of its lifetime.
     *
     * A session already past `$expiresAt` is ignored — it is refused on
     * expiry anyway, and storing an entry that outlives the token it
     * revokes would grow the list for no benefit.
     */
    public function revoke(string $sessionId, int $expiresAt, int $now): void
    {
        if ($sessionId === '') {
            return;
        }

        $remaining = $expiresAt - $now;
        if ($remaining <= 0) {
            return;
        }

        set_transient(self::PREFIX . $this->key($sessionId), 1, $remaining);
    }

    /**
     * Whether this session has been signed out.
     *
     * An empty id is never revoked: sessions issued before ids existed
     * carry none, and they must keep working until they expire rather
     * than all being refused at once by an upgrade. They cannot be
     * revoked individually, which is why they are also short-lived —
     * see {@see SessionCookie::TTL_SECONDS}.
     */
    public function isRevoked(string $sessionId): bool
    {
        if ($sessionId === '') {
            return false;
        }

        return get_transient(self::PREFIX . $this->key($sessionId)) !== false;
    }

    /**
     * Drop a revocation entry. Only used by tests and by an explicit
     * administrative undo; ordinary expiry is WordPress's job.
     */
    public function forget(string $sessionId): void
    {
        if ($sessionId !== '') {
            delete_transient(self::PREFIX . $this->key($sessionId));
        }
    }

    private function key(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }
}
