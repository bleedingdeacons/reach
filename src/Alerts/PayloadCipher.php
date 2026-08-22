<?php

declare(strict_types=1);

namespace Reach\Alerts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Encrypts an alert's readable text to one handset's own key.
 *
 * <b>Why this is not {@see \Reach\Core\Cipher}.</b> That one derives its
 * key from a WordPress salt, which is exactly right for data this site
 * encrypts to itself and reads back — settings, alert contacts, the
 * stored copy of the very key used here. This encrypts to a secret the
 * *handset* holds, so the key arrives as an argument and the site has no
 * standing ability to read the result. Same construction, opposite
 * direction, and folding them together would mean one class where the
 * key source is a mode flag.
 *
 * <b>What is encrypted, and what is not.</b> Only the parts a person
 * could read: the title, the body, and the reference. Everything else in
 * the push has to survive in the clear because the handset needs it
 * before it can decrypt anything — the alert id it will acknowledge
 * against, the kind (so a removal notice never reaches the alarm), the
 * priority, the expiry it checks before ringing, and the channel and
 * sound it builds the notification from. None of those name a person.
 *
 * <b>Size.</b> FCM caps a data message at 4KB.
 * {@see AlertRequest} already caps a title at 200 bytes, a body at 1000
 * and a reference at 64, and encryption adds an envelope plus base64 —
 * roughly a third. That turns 1264 bytes of worst-case text into about
 * 1770, so the worst-case message grows by around 500 bytes and stays
 * inside the cap. It is not a large margin. A future field added to the
 * encrypted blob should be measured rather than assumed.
 */
final class PayloadCipher
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    /**
     * Encrypt the readable fields as one JSON blob.
     *
     * $base64Key is the handset's key exactly as enrolment issued it.
     * Returns '' when it is unusable or the cipher refuses — a caller
     * must read that as "send this one in the clear or not at all",
     * never as an empty payload.
     */
    public static function seal(Alert $alert, string $base64Key): string
    {
        $key = base64_decode($base64Key, true);
        if (!is_string($key) || strlen($key) !== 32) {
            return '';
        }

        $json = wp_json_encode([
            'title'     => $alert->title,
            'body'      => $alert->body,
            'reference' => $alert->reference,
        ]);

        if (!is_string($json)) {
            return '';
        }

        $iv = random_bytes(self::IV_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $json,
            self::CIPHER,
            $key,
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
}
