<?php

declare(strict_types=1);

namespace Reach\Tests;

use Reach\Alerts\AlertApi;
use Reach\Alerts\AlertDispatcher;
use Reach\Devices\ResponderGate;
use Reach\Tests\Fixtures\InMemoryAlertContactRepository;
use Reach\Tests\Fixtures\InMemoryAlertRepository;
use Reach\Tests\Fixtures\InMemoryDeviceRepository;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use WP_Error;

/**
 * Reach's public alerting API — the surface another plugin is actually
 * held to, and therefore the one where a change is a breaking change.
 *
 * The distinction that matters is between the two call forms. {@see
 * AlertApi::send()} returns the stored alert's id or a WP_Error saying
 * why it was refused; the action form cannot, because do_action() has no
 * return value, so it swallows the refusal on purpose. A caller using
 * the action has already chosen not to find out.
 */

beforeEach(function () {
    $this->api = function (): AlertApi {
        return new AlertApi(new AlertDispatcher(
            $this->alerts,
            new InMemoryAlertContactRepository(),
            new InMemoryDeviceRepository(),
            new ResponderGate(new InMemoryMemberRepository([])),
            [],
        ));
    };

    $this->alerts = new InMemoryAlertRepository();
});

test('send stores the alert and returns its id', function () {
    $id = ($this->api)()->send([
        'kind'      => 'shift_uncovered',
        'source'    => 'trusted',
        'title'     => 'Helpline shift uncovered',
        'body'      => 'Tonight 22:00–08:00 has nobody signed up.',
        'reference' => 'SHIFT-2026-08-15-N',
        'priority'  => 'urgent',
    ]);

    $this->assertIsInt($id);
    $this->assertCount(1, $this->alerts->alerts);
    $this->assertSame($id, $this->alerts->alerts[0]->id);
    $this->assertSame('shift_uncovered', $this->alerts->alerts[0]->kind);
    $this->assertTrue($this->alerts->alerts[0]->isUrgent());
});

test('kind and title are the only required fields', function () {
    $id = ($this->api)()->send(['kind' => 'something', 'title' => 'Something happened']);

    $this->assertIsInt($id);
    $this->assertSame('unknown', $this->alerts->alerts[0]->source);
    $this->assertTrue($this->alerts->alerts[0]->isBroadcast());
});

/**
 * @param array<string, mixed> $args
 */
test('send explains a refusal', function (array $args, string $code) {
    $result = ($this->api)()->send($args);

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame($code, $result->get_error_code());
    $this->assertSame([], $this->alerts->alerts, 'a refused alert must not be stored');
})->with('refusals');

/** @return array<string, array{0: array<string, mixed>, 1: string}> */
dataset('refusals', function (): array {
    return [
        'no kind' => [
            ['title' => 'Something happened'],
            'reach_alert_missing_kind',
        ],
        'no title' => [
            ['kind' => 'something'],
            'reach_alert_missing_title',
        ],
        'unusable target' => [
            ['kind' => 'something', 'title' => 'T', 'target_email' => 'not-an-address'],
            'reach_alert_bad_target',
        ],
    ];
});

test('register hooks the action form', function () {
    $this->captureAction(AlertApi::SEND_ACTION);

    ($this->api)()->register();

    $this->assertCount(1, $this->actionCallbacks(AlertApi::SEND_ACTION));
});

test('the action form raises the alert', function () {
    // The point of the action: a plugin can fire it without depending
    // on Reach being active, because do_action() on an unhooked name
    // is simply inert.
    ($this->api)()->handleAction(['kind' => 'shift_uncovered', 'title' => 'Shift uncovered']);

    $this->assertCount(1, $this->alerts->alerts);
    $this->assertSame('shift_uncovered', $this->alerts->alerts[0]->kind);
});

test('the action form swallows a refusal', function () {
    // Deliberate: do_action() has no return value, so there is
    // nowhere to report the refusal to. The dispatcher still logs it,
    // and a plugin that wants to know calls send().
    ($this->api)()->handleAction(['title' => 'No kind given']);

    $this->assertSame([], $this->alerts->alerts);
});

test('the action form ignores anything that is not an array', function (mixed $args) {
    // do_action() passes whatever it was given, and a caller firing
    // the hook with a string must not fatal the request it is part of.
    ($this->api)()->handleAction($args);

    $this->assertSame([], $this->alerts->alerts);
})->with('nonArrays');

/** @return array<string, array{0: mixed}> */
dataset('nonArrays', function (): array {
    return [
        'null'   => [null],
        'string' => ['kind=shift_uncovered'],
        'int'    => [42],
        'object' => [new \stdClass()],
    ];
});
