<?php

declare(strict_types=1);

namespace Reach\Tests;

use Reach\Alerts\Alert;
use Reach\Alerts\AlertRequest;
use WP_Error;

/**
 * The contract other plugins are held to when they raise an alert.
 *
 * Everything here is about being forgiving with what can be salvaged
 * and strict about what cannot: an over-long title is clipped and still
 * rings the phone, while an alert with no kind or no title is refused
 * because it is noise rather than a degraded alert.
 */

beforeEach(function () {
    /** @param array<string, mixed> $args */
    $this->request = function (array $args): AlertRequest {
        $request = AlertRequest::fromArray($args + ['kind' => 'a', 'title' => 'b']);

        $this->assertInstanceOf(AlertRequest::class, $request);

        return $request;
    };
});

test('minimal request is accepted', function () {
    $request = AlertRequest::fromArray(['kind' => 'test', 'title' => 'Something happened']);

    $this->assertInstanceOf(AlertRequest::class, $request);
    $this->assertSame('test', $request->kind);
    $this->assertSame('Something happened', $request->title);
    $this->assertSame(Alert::PRIORITY_NORMAL, $request->priority);
    $this->assertSame('', $request->targetEmail);
    $this->assertSame(AlertRequest::DEFAULT_TTL_SECONDS, $request->ttlSeconds);
});

test('kind is required', function () {
    $result = AlertRequest::fromArray(['title' => 'No kind here']);

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('reach_alert_missing_kind', $result->get_error_code());
});

test('title is required', function () {
    $result = AlertRequest::fromArray(['kind' => 'test']);

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('reach_alert_missing_title', $result->get_error_code());
});

test('whitespace only title is refused', function () {
    $result = AlertRequest::fromArray(['kind' => 'test', 'title' => "   \n  "]);

    $this->assertInstanceOf(WP_Error::class, $result);
});

test('invalid target email is refused', function () {
    $result = AlertRequest::fromArray([
        'kind'         => 'test',
        'title'        => 'Hello',
        'target_email' => 'not-an-address',
    ]);

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('reach_alert_bad_target', $result->get_error_code());
});

test('target email is lowercased', function () {
    $request = AlertRequest::fromArray([
        'kind'         => 'test',
        'title'        => 'Hello',
        'target_email' => 'Responder@Example.COM',
    ]);

    $this->assertInstanceOf(AlertRequest::class, $request);
    $this->assertSame('responder@example.com', $request->targetEmail);
});

test('overlong title is clipped rather than refused', function () {
    // A clipped alert still rings the phone, which is the point.
    $request = AlertRequest::fromArray([
        'kind'  => 'test',
        'title' => str_repeat('a', 500),
    ]);

    $this->assertInstanceOf(AlertRequest::class, $request);
    $this->assertLessThanOrEqual(200, strlen($request->title));
});

test('markup is stripped from text', function () {
    $request = AlertRequest::fromArray([
        'kind'  => 'test',
        'title' => '<b>Bold</b> alert',
    ]);

    $this->assertInstanceOf(AlertRequest::class, $request);
    $this->assertStringNotContainsString('<b>', $request->title);
});

test('non string title is treated as absent', function () {
    // Casting would put "Array" on a lock screen and hide the caller's bug.
    $result = AlertRequest::fromArray(['kind' => 'test', 'title' => ['oops']]);

    $this->assertInstanceOf(WP_Error::class, $result);
});

test('unknown priority falls back to normal', function () {
    $request = AlertRequest::fromArray([
        'kind'     => 'test',
        'title'    => 'Hello',
        'priority' => 'catastrophic',
    ]);

    $this->assertInstanceOf(AlertRequest::class, $request);
    $this->assertSame(Alert::PRIORITY_NORMAL, $request->priority);
});

test('urgent priority is kept', function () {
    $request = AlertRequest::fromArray([
        'kind'     => 'test',
        'title'    => 'Hello',
        'priority' => 'urgent',
    ]);

    $this->assertInstanceOf(AlertRequest::class, $request);
    $this->assertSame(Alert::PRIORITY_URGENT, $request->priority);
});

