<?php

declare(strict_types=1);

namespace Reach\Tests;

use Reach\Devices\ResponderGate;
use Reach\Tests\Fixtures\MemberStub;
use Unity\Members\ResponderCertification;
use Unity\Testing\Doubles\InMemoryMemberRepository;

/**
 * Hand's eligibility gate: a member with a usable address and a home
 * group.
 *
 * <b>This used to be much stricter — certified telephone responders and
 * nobody else — and the tests that asserted that are gone rather than
 * skipped.</b> A 12th-stepper who is not a responder, and a responder
 * still working towards certification, are both admitted now. That is
 * the decision, not a regression, and the tests below say so explicitly
 * so nobody restores the old rule by accident.
 *
 * What remains is a real gate, and the two halves earn their place: the
 * address is what a handset is matched on and an alert routed by, and
 * the home group is what separates a current member from a half-imported
 * stub.
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

    public function testAdmitsAMemberWhoIsNotAResponderAtAll(): void
    {
        // Previously the headline refusal. The handset is no longer only
        // the helpline's — it carries messages between members too — so
        // holding a responder role is not what decides who may use it.
        $gate = $this->gateWith(new MemberStub(
            personalEmail: 'stepper@example.com',
            twelfthStepper: true,
            telephoneResponder: false,
        ));

        $this->assertNotNull($gate->authorisedMember('stepper@example.com'));
    }

    /**
     * <b>Certification no longer gates the handset, in any state.</b>
     * This is the change with the most behind it, so every state is
     * asserted rather than one standing for the rest: the old gate
     * refused Applied, In Training, Pending and None, and none of them
     * is refused now.
     *
     * @dataProvider uncertifiedStates
     */
    public function testAdmitsResponderWhateverTheirCertification(
        ResponderCertification $certification
    ): void {
        $gate = $this->gateWith(new MemberStub(
            personalEmail: 'trainee@example.com',
            twelfthStepper: false,
            telephoneResponder: true,
            responderCertification: $certification,
        ));

        $this->assertNotNull($gate->authorisedMember('trainee@example.com'));
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

    public function testRefusesAMemberWithNoHomeGroup(): void
    {
        // The half-imported stub: a name and nothing else. Letting one
        // enrol would put a handset on the rota for a record nobody has
        // finished creating.
        $gate = $this->gateWith(new MemberStub(
            personalEmail: 'stub@example.com',
            homeGroup: 0,
        ));

        $this->assertNull($gate->authorisedMember('stub@example.com'));
    }

    public function testRefusesAMemberWithNoAddress(): void
    {
        // Nothing to match a handset on and nothing to route an alert
        // to. Admitting one would put a handset on the list that
        // silently never rings.
        $gate = $this->gateWith(new MemberStub(personalEmail: ''));

        $this->assertNull($gate->authorisedMember(''));
        $this->assertFalse($this->gateWith()->isAuthorised(new MemberStub(personalEmail: '')));
    }

    public function testRefusesAMemberWhoseAddressIsNotAnAddress(): void
    {
        // "n/a" and half-typed addresses are in real member data. The
        // address is validated rather than merely counted, because an
        // undeliverable one is the same as none.
        $this->assertFalse(
            $this->gateWith()->isAuthorised(new MemberStub(personalEmail: 'n/a')),
        );
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
