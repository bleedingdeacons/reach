<?php

declare(strict_types=1);

namespace Reach\Auth;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classify whether an email address is a provider-anonymised relay
 * that we don't trust as a permanent contact address.
 *
 * Facebook hands out addresses on `privaterelay.facebook.com` (and
 * other `*.facebook.com` subdomains depending on flow) when the user
 * declines to share their real one — and unlike Apple, Facebook does
 * not actually maintain a working forwarder behind these. We treat
 * them as "we know who you are on Facebook but we don't have an email
 * for you yet".
 *
 * Apple's privaterelay.appleid.com is deliberately *not* on this list:
 * Apple really does forward email to the user's real inbox, so a
 * relay address from Apple is genuinely contactable and Reach accepts
 * it as the authorisation address.
 *
 * Reach treats a hit here as a useful signal but not as the
 * authoritative authorisation address: the controller, on seeing a
 * relay, sends the user to a small form to type the real address
 * they want to be reachable on. The relay is still kept on the
 * session as `providerEmail` so the audit trail can connect "Facebook
 * said X" to "user told us they're at Y".
 *
 * The matching is deliberately broad: anything ending in
 * `facebook.com` is treated as anonymised. A real user's personal
 * address will essentially never live under that domain — Facebook
 * doesn't host mail for end users — so a false positive is vanishingly
 * unlikely, and a false negative (user accepted as authorised on a
 * relay address) is the case we're specifically trying to avoid.
 */
final class AnonymisedEmailDetector
{
    /**
     * Domains that we always treat as anonymised on their own merits.
     * Listed as case-insensitive suffixes (matched on the part after `@`).
     */
    private const RELAY_SUFFIXES = [
        'facebook.com',
    ];

    public static function isAnonymised(string $email): bool
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }
        $domain = strtolower(substr($email, $at + 1));
        if ($domain === '') {
            return false;
        }
        foreach (self::RELAY_SUFFIXES as $suffix) {
            if ($domain === $suffix || str_ends_with($domain, '.' . $suffix)) {
                return true;
            }
        }
        return false;
    }
}
