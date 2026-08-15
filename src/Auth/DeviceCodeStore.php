<?php

declare(strict_types=1);

namespace Reach\Auth;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single-use exchange codes for the native-app sign-in flow.
 *
 * <b>Why a code and not the token.</b> Hand signs in by opening reach's
 * existing OAuth flow in the system browser
 * (ASWebAuthenticationSession / Custom Tabs) and catching a redirect
 * back to its own URI scheme. Whatever the callback puts in that
 * redirect passes through the browser: it lands in history, it can be
 * read by anything else registered for the scheme, and on some
 * platforms it is logged. So the callback emits a short-lived one-time
 * code, and the app trades that code for its device token over TLS in a
 * direct POST. This is the authorization-code pattern from RFC 8252
 * ("OAuth 2.0 for Native Apps"), and the reason that RFC exists at all.
 *
 * The window is deliberately tight — two minutes is far more than the
 * handful of seconds an app needs to make one HTTP call, and short
 * enough that a code recovered from browser history later is already
 * dead. Codes are single-use on top of that, so replaying one fails
 * even inside the window.
 *
 * Transients, not a table, for the same reason {@see StateStore} uses
 * them: the data is inherently short-lived and WordPress already
 * expires it.
 */
final class DeviceCodeStore
{
    private const PREFIX = 'reach_device_code_';
    private const TTL_SECONDS = 120;

    /**
     * Issue a code standing for a proven identity.
     *
     * The identity has already cleared the eligibility gate by the time
     * this is called — the code is a receipt for that decision, not an
     * invitation to re-make it. It is still re-checked at exchange time,
     * because a role can be withdrawn between the two calls.
     */
    public function issue(VerifiedIdentity $identity): string
    {
        $code = bin2hex(random_bytes(32));

        set_transient(
            self::PREFIX . $this->key($code),
            [
                'email'    => $identity->email,
                'provider' => $identity->provider,
                'sub'      => $identity->sub,
            ],
            self::TTL_SECONDS,
        );

        return $code;
    }

    /**
     * Consume a code, returning the identity it stood for and deleting
     * it. Null when the code is unknown, already spent, or expired —
     * all indistinguishable to the caller, which returns one generic
     * error for the lot.
     */
    public function consume(string $code): ?VerifiedIdentity
    {
        if ($code === '') {
            return null;
        }

        $key = self::PREFIX . $this->key($code);
        $stored = get_transient($key);
        if (!is_array($stored)) {
            return null;
        }

        delete_transient($key);

        $email = (string) ($stored['email'] ?? '');
        if ($email === '') {
            return null;
        }

        return new VerifiedIdentity(
            email: $email,
            provider: (string) ($stored['provider'] ?? ''),
            sub: (string) ($stored['sub'] ?? ''),
        );
    }

    /**
     * Transients are keyed by a hash of the code rather than the code
     * itself. Option names are not secret — they show up in a database
     * dump, in query logs, and in any admin tool that lists options —
     * and a live exchange code sitting in one would be a credential in
     * the clear for the length of its window.
     */
    private function key(string $code): string
    {
        return hash('sha256', $code);
    }
}
