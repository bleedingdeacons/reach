<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
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

beforeEach(function () {
    WpState::$options = [];
});

test('client secret round trips through encryption', function () {
    $settings = new Settings();
    $settings->setClientSecret('google', 'super-secret-value');

    $this->assertSame('super-secret-value', $settings->getClientSecret('google'));
});

test('stored client secret is not plaintext', function () {
    $settings = new Settings();
    $settings->setClientSecret('google', 'super-secret-value');

    // Inspect the raw option row: the plaintext must not appear anywhere.
    $stored = WpState::$options[Settings::OPTION_SECRETS] ?? [];
    $raw = json_encode($stored);
    $this->assertStringNotContainsString('super-secret-value', (string) $raw);
    $this->assertNotSame('', $stored['client_secret_google'] ?? '');
});

test('empty secret removes the stored key', function () {
    $settings = new Settings();
    $settings->setClientSecret('google', 'value');
    $settings->setClientSecret('google', '');

    $this->assertSame('', $settings->getClientSecret('google'));
    $this->assertArrayNotHasKey('client_secret_google', WpState::$options[Settings::OPTION_SECRETS] ?? []);
});

test('unset secret returns empty string', function () {
    $this->assertSame('', (new Settings())->getClientSecret('microsoft'));
});

test('corrupted ciphertext decrypts to empty string not an error', function () {
    // A too-short / garbage blob must be rejected by the length guard and
    // return '' rather than emitting an openssl warning or partial data.
    WpState::$options[Settings::OPTION_SECRETS] = [
        'client_secret_google' => base64_encode('too-short'),
    ];

    $this->assertSame('', (new Settings())->getClientSecret('google'));
});

test('secret is unreadable after the salt rotates', function () {
    $settings = new Settings();
    $settings->setClientSecret('google', 'value');

    // Rotating the auth salt (a WP recovery action) changes the derived
    // key, so previously stored secrets no longer decrypt — the intended
    // behaviour after a suspected breach.
    $this->salts['auth'] = 'rotated-salt-' . str_repeat('z', 48);

    $this->assertSame('', $settings->getClientSecret('google'));
});

test('provider name is normalised for both id and secret', function () {
    $settings = new Settings();
    $settings->setClientId('Google!!', 'id-123');
    $settings->setClientSecret('Google!!', 'secret-123');

    // Normalisation strips non [a-z0-9_] so the mixed-case/punctuated
    // form resolves to the same key as the clean one.
    $this->assertSame('id-123', $settings->getClientId('google'));
    $this->assertSame('secret-123', $settings->getClientSecret('google'));
});

// --- place bias -------------------------------------------------------
test('place bias set get and clear', function () {
    $settings = new Settings();
    $this->assertSame('', $settings->getPlaceBias());

    $settings->setPlaceBias('  BS5  ');
    $this->assertSame('BS5', $settings->getPlaceBias());

    $settings->setPlaceBias('   ');
    $this->assertSame('', $settings->getPlaceBias());
});

// --- call-request email ----------------------------------------------
test('call request email falls back to admin email when unset', function () {
    WpState::$options['admin_email'] = 'admin@example.com';
    $this->assertSame('admin@example.com', (new Settings())->getCallRequestEmail());
});

test('call request email stored and returned when valid', function () {
    $settings = new Settings();
    $settings->setCallRequestEmail('ops@example.com');
    $this->assertSame('ops@example.com', $settings->getCallRequestEmail());
});

test('invalid call request email is stored blank and falls back', function () {
    WpState::$options['admin_email'] = 'admin@example.com';
    $settings = new Settings();
    $settings->setCallRequestEmail('not-an-email');

    // Invalid input is not stored, so the getter falls back to admin.
    $this->assertSame('admin@example.com', $settings->getCallRequestEmail());
    $this->assertArrayNotHasKey('call_request_email', WpState::$options[Settings::OPTION_PUBLIC] ?? []);
});
