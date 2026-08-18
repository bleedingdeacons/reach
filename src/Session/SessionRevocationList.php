<?php

declare(strict_types=1);

namespace Reach\Session;

if (!defined('ABSPATH')) {
    exit;
}

use function delete_option;
use function get_option;
use function update_option;

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
 * <b>Why an option and not a transient.</b> The other short-lived
 * stores in this plugin — {@see \Reach\Auth\StateStore},
 * {@see \Reach\Auth\DeviceCodeStore} — are transients, and this began
 * as one for the same stated reason: the data is inherently short-lived
 * and WordPress already expires it. That reasoning does not carry over,
 * because those two fail *closed* and this fails *open*. Losing a
 * stashed OAuth state means a sign-in is refused; losing a revocation
 * entry means a signed-out session is accepted again. WordPress is
 * explicit that a transient's expiry is a maximum and not a guarantee —
 * under an external object cache an LRU eviction or a `wp_cache_flush()`
 * can drop one early — so a revocation held in one is a security
 * decision that quietly undoes itself. Options are durable, and expiry
 * here is cheap to do by hand.
 *
 * <b>Why it stays small.</b> An entry is only needed for the remainder
 * of the session it revokes — once the token would be refused for being
 * expired there is nothing left to revoke — so each carries its own
 * expiry and every write drops the ones that have passed. The list is
 * bounded by the number of sign-outs in a single session lifetime, not
 * by the number of sessions ever issued.
 *
 * Ids are stored hashed, for the reason {@see \Reach\Auth\DeviceCodeStore}
 * gives: option contents are not secret — they appear in a database
 * dump and in any admin tool that lists options — and a live session id
 * sitting in one would be a credential in the clear.
 */
final class SessionRevocationList
{
    /**
     * Not autoloaded: most requests never consult this, and the ones
     * that do are already reading a cookie and a member record.
     */
    public const OPTION = 'reach_revoked_sessions';

    /**
     * Revoke a session for whatever remains of its lifetime.
     *
     * A session already past `$expiresAt` is ignored — it is refused on
     * expiry anyway, and storing an entry that outlives the token it
     * revokes would grow the list for no benefit.
     *
     * Read-modify-write, so two sign-outs landing in the same instant
     * could see one lose its entry. The window is a single option
     * round trip and the affected session is one whose owner is at that
     * moment signing out on another device; a lock or a row per id
     * would cost more than that is worth. Noted rather than hidden.
     */
    public function revoke(string $sessionId, int $expiresAt, int $now): void
    {
        if ($sessionId === '' || $expiresAt <= $now) {
            return;
        }

        $entries = $this->prune($this->all(), $now);
        $entries[$this->key($sessionId)] = $expiresAt;

        update_option(self::OPTION, $entries, false);
    }

    /**
     * Whether this session has been signed out.
     *
     * An empty id is never revoked: sessions issued before ids existed
     * carry none, and they must keep working until they expire rather
     * than all being refused at once by an upgrade. They cannot be
     * revoked individually, which is why they are also short-lived —
     * see {@see SessionCookie::TTL_SECONDS}.
     *
     * An entry past its own expiry is treated as absent without being
     * rewritten: this runs on every authenticated request, and a read
     * that writes would put an option update on the hot path.
     */
    public function isRevoked(string $sessionId, ?int $now = null): bool
    {
        if ($sessionId === '') {
            return false;
        }

        $now = $now ?? time();
        $entries = $this->all();
        $key = $this->key($sessionId);

        return isset($entries[$key]) && $entries[$key] > $now;
    }

    /**
     * Drop a revocation entry. Only used by tests and by an explicit
     * administrative undo; ordinary expiry is {@see prune()}'s job.
     */
    public function forget(string $sessionId): void
    {
        if ($sessionId === '') {
            return;
        }

        $entries = $this->all();
        unset($entries[$this->key($sessionId)]);

        if ($entries === []) {
            delete_option(self::OPTION);
            return;
        }

        update_option(self::OPTION, $entries, false);
    }

    /**
     * The stored entries, as id-hash => expiry.
     *
     * Defensive about shape: this is an option row, so a corrupt or
     * hand-edited value must degrade to "nothing is revoked" rather
     * than to a fatal on every authenticated request.
     *
     * @return array<string, int>
     */
    private function all(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            return [];
        }

        $entries = [];
        foreach ($stored as $key => $expiresAt) {
            if (is_string($key) && is_numeric($expiresAt)) {
                $entries[$key] = (int) $expiresAt;
            }
        }

        return $entries;
    }

    /**
     * @param array<string, int> $entries
     * @return array<string, int>
     */
    private function prune(array $entries, int $now): array
    {
        return array_filter($entries, static fn(int $expiresAt): bool => $expiresAt > $now);
    }

    private function key(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }
}
