<?php

declare(strict_types=1);

namespace Reach\Alerts\Fcm;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The Google service-account credentials FCM is called with.
 *
 * Firebase hands an admin a JSON key file — "Project settings → Service
 * accounts → Generate new private key". The whole file is pasted into
 * Reach's settings, stored encrypted at rest alongside the OAuth client
 * secrets, and parsed here.
 *
 * Only four of its fields matter to us. The rest of the file is Google's
 * own bookkeeping and is ignored rather than validated, so a key file
 * that gains a field in some future format still parses.
 *
 * <b>This is a credential with real reach.</b> The private key in that
 * file can send a push notification to every handset in the Firebase
 * project. It is never logged, never returned by the settings page once
 * saved, and never leaves the server — the same handling the OAuth
 * client secrets get, for the same reason.
 */
final class ServiceAccount
{
    private function __construct(
        public readonly string $projectId,
        public readonly string $clientEmail,
        public readonly string $privateKey,
        public readonly string $tokenUri,
    ) {
    }

    /**
     * Parse a service-account key file, or null if it is not one.
     *
     * Null covers empty configuration as well as malformed JSON and
     * missing fields — all of which mean the same thing to the caller:
     * FCM is not configured, so fall back to letting handsets pull.
     * Distinguishing them would only matter to an admin, and the
     * settings page validates on save where it can say so properly.
     */
    public static function fromJson(string $json): ?self
    {
        $json = trim($json);
        if ($json === '') {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }

        $projectId   = self::string($data, 'project_id');
        $clientEmail = self::string($data, 'client_email');
        $privateKey  = self::string($data, 'private_key');

        if ($projectId === '' || $clientEmail === '' || $privateKey === '') {
            return null;
        }

        return new self($projectId, $clientEmail, $privateKey, self::tokenUri($data));
    }

    /**
     * The FCM v1 send endpoint for this project.
     */
    public function sendEndpoint(): string
    {
        return 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($this->projectId) . '/messages:send';
    }

    /**
     * A short, non-secret fingerprint of these credentials.
     *
     * Used to key the cached access token, so that replacing the service
     * account in settings invalidates the cached token immediately
     * rather than leaving the old project being pushed to for up to an
     * hour. The client email is not secret — the private key is — so
     * hashing it is about producing a fixed-length cache key, not about
     * concealment.
     */
    public function fingerprint(): string
    {
        return substr(hash('sha256', $this->clientEmail . '|' . $this->projectId), 0, 16);
    }

    /**
     * The token endpoint these credentials are exchanged at.
     *
     * Google has always emitted `token_uri`, but it is documented as
     * part of the file rather than as a constant, so a value in the file
     * is honoured — provided it is one of Google's. A key file is pasted
     * in by an administrator, which makes this a low-privilege-gain sink
     * rather than a hole; it is still a field from a file that decides
     * where a signed assertion gets POSTed, and a typo or a doctored
     * file should not be able to redirect that anywhere.
     *
     * An unrecognised host falls back to the published endpoint rather
     * than refusing the whole file. The private key is what matters, the
     * assertion is signed for Google either way, and failing closed here
     * would take push notifications down over a field nobody edits.
     *
     * @param array<mixed, mixed> $data
     */
    private static function tokenUri(array $data): string
    {
        $default = 'https://oauth2.googleapis.com/token';

        $configured = self::string($data, 'token_uri');
        if ($configured === '') {
            return $default;
        }

        $parts = parse_url($configured);
        if (!is_array($parts)) {
            return $default;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower((string) ($parts['host'] ?? ''));

        $permitted = $scheme === 'https'
            && in_array($host, ['oauth2.googleapis.com', 'accounts.google.com'], true);

        return $permitted ? $configured : $default;
    }

    /**
     * @param array<mixed, mixed> $data
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    }
}
