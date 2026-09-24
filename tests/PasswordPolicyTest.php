<?php

declare(strict_types=1);

namespace Reach\Tests;

use Reach\Auth\PasswordPolicy;

/**
 * Unit tests for {@see PasswordPolicy} — the NIST SP 800-63B / NCSC-style
 * rules: length-based, no composition requirements, common and context
 * passwords rejected.
 */

beforeEach(function () {
    $this->policy = new PasswordPolicy();
});

test('accepts a long passphrase with no special characters', function () {
    // The whole point of the modern guidance: a long, all-lowercase
    // pass-phrase is strong and must be accepted despite having no
    // digits/symbols/uppercase.
    $this->assertNull($this->policy->validate('correct horse battery staple'));
    $this->assertTrue($this->policy->isAcceptable('correct horse battery staple'));
});

test('rejects too short', function () {
    $this->assertNotNull($this->policy->validate('short'));
});

test('accepts at exactly minimum length', function () {
    $atMinimum = 'trombone-cliff'; // 14 characters
    $this->assertSame(PasswordPolicy::MIN_LENGTH, strlen($atMinimum));
    $this->assertNull($this->policy->validate($atMinimum));
});

test('rejects just under minimum length', function () {
    // 13 characters — one below the minimum.
    $this->assertNotNull($this->policy->validate('trombonecliff'));
});

test('rejects too long', function () {
    $this->assertNotNull($this->policy->validate(str_repeat('a', PasswordPolicy::MAX_LENGTH + 1)));
});

test('measures length in code points not bytes', function () {
    // Seven multi-byte characters is below the 8 code-point minimum even
    // though it is well over 8 bytes.
    $this->assertNotNull($this->policy->validate('héllو1é'));
});

test('rejects common passwords', function () {
    // Long-but-predictable entries (>= the 14-char minimum) so it is the
    // deny-list, not the length check, doing the rejecting.
    foreach (['passwordpassword', 'password123456', 'qwertyuiopasdf', 'iloveyou123456'] as $common) {
        $this->assertGreaterThanOrEqual(PasswordPolicy::MIN_LENGTH, strlen($common));
        $this->assertNotNull($this->policy->validate($common), "$common should be rejected");
    }
});

test('common password check is case insensitive', function () {
    $this->assertNotNull($this->policy->validate('PasswordPassword'));
});

test('rejects password containing email local part', function () {
    // Long enough to clear the length check, so the email local-part is
    // what triggers the rejection.
    $this->assertNotNull($this->policy->validate('gordon-rocks-hard', ['email' => 'gordon@example.com']));
});

test('allows password unrelated to email', function () {
    $this->assertNull($this->policy->validate('velvet thunder ridge', ['email' => 'gordon@example.com']));
});

test('short email local part is not used as a context term', function () {
    // A 2-char local-part ("jo") would match far too many passwords, so
    // it must be ignored — this password is otherwise fine.
    $this->assertNull($this->policy->validate('jofferson tunnel', ['email' => 'jo@example.com']));
});
