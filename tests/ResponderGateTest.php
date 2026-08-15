<?php

declare(strict_types=1);

namespace Reach\Tests;

use Reach\Devices\ResponderGate;
use Reach\Tests\Fixtures\MemberStub;
use Unity\Members\ResponderCertification;
use Unity\Testing\Doubles\InMemoryMemberRepository;

/**
 * Hand's eligibility gate: certified telephone responders, and nobody
 * else.
 *
 * This is deliberately stricter than the gate the Reach website applies,
 * which also admits 12th-steppers. The difference is the point of the
 * class, so it is asserted here rather than left as a comment: Hand is
 * the helpline handset and receives alerts raised for the duty rota.
 */
final class ResponderGateTest extends ReachTestCase
{
    public function testAdmitsCertifiedTelephoneResponder(): void
    {
        $gate = $this->gateWith(new MemberStub(
            personalEmail: 'responder@example.com',
            twelfthStepper: false,
            telephoneResponder: true,
            responderCertification: ResponderCertification::Certified,
        ));

        $this->assertNotNull($gate->authorisedMember('responder@example.com'));
    }

    public function testRefusesTwelfthStepperWhoIsNotAResponder(): void
    {
        // The headline difference from the website's gate. A
        // 12th-stepper can sign in to Reach and is refused by Hand.
        $gate = $this->gateWith(new MemberStub(
            personalEmail: 'stepper@example.com',
            twelfthStepper: true,
            telephoneResponder: false,
        ));

        $this->assertNull($gate->authorisedMember('stepper@example.com'));
    }

    /**
     * @dataProvider uncertifiedStates
     */
    public function testRefusesResponderWhoIsNotYetCertified(ResponderCertification $certification): void
    {
        // Applied, In Training and Pending are people working towards
        // certification. Sending them a live helpline alert would put an
        // untrained volunteer on a 12th-step call.
        $gate = $this->gateWith(new MemberStub(
            personalEmail: 'trainee@example.com',
            twelfthStepper: false,
            telephoneResponder: true,
            responderCertification: $certification,
        ));

        $this->assertNull($gate->authorisedMember('trainee@example.com'));
    }

    /**
     * @return array<string, array{0: ResponderCertification}>
     */
    public static function uncertifiedStates(): array
    {
        return [
            'none'        => [ResponderCertification::None],
            'applied'     => [ResponderCertification::Applied],
            'in training' => [ResponderCertification::InTraining],
            'pending'     => [ResponderCertification::Pending],
        ];
    }

    public function testRefusesUnknownEmail(): void
    {
        $gate = $this->gateWith();

        $this->assertNull($gate->authorisedMember('nobody@example.com'));
    }

    public function testRefusesEmptyEmail(): void
    {
        $gate = $this->gateWith();

        $this->assertNull($gate->authorisedMember(''));
        $this->assertNull($gate->authorisedMember('   '));
    }

    public function testMatchesEmailCaseInsensitively(): void
    {
        // Sign-in paths hand over whatever the provider said, and
        // providers differ on casing.
        $gate = $this->gateWith(new MemberStub(
            personalEmail: 'responder@example.com',
            twelfthStepper: false,
            telephoneResponder: true,
            responderCertification: ResponderCertification::Certified,
        ));

        $this->assertNotNull($gate->authorisedMember('  Responder@Example.com  '));
    }

    public function testIsAuthorisedRefusesNull(): void
    {
        $this->assertFalse($this->gateWith()->isAuthorised(null));
    }

    private function gateWith(MemberStub ...$members): ResponderGate
    {
        return new ResponderGate(new InMemoryMemberRepository($members));
    }
}
