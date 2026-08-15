<?php

declare(strict_types=1);

namespace Reach\Tests;

use Reach\Alerts\AlertApi;
use Reach\Alerts\AlertDispatcher;
use Reach\Devices\ResponderGate;
use Reach\Tests\Fixtures\InMemoryAlertContactRepository;
use Reach\Tests\Fixtures\InMemoryAlertRepository;
use Reach\Tests\Fixtures\InMemoryDeviceRepository;
use Reach\Tests\ReachTestCase;
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
final class AlertApiTest extends ReachTestCase
{
    private InMemoryAlertRepository $alerts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alerts = new InMemoryAlertRepository();
    }

    public function testSendStoresTheAlertAndReturnsItsId(): void
    {
        $id = $this->api()->send([
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
    }

    public function testKindAndTitleAreTheOnlyRequiredFields(): void
    {
        $id = $this->api()->send(['kind' => 'something', 'title' => 'Something happened']);

        $this->assertIsInt($id);
        $this->assertSame('unknown', $this->alerts->alerts[0]->source);
        $this->assertTrue($this->alerts->alerts[0]->isBroadcast());
    }

    /**
     * @dataProvider refusals
     * @param array<string, mixed> $args
     */
    public function testSendExplainsARefusal(array $args, string $code): void
    {
        $result = $this->api()->send($args);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame($code, $result->get_error_code());
        $this->assertSame([], $this->alerts->alerts, 'a refused alert must not be stored');
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function refusals(): array
    {
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
    }

    public function testRegisterHooksTheActionForm(): void
    {
        $this->captureAction(AlertApi::SEND_ACTION);

        $this->api()->register();

        $this->assertCount(1, $this->actionCallbacks(AlertApi::SEND_ACTION));
    }

    public function testTheActionFormRaisesTheAlert(): void
    {
        // The point of the action: a plugin can fire it without depending
        // on Reach being active, because do_action() on an unhooked name
        // is simply inert.
        $this->api()->handleAction(['kind' => 'shift_uncovered', 'title' => 'Shift uncovered']);

        $this->assertCount(1, $this->alerts->alerts);
        $this->assertSame('shift_uncovered', $this->alerts->alerts[0]->kind);
    }

    public function testTheActionFormSwallowsARefusal(): void
    {
        // Deliberate: do_action() has no return value, so there is
        // nowhere to report the refusal to. The dispatcher still logs it,
        // and a plugin that wants to know calls send().
        $this->api()->handleAction(['title' => 'No kind given']);

        $this->assertSame([], $this->alerts->alerts);
    }

    /**
     * @dataProvider nonArrays
     */
    public function testTheActionFormIgnoresAnythingThatIsNotAnArray(mixed $args): void
    {
        // do_action() passes whatever it was given, and a caller firing
        // the hook with a string must not fatal the request it is part of.
        $this->api()->handleAction($args);

        $this->assertSame([], $this->alerts->alerts);
    }

    /** @return array<string, array{0: mixed}> */
    public static function nonArrays(): array
    {
        return [
            'null'   => [null],
            'string' => ['kind=shift_uncovered'],
            'int'    => [42],
            'object' => [new \stdClass()],
        ];
    }

    private function api(): AlertApi
    {
        return new AlertApi(new AlertDispatcher(
            $this->alerts,
            new InMemoryAlertContactRepository(),
            new InMemoryDeviceRepository(),
            new ResponderGate(new InMemoryMemberRepository([])),
            [],
        ));
    }
}
