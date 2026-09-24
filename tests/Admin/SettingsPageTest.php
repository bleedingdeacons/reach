<?php

declare(strict_types=1);

namespace Reach\Tests\Admin;

use function Brain\Monkey\Functions\when;
use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use BleedingDeacons\WpMocks\WpState;
use Reach\Admin\SettingsPage;
use Reach\Core\Settings;
use ReflectionMethod;
use Scrutiny\Privacy\PersonalDataPolicy;

/**
 * Tests for the Reach settings screen.
 *
 * The screen is the odd one out in this directory twice over. It is the only
 * one gated on `manage_options` rather than
 * {@see PersonalDataPolicy::VIEW_CAPABILITY} — deliberately stricter than the
 * parent menu, because OAuth credentials are not personal data but are worth
 * more — and it is the only one that writes anything.
 *
 * handleSave() ends `wp_safe_redirect(); exit;`, and the stubs record
 * redirects rather than throwing, so the exit would take the test runner with
 * it. The form-parsing body it used to hold inline now lives in the private
 * saveFromRequest(); the guard above it is still driven through the public
 * method, because wp_die() throws. That split is the only production change
 * here and it is behaviour-identical.
 *
 * Secrets are the interesting part of the save, and all three of their rules
 * are asserted by reading the value back out of {@see Settings} — through the
 * real AES-256-GCM round trip, not a recorded call: a new value replaces, a
 * blank one keeps what is stored, and only the explicit remove checkbox
 * clears. The form itself must never send a secret back to the browser.
 */

covers(\Reach\Admin\SettingsPage::class);

