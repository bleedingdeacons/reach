<?php

declare(strict_types=1);

namespace Reach\Tests;

use Brain\Monkey\Functions;
use Reach\Core\Capabilities;
use WP_Role;

/**
 * Tests for Reach's own capabilities.
 *
 * The one that matters is that the grant runs on every load. A
 * capability handed out only at activation would never reach a site
 * updated over an active plugin — which is how these sites update — and
 * the release introducing it would quietly take the send buttons away
 * from every administrator.
 *
 * @covers \Reach\Core\Capabilities
 */
final class CapabilitiesTest extends ReachTestCase
{
    /** @test */
    public function an_administrator_is_given_the_send_capability(): void
    {
        $role = new WP_Role('administrator');
        Functions\when('get_role')->justReturn($role);

        Capabilities::ensureAssigned();

        $this->assertTrue($role->has_cap(Capabilities::SEND_ALERTS));
        $this->assertTrue($role->has_cap(Capabilities::MANAGE_DEVICES));
    }

    /** @test */
    public function a_role_that_already_has_it_is_not_written_to_again(): void
    {
        // add_cap() writes to the options table, and this runs on every
        // request. The has_cap() guard is what keeps the common path free.
        $granted = [];
        $role = new class ('administrator', [
            Capabilities::SEND_ALERTS    => true,
            Capabilities::MANAGE_DEVICES => true,
        ]) extends WP_Role {
            /** @var array<int, string> */
            public array $granted = [];

            public function add_cap(string $cap, bool $grant = true): void
            {
                $this->granted[] = $cap;
                parent::add_cap($cap, $grant);
            }
        };

        Functions\when('get_role')->justReturn($role);

        Capabilities::ensureAssigned();

        $this->assertSame([], $role->granted, 'nothing should be written when the role already has it');
        unset($granted);
    }

    /** @test */
    public function a_site_with_no_administrator_role_is_left_alone(): void
    {
        // get_role() answers null on a site whose roles have been
        // rewritten. Reaching into null would fatal on every request.
        Functions\when('get_role')->justReturn(null);

        Capabilities::ensureAssigned();

        $this->assertTrue(true, 'no error is the assertion');
    }

    /** @test */
    public function sending_is_not_scrutinys_view_capability(): void
    {
        // The whole point of the split: reading the devices screen names
        // responders and is a personal-data read; sending makes every
        // handset on the rota ring. They are not the same permission.
        $this->assertNotSame(
            \Scrutiny\Privacy\PersonalDataPolicy::VIEW_CAPABILITY,
            Capabilities::SEND_ALERTS,
        );
        $this->assertNotSame(
            \Scrutiny\Privacy\PersonalDataPolicy::VIEW_CAPABILITY,
            Capabilities::MANAGE_DEVICES,
        );
        $this->assertNotSame(
            Capabilities::SEND_ALERTS,
            Capabilities::MANAGE_DEVICES,
            'ringing a handset and cutting one off are different powers',
        );
        $this->assertSame('reach_send_alerts', Capabilities::SEND_ALERTS);
        $this->assertSame('reach_manage_devices', Capabilities::MANAGE_DEVICES);
    }
}