test('payload is flattened to strings', function () {
    $request = AlertRequest::fromArray([
        'kind'    => 'test',
        'title'   => 'Hello',
        'payload' => [
            'shift_id' => 42,
            'covered'  => false,
            'nested'   => ['dropped'],
            'name'     => 'night',
        ],
    ]);

    $this->assertInstanceOf(AlertRequest::class, $request);
    $this->assertSame('42', $request->payload['shift_id']);
    $this->assertSame('false', $request->payload['covered']);
    $this->assertSame('night', $request->payload['name']);
    // FCM's data block is a string→string map; anything nested has
    // no representation there.
    $this->assertArrayNotHasKey('nested', $request->payload);
});

test('subject and message are accepted as aliases', function () {
    // The names a caller naturally reaches for, alongside the wire
    // names the database has always used.
    $request = AlertRequest::fromArray([
        'kind'    => 'test',
        'subject' => 'Helpline shift uncovered',
        'message' => 'Tonight 22:00-08:00 has nobody signed up.',
    ]);

    $this->assertInstanceOf(AlertRequest::class, $request);
    $this->assertSame('Helpline shift uncovered', $request->title);
    $this->assertSame('Tonight 22:00-08:00 has nobody signed up.', $request->body);
});

test('explicit wire names win over aliases', function () {
    // So an existing integration cannot be changed underneath it by a
    // caller that happens to send both.
    $request = AlertRequest::fromArray([
        'kind'    => 'test',
        'title'   => 'From title',
        'subject' => 'From subject',
        'body'    => 'From body',
        'message' => 'From message',
    ]);

    $this->assertInstanceOf(AlertRequest::class, $request);
    $this->assertSame('From title', $request->title);
    $this->assertSame('From body', $request->body);
});

test('contact is carried separately and capped', function () {
    $request = AlertRequest::fromArray([
        'kind'    => 'test',
        'title'   => 'Hello',
        'contact' => str_repeat('x', 900),
    ]);

    $this->assertInstanceOf(AlertRequest::class, $request);
    $this->assertLessThanOrEqual(500, strlen($request->contact));
});

test('contact defaults to empty', function () {
    // Most alerts carry no personal data at all, and that is the
    // default rather than something a caller has to opt out of.
    $request = AlertRequest::fromArray(['kind' => 'test', 'title' => 'Hello']);

    $this->assertInstanceOf(AlertRequest::class, $request);
    $this->assertSame('', $request->contact);
});

test('ttl is clamped into range', function () {
    $tooShort = AlertRequest::fromArray(['kind' => 'a', 'title' => 'b', 'ttl' => 5]);
    $tooLong  = AlertRequest::fromArray(['kind' => 'a', 'title' => 'b', 'ttl' => 999999]);

    $this->assertInstanceOf(AlertRequest::class, $tooShort);
    $this->assertInstanceOf(AlertRequest::class, $tooLong);
    $this->assertSame(AlertRequest::DEFAULT_TTL_SECONDS, $tooShort->ttlSeconds);
    $this->assertSame(86400, $tooLong->ttlSeconds);
});

test('the device target only ever widens to everybody', function (mixed $given, int $expected) {
    // Nonsense becomes 0 — "any handset this alert's address resolves
    // to" — rather than an invented row id. Getting it wrong towards
    // a broadcast is recoverable; getting it wrong towards some other
    // responder's phone is not.
    $request = AlertRequest::fromArray([
        'kind' => 'a',
        'title' => 'b',
        'target_device_id' => $given,
    ]);

    $this->assertInstanceOf(AlertRequest::class, $request);
    $this->assertSame($expected, $request->targetDeviceId);
})->with('deviceTargets');

/** @return array<string, array{0: mixed, 1: int}> */
dataset('deviceTargets', function (): array {
    return [
        'an id'           => [7, 7],
        'a numeric string' => ['7', 7],
        'zero'            => [0, 0],
        'negative'        => [-3, 0],
        'a word'          => ['nonsense', 0],
        'an array'        => [[7], 0],
        'null'            => [null, 0],
        'a float'         => [7.5, 0],
    ];
});

test('an alert is for any handset unless one is named', function () {
    $request = AlertRequest::fromArray(['kind' => 'a', 'title' => 'b']);

    $this->assertInstanceOf(AlertRequest::class, $request);
    $this->assertSame(0, $request->targetDeviceId);
});

test('expiry is computed from ttl', function () {
    $request = AlertRequest::fromArray(['kind' => 'a', 'title' => 'b', 'ttl' => 600]);

    $this->assertInstanceOf(AlertRequest::class, $request);
    $this->assertSame(1_700_000_600, $request->expiresAt(1_700_000_000));
});

