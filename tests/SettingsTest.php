<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\Core\Settings;

/**
 * The Settings class is the only place outside `wp-config.php` where
 * an OAuth client secret can be coerced back to plaintext. These
 * tests cover the only behaviours that matter to an external caller:
 * round-trip, salt-rotation invalidation, and the empty-write
 * semantics expected by the admin page (empty input must not clobber
 * an existing secret silently — that responsibility sits in the
 * admin form, but the Settings primitive needs to support either
 * choice, and we test the simple cases here).
 */
final class SettingsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__reach_options'] = [];
    }

    public function testClientIdRoundTrip(): void
    {
        $settings = new Settings();
        $settings->setClientId('google', 'abc123.apps.googleusercontent.com');
        $this->assertSame('abc123.apps.googleusercontent.com', $settings->getClientId('google'));
    }

    public function testClientSecretRoundTrip(): void
    {
        $settings = new Settings();
        $settings->setClientSecret('google', 'a-very-secret-string');
        $this->assertSame('a-very-secret-string', $settings->getClientSecret('google'));
    }

    public function testStoredSecretIsCiphertext(): void
    {
        $settings = new Settings();
        $settings->setClientSecret('google', 'plaintext-secret');

        $raw = $GLOBALS['__reach_options'][Settings::OPTION_SECRETS]['client_secret_google'];
        $this->assertNotSame('plaintext-secret', $raw);
        $this->assertStringNotContainsString('plaintext-secret', base64_decode($raw, true) ?: '');
    }

    public function testRotatingSaltInvalidatesSecret(): void
    {
        $settings = new Settings();
        $settings->setClientSecret('google', 'plaintext-secret');

        // Rotate the auth salt — simulating an admin action.
        $GLOBALS['__reach_salts']['auth'] = 'new-auth-salt-' . str_repeat('z', 48);

        // Decryption with the new key must fail and return empty,
        // never raise — admins shouldn't see a fatal on a salt rotation.
        $this->assertSame('', $settings->getClientSecret('google'));
    }

    public function testEmptySecretDeletesEntry(): void
    {
        $settings = new Settings();
        $settings->setClientSecret('google', 'something');
        $this->assertNotEmpty($settings->getClientSecret('google'));

        $settings->setClientSecret('google', '');
        $this->assertSame('', $settings->getClientSecret('google'));
        $this->assertArrayNotHasKey(
            'client_secret_google',
            $GLOBALS['__reach_options'][Settings::OPTION_SECRETS] ?? []
        );
    }

    public function testRequireCapabilityToggleDefaultsOff(): void
    {
        $settings = new Settings();
        $this->assertFalse($settings->requireScrutinyCapability());
        $settings->setRequireScrutinyCapability(true);
        $this->assertTrue($settings->requireScrutinyCapability());
    }
}
