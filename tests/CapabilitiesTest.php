<?php

declare(strict_types=1);

namespace Reach\Tests;

use Scrutiny\Privacy\PersonalDataPolicy;
use function Brain\Monkey\Functions\when;
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
 */

covers(\Reach\Core\Capabilities::class);

test('an administrator is given the send capability', function () {
    $role = new WP_Role('administrator');
    when('get_role')->justReturn($role);

    Capabilities::ensureAssigned();

    $this->assertTrue($role->has_cap(Capabilities::SEND_ALERTS));
    $this->assertTrue($role->has_cap(Capabilities::MANAGE_DEVICES));
});

test('a role that already has it is not written to again', function () {
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

    when('get_role')->justReturn($role);

    Capabilities::ensureAssigned();

    $this->assertSame([], $role->granted, 'nothing should be written when the role already has it');
    unset($granted);
});

test('a site with no administrator role is left alone', function () {
    // get_role() answers null on a site whose roles have been
    // rewritten. Reaching into null would fatal on every request.
    when('get_role')->justReturn(null);

    Capabilities::ensureAssigned();

    $this->assertTrue(true, 'no error is the assertion');
});

test('sending is not scrutinys view capability', function () {
    // The whole point of the split: reading the devices screen names
    // responders and is a personal-data read; sending makes every
    // handset on the rota ring. They are not the same permission.
    $this->assertNotSame(
        PersonalDataPolicy::VIEW_CAPABILITY,
        Capabilities::SEND_ALERTS,
    );
    $this->assertNotSame(
        PersonalDataPolicy::VIEW_CAPABILITY,
        Capabilities::MANAGE_DEVICES,
    );
    $this->assertNotSame(
        Capabilities::SEND_ALERTS,
        Capabilities::MANAGE_DEVICES,
        'ringing a handset and cutting one off are different powers',
    );
    $this->assertSame('reach_send_alerts', Capabilities::SEND_ALERTS);
    $this->assertSame('reach_manage_devices', Capabilities::MANAGE_DEVICES);
});