// ── level and response ────────────────────────────────────────────
test('an alert that names neither is yellow and somebodys to take', function () {
    // The defaults are what every alert did before the fields existed:
    // audible, and cleared off the rota by whoever picks it up.
    $request = ($this->request)([]);

    $this->assertSame(Alert::LEVEL_YELLOW, $request->level);
    $this->assertSame(Alert::RESPONSE_FIRST, $request->response);
});

test('the level is taken as given', function (string $given, string $expected) {
    $this->assertSame($expected, ($this->request)(['level' => $given])->level);
})->with('levels');

/** @return array<string, array{0: string, 1: string}> */
dataset('levels', function (): array {
    return [
        'red'            => ['red', Alert::LEVEL_RED],
        'yellow'         => ['yellow', Alert::LEVEL_YELLOW],
        'blue'           => ['blue', Alert::LEVEL_BLUE],
        'shouted'        => ['RED', Alert::LEVEL_RED],
        'padded'         => ['  blue  ', Alert::LEVEL_BLUE],
        // Coerced, not refused: an alert delivered at the wrong volume
        // beats an alert refused over a spelling.
        'invented'       => ['puce', Alert::LEVEL_YELLOW],
        'empty'          => ['', Alert::LEVEL_YELLOW],
    ];
});

test('the response requirement is taken as given', function (string $given, string $expected) {
    $this->assertSame($expected, ($this->request)(['response' => $given])->response);
})->with('responses');

/** @return array<string, array{0: string, 1: string}> */
dataset('responses', function (): array {
    return [
        'first'    => ['first', Alert::RESPONSE_FIRST],
        'none'     => ['none', Alert::RESPONSE_NONE],
        'shouted'  => ['NONE', Alert::RESPONSE_NONE],
        // Falls back to first-to-respond, which is the safe direction:
        // a mistyped value costs at worst a notice nobody needed,
        // where the other way round leaves an answered alert on thirty
        // screens.
        'invented' => ['maybe', Alert::RESPONSE_FIRST],
        'empty'    => ['', Alert::RESPONSE_FIRST],
    ];
});

test('an older callers priority is read as a level', function () {
    // The two-value vocabulary maps up. Normal means yellow rather
    // than blue: a caller using the old API asked for an alert that
    // makes a noise, and blue does not.
    $this->assertSame(Alert::LEVEL_RED, ($this->request)(['priority' => 'urgent'])->level);
    $this->assertSame(Alert::LEVEL_YELLOW, ($this->request)(['priority' => 'normal'])->level);
});

test('an explicit level beats a priority', function () {
    // What a plugin mid-migration looks like. The newer field is the
    // one it added on purpose.
    $request = ($this->request)(['level' => 'blue', 'priority' => 'urgent']);

    $this->assertSame(Alert::LEVEL_BLUE, $request->level);
});

test('the priority is derived from the level and never from the caller', function () {
    // Stored so an older handset still reads something it understands,
    // and derived so the two can never disagree on one row.
    $this->assertSame(Alert::PRIORITY_URGENT, ($this->request)(['level' => 'red'])->priority);
    $this->assertSame(Alert::PRIORITY_NORMAL, ($this->request)(['level' => 'yellow'])->priority);
    $this->assertSame(Alert::PRIORITY_NORMAL, ($this->request)(['level' => 'blue'])->priority);

    // Including where the caller asked for the contradiction outright.
    $contradictory = ($this->request)(['level' => 'blue', 'priority' => 'urgent']);
    $this->assertSame(Alert::PRIORITY_NORMAL, $contradictory->priority);
});

test('the level and the response are independent', function () {
    // A red alert everybody must see, and a blue job somebody still
    // has to pick up. Neither is a contradiction.
    $drill = ($this->request)(['level' => 'red', 'response' => 'none']);
    $this->assertSame(Alert::LEVEL_RED, $drill->level);
    $this->assertSame(Alert::RESPONSE_NONE, $drill->response);

    $chore = ($this->request)(['level' => 'blue', 'response' => 'first']);
    $this->assertSame(Alert::LEVEL_BLUE, $chore->level);
    $this->assertSame(Alert::RESPONSE_FIRST, $chore->response);
});
