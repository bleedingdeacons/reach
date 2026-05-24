<?php

declare(strict_types=1);

namespace Reach\Auth;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Verify RS256-signed ID tokens against a provider's JWKS endpoint.
 *
 * Used by:
 *   - Google provider, on the server side after the code exchange
 *     (the token endpoint returns an `id_token` we verify before
 *     trusting any claims in it).
 *   - Apple provider, when the browser POSTs the ID token straight
 *     from Apple's JS SDK — we have no code to exchange in that flow,
 *     so signature verification *is* the trust anchor.
 *
 * Microsoft Entra v2.0 also issues RS256 ID tokens; the same verifier
 * works there with a different JWKS URL.
 *
 * Implementation notes
 * --------------------
 * The verifier is intentionally narrow: it accepts only the RS256
 * algorithm and reads the signing key out of the JWKS keyed by `kid`.
 * The "none" algorithm and any HMAC algorithms (which would let a
 * crafted token claim to be signed by the symmetric value of the
 * public key) are rejected outright. JWKS responses are cached in a
 * transient for an hour, which is short enough to pick up provider
 * key rotation within a working day and long enough to keep latency
 * off every sign-in.
 *
 * Both `exp` and `iat` are *required*: a token without an expiry
 * would otherwise verify forever, and a token without an issued-at
 * timestamp can't be sanity-checked against clock skew. OIDC requires
 * both for ID tokens, so the only tokens this would reject are ones
 * that wouldn't be RFC-compliant anyway.
 *
 * The verifier does *not* fetch JWKS via web_fetch or any third-party
 * library — wp_remote_get is enough and keeps the dependency surface
 * to zero, matching the rest of the stack.
 */
final class JwtVerifier
{
    use \Reach\Logger\HasLogger;
    use Base64Url;

    protected static function logChannel(): string
    {
        return 'reach';
    }

    private const JWKS_CACHE_PREFIX = 'reach_jwks_';
    private const JWKS_CACHE_TTL = HOUR_IN_SECONDS;
    private const HTTP_TIMEOUT = 5;

    /** Tolerance for clock skew when checking `iat`/`exp`. */
    private const CLOCK_SKEW_SECONDS = 60;

