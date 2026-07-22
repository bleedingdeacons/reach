<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\Core\Settings;

/**
 * Cover the parts of {@see Settings} the existing SettingsTest / out-of-hours
 * test don't: the AES-256-GCM encryption of OAuth client secrets, the place
 * bias, and the call-request notification address. The secret round-trip is
 * the security-relevant one — a database dump must never yield a usable
 * client secret, so the stored value must be ciphertext and a corrupted or
 * wrong-key blob must decrypt to the empty string rather than leaking
 * anything or erroring.
 */
final class SettingsSecretsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__reach_options'] = [];
    }

    public function testClientSecretRoundTripsThroughEncryption(): void
    {
        $settings = new Settings();
        $settings->setClientSecret('google', 'super-secret-value');

        $this->assertSame('super-secret-value', $settings->getClientSecret('google'));
    }

    public function testStoredClientSecretIsNotPlaintext(): void
    {
        $settings = new Settings();
        $settings->setClientSecret('google', 'super-secret-value');

        // Inspect the raw option row: the plaintext must not appear anywhere.
        $stored = $GLOBALS['__reach_options'][Settings::OPTION_SECRETS] ?? [];
        $raw = json_encode($stored);
        $this->assertStringNotContainsString('super-secret-value', (string) $raw);
        $this->assertNotSame('', $stored['client_secret_google'] ?? '');
    }

    public function testEmptySecretRemovesTheStoredKey(): void
    {
        $settings = new Settings();
        $settings->setClientSecret('google', 'value');
        $settings->setClientSecret('google', '');

        $this->assertSame('', $settings->getClientSecret('google'));
        $this->assertArrayNotHasKey('client_secret_google', $GLOBALS['__reach_options'][Settings::OPTION_SECRETS] ?? []);
    }

    public function testUnsetSecretReturnsEmptyString(): void
    {
        $this->assertSame('', (new Settings())->getClientSecret('microsoft'));
    }

    public function testCorruptedCiphertextDecryptsToEmptyStringNotAnError(): void
    {
        // A too-short / garbage blob must be rejected by the length guard and
        // return '' rather than emitting an openssl warning or partial data.
        $GLOBALS['__reach_options'][Settings::OPTION_SECRETS] = [
            'client_secret_google' => base64_encode('too-short'),
        ];

        $this->assertSame('', (new Settings())->getClientSecret('google'));
    }

    public function testSecretIsUnreadableAfterTheSaltRotates(): void
    {
        $settings = new Settings();
        $settings->setClientSecret('google', 'value');

        // Rotating the auth salt (a WP recovery action) changes the derived
        // key, so previously stored secrets no longer decrypt — the intended
        // behaviour after a suspected breach.
        $GLOBALS['__reach_salts']['auth'] = 'rotated-salt-' . str_repeat('z', 48);

        $this->assertSame('', $settings->getClientSecret('google'));

        // Restore for other tests sharing the process.
        $GLOBALS['__reach_salts']['auth'] = 'test-auth-salt-' . str_repeat('x', 48);
    }

    public function testProviderNameIsNormalisedForBothIdAndSecret(): void
    {
        $settings = new Settings();
        $settings->setClientId('Google!!', 'id-123');
        $settings->setClientSecret('Google!!', 'secret-123');

        // Normalisation strips non [a-z0-9_] so the mixed-case/punctuated
        // form resolves to the same key as the clean one.
        $this->assertSame('id-123', $settings->getClientId('google'));
        $this->assertSame('secret-123', $settings->getClientSecret('google'));
    }

    // --- place bias -------------------------------------------------------

    public function testPlaceBiasSetGetAndClear(): void
    {
        $settings = new Settings();
        $this->assertSame('', $settings->getPlaceBias());

        $settings->setPlaceBias('  BS5  ');
        $this->assertSame('BS5', $settings->getPlaceBias());

        $settings->setPlaceBias('   ');
        $this->assertSame('', $settings->getPlaceBias());
    }

    // --- call-request email ----------------------------------------------

    public function testCallRequestEmailFallsBackToAdminEmailWhenUnset(): void
    {
        $GLOBALS['__reach_options']['admin_email'] = 'admin@example.com';
        $this->assertSame('admin@example.com', (new Settings())->getCallRequestEmail());
    }

    public function testCallRequestEmailStoredAndReturnedWhenValid(): void
    {
        $settings = new Settings();
        $settings->setCallRequestEmail('ops@example.com');
        $this->assertSame('ops@example.com', $settings->getCallRequestEmail());
    }

    public function testInvalidCallRequestEmailIsStoredBlankAndFallsBack(): void
    {
        $GLOBALS['__reach_options']['admin_email'] = 'admin@example.com';
        $settings = new Settings();
        $settings->setCallRequestEmail('not-an-email');

        // Invalid input is not stored, so the getter falls back to admin.
        $this->assertSame('admin@example.com', $settings->getCallRequestEmail());
        $this->assertArrayNotHasKey('call_request_email', $GLOBALS['__reach_options'][Settings::OPTION_PUBLIC] ?? []);
    }
}
