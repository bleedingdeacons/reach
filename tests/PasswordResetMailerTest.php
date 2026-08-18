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
final class PasswordResetMailerTest extends ReachTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        WpState::$mail = [];
        WpState::$mailResult = true;
    }

    protected function tearDown(): void
    {
        WpState::$mailResult = true;
        parent::tearDown();
    }

    public function testQueueingSendsNothingYet(): void
    {
        (new PasswordResetMailer())->queue('user@example.com', 'raw-token');

        $this->assertSame([], WpState::$mail, 'the send must not happen during the request');
    }

    public function testQueueingRegistersTheShutdownFlush(): void
    {
        $mailer = new PasswordResetMailer();
        $mailer->queue('user@example.com', 'raw-token');

        $this->assertActionAdded('shutdown', [$mailer, 'flush']);
    }

    public function testFlushSendsTheQueuedLink(): void
    {
        $mailer = new PasswordResetMailer();
        $mailer->queue('user@example.com', 'raw-token');

        $mailer->flush();

        $this->assertCount(1, WpState::$mail);
        $this->assertSame('user@example.com', WpState::$mail[0]['to']);
        $this->assertStringContainsString('token=raw-token', (string) WpState::$mail[0]['message']);
    }

    public function testFlushingTwiceDoesNotSendTwice(): void
    {
        // The shutdown hook is one callback but nothing stops it being
        // reached twice; a member must not get two links because of it.
        $mailer = new PasswordResetMailer();
        $mailer->queue('user@example.com', 'raw-token');

        $mailer->flush();
        $mailer->flush();

        $this->assertCount(1, WpState::$mail);
    }

    public function testFlushWithNothingQueuedIsANoOp(): void
    {
        (new PasswordResetMailer())->flush();

        $this->assertSame([], WpState::$mail);
    }

    public function testTwoQueuedLinksBothGoOutOnOneFlush(): void
    {
        $mailer = new PasswordResetMailer();
        $mailer->queue('one@example.com', 'token-one');
        $mailer->queue('two@example.com', 'token-two');

        $mailer->flush();

        $this->assertCount(2, WpState::$mail);
        $this->assertSame('one@example.com', WpState::$mail[0]['to']);
        $this->assertSame('two@example.com', WpState::$mail[1]['to']);
    }

    public function testTheFlushIsRegisteredOnceHoweverManyLinksAreQueued(): void
    {
        // Capture before the code that registers, per ReachTestCase.
        $this->captureAction('shutdown');

        $mailer = new PasswordResetMailer();
        $mailer->queue('one@example.com', 'token-one');
        $mailer->queue('two@example.com', 'token-two');

        // Registering twice would send the first batch, then send
        // nothing - harmless today, but only by accident.
        $this->assertCount(1, $this->actionCallbacks('shutdown'));
    }

    public function testSendDeliversImmediatelyForCallersThatWantIt(): void
    {
        $sent = (new PasswordResetMailer())->send('user@example.com', 'raw-token');

        $this->assertTrue($sent);
        $this->assertCount(1, WpState::$mail);
    }

    public function testSendReportsFailure(): void
    {
        WpState::$mailResult = false;

        $this->assertFalse((new PasswordResetMailer())->send('user@example.com', 'raw-token'));
    }

    /**
     * The link carries the token and nothing else — no address in the
     * URL, and no statement about whether an account existed.
     */
    public function testTheMessageCarriesOnlyTheToken(): void
    {
        $mailer = new PasswordResetMailer();
        $mailer->queue('user@example.com', 'raw-token');
        $mailer->flush();

        $message = (string) WpState::$mail[0]['message'];

        $this->assertStringContainsString('token=raw-token', $message);
        $this->assertStringNotContainsString('user@example.com', $message);
    }
}