    /**
     * Verify a JWT and return its claims, or null on any failure.
     *
     * @param string $jwt             The compact-serialised JWT.
     * @param string $jwksUrl         The provider's JWKS endpoint.
     * @param string $expectedIssuer  The exact `iss` claim required.
     * @param string $expectedAudience The exact `aud` claim required (your client id).
     * @param string|null $expectedNonce If non-null, the token must carry this nonce.
     *
     * @return array<string, mixed>|null
     */
    public function verify(
        string $jwt,
        string $jwksUrl,
        string $expectedIssuer,
        string $expectedAudience,
        ?string $expectedNonce = null
    ): ?array {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        [$header64, $payload64, $signature64] = $parts;

        $header = json_decode($this->base64UrlDecode($header64), true);
        $payload = json_decode($this->base64UrlDecode($payload64), true);
        if (!is_array($header) || !is_array($payload)) {
            return null;
        }

        // Algorithm must be RS256 — never trust 'none' or HMAC.
        if (($header['alg'] ?? null) !== 'RS256') {
            self::logWarning('JWT: rejected algorithm', ['alg' => $header['alg'] ?? null]);
            return null;
        }

        $kid = $header['kid'] ?? null;
        if (!is_string($kid) || $kid === '') {
            return null;
        }

        $jwk = $this->findKey($jwksUrl, $kid);
        if ($jwk === null) {
            // Cache miss for a fresh key — refetch once with cache busted.
            $jwk = $this->findKey($jwksUrl, $kid, forceRefresh: true);
            if ($jwk === null) {
                self::logWarning('JWT: no matching key', ['kid' => $kid, 'jwks' => $jwksUrl]);
                return null;
            }
        }

        $publicKey = $this->jwkToPem($jwk);
        if ($publicKey === null) {
            return null;
        }

        $signature = $this->base64UrlDecode($signature64);
        $signed = $header64 . '.' . $payload64;
        $ok = openssl_verify($signed, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            self::logWarning('JWT: signature verification failed');
            return null;
        }

        // Claim checks.
        $now = time();

        // Both exp and iat are mandatory. OIDC requires them on ID
        // tokens; a token without exp would otherwise verify forever,
        // and a missing iat can't be skew-checked against the future.
        if (!isset($payload['exp']) || !is_numeric($payload['exp'])) {
            self::logWarning('JWT: missing or non-numeric exp claim');
            return null;
        }
        if (!isset($payload['iat']) || !is_numeric($payload['iat'])) {
            self::logWarning('JWT: missing or non-numeric iat claim');
            return null;
        }
        if ($now > ((int) $payload['exp'] + self::CLOCK_SKEW_SECONDS)) {
            return null;
        }
        if (((int) $payload['iat'] - self::CLOCK_SKEW_SECONDS) > $now) {
            return null;
        }
        if (($payload['iss'] ?? null) !== $expectedIssuer) {
            return null;
        }

        // aud may be a string or an array of strings.
        $aud = $payload['aud'] ?? null;
        $audMatches = is_string($aud)
            ? $aud === $expectedAudience
            : (is_array($aud) && in_array($expectedAudience, $aud, true));
        if (!$audMatches) {
            return null;
        }

        if ($expectedNonce !== null && ($payload['nonce'] ?? null) !== $expectedNonce) {
            return null;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findKey(string $jwksUrl, string $kid, bool $forceRefresh = false): ?array
    {
        $cacheKey = self::JWKS_CACHE_PREFIX . md5($jwksUrl);
        if ($forceRefresh) {
            delete_transient($cacheKey);
        }

        $jwks = get_transient($cacheKey);
        if (!is_array($jwks)) {
            $jwks = $this->fetchJwks($jwksUrl);
            if ($jwks === null) {
                return null;
            }
            set_transient($cacheKey, $jwks, self::JWKS_CACHE_TTL);
        }

        foreach ($jwks['keys'] ?? [] as $key) {
            if (is_array($key) && ($key['kid'] ?? null) === $kid) {
                return $key;
            }
        }
        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchJwks(string $url): ?array
    {
        $response = wp_remote_get($url, [
            'timeout' => self::HTTP_TIMEOUT,
            'headers' => ['Accept' => 'application/json'],
        ]);
        if (is_wp_error($response)) {
            self::logWarning('JWKS fetch error', ['url' => $url, 'error' => $response->get_error_message()]);
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Convert a JWK (RSA, n + e) into a PEM-formatted public key OpenSSL
     * can consume. Manual DER encoding rather than a library so we keep
     * the dependency footprint at zero.
     *
     * @param array<string, mixed> $jwk
     */
    private function jwkToPem(array $jwk): ?string
    {
        if (($jwk['kty'] ?? null) !== 'RSA') {
            return null;
        }
        $n = $this->base64UrlDecode((string) ($jwk['n'] ?? ''));
        $e = $this->base64UrlDecode((string) ($jwk['e'] ?? ''));
        if ($n === '' || $e === '') {
            return null;
        }

        // DER-encode SubjectPublicKeyInfo for RSA.
        $modulus = $this->derInteger($n);
        $exponent = $this->derInteger($e);
        $rsaPublicKey = $this->derSequence($modulus . $exponent);
        // OID for rsaEncryption + NULL parameters.
        $algorithmIdentifier = $this->derSequence(
            "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" . "\x05\x00"
        );
        $subjectPublicKey = $this->derBitString($rsaPublicKey);
        $spki = $this->derSequence($algorithmIdentifier . $subjectPublicKey);

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";

        return $pem;
    }

    private function derInteger(string $bytes): string
    {
        // RSA integers are unsigned; prefix a 0x00 byte if the high bit is set
        // so the DER INTEGER isn't interpreted as negative.
        if ($bytes !== '' && (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . $this->derLength(strlen($bytes)) . $bytes;
    }

    private function derSequence(string $contents): string
    {
        return "\x30" . $this->derLength(strlen($contents)) . $contents;
    }

    private function derBitString(string $contents): string
    {
        // Leading byte is the count of unused bits in the final byte (always 0 here).
        $contents = "\x00" . $contents;
        return "\x03" . $this->derLength(strlen($contents)) . $contents;
    }

    private function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}
