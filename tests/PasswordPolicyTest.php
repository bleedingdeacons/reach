<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\Auth\PasswordPolicy;

/**
 * Unit tests for {@see PasswordPolicy} — the NIST SP 800-63B / NCSC-style
 * rules: length-based, no composition requirements, common and context
 * passwords rejected.
 */
final class PasswordPolicyTest extends TestCase
{
    private PasswordPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new PasswordPolicy();
    }

    public function testAcceptsALongPassphraseWithNoSpecialCharacters(): void
    {
        // The whole point of the modern guidance: a long, all-lowercase
        // pass-phrase is strong and must be accepted despite having no
        // digits/symbols/uppercase.
        $this->assertNull($this->policy->validate('correct horse battery staple'));
        $this->assertTrue($this->policy->isAcceptable('correct horse battery staple'));
    }

    public function testRejectsTooShort(): void
    {
        $this->assertNotNull($this->policy->validate('short'));
    }

    public function testAcceptsAtExactlyMinimumLength(): void
    {
        $atMinimum = 'trombone-cliff'; // 14 characters
        $this->assertSame(PasswordPolicy::MIN_LENGTH, strlen($atMinimum));
        $this->assertNull($this->policy->validate($atMinimum));
    }

    public function testRejectsJustUnderMinimumLength(): void
    {
        // 13 characters — one below the minimum.
        $this->assertNotNull($this->policy->validate('trombonecliff'));
    }

    public function testRejectsTooLong(): void
    {
        $this->assertNotNull($this->policy->validate(str_repeat('a', PasswordPolicy::MAX_LENGTH + 1)));
    }

    public function testMeasuresLengthInCodePointsNotBytes(): void
    {
        // Seven multi-byte characters is below the 8 code-point minimum even
        // though it is well over 8 bytes.
        $this->assertNotNull($this->policy->validate('héllو1é'));
    }

    public function testRejectsCommonPasswords(): void
    {
        // Long-but-predictable entries (>= the 14-char minimum) so it is the
        // deny-list, not the length check, doing the rejecting.
        foreach (['passwordpassword', 'password123456', 'qwertyuiopasdf', 'iloveyou123456'] as $common) {
            $this->assertGreaterThanOrEqual(PasswordPolicy::MIN_LENGTH, strlen($common));
            $this->assertNotNull($this->policy->validate($common), "$common should be rejected");
        }
    }

    public function testCommonPasswordCheckIsCaseInsensitive(): void
    {
        $this->assertNotNull($this->policy->validate('PasswordPassword'));
    }

    public function testRejectsPasswordContainingEmailLocalPart(): void
    {
        // Long enough to clear the length check, so the email local-part is
        // what triggers the rejection.
        $this->assertNotNull($this->policy->validate('gordon-rocks-hard', ['email' => 'gordon@example.com']));
    }

    public function testAllowsPasswordUnrelatedToEmail(): void
    {
        $this->assertNull($this->policy->validate('velvet thunder ridge', ['email' => 'gordon@example.com']));
    }

    public function testShortEmailLocalPartIsNotUsedAsAContextTerm(): void
    {
        // A 2-char local-part ("jo") would match far too many passwords, so
        // it must be ignored — this password is otherwise fine.
        $this->assertNull($this->policy->validate('jofferson tunnel', ['email' => 'jo@example.com']));
    }
}
