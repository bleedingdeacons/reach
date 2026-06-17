<?php

declare(strict_types=1);

namespace Reach\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings store for Reach.
 *
 * Public fields (client IDs) live in a single wp_option as plain
 * JSON. Secrets (OAuth client secrets) are AES-256-GCM encrypted
 * before being written, keyed by a hash of WordPress's AUTH_KEY
 * salt, so a database dump alone never yields usable credentials.
 *
 * Two reads, two writes — public values and secret values are kept in
 * separate option rows so we can render the admin form without
 * decrypting anything when no secret is being edited.
 */
final class Settings
{
    public const OPTION_PUBLIC = 'reach_settings';
    public const OPTION_SECRETS = 'reach_secrets';

    private const CIPHER = 'aes-256-gcm';

    public function getClientId(string $provider): string
    {
        $all = get_option(self::OPTION_PUBLIC, []);
        $key = 'client_id_' . $this->normaliseProvider($provider);
        return is_array($all) && isset($all[$key]) && is_string($all[$key]) ? $all[$key] : '';
    }

    /**
     * Free-text area used to disambiguate place-name lookups on the
     * find page. Stored as plain text — it's a piece of public config,
     * not a secret.
     *
     * When non-empty, the geocoder treats this string as the centre of
     * the operating region. Place-name lookups (e.g. "Kingswood", which
     * matches several UK localities) are re-ranked so the result
     * geographically closest to this centre wins. Postcode and outcode
     * lookups are unaffected — they're already unambiguous.
     *
     * Prefer a postcode or outcode here (e.g. "BS5", "BS1 4ST") because
     * the bias is itself geocoded on first use, and a postcode resolves
     * to a single centroid without ambiguity. A bare place name will
     * work but inherits whatever postcodes.io ranks first for it.
     */
    public function getPlaceBias(): string
    {
        $all = get_option(self::OPTION_PUBLIC, []);
        return is_array($all) && isset($all['place_bias']) && is_string($all['place_bias'])
            ? $all['place_bias']
            : '';
    }

    public function setPlaceBias(string $value): void
    {
        $all = get_option(self::OPTION_PUBLIC, []);
        if (!is_array($all)) {
            $all = [];
        }
        $value = trim($value);
        if ($value === '') {
            unset($all['place_bias']);
        } else {
            $all['place_bias'] = $value;
        }
        update_option(self::OPTION_PUBLIC, $all, false);
    }

    /**
     * Out-of-hours window bounds, each as a 24-hour `H:i` string (e.g.
     * "22:00"), or the empty string when unset. Both bounds must be set
     * for the window to be active — see {@see isOutOfHours()}.
     *
     * The window controls whether the find page offers the "Request a
     * callback" option: during these hours a responder is nudged to ask
     * the 12th-stepper to call back rather than ringing them directly.
     */
    public function getOutOfHoursStart(): string
    {
        return $this->readTime('out_of_hours_start');
    }

    public function getOutOfHoursEnd(): string
    {
        return $this->readTime('out_of_hours_end');
    }

    private function readTime(string $key): string
    {
        $all = get_option(self::OPTION_PUBLIC, []);
        $value = is_array($all) && isset($all[$key]) && is_string($all[$key]) ? $all[$key] : '';
        return $this->normaliseTime($value);
    }

    /**
     * Persist the out-of-hours window. Each bound is normalised to
     * `H:i`; anything that doesn't parse as a time is stored blank,
     * which disables the window. Stored alongside the other public
     * find-page config in the same option row.
     */
    public function setOutOfHours(string $start, string $end): void
    {
        $all = get_option(self::OPTION_PUBLIC, []);
        if (!is_array($all)) {
            $all = [];
        }

        foreach (['out_of_hours_start' => $start, 'out_of_hours_end' => $end] as $key => $raw) {
            $value = $this->normaliseTime($raw);
            if ($value === '') {
                unset($all[$key]);
            } else {
                $all[$key] = $value;
            }
        }

        update_option(self::OPTION_PUBLIC, $all, false);
    }

    /**
     * Whether the given moment falls inside the configured out-of-hours
     * window, evaluated in the site's timezone.
     *
     * Returns false unless *both* bounds are set, so an unconfigured or
     * half-configured window simply disables the feature. A window whose
     * start is later than its end is treated as spanning midnight (e.g.
     * 22:00–08:00 means "22:00 through to 08:00 the next morning"). A
     * window with equal bounds is treated as empty (off) rather than
     * "always on", which is the safer default for a feature gate.
     */
    public function isOutOfHours(int $nowEpoch): bool
    {
        $start = $this->getOutOfHoursStart();
        $end   = $this->getOutOfHoursEnd();
        if ($start === '' || $end === '' || $start === $end) {
            return false;
        }

        // wp_date() renders in the site timezone; compare as HH:MM
        // strings, which sort lexicographically the same as clock order.
        $now = function_exists('wp_date') ? wp_date('H:i', $nowEpoch) : gmdate('H:i', $nowEpoch);
        if (!is_string($now)) {
            return false;
        }

        if ($start < $end) {
            // Same-day window, e.g. 09:00–17:00.
            return $now >= $start && $now < $end;
        }

        // Wraps past midnight, e.g. 22:00–08:00.
        return $now >= $start || $now < $end;
    }

    /**
     * Coerce a value to a canonical `H:i` 24-hour string, or '' if it
     * isn't a valid time. Accepts "H:i" and "H:i:s" inputs (an
     * <input type="time"> may submit either depending on step).
     */
    private function normaliseTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $value, $m)) {
            return '';
        }
        return $m[1] . ':' . $m[2];
    }

    public function setClientId(string $provider, string $value): void
    {
        $all = get_option(self::OPTION_PUBLIC, []);
        if (!is_array($all)) {
            $all = [];
        }
        $all['client_id_' . $this->normaliseProvider($provider)] = $value;
        update_option(self::OPTION_PUBLIC, $all, false);
    }

    public function getClientSecret(string $provider): string
    {
        $all = get_option(self::OPTION_SECRETS, []);
        $key = 'client_secret_' . $this->normaliseProvider($provider);
        if (!is_array($all) || !isset($all[$key]) || !is_string($all[$key])) {
            return '';
        }
        return $this->decrypt($all[$key]);
    }

    public function setClientSecret(string $provider, string $value): void
    {
        $all = get_option(self::OPTION_SECRETS, []);
        if (!is_array($all)) {
            $all = [];
        }
        $key = 'client_secret_' . $this->normaliseProvider($provider);
        if ($value === '') {
            unset($all[$key]);
        } else {
            $all[$key] = $this->encrypt($value);
        }
        update_option(self::OPTION_SECRETS, $all, false);
    }

    /**
     * Derive a 32-byte encryption key from WordPress's AUTH_KEY salt.
     * Using a salt means rotating it (a recovery action WP already
     * supports) invalidates stored secrets — which is the right
     * behaviour after a suspected DB breach.
     */
    private function key(): string
    {
        return hash('sha256', wp_salt('auth') . '|reach-secrets', true);
    }

    private function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );
        if ($ciphertext === false) {
            return '';
        }
        // Pack iv|tag|ciphertext and base64 for safe option storage.
        return base64_encode($iv . $tag . $ciphertext);
    }

    private function decrypt(string $stored): string
    {
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) < 28) {
            return '';
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        return $plaintext === false ? '' : $plaintext;
    }

    private function normaliseProvider(string $provider): string
    {
        return preg_replace('/[^a-z0-9_]/', '', strtolower($provider)) ?? '';
    }
}
