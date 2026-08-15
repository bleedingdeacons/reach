<?php

declare(strict_types=1);

namespace Reach\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AES-256-GCM encryption for data held at rest.
 *
 * The same construction {@see Settings} uses for OAuth client secrets —
 * a key derived from a WordPress salt, a random IV per value, and the
 * authentication tag packed alongside the ciphertext — extracted so
 * something other than the settings store can use it.
 *
 * Each caller passes its own `$domain`, which is mixed into the key.
 * That means a value encrypted for one purpose cannot be decrypted as
 * another even though both derive from the same salt, so a bug that
 * crossed the two would fail closed rather than quietly succeed.
 *
 * Rotating `wp_salt('auth')` makes every stored value undecryptable.
 * That is the intended behaviour after a suspected breach, and it is
 * why the things encrypted this way are all recoverable by other means
 * — a re-entered credential, a re-sent alert.
 */
final class Cipher
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    public function __construct(private readonly string $domain)
    {
    }

    /**
     * Encrypt a value, returning base64 of iv|tag|ciphertext. Returns ''
     * if encryption fails, which the caller must treat as "do not
     * store" rather than as an empty value.
     */
    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_BYTES,
        );

        if ($ciphertext === false) {
            return '';
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt a value produced by {@see encrypt()}. Returns '' for
     * anything that does not decrypt — malformed input, a rotated salt,
     * or a tampered ciphertext, which GCM detects rather than returning
     * garbage.
     */
    public function decrypt(string $stored): string
    {
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) < self::IV_BYTES + self::TAG_BYTES) {
            return '';
        }

        $iv         = substr($raw, 0, self::IV_BYTES);
        $tag        = substr($raw, self::IV_BYTES, self::TAG_BYTES);
        $ciphertext = substr($raw, self::IV_BYTES + self::TAG_BYTES);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        return $plaintext === false ? '' : $plaintext;
    }

    private function key(): string
    {
        return hash('sha256', wp_salt('auth') . '|' . $this->domain, true);
    }
}
