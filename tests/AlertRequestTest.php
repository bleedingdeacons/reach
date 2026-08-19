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
final class AlertRequestTest extends ReachTestCase
{
    public function testMinimalRequestIsAccepted(): void
    {
        $request = AlertRequest::fromArray(['kind' => 'test', 'title' => 'Something happened']);

        $this->assertInstanceOf(AlertRequest::class, $request);
        $this->assertSame('test', $request->kind);
        $this->assertSame('Something happened', $request->title);
        $this->assertSame(Alert::PRIORITY_NORMAL, $request->priority);
        $this->assertSame('', $request->targetEmail);
        $this->assertSame(AlertRequest::DEFAULT_TTL_SECONDS, $request->ttlSeconds);
    }

    public function testKindIsRequired(): void
    {
        $result = AlertRequest::fromArray(['title' => 'No kind here']);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_alert_missing_kind', $result->get_error_code());
    }

    public function testTitleIsRequired(): void
    {
        $result = AlertRequest::fromArray(['kind' => 'test']);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_alert_missing_title', $result->get_error_code());
    }

    public function testWhitespaceOnlyTitleIsRefused(): void
    {
        $result = AlertRequest::fromArray(['kind' => 'test', 'title' => "   \n  "]);

        $this->assertInstanceOf(WP_Error::class, $result);
    }

    public function testInvalidTargetEmailIsRefused(): void
    {
        $result = AlertRequest::fromArray([
            'kind'         => 'test',
            'title'        => 'Hello',
            'target_email' => 'not-an-address',
        ]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reach_alert_bad_target', $result->get_error_code());
    }

    public function testTargetEmailIsLowercased(): void
    {
        $request = AlertRequest::fromArray([
            'kind'         => 'test',
            'title'        => 'Hello',
            'target_email' => 'Responder@Example.COM',
        ]);

        $this->assertInstanceOf(AlertRequest::class, $request);
        $this->assertSame('responder@example.com', $request->targetEmail);
    }

    public function testOverlongTitleIsClippedRatherThanRefused(): void
    {
        // A clipped alert still rings the phone, which is the point.
        $request = AlertRequest::fromArray([
            'kind'  => 'test',
            'title' => str_repeat('a', 500),
        ]);

        $this->assertInstanceOf(AlertRequest::class, $request);
        $this->assertLessThanOrEqual(200, strlen($request->title));
    }

    public function testMarkupIsStrippedFromText(): void
    {
        $request = AlertRequest::fromArray([
            'kind'  => 'test',
            'title' => '<b>Bold</b> alert',
        ]);

        $this->assertInstanceOf(AlertRequest::class, $request);
        $this->assertStringNotContainsString('<b>', $request->title);
    }

    public function testNonStringTitleIsTreatedAsAbsent(): void
    {
        // Casting would put "Array" on a lock screen and hide the caller's bug.
        $result = AlertRequest::fromArray(['kind' => 'test', 'title' => ['oops']]);

        $this->assertInstanceOf(WP_Error::class, $result);
    }

    public function testUnknownPriorityFallsBackToNormal(): void
    {
        $request = AlertRequest::fromArray([
            'kind'     => 'test',
            'title'    => 'Hello',
            'priority' => 'catastrophic',
        ]);

        $this->assertInstanceOf(AlertRequest::class, $request);
        $this->assertSame(Alert::PRIORITY_NORMAL, $request->priority);
    }

    public function testUrgentPriorityIsKept(): void
    {
        $request = AlertRequest::fromArray([
            'kind'     => 'test',
            'title'    => 'Hello',
            'priority' => 'urgent',
        ]);

        $this->assertInstanceOf(AlertRequest::class, $request);
        $this->assertSame(Alert::PRIORITY_URGENT, $request->priority);
    }

    public function testPayloadIsFlattenedToStrings(): void
    {
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
    }

    public function testSubjectAndMessageAreAcceptedAsAliases(): void
    {
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
    }

    public function testExplicitWireNamesWinOverAliases(): void
    {
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
    }

    public function testContactIsCarriedSeparatelyAndCapped(): void
    {
        $request = AlertRequest::fromArray([
            'kind'    => 'test',
            'title'   => 'Hello',
            'contact' => str_repeat('x', 900),
        ]);

        $this->assertInstanceOf(AlertRequest::class, $request);
        $this->assertLessThanOrEqual(500, strlen($request->contact));
    }

    public function testContactDefaultsToEmpty(): void
    {
        // Most alerts carry no personal data at all, and that is the
        // default rather than something a caller has to opt out of.
        $request = AlertRequest::fromArray(['kind' => 'test', 'title' => 'Hello']);

        $this->assertInstanceOf(AlertRequest::class, $request);
        $this->assertSame('', $request->contact);
    }

    public function testTtlIsClampedIntoRange(): void
    {
        $tooShort = AlertRequest::fromArray(['kind' => 'a', 'title' => 'b', 'ttl' => 5]);
        $tooLong  = AlertRequest::fromArray(['kind' => 'a', 'title' => 'b', 'ttl' => 999999]);

        $this->assertInstanceOf(AlertRequest::class, $tooShort);
        $this->assertInstanceOf(AlertRequest::class, $tooLong);
        $this->assertSame(AlertRequest::DEFAULT_TTL_SECONDS, $tooShort->ttlSeconds);
        $this->assertSame(86400, $tooLong->ttlSeconds);
    }

    /**
     * @dataProvider deviceTargets
     */
    public function testTheDeviceTargetOnlyEverWidensToEverybody(mixed $given, int $expected): void
    {
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
    }

    /** @return array<string, array{0: mixed, 1: int}> */
    public static function deviceTargets(): array
    {
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
    }

    public function testAnAlertIsForAnyHandsetUnlessOneIsNamed(): void
    {
        $request = AlertRequest::fromArray(['kind' => 'a', 'title' => 'b']);

        $this->assertInstanceOf(AlertRequest::class, $request);
        $this->assertSame(0, $request->targetDeviceId);
    }

    public function testExpiryIsComputedFromTtl(): void
    {
        $request = AlertRequest::fromArray(['kind' => 'a', 'title' => 'b', 'ttl' => 600]);

        $this->assertInstanceOf(AlertRequest::class, $request);
        $this->assertSame(1_700_000_600, $request->expiresAt(1_700_000_000));
    }
}
