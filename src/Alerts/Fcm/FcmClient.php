<?php

declare(strict_types=1);

namespace Reach\Alerts\Fcm;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Auth\Base64Url;
use Reach\Logger\HasLogger;

/**
 * Talks to Firebase Cloud Messaging's HTTP v1 API.
 *
 * Two responsibilities, in order: turn the service-account key into a
 * short-lived OAuth access token, then POST messages with it.
 *
 * <b>Why the JWT dance.</b> FCM's legacy API took a static server key
 * in a header; that API is switched off. The v1 API takes an OAuth
 * bearer token, and a service account obtains one by signing a JWT
 * assertion with its private key and exchanging it at Google's token
 * endpoint (RFC 7523). The assertion is what proves possession of the
 * key; the access token that comes back is what every send actually
 * carries.
 *
 * Access tokens last an hour. They are cached in a transient keyed by a
 * fingerprint of the credentials, expiring a little early so a token is
 * never used in the seconds around its own expiry, and invalidating
 * immediately if the service account is replaced in settings.
 *
 * <b>Failure is expected and must be quiet.</b> A push that cannot be
 * sent is not an error the alert path should propagate: the alert is
 * already stored, and the handset will collect it on its next poll.
 * Every method here therefore reports failure as a return value and
 * logs it, rather than throwing into a caller whose job is to keep
 * delivering to the other handsets in the list.
 */
final class FcmClient
{
    use Base64Url;
    use HasLogger;

    /** Cache key prefix for the OAuth access token. */
    private const TOKEN_TRANSIENT_PREFIX = 'reach_fcm_token_';

    /**
     * Seconds of headroom left on a cached access token.
     *
     * Google issues them for 3600s. Caching for 3300 means we never
     * present one in its last five minutes, which covers both clock
     * skew between this server and Google's and a send that starts just
     * before expiry and arrives just after.
     */
    private const TOKEN_CACHE_SECONDS = 3300;

    /** Lifetime claimed in the JWT assertion. Google caps this at an hour. */
    private const ASSERTION_TTL = 3600;

    /** The single OAuth scope FCM sending needs. */
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TIMEOUT_SECONDS = 10;

    protected static function logChannel(): string
    {
        return 'reach';
    }

    /**
     * Send one message.
     *
     * $message is the value of the FCM `message` key — see
     * {@see \Reach\Alerts\Transport\FcmTransport} for how it is built.
     *
     * Returns true when Google accepted it. False means it did not
     * arrive, for any reason.
     *
     * @param array<string, mixed> $message
     */
    public function send(ServiceAccount $account, array $message): bool
    {
        $token = $this->accessToken($account);
        if ($token === '') {
            return false;
        }

        $response = wp_remote_post($account->sendEndpoint(), [
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json; charset=utf-8',
            ],
            'body' => (string) wp_json_encode(['message' => $message]),
        ]);

        if (is_wp_error($response)) {
            self::logWarning('FCM send failed', ['error' => $response->get_error_message()]);
            return false;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status >= 200 && $status < 300) {
            return true;
        }

        $body = substr((string) wp_remote_retrieve_body($response), 0, 500);

        // 401 and 403 are almost never about the handset. They mean this
        // server cannot send at all: the service account lacks
        // cloudmessaging.messages.create on the project, the Firebase
        // Cloud Messaging API is not enabled, or the credentials are for
        // somewhere else. That is every alert to every responder failing,
        // not one dead phone, and it is worth waking somebody for.
        //
        // This exact case ran undetected here for weeks, logged at
        // warning alongside the ordinary refusals it is nothing like.
        if ($status === 401 || $status === 403) {
            self::logError(
                'FCM refused to send. This is a configuration fault, not a handset fault — '
                . 'no alert can be pushed to anyone until it is fixed. Check that the service '
                . 'account has the Firebase Cloud Messaging API Admin role on the project and '
                . 'that the FCM API is enabled.',
                ['status' => $status, 'body' => $body],
            );

            return false;
        }

        // Everything else is about this one message: a dead registration
        // token, a malformed payload, a rate limit, a bad hour at Google.
        // Ordinary, survivable, and not worth more than a warning.
        self::logWarning('FCM rejected a message', [
            'status' => $status,
            'body'   => $body,
        ]);

        return false;
    }

    /**
     * Whether a failure status means "stop using this registration
     * token". Separated from {@see send()} so the caller can act on it
     * without parsing log lines.
     *
     * <b>404 only, and 403 deliberately not.</b> 404 is FCM's UNREGISTERED
     * — the app was uninstalled or the token was replaced, and the stored
     * one will never work again. 403 was treated the same way here and is
     * not the same thing: the common 403 is PERMISSION_DENIED against the
     * project, which says nothing whatever about the handset. Acting on it
     * would have thrown away a perfectly good registration token for every
     * device on the rota, on every alert, while the actual fault sat in
     * the service account's IAM roles.
     *
     * SENDER_ID_MISMATCH is also a 403 and does mean the token is unusable
     * here, so telling the two apart needs the error body rather than the
     * status. Nothing needs that yet; when something does, it belongs in a
     * method that reads the body, not in this one.
     */
    public function isDeadTokenStatus(int $status): bool
    {
        return $status === 404;
    }

    /**
     * A usable OAuth access token, from cache or freshly minted.
     * Empty string when one cannot be obtained.
     */
    private function accessToken(ServiceAccount $account): string
    {
        $key = self::TOKEN_TRANSIENT_PREFIX . $account->fingerprint();

        $cached = get_transient($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $assertion = $this->assertion($account);
        if ($assertion === '') {
            return '';
        }

        $response = wp_remote_post($account->tokenUri, [
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body'    => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $assertion,
            ],
        ]);

        if (is_wp_error($response)) {
            self::logWarning('FCM token request failed', ['error' => $response->get_error_message()]);
            return '';
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($decoded) || !isset($decoded['access_token']) || !is_string($decoded['access_token'])) {
            self::logWarning('FCM token response had no access_token', [
                'status' => (int) wp_remote_retrieve_response_code($response),
            ]);
            return '';
        }

        $token = $decoded['access_token'];
        set_transient($key, $token, self::TOKEN_CACHE_SECONDS);

        return $token;
    }

    /**
     * Build and sign the JWT assertion proving we hold the service
     * account's private key. Empty string if the key will not sign,
     * which in practice means it is malformed.
     */
    private function assertion(ServiceAccount $account): string
    {
        $now = time();

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => $account->clientEmail,
            'scope' => self::SCOPE,
            'aud'   => $account->tokenUri,
            'iat'   => $now,
            'exp'   => $now + self::ASSERTION_TTL,
        ];

        $signingInput = $this->base64UrlEncode((string) wp_json_encode($header))
            . '.'
            . $this->base64UrlEncode((string) wp_json_encode($claims));

        $key = openssl_pkey_get_private($account->privateKey);
        if ($key === false) {
            self::logWarning('FCM service-account private key could not be read');
            return '';
        }

        $signature = '';
        $signed = openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256);
        if ($signed === false) {
            self::logWarning('FCM assertion could not be signed');
            return '';
        }

        return $signingInput . '.' . $this->base64UrlEncode($signature);
    }
}
