<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\Auth\AnonymisedEmailDetector;

/**
 * The detector's job is binary — relay or not — but the consequences
 * of getting it wrong are asymmetric. A false positive refuses access
 * to a user who actually has a real address (it's read as anonymised
 * and sign-in is denied); a false negative lets a Facebook relay
 * address through as an authorisation email (defeats the point). Both
 * are bad, so the tests lean hard on accuracy: explicit truth on every
 * shape of Facebook relay we've seen, explicit falsehood on a handful
 * of real-world lookalikes that must keep working.
 */
final class AnonymisedEmailDetectorTest extends TestCase
{
    /**
     * @dataProvider relayCases
     */
    public function testRelayDomainsAreAnonymised(string $email): void
    {
        $this->assertTrue(
            AnonymisedEmailDetector::isAnonymised($email),
            $email . ' should be classified as anonymised'
        );
    }

    /**
     * @return iterable<array{string}>
     */
    public static function relayCases(): iterable
    {
        // The headline case — what we've actually observed Facebook
        // hand out in the OIDC flow.
        yield ['hash123@privaterelay.facebook.com'];
        // Bare facebook.com — paranoia, but the detector's promise is
        // "anything on facebook.com is treated as anonymised", and we
        // want that promise to hold.
        yield ['someone@facebook.com'];
        // Mixed case must not slip through. Facebook normally emits
        // lower-case but we don't rely on that.
        yield ['Hash123@PrivateRelay.Facebook.COM'];
        // Other *.facebook.com subdomains, just in case Facebook
        // introduces another relay variant.
        yield ['x@mail.facebook.com'];
        yield ['x@anything.facebook.com'];
    }

    /**
     * @dataProvider realEmailCases
     */
    public function testRealAddressesAreNotAnonymised(string $email): void
    {
        $this->assertFalse(
            AnonymisedEmailDetector::isAnonymised($email),
            $email . ' should be treated as a real address'
        );
    }

    /**
     * @return iterable<array{string}>
     */
    public static function realEmailCases(): iterable
    {
        yield ['alice@gmail.com'];
        yield ['bob@example.co.uk'];
        // Apple privaterelay is intentionally *not* on the list —
        // Apple's relay forwards mail, so it's a real contact address.
        yield ['hash@privaterelay.appleid.com'];
        // Lookalike that ends with facebook.com but on a different TLD
        // is not the same domain. This is unlikely to exist as a real
        // mail host but the matcher must not be tricked by suffix-
        // substring matching.
        yield ['x@notfacebook.com'];
        yield ['x@fake-facebook.com.evil.example'];
    }

    /**
     * @dataProvider malformedCases
     */
    public function testMalformedInputIsNotAnonymised(string $email): void
    {
        $this->assertFalse(AnonymisedEmailDetector::isAnonymised($email));
    }

    /**
     * @return iterable<array{string}>
     */
    public static function malformedCases(): iterable
    {
        yield [''];
        yield ['no-at-sign'];
        yield ['trailing-at@'];
        yield ['@no-local-part.com'];
    }
}
