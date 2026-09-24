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

function gateWith(MemberStub ...$members): ResponderGate
{
    return new ResponderGate(new InMemoryMemberRepository($members));
}

test('admits certified telephone responder', function () {
    $gate = gateWith(new MemberStub(
        personalEmail: 'responder@example.com',
        twelfthStepper: false,
        telephoneResponder: true,
        responderCertification: ResponderCertification::Certified,
    ));

    $this->assertNotNull($gate->authorisedMember('responder@example.com'));
});

test('admits a member who is not a responder at all', function () {
    // Previously the headline refusal. The handset is no longer only
    // the helpline's — it carries messages between members too — so
    // holding a responder role is not what decides who may use it.
    $gate = gateWith(new MemberStub(
        personalEmail: 'stepper@example.com',
        twelfthStepper: true,
        telephoneResponder: false,
    ));

    $this->assertNotNull($gate->authorisedMember('stepper@example.com'));
});

/**
 * <b>Certification no longer gates the handset, in any state.</b>
 * This is the change with the most behind it, so every state is
 * asserted rather than one standing for the rest: the old gate
 * refused Applied, In Training, Pending and None, and none of them
 * is refused now.
 */
test('admits responder whatever their certification', function (
    ResponderCertification $certification
) {
    $gate = gateWith(new MemberStub(
        personalEmail: 'trainee@example.com',
        twelfthStepper: false,
        telephoneResponder: true,
        responderCertification: $certification,
    ));

    $this->assertNotNull($gate->authorisedMember('trainee@example.com'));
})->with('uncertifiedStates');

/**
 * @return array<string, array{0: ResponderCertification}>
 */
dataset('uncertifiedStates', function (): array {
    return [
        'none'        => [ResponderCertification::None],
        'applied'     => [ResponderCertification::Applied],
        'in training' => [ResponderCertification::InTraining],
        'pending'     => [ResponderCertification::Pending],
    ];
});

test('refuses a member with no home group', function () {
    // The half-imported stub: a name and nothing else. Letting one
    // enrol would put a handset on the rota for a record nobody has
    // finished creating.
    $gate = gateWith(new MemberStub(
        personalEmail: 'stub@example.com',
        homeGroup: 0,
    ));

    $this->assertNull($gate->authorisedMember('stub@example.com'));
});

test('refuses a member with no address', function () {
    // Nothing to match a handset on and nothing to route an alert
    // to. Admitting one would put a handset on the list that
    // silently never rings.
    $gate = gateWith(new MemberStub(personalEmail: ''));

    $this->assertNull($gate->authorisedMember(''));
    $this->assertFalse(gateWith()->isAuthorised(new MemberStub(personalEmail: '')));
});

test('refuses a member whose address is not an address', function () {
    // "n/a" and half-typed addresses are in real member data. The
    // address is validated rather than merely counted, because an
    // undeliverable one is the same as none.
    $this->assertFalse(
        gateWith()->isAuthorised(new MemberStub(personalEmail: 'n/a')),
    );
});

test('refuses unknown email', function () {
    $gate = gateWith();

    $this->assertNull($gate->authorisedMember('nobody@example.com'));
});

test('refuses empty email', function () {
    $gate = gateWith();

    $this->assertNull($gate->authorisedMember(''));
    $this->assertNull($gate->authorisedMember('   '));
});

test('matches email case insensitively', function () {
    // Sign-in paths hand over whatever the provider said, and
    // providers differ on casing.
    $gate = gateWith(new MemberStub(
        personalEmail: 'responder@example.com',
        twelfthStepper: false,
        telephoneResponder: true,
        responderCertification: ResponderCertification::Certified,
    ));

    $this->assertNotNull($gate->authorisedMember('  Responder@Example.com  '));
});

test('is authorised refuses null', function () {
    $this->assertFalse(gateWith()->isAuthorised(null));
});
