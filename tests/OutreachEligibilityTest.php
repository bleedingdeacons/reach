<?php

declare(strict_types=1);

namespace Reach\Tests;

use Reach\Auth\OutreachEligibility;
use Reach\Auth\PasswordPolicy;
use Reach\Auth\PasswordResetMailer;
use Reach\Auth\PasswordAuthenticator;
use Reach\Core\RateLimiter;
use Reach\Rest\PasswordAuthController;
use Reach\Session\SessionCookie;
use Reach\Tests\Fixtures\MemberStub;
use Scrutiny\Testing\Doubles\SpyAuditLogger;
use Unity\Members\ResponderCertification;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use WP_Error;
use WP_REST_Request;

// Reuse the in-memory credential repo from the authenticator test.
require_once __DIR__ . '/PasswordAuthenticatorTest.php';

/**
 * The one rule that decides who may sign in to Reach.
 *
 * It used to be written out three times and the copies had drifted:
 * `/auth/login` accepted any telephone responder while OAuth required a
 * current certification, so an uncertified responder with a password
 * could get a session that the same person could not get through a
 * provider. The rule lives in one place now, and the last two tests here
 * exist specifically to stop that gap reopening — they drive the
 * password controller end to end rather than testing the rule in
 * isolation, because testing the rule alone is exactly what let the
 * controller drift away from it.
 */
final class OutreachEligibilityTest extends ReachTestCase
{
    public function testTwelfthStepperIsAdmitted(): void
    {
        $this->assertTrue(OutreachEligibility::permits(
            new MemberStub(personalEmail: 'a@example.com', twelfthStepper: true),
        ));
    }

    public function testCertifiedTelephoneResponderIsAdmitted(): void
    {
        $this->assertTrue(OutreachEligibility::permits(new MemberStub(
            personalEmail: 'a@example.com',
            twelfthStepper: false,
            telephoneResponder: true,
            responderCertification: ResponderCertification::Certified,
        )));
    }

    /**
     * @dataProvider uncertifiedStates
     */
    public function testUncertifiedTelephoneResponderIsRefused(ResponderCertification $certification): void
    {
        $this->assertFalse(OutreachEligibility::permits(new MemberStub(
            personalEmail: 'a@example.com',
            twelfthStepper: false,
            telephoneResponder: true,
            responderCertification: $certification,
        )));
    }

    /** @return array<string, array{0: ResponderCertification}> */
    public static function uncertifiedStates(): array
    {
        return [
            'none'        => [ResponderCertification::None],
            'applied'     => [ResponderCertification::Applied],
            'in training' => [ResponderCertification::InTraining],
            'pending'     => [ResponderCertification::Pending],
        ];
    }

    public function testTwelfthStepperIsAdmittedEvenWhenAlsoAnUncertifiedResponder(): void
    {
        // The two roles are independent, and the 12th-step role is
        // sufficient on its own — an uncertified responder badge must not
        // subtract an entitlement the member already has.
        $this->assertTrue(OutreachEligibility::permits(new MemberStub(
            personalEmail: 'a@example.com',
            twelfthStepper: true,
            telephoneResponder: true,
            responderCertification: ResponderCertification::InTraining,
        )));
    }

    public function testMemberWithNeitherRoleIsRefused(): void
    {
        $this->assertFalse(OutreachEligibility::permits(
            new MemberStub(personalEmail: 'a@example.com', twelfthStepper: false),
        ));
    }

    public function testNullIsRefused(): void
    {
        // No member matched the verified email. Callers pass a lookup
        // result straight in, so this must be a refusal rather than a
        // TypeError.
        $this->assertFalse(OutreachEligibility::permits(null));
    }

    // --- the regression this class was created for ------------------------

    public function testPasswordLoginRefusesAnUncertifiedResponder(): void
    {
        // The bug: this path accepted any telephone responder, so an
        // uncertified one could sign in by password while being refused
        // by OAuth.
        $credentials = new InMemoryPasswordCredentialRepository();
        $credentials->seedPassword('trainee@example.com', 'correcthorse10');

        $controller = $this->controllerWith(
            [new MemberStub(
                personalEmail: 'trainee@example.com',
                twelfthStepper: false,
                telephoneResponder: true,
                responderCertification: ResponderCertification::InTraining,
            )],
            $credentials,
        );

        $result = $controller->login(new WP_REST_Request([
            'email'    => 'trainee@example.com',
            'password' => 'correcthorse10',
        ]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_not_eligible', $result->get_error_code());
        $this->assertSame(403, $result->get_error_data()['status'] ?? null);
    }

    public function testPasswordLoginStillAdmitsACertifiedResponder(): void
    {
        // The other half of the fix: tightening the gate must not lock
        // out the people it is meant to admit.
        $credentials = new InMemoryPasswordCredentialRepository();
        $credentials->seedPassword('responder@example.com', 'correcthorse10');

        $controller = $this->controllerWith(
            [new MemberStub(
                personalEmail: 'responder@example.com',
                twelfthStepper: false,
                telephoneResponder: true,
                responderCertification: ResponderCertification::Certified,
            )],
            $credentials,
        );

        $result = $controller->login(new WP_REST_Request([
            'email'    => 'responder@example.com',
            'password' => 'correcthorse10',
        ]));

        $this->assertNotInstanceOf(WP_Error::class, $result);
    }

    /**
     * @param array<int, MemberStub> $members
     */
    private function controllerWith(
        array $members,
        InMemoryPasswordCredentialRepository $credentials,
    ): PasswordAuthController {
        $repository = new InMemoryMemberRepository($members);

        return new PasswordAuthController(
            new PasswordAuthenticator(
                $credentials,
                $repository,
                new PasswordResetMailer(),
                new PasswordPolicy(),
            ),
            new SessionCookie(),
            $repository,
            new SpyAuditLogger(),
            new RateLimiter(),
        );
    }
}
