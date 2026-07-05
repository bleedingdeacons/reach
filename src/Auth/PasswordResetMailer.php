<?php

declare(strict_types=1);

namespace Reach\Auth;

if (!defined('ABSPATH')) {
    exit;
}

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
 */
final class PasswordResetMailer
{
    /**
     * Send the set/reset link to $email. Returns wp_mail's success flag.
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