beforeEach(function () {
    // ── helpers ───────────────────────────────────────────────────────
    $this->save = function (): void {
        (new ReflectionMethod(SettingsPage::class, 'saveFromRequest'))->invoke($this->page);
    };

    $this->render = function (): string {
        ob_start();
        try {
            $this->page->render();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    };

    $_GET = [];
    $_POST = [];

    $this->settings = new Settings();
    $this->page = new SettingsPage($this->settings);

    // Not part of the shared WordPress stub set — it lives in wp-admin.
    when('submit_button')->alias(static function (): void {
        echo '<button type="submit" class="button button-primary">Save Changes</button>';
    });
});

afterEach(function () {
    $_GET = [];
    $_POST = [];
});

// ── registration ──────────────────────────────────────────────────
test('register hooks the menu the init and the save handler', function () {
    $this->page->register();

    foreach (['admin_menu', 'admin_init', 'admin_post_reach_save_settings'] as $hook) {
        $this->assertActionAdded($hook, false, 'expected ' . $hook . ' to be hooked');
    }
});

/**
 * Stricter than the menu it hangs off: a user who can see Reach's
 * personal-data screens but cannot manage options simply never sees this
 * item, which is WordPress doing the hiding for us.
 */
test('add menu attaches under reach behind manage options', function () {
    $this->page->addMenu();

    $this->assertCount(1, WpState::$menus);
    $this->assertSame('submenu', WpState::$menus[0]['type']);
    $this->assertSame('reach', WpState::$menus[0]['parent']);
    $this->assertSame('reach-settings', WpState::$menus[0]['slug']);
    $this->assertSame('manage_options', WpState::$menus[0]['cap']);
    $this->assertNotSame(PersonalDataPolicy::VIEW_CAPABILITY, WpState::$menus[0]['cap']);
});

/**
 * registerSettings() is deliberately empty: the secret fields need merge
 * logic ("empty means don't change") that the Settings API has no way to
 * express, so the save goes through admin-post.php instead. Asserting the
 * emptiness is what stops someone quietly reintroducing register_setting()
 * and with it a code path that overwrites a stored secret with a blank.
 */
test('register settings registers nothing with the settings api', function () {
    $registered = [];
    when('register_setting')->alias(
        static function (string $group, string $name, mixed $args = []) use (&$registered): void {
            $registered[] = $name;
        }
    );

    $this->page->registerSettings();

    $this->assertSame([], $registered);
});

// ── capability guards ─────────────────────────────────────────────
test('the screen renders nothing without manage options', function () {
    WpState::$deniedCaps = ['manage_options'];
    $this->settings->setClientId('google', 'google-client-id');

    $html = ($this->render)();

    $this->assertSame('', $html);
    $this->assertStringNotContainsString('google-client-id', $html);
});

/**
 * The personal-data capability is not a substitute here: it opens the
 * three operational screens, not the credentials.
 */
test('saving without manage options dies', function () {
    WpState::$deniedCaps = ['manage_options'];
    WpState::$userCan = true;

    $_POST = ['place_bias' => 'BS5'];

    $this->expectException(WpDieException::class);
    $this->page->handleSave();
});

test('nothing is written when the save is refused', function () {
    WpState::$deniedCaps = ['manage_options'];
    $_POST = ['place_bias' => 'BS5'];

    try {
        $this->page->handleSave();
    } catch (WpDieException) {
        // Expected; the assertion is that the write never happened.
    }

    $this->assertSame('', $this->settings->getPlaceBias());
});

// ── the rendered form ─────────────────────────────────────────────
test('the form posts to admin post with a nonce', function () {
    $html = ($this->render)();

    $this->assertStringContainsString('action="https://example.test/wp-admin/admin-post.php"', $html);
    $this->assertStringContainsString('name="action" value="reach_save_settings"', $html);
    $this->assertStringContainsString('value="nonce-reach_save_settings"', $html);
    $this->assertStringContainsString('Save Changes', $html);
});

test('the saved notice shows only after a save', function () {
    $this->assertStringNotContainsString('Settings saved.', ($this->render)());

    $_GET = ['updated' => '1'];

    $this->assertStringContainsString('Settings saved.', ($this->render)());
});

test('the find page settings are rendered with their stored values', function () {
    $this->settings->setPlaceBias('BS5');
    $this->settings->setOutOfHours('22:00', '08:00');
    $this->settings->setCallRequestEmail('callbacks@example.test');

    $html = ($this->render)();

    $this->assertStringContainsString('value="BS5"', $html);
    $this->assertStringContainsString('value="22:00"', $html);
    $this->assertStringContainsString('value="08:00"', $html);
    $this->assertStringContainsString('value="callbacks@example.test"', $html);
});

test('the call request field offers the site admin address as its placeholder', function () {
    WpState::$options['admin_email'] = 'admin@example.test';

    $html = ($this->render)();

    $this->assertStringContainsString('placeholder="admin@example.test"', $html);
    // With nothing configured, the getter already falls back to that same
    // address, so it is also the field's value.
    $this->assertStringContainsString('value="admin@example.test"', $html);
});

test('the redirect uris an admin has to register are shown', function () {
    $html = ($this->render)();

    $this->assertStringContainsString('reach/v1/oauth/callback', $html);
    $this->assertStringContainsString('/reach/signin', $html);
});

test('every provider gets a client id field', function () {
    $html = ($this->render)();

    foreach (['google', 'microsoft', 'apple', 'facebook'] as $provider) {
        $this->assertStringContainsString('name="client_id_' . $provider . '"', $html);
    }
    foreach (['Google', 'Microsoft', 'Apple', 'Facebook'] as $label) {
        $this->assertStringContainsString('<h3>' . $label . '</h3>', $html);
    }
});

/**
 * Apple's client-side flow has no client secret, so offering a field for
 * one would invite an admin to paste a credential nothing reads.
 */
test('apple gets no client secret field', function () {
    $html = ($this->render)();

    foreach (['google', 'microsoft', 'facebook'] as $provider) {
        $this->assertStringContainsString('name="client_secret_' . $provider . '"', $html);
    }
    $this->assertStringNotContainsString('name="client_secret_apple"', $html);
});

test('a stored client id is rendered but a stored secret never is', function () {
    $this->settings->setClientId('google', 'google-client-id');
    $this->settings->setClientSecret('google', 'super-secret-value');

    $html = ($this->render)();

    $this->assertStringContainsString('value="google-client-id"', $html);
    $this->assertStringNotContainsString('super-secret-value', $html);
    // Shown as a fixed-width placeholder instead, so an admin can see one
    // is set without it being readable off the form.
    $this->assertStringContainsString('•••••••• (saved — leave blank to keep)', $html);
    $this->assertStringContainsString('name="remove_secret_google"', $html);
});

test('a provider with no stored secret offers no remove checkbox', function () {
    $html = ($this->render)();

    $this->assertStringNotContainsString('name="remove_secret_google"', $html);
    $this->assertStringNotContainsString('leave blank to keep', $html);
});

// ── saving (reflection: the live caller exits) ────────────────────
test('the find page settings are saved', function () {
    $_POST = [
        'place_bias'         => '  BS5  ',
        'out_of_hours_start' => '22:00',
        'out_of_hours_end'   => '08:00',
        'call_request_email' => 'callbacks@example.test',
    ];

    ($this->save)();

    $this->assertSame('BS5', $this->settings->getPlaceBias());
    $this->assertSame('22:00', $this->settings->getOutOfHoursStart());
    $this->assertSame('08:00', $this->settings->getOutOfHoursEnd());
    $this->assertSame('callbacks@example.test', $this->settings->getCallRequestEmail());
});

test('an absent field clears the value it names', function () {
    $this->settings->setPlaceBias('BS5');
    $this->settings->setOutOfHours('22:00', '08:00');

    // An empty POST is what an admin submitting a cleared form sends.
    ($this->save)();

    $this->assertSame('', $this->settings->getPlaceBias());
    $this->assertSame('', $this->settings->getOutOfHoursStart());
    $this->assertSame('', $this->settings->getOutOfHoursEnd());
});

/**
 * The shape checks are Settings' job, not the page's — the page only
 * unslashes and string-guards — but the pairing is worth pinning: junk in
 * either field disables the window rather than half-configuring it.
 */
test('an unparseable out of hours bound is stored blank', function () {
    $_POST = ['out_of_hours_start' => 'half past nine', 'out_of_hours_end' => '08:00'];

    ($this->save)();

    $this->assertSame('', $this->settings->getOutOfHoursStart());
    $this->assertSame('08:00', $this->settings->getOutOfHoursEnd());
});

test('an invalid call request address falls back to the site admin', function () {
    WpState::$options['admin_email'] = 'admin@example.test';
    $_POST = ['call_request_email' => 'not-an-address'];

    ($this->save)();

    $this->assertSame('admin@example.test', $this->settings->getCallRequestEmail());
});

test('every provider client id is saved', function () {
    $_POST = [
        'client_id_google'    => 'google-id',
        'client_id_microsoft' => 'microsoft-id',
        'client_id_apple'     => 'apple-id',
        'client_id_facebook'  => 'facebook-id',
    ];

    ($this->save)();

    $this->assertSame('google-id', $this->settings->getClientId('google'));
    $this->assertSame('microsoft-id', $this->settings->getClientId('microsoft'));
    $this->assertSame('apple-id', $this->settings->getClientId('apple'));
    $this->assertSame('facebook-id', $this->settings->getClientId('facebook'));
});

test('a submitted secret is stored encrypted and reads back', function () {
    $_POST = ['client_secret_google' => '  a-new-secret  '];

    ($this->save)();

    $this->assertSame('a-new-secret', $this->settings->getClientSecret('google'));

    $stored = WpState::$options[Settings::OPTION_SECRETS];
    $this->assertIsArray($stored);
    $this->assertStringNotContainsString(
        'a-new-secret',
        (string) json_encode($stored),
        'the secret must not be recoverable from the option row',
    );
});

/**
 * The rule the whole manual handler exists for: an empty secret field is
 * "leave it alone", because the form never shows the stored value and so
 * an admin editing anything else submits it blank every time.
 */
test('an empty secret field leaves the stored secret alone', function () {
    $this->settings->setClientSecret('google', 'existing-secret');

    $_POST = ['client_secret_google' => '', 'client_id_google' => 'google-id'];
    ($this->save)();

    $this->assertSame('existing-secret', $this->settings->getClientSecret('google'));
    $this->assertSame('google-id', $this->settings->getClientId('google'));
});

test('an absent secret field leaves the stored secret alone', function () {
    $this->settings->setClientSecret('google', 'existing-secret');

    ($this->save)();

    $this->assertSame('existing-secret', $this->settings->getClientSecret('google'));
});

test('the remove checkbox clears the stored secret', function () {
    $this->settings->setClientSecret('google', 'existing-secret');

    $_POST = ['remove_secret_google' => '1'];
    ($this->save)();

    $this->assertSame('', $this->settings->getClientSecret('google'));
});

/**
 * Remove wins over a value typed into the field in the same submit —
 * ticking the box and typing a new secret is contradictory, and clearing
 * is the safer reading.
 */
test('the remove checkbox beats a secret typed alongside it', function () {
    $this->settings->setClientSecret('google', 'existing-secret');

    $_POST = ['remove_secret_google' => '1', 'client_secret_google' => 'a-new-secret'];
    ($this->save)();

    $this->assertSame('', $this->settings->getClientSecret('google'));
});

test('an unticked remove checkbox does not clear anything', function () {
    $this->settings->setClientSecret('google', 'existing-secret');

    $_POST = ['remove_secret_google' => '0'];
    ($this->save)();

    $this->assertSame('existing-secret', $this->settings->getClientSecret('google'));
});

test('a secret posted for apple is ignored', function () {
    $_POST = ['client_secret_apple' => 'apple-has-no-secret', 'remove_secret_apple' => '1'];

    ($this->save)();

    $this->assertSame('', $this->settings->getClientSecret('apple'));
    $this->assertArrayNotHasKey(
        Settings::OPTION_SECRETS,
        WpState::$options,
        'Apple never reaches the secret store at all, not even to clear it',
    );
});

test('saving one provider does not disturb another', function () {
    $this->settings->setClientSecret('google', 'google-secret');
    $this->settings->setClientSecret('facebook', 'facebook-secret');

    $_POST = ['remove_secret_google' => '1'];
    ($this->save)();

    $this->assertSame('', $this->settings->getClientSecret('google'));
    $this->assertSame('facebook-secret', $this->settings->getClientSecret('facebook'));
});
