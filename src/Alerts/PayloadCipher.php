<?php

declare(strict_types=1);

namespace Reach\Alerts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Encrypts a push payload to one handset's own key.
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
 * <b>What is encrypted: everything.</b> This used to seal the title, the
 * body and the reference, and let the rest travel in the clear on the
 * grounds that the handset needed it before it could decrypt anything —
 * the alert id, the kind, the priority, the channel and sound. It does
 * not need any of them first. It holds one key, it opens one blob, and
 * what is inside tells it what it has.
 *
 * The reason to close that gap is that "an alert names nobody" is a
 * convention rather than a property. {@see AlertRequest} caps lengths
 * and strips markup; it does not read meaning, and it cannot — detecting
 * personal data in free text is unreliable in both directions, and a
 * false positive silences a real 3am alert. So nothing is left readable
 * to be careless with, and validity becomes "does it decrypt and parse
 * as JSON" rather than "does its content look sensitive".
 *
 * <b>Compressed first, and this is load-bearing.</b> FCM caps a data
 * message at 4KB. The worst case {@see AlertRequest}'s own caps allow —
 * title 200, body 1000, reference 64, payload 2000, plus the fixed
 * fields — is 3433 bytes of JSON, which seals and base64s to 4616:
 * over the limit. Without the gzip, the largest alerts the API accepts
 * would be accepted and then silently fail to send.
 *
 * How much the gzip buys depends entirely on the text. Prose and
 * references compress hard — the same worst case built from repeated
 * characters comes to 248 bytes — but content that does not compress at
 * all still seals to 2724, and that is the figure the margin should be
 * read against. It is comfortable rather than generous, and a future
 * field added to the payload should be measured rather than assumed.
 *
 * The envelope is unchanged from when this sealed three fields — 12-byte
 * nonce, 16-byte tag, ciphertext, base64 — so the Hand side's framing is
 * the same and only what is inside it moved.
 */
final class PayloadCipher
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    /**
     * Encrypt one map as a single blob.
     *
     * $base64Key is the handset's key exactly as enrolment issued it.
     * Returns '' when it is unusable or the cipher refuses — a caller
     * must read that as "this handset cannot be sent to", never as an
     * empty payload. {@see Transport\FcmTransport::dataFor()} is where
     * that refusal is turned into a logged error.
     *
     * @param array<string, string> $data
     */
    public static function seal(array $data, string $base64Key): string
    {
        $key = base64_decode($base64Key, true);
        if (!is_string($key) || strlen($key) !== 32) {
            return '';
        }

        $json = wp_json_encode($data);

        if (!is_string($json)) {
            return '';
        }

        $compressed = gzencode($json);

        if (!is_string($compressed)) {
            return '';
        }

        $iv = random_bytes(self::IV_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $compressed,
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
