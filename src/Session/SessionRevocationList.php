<?php

declare(strict_types=1);

namespace Reach\Session;

if (!defined('ABSPATH')) {
    exit;
}

use function add_option;
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
 * <b>Why options and not transients.</b> The other short-lived stores in
 * this plugin — {@see \Reach\Auth\StateStore},
 * {@see \Reach\Auth\DeviceCodeStore} — are transients, and this began as
 * one for the same stated reason: the data is inherently short-lived and
 * WordPress already expires it. That reasoning does not carry over,
 * because those two fail *closed* and this fails *open*. Losing a
 * stashed OAuth state means a sign-in is refused; losing a revocation
 * entry means a signed-out session is accepted again. WordPress is
 * explicit that a transient's expiry is a maximum and not a guarantee —
 * under an external object cache an LRU eviction or a `wp_cache_flush()`
 * can drop one early — so a revocation held in one is a security
 * decision that quietly undoes itself.
 *
 * <b>Why one option per revocation.</b> A single option holding a map of
 * every revoked id is a read-modify-write, and two sign-outs landing
 * together can drop one of them — which fails open, in the one place
 * that must not. Each revocation is therefore its own option, written
 * with `add_option()`: that is an INSERT against a unique column, so
 * concurrent writers cannot lose each other's entries, and a repeat
 * revocation is a harmless no-op. Reading is a single targeted option
 * rather than an array that grows with every sign-out.
 *
 * <b>Housekeeping is separate, and deliberately best-effort.</b> An
 * entry is only needed for the remainder of the session it revokes, so
 * spent ones are swept on the next sign-out. Finding them needs a list,
 * and {@see INDEX_OPTION} is that list — a read-modify-write, and this
 * time that is fine: losing an index entry leaves an option row nobody
 * sweeps, which wastes a row rather than restoring access. Correctness
 * lives in the per-id options; only tidiness lives in the index.
 *
 * Ids are stored hashed, for the reason {@see \Reach\Auth\DeviceCodeStore}
 * gives: option names and contents are not secret — they appear in a
 * database dump and in any admin tool that lists options — and a live
 * session id sitting in one would be a credential in the clear.
 */
final class SessionRevocationList
{
    /**
     * Prefix for the per-revocation options. Followed by the hashed
     * session id; well inside the 191-character option_name limit.
     */
    public const PREFIX = 'reach_revoked_session_';

    /**
     * Names the outstanding revocations so spent ones can be swept.
     * Housekeeping only — see the class docblock.
     */
    public const INDEX_OPTION = 'reach_revoked_sessions_index';

    /**
     * Revoke a session for whatever remains of its lifetime.
     *
     * A session already past `$expiresAt` is ignored — it is refused on
     * expiry anyway, and storing an entry that outlives the token it
     * revokes would grow the list for no benefit.
     */
    public function revoke(string $sessionId, int $expiresAt, int $now): void
    {
        if ($sessionId === '' || $expiresAt <= $now) {
            return;
        }

        $key = $this->key($sessionId);

        // INSERT, not read-modify-write: two sign-outs at once cannot
        // lose each other. False means it was already revoked, which is
        // the outcome the caller wanted either way.
        add_option(self::PREFIX . $key, $expiresAt, '', false);

        $this->sweep($now, [$key => $expiresAt]);
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
     * that writes would put an option update on the hot path. Sweeping
     * is {@see revoke()}'s job.
     */
    public function isRevoked(string $sessionId, ?int $now = null): bool
    {
        if ($sessionId === '') {
            return false;
        }

        $expiresAt = get_option(self::PREFIX . $this->key($sessionId));

        return is_numeric($expiresAt) && (int) $expiresAt > ($now ?? time());
    }

    /**
     * Drop a revocation entry. Only used by tests and by an explicit
     * administrative undo; ordinary expiry is {@see sweep()}'s job.
     */
    public function forget(string $sessionId): void
    {
        if ($sessionId === '') {
            return;
        }

        $key = $this->key($sessionId);
        delete_option(self::PREFIX . $key);

        $index = $this->index();
        unset($index[$key]);
        $this->writeIndex($index);
    }

    /**
     * Delete the revocations that have outlived the sessions they
     * revoked, and fold in whatever the caller has just added.
     *
     * @param array<string, int> $additions
     */
    private function sweep(int $now, array $additions = []): void
    {
        $index = $this->index() + $additions;

        $live = [];
        foreach ($index as $key => $expiresAt) {
            if ($expiresAt > $now) {
                $live[$key] = $expiresAt;
                continue;
            }
            delete_option(self::PREFIX . $key);
        }

        $this->writeIndex($live);
    }

    /**
     * The index, as id-hash => expiry.
     *
     * Defensive about shape: this is an option row, so a corrupt or
     * hand-edited value must degrade to "nothing to sweep" rather than
     * to a fatal.
     *
     * @return array<string, int>
     */
    private function index(): array
    {
        $stored = get_option(self::INDEX_OPTION, []);
        if (!is_array($stored)) {
            return [];
        }

        $index = [];
        foreach ($stored as $key => $expiresAt) {
            if (is_string($key) && is_numeric($expiresAt)) {
                $index[$key] = (int) $expiresAt;
            }
        }

        return $index;
    }

    /**
     * @param array<string, int> $index
     */
    private function writeIndex(array $index): void
    {
        if ($index === []) {
            delete_option(self::INDEX_OPTION);
            return;
        }

        update_option(self::INDEX_OPTION, $index, false);
    }

    private function key(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }
}
