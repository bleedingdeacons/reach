<?php

declare(strict_types=1);

namespace Reach\Auth;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RFC 4648 §5 base64url encode/decode helpers.
 *
 * The encoding differs from standard base64 in two ways: `+` and `/`
 * are replaced with `-` and `_` respectively, and trailing `=` padding
 * is omitted. This is the form used in JWTs, OAuth/OIDC PKCE
 * challenges, and most other web-token contexts — including the
 * HMAC-signed cookie body Reach uses for sessions, the JWKS modulus
 * and exponent in RS256 verification, the attempt-token signature
 * binding viewer to member, and the Microsoft ID-token preview parse.
 *
 * Pre-1.x there were four near-identical copies of these helpers, one
 * per class that happened to need them. They differed only in error-
 * return type (empty string vs null on failure) and method name
 * (`base64UrlDecode` vs `b64urlDecode`). This trait is the single
 * source of truth; the wrapper method per failure-mode shape is one
 * line each so existing callers keep their preferred return type
 * without an extra null/empty-string check at every call site.
 */
trait Base64Url
{
    /**
     * Encode binary input as a base64url string.
     */
    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Decode a base64url string. Returns null on decoding failure —
     * use this form when "did this even decode" is a meaningful
     * failure mode the caller wants to handle distinctly from "the
     * decoded value happens to be empty".
     */
    protected function base64UrlDecodeOrNull(string $data): ?string
    {
        $pad = strlen($data) % 4;
        if ($pad > 0) {
            $data .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }

    /**
     * Decode a base64url string. Returns the empty string on failure —
     * use this form when the caller treats failure the same as "valid
     * but empty input", which is the common case for JWT components
     * where downstream parsing will fail either way.
     */
    protected function base64UrlDecode(string $data): string
    {
        return $this->base64UrlDecodeOrNull($data) ?? '';
    }
}
