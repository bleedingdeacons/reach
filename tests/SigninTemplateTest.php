<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;

/**
 * The sign-in page's Apple flow, as rendered.
 *
 * Apple refuses a return URL its Services ID does not list, so the one
 * the page hands AppleID.auth.init() has to be this site's own sign-in
 * URL. It used to be built from window.location.origin, which on a
 * WordPress installed in a subdirectory (the /amber test bed) pointed
 * at another site's page.
 */

/** Render templates/signin.php for an iPhone visitor with Apple configured. */
function renderSigninForIphone(): string
{
    // Defined by reach.php in production, which a unit test never loads.
    defined('REACH_PLUGIN_URL') || define('REACH_PLUGIN_URL', 'https://example.test/wp-content/plugins/reach/');
    defined('REACH_VERSION') || define('REACH_VERSION', '0.0.0-test');

    WpState::$options['reach_settings'] = ['client_id_apple' => 'org.example.reach.web'];
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15';

    ob_start();
    try {
        require dirname(__DIR__) . '/templates/signin.php';
    } finally {
        $html = (string) ob_get_clean();
        unset($_SERVER['HTTP_USER_AGENT']);
    }

    return $html;
}

test('apple returns to the site\'s own sign-in url, subdirectory included', function () {
    Functions\when('home_url')->alias(
        static fn (string $path = ''): string => 'https://example.test/amber/' . ltrim($path, '/')
    );

    $html = renderSigninForIphone();

    $this->assertStringContainsString('Continue with Apple', $html);
    $this->assertStringContainsString('redirectURI: signinUrl', $html);
    $this->assertStringContainsString('var signinUrl = "https:\/\/example.test\/amber\/reach\/signin"', $html);
    $this->assertStringNotContainsString('window.location.origin', $html);
});
