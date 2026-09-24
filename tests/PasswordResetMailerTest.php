<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
use Reach\Auth\PasswordResetMailer;

/**
 * Tests for {@see PasswordResetMailer}, and in particular for the
 * queueing that keeps the request-reset endpoint from leaking which
 * addresses belong to members.
 *
 * The endpoint already answered `{sent: true}` either way, so the body
 * gave nothing away — but sending is a synchronous SMTP round trip and
 * not sending is a return, and the difference is measurable from
 * outside. Queueing moves the cost past the response so both branches
 * answer in the same time.
 *
 * Two halves, tested separately because they run at different moments:
 * the registration (asserted as a hook, since Brain Monkey records
 * hooks without firing them) and the sending ({@see flush()}, which is
 * what the hook eventually calls).
 */

beforeEach(function () {
    WpState::$mail = [];
    WpState::$mailResult = true;
});

afterEach(function () {
    WpState::$mailResult = true;
});

test('queueing sends nothing yet', function () {
    (new PasswordResetMailer())->queue('user@example.com', 'raw-token');

    $this->assertSame([], WpState::$mail, 'the send must not happen during the request');
});

test('queueing registers the shutdown flush', function () {
    $mailer = new PasswordResetMailer();
    $mailer->queue('user@example.com', 'raw-token');

    $this->assertActionAdded('shutdown', [$mailer, 'flush']);
});

test('flush sends the queued link', function () {
    $mailer = new PasswordResetMailer();
    $mailer->queue('user@example.com', 'raw-token');

    $mailer->flush();

    $this->assertCount(1, WpState::$mail);
    $this->assertSame('user@example.com', WpState::$mail[0]['to']);
    $this->assertStringContainsString('token=raw-token', (string) WpState::$mail[0]['message']);
});

test('flushing twice does not send twice', function () {
    // The shutdown hook is one callback but nothing stops it being
    // reached twice; a member must not get two links because of it.
    $mailer = new PasswordResetMailer();
    $mailer->queue('user@example.com', 'raw-token');

    $mailer->flush();
    $mailer->flush();

    $this->assertCount(1, WpState::$mail);
});

test('flush with nothing queued is a no op', function () {
    (new PasswordResetMailer())->flush();

    $this->assertSame([], WpState::$mail);
});

test('two queued links both go out on one flush', function () {
    $mailer = new PasswordResetMailer();
    $mailer->queue('one@example.com', 'token-one');
    $mailer->queue('two@example.com', 'token-two');

    $mailer->flush();

    $this->assertCount(2, WpState::$mail);
    $this->assertSame('one@example.com', WpState::$mail[0]['to']);
    $this->assertSame('two@example.com', WpState::$mail[1]['to']);
});

test('the flush is registered once however many links are queued', function () {
    // Capture before the code that registers, per ReachTestCase.
    $this->captureAction('shutdown');

    $mailer = new PasswordResetMailer();
    $mailer->queue('one@example.com', 'token-one');
    $mailer->queue('two@example.com', 'token-two');

    // Registering twice would send the first batch, then send
    // nothing - harmless today, but only by accident.
    $this->assertCount(1, $this->actionCallbacks('shutdown'));
});

test('send delivers immediately for callers that want it', function () {
    $sent = (new PasswordResetMailer())->send('user@example.com', 'raw-token');

    $this->assertTrue($sent);
    $this->assertCount(1, WpState::$mail);
});

test('send reports failure', function () {
    WpState::$mailResult = false;

    $this->assertFalse((new PasswordResetMailer())->send('user@example.com', 'raw-token'));
});

/**
 * The link carries the token and nothing else — no address in the
 * URL, and no statement about whether an account existed.
 */
test('the message carries only the token', function () {
    $mailer = new PasswordResetMailer();
    $mailer->queue('user@example.com', 'raw-token');
    $mailer->flush();

    $message = (string) WpState::$mail[0]['message'];

    $this->assertStringContainsString('token=raw-token', $message);
    $this->assertStringNotContainsString('user@example.com', $message);
});
