<?php

declare(strict_types=1);

namespace Reach\Auth;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides whether a visitor is on an Apple device, so the sign-in page
 * offers "Sign in with Apple" only where it belongs.
 *
 * On an iPhone, iPad or Mac the Apple ID is already on the device and
 * the button is one Face ID / Touch ID away. Anywhere else it means
 * typing an Apple ID and password into a popup and approving the code
 * on another device — the other providers and the password form are
 * the better offer there, so the button is left out.
 *
 * Detection is by user-agent. That is enough here because nothing is
 * being secured: the Apple endpoints stay open to every device, and a
 * visitor whose browser hides its platform simply sees the other
 * options. The sign-in page is sent `no-store` (PageRouter), so a
 * shared cache never serves one device's variant to another.
 *
 * iPadOS Safari reports a desktop "Macintosh" user-agent by default.
 * Matching Macs therefore covers iPads too, with no touch sniffing.
 */
final class AppleDevice
{
    /**
     * Tokens that only Apple platforms put in a user-agent. iPhone and
     * iPad browsers (Safari, and Chrome/Firefox/Edge on iOS, which all
     * run WebKit) carry their device name; macOS browsers, and iPadOS
     * and visionOS Safari in their default desktop mode, carry
     * "Macintosh".
     */
    private const PATTERN = '/\b(?:iPhone|iPad|iPod|Macintosh)\b/i';

    /**
     * Is this user-agent string from an Apple device?
     */
    public static function matches(string $userAgent): bool
    {
        return preg_match(self::PATTERN, $userAgent) === 1;
    }

    /**
     * Is the current request from an Apple device?
     */
    public static function isCurrentRequest(): bool
    {
        $userAgent = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])
            ? $_SERVER['HTTP_USER_AGENT']
            : '';

        return self::matches($userAgent);
    }
}
