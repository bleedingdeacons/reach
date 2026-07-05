<?php

declare(strict_types=1);

namespace Reach\Core;

if (!defined('ABSPATH')) {
    exit;
}

use function get_transient;
use function set_transient;

/**
 * Fixed-window request throttle backed by WordPress transients.
 *
 * Used to cap abusive traffic to the unauthenticated auth endpoints (login,
 * request-reset) per client IP, as a coarse second layer alongside the
 * per-account lockout and per-email reset cooldown. It bounds brute-force
 * and reset-flood volume without being the sole defence.
 *
 * Caveat: the client IP is taken from REMOTE_ADDR only. That is the one
 * value a caller can't spoof, but behind a reverse proxy / CDN it may be the
 * edge's address rather than the visitor's — so limits are set generously to
 * avoid penalising many users who share an edge. Precise per-visitor limiting
 * belongs at the CDN/WAF, not here.
 */
final class RateLimiter
{
    /**
     * Record one hit for $key and report whether the caller is now over the
     * limit for the current window (and should be refused). The first hit of
     * a window seeds the counter; the transient's own TTL expires the window.
     */
    public function overLimit(string $key, int $max, int $windowSeconds): bool
    {
        $windowSeconds = max(1, $windowSeconds);
        $window = (int) floor(time() / $windowSeconds);
        $bucket = 'reach_rl_' . md5($key . '|' . $window);

        $count = (int) get_transient($bucket);
        if ($count >= $max) {
            return true;
        }

        // Keep the counter a little past the window so a burst straddling the
        // boundary is still counted.
        set_transient($bucket, $count + 1, $windowSeconds * 2);
        return false;
    }

    /**
     * The client IP from REMOTE_ADDR, or 'unknown' when absent/invalid.
     * Deliberately does not trust X-Forwarded-For (spoofable).
     */
    public function clientIp(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : 'unknown';
    }
}
