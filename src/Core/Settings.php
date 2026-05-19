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
