<?php

declare(strict_types=1);

namespace Reach\Auth;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Moves the emailed password-reset token out of the URL.
 *
 * <b>What was wrong with the URL.</b> PasswordResetMailer sends
 * `/reach/set-password?token=…`. The token is 32 random bytes, single-use,
 * and expires in an hour, and every asset on that page is same-origin, so
 * there was no Referer leak to a third party. What remained is that the
 * token sat in the browser's address bar and history for the life of the
 * page, and in the Referer of anything that page might later link out to.
 *
 * <b>The pattern.</b> The same one WordPress core uses for its own reset
 * links in wp-login.php: read the token on arrival, put it in a short-lived
 * cookie scoped to this page, and redirect to the bare URL. A redirect
 * replaces the history entry rather than adding to it, so the credential
 * stops being in the URL bar, in history, in a bookmark, in a screenshot,
 * or in any Referer — and lives instead in a HttpOnly cookie the page reads
 * back server-side.
 *
 * <b>What this does not fix.</b> The emailed link still has to carry the
 * token, so the *first* request is still one line in the web server's access
 * log. Nothing that keeps a link clickable can avoid that, and core does not
 * either. Removing it entirely means not putting the token in a URL at all —
 * which is what Fellowship does, below.
 *
 * Fellowship solved it that way — its mailer sends a code the member types
 * into the app, with no URL involved. That is the stronger shape, and it is
 * available to Fellowship because there is an app to type into. A browser
 * reset flow that emails a link does not have that option without asking
 * members to copy a code between an email and a web page, which is a real
 * usability cost for a token already single-use and hour-limited.
 */
final class ResetTokenCookie
{
    public const COOKIE_NAME = 'reach_reset_token';

    /**
     * The page the cookie is scoped to. Narrower than the session cookie's
     * path on purpose: this credential is for one form on one page, so
     * nothing else on the site should ever be sent it.
     */
    public const COOKIE_PATH = '/reach/set-password';

    /**
     * Matches the token's own 60-minute expiry. A cookie outliving the
     * token it carries would only keep a spent credential warm.
     */
    private const TTL = 3600;

    /**
     * Stash a token arriving in the query string and report whether the
     * caller should now redirect to the bare page.
     *
     * Returns false when there is nothing to move, so the ordinary
     * post-redirect load falls straight through to {@see read()}.
     */
    public function captureFromQuery(): bool
    {
        $token = isset($_GET['token'])
            ? sanitize_text_field(wp_unslash((string) $_GET['token']))
            : '';

        if ($token === '') {
            return false;
        }

        $this->write($token);

        return true;
    }

    /**
     * The token for this request: whatever was just captured, or the cookie
     * left by the redirect. Empty string when there is neither.
     */
    public function read(): string
    {
        return isset($_COOKIE[self::COOKIE_NAME])
            ? sanitize_text_field(wp_unslash((string) $_COOKIE[self::COOKIE_NAME]))
            : '';
    }

    /**
     * Where to send the browser once the token is stashed — the same page
     * with no query string.
     */
    public function bareUrl(): string
    {
        return home_url(self::COOKIE_PATH);
    }

    private function write(string $token): void
    {
        if (!headers_sent()) {
            setcookie(
                self::COOKIE_NAME,
                $token,
                [
                    'expires'  => time() + self::TTL,
                    'path'     => self::COOKIE_PATH,
                    'domain'   => COOKIE_DOMAIN ?: '',
                    'secure'   => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]
            );
        }

        // Reflect into $_COOKIE so this same request can read it back. That
        // matters when headers are already sent and the redirect cannot
        // happen: the form still renders with a working token rather than
        // telling the member their link is invalid.
        $_COOKIE[self::COOKIE_NAME] = $token;
    }
}
