<?php

declare(strict_types=1);

namespace Reach\CallAttempts;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Auth\Base64Url;

use function wp_salt;

/**
 * Issues and verifies short-lived tokens that bind a (viewer, member)
 * pair so a Reach user can only log an attempt against a member who
 * was actually shown to *them*.
 *
 * Why this exists
 * ---------------
 * Without a token, anyone with a Reach session could POST
 * /reach/v1/call-attempts with any member_id and any outcome,
 * skewing the responsiveness signal for members they've never
 * actually contacted. The token is the cheap stand-in for a "did the
 * server hand this member to this viewer?" check that would otherwise
 * need a stateful per-session list.
 *
 * Shape
 * -----
 * The token is `payload_b64.hmac_b64` where the payload is a tiny
 * JSON blob: viewer email (lowercased), member id, issued-at. HMAC
 * is SHA-256 keyed by wp_salt('auth') — same scheme used elsewhere
 * in Reach for cookie signing. 12-hour TTL: long enough for the
 * typical "look someone up, try calling, mark the outcome" loop, not
 * so long that a stale token from a previous session is useful.
 *
 * Not for security-critical decisions
 * -----------------------------------
 * This is throttling/integrity, not privacy. The token doesn't grant
 * access to anything that wasn't already public to the session. Its
 * sole job is to make outcome-stuffing require active cooperation
 * with the find-page rather than being a one-line cURL command.
 */
final class AttemptTokenMinter
{
    use Base64Url;

    public const TTL_SECONDS = 43200; // 12 hours

    /**
     * Generate a token binding (viewer, member) together.
     */
    public function mint(string $viewerEmail, int $memberId, int $now): string
    {
        $payload = [
            'v' => strtolower(trim($viewerEmail)),
            'm' => $memberId,
            't' => $now,
        ];
        $payloadB64 = $this->base64UrlEncode((string) json_encode($payload));
        $sig = $this->sign($payloadB64);
        return $payloadB64 . '.' . $sig;
    }

    /**
     * Decode and verify a token against the requesting viewer and the
     * member id they're trying to log against. Returns true only when
     * the signature is valid, the payload parses, the bound viewer
     * matches, the bound member matches, and the token is within TTL.
     *
     * Returns false rather than throwing because the caller turns a
     * false into a 403 either way — no information is gained from the
     * specific failure reason, and silence makes brute-force probing
     * less informative.
     */
    public function verify(string $token, string $viewerEmail, int $memberId, int $now): bool
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$payloadB64, $sig] = $parts;

        $expected = $this->sign($payloadB64);
        if (!hash_equals($expected, $sig)) {
            return false;
        }

        $json = $this->base64UrlDecodeOrNull($payloadB64);
        if ($json === null) {
            return false;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['v'], $data['m'], $data['t'])) {
            return false;
        }

        $boundViewer = strtolower(trim($viewerEmail));
        if ((string) $data['v'] !== $boundViewer) {
            return false;
        }
        if ((int) $data['m'] !== $memberId) {
            return false;
        }
        if ($now - (int) $data['t'] > self::TTL_SECONDS) {
            return false;
        }
        if ((int) $data['t'] > $now + 60) {
            // Clock skew indulgence is fine; future-dated by more than
            // a minute is almost certainly a forged token.
            return false;
        }
        return true;
    }

    private function sign(string $payloadB64): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $payloadB64, wp_salt('auth'), true));
    }
}
