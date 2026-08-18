<?php

declare(strict_types=1);

namespace Reach\Auth;

if (!defined('ABSPATH')) {
    exit;
}

use function add_action;
use function add_query_arg;
use function home_url;
use function wp_mail;

/**
 * Emails the one-time link a member uses to set or reset their password.
 *
 * The link carries only the raw reset token (`?token=…`); the address is
 * not put in the URL, and only the token's SHA-256 hash is stored server
 * side. Plain-text body — it carries a security-sensitive link to an
 * inbox and plain text avoids any HTML-injection surface.
 *
 * Mirrors {@see \Reach\CallRequests\CallRequestMailer}: a thin wrapper over
 * wp_mail returning its success flag so the caller can react to a send
 * failure. The message never states whether an account existed — the
 * caller only invokes this for eligible members, and the endpoint's
 * response is identical regardless, so nothing here leaks account
 * existence.
 *
 * <b>Why sending is queued rather than done on the spot.</b> The
 * request-reset endpoint answers `{sent: true}` whether or not a link
 * went out, so its *body* reveals nothing — but the two branches did not
 * cost the same. Sending is a synchronous SMTP round trip; not sending
 * is a return. Tens to hundreds of milliseconds, measurable from
 * outside, is enough to tell whether an address belongs to an eligible
 * member — exactly the account enumeration the constant response exists
 * to prevent. {@see queue()} defers the work past the response so both
 * branches answer in the same time, which is the same reasoning
 * {@see PasswordAuthenticator::burnTime()} applies on the login path.
 */
final class PasswordResetMailer
{
    /**
     * Links waiting to go out after the response.
     *
     * @var array<int, array{email: string, token: string}>
     */
    private array $pending = [];

    /** Whether the shutdown flush has been registered this request. */
    private bool $hooked = false;

    /**
     * Queue the set/reset link for $email, to be sent once the response
     * has been handed back — see the class docblock for why.
     *
     * The hook is registered on first use rather than at construction:
     * most requests queue nothing, and a request that queues twice
     * should still flush once.
     */
    public function queue(string $email, string $rawToken): void
    {
        $this->pending[] = ['email' => $email, 'token' => $rawToken];

        if ($this->hooked) {
            return;
        }
        $this->hooked = true;

        // Late priority so anything else still writing to the response
        // has already run by the time we start talking to an SMTP server.
        add_action('shutdown', [$this, 'flush'], PHP_INT_MAX);
    }

    /**
     * Send everything {@see queue()} has accumulated.
     *
     * Public because it is the shutdown callback, and because it is
     * where the sending is actually observable — the registration above
     * is asserted separately as a hook.
     *
     * `fastcgi_finish_request()` is called first where the SAPI provides
     * it (PHP-FPM, which is the usual deployment): it returns the
     * response to the client and lets the rest of the script run
     * unwatched, which is what makes the timing genuinely equal rather
     * than merely later. Where it is absent the send still happens after
     * WordPress has finished with the response, which narrows the
     * window without closing it — worth being clear about rather than
     * claiming more than the platform gives.
     */
    public function flush(): void
    {
        if ($this->pending === []) {
            return;
        }

        $queued = $this->pending;
        $this->pending = [];

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        foreach ($queued as $item) {
            $this->send($item['email'], $item['token']);
        }
    }

    /**
     * Send the set/reset link to $email immediately. Returns wp_mail's
     * success flag.
     *
     * Callers on the enumeration-sensitive path want {@see queue()};
     * this stays public because it is the unit of work, and the queue is
     * only a decision about when to run it.
     */
    public function send(string $email, string $rawToken): bool
    {
        $blogName = (string) get_bloginfo('name');
        $siteName = $blogName !== '' ? $blogName : 'Reach';

        $link = add_query_arg('token', $rawToken, home_url('/reach/set-password'));

        $subject = sprintf('[%s] Set your Reach password', $siteName);

        $lines = [
            'Someone (hopefully you) asked to set or reset the password for your Reach account.',
            '',
            'To choose a new password, open this link within the next hour:',
            '',
            $link,
            '',
            'The link can be used once and expires after 60 minutes. If you did not',
            'request this, you can safely ignore this email — your account is unchanged',
            'and you can still sign in with one of the social sign-in buttons.',
        ];

        $body    = implode("\n", $lines);
        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        return (bool) wp_mail($email, $subject, $body, $headers);
    }
}
