<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
use Reach\Core\Settings;

/**
 * Out-of-hours window behaviour on {@see Settings}.
 *
 * The test bootstrap does not define wp_date(), so isOutOfHours() falls
 * back to gmdate() — i.e. the window is evaluated in UTC here. Every
 * epoch below is therefore built with gmmktime() so "now" is an exact,
 * timezone-free clock time and the assertions stay deterministic.
 */

/** Epoch for a given UTC wall-clock time on a fixed reference day. */
function epochAt(int $hour, int $minute): int
{
    return gmmktime($hour, $minute, 0, 1, 1, 2021);
}

beforeEach(function () {
    WpState::$options = [];
});

test('disabled when unset', function () {
    $settings = new Settings();
    $this->assertFalse($settings->isOutOfHours(epochAt(3, 0)));
});

test('disabled when only one bound set', function () {
    $settings = new Settings();
    $settings->setOutOfHours('22:00', '');
    $this->assertSame('', $settings->getOutOfHoursEnd());
    $this->assertFalse($settings->isOutOfHours(epochAt(23, 0)));
});

test('equal bounds treated as off', function () {
    $settings = new Settings();
    $settings->setOutOfHours('09:00', '09:00');
    $this->assertFalse($settings->isOutOfHours(epochAt(9, 0)));
    $this->assertFalse($settings->isOutOfHours(epochAt(15, 0)));
});

test('same day window', function () {
    $settings = new Settings();
    $settings->setOutOfHours('09:00', '17:00');

    $this->assertTrue($settings->isOutOfHours(epochAt(9, 0)));   // start inclusive
    $this->assertTrue($settings->isOutOfHours(epochAt(12, 30)));
    $this->assertTrue($settings->isOutOfHours(epochAt(16, 59)));
    $this->assertFalse($settings->isOutOfHours(epochAt(8, 59)));
    $this->assertFalse($settings->isOutOfHours(epochAt(17, 0)));  // end exclusive
    $this->assertFalse($settings->isOutOfHours(epochAt(23, 0)));
});

test('window spanning midnight', function () {
    $settings = new Settings();
    $settings->setOutOfHours('22:00', '08:00');

    $this->assertTrue($settings->isOutOfHours(epochAt(22, 0)));  // start inclusive
    $this->assertTrue($settings->isOutOfHours(epochAt(23, 30)));
    $this->assertTrue($settings->isOutOfHours(epochAt(0, 0)));
    $this->assertTrue($settings->isOutOfHours(epochAt(7, 59)));
    $this->assertFalse($settings->isOutOfHours(epochAt(8, 0)));  // end exclusive
    $this->assertFalse($settings->isOutOfHours(epochAt(12, 0)));
    $this->assertFalse($settings->isOutOfHours(epochAt(21, 59)));
});

test('normalises seconds and stores', function () {
    $settings = new Settings();
    // An <input type="time" step="1"> can submit H:i:s — the seconds
    // should be dropped to a clean H:i.
    $settings->setOutOfHours('22:00:30', '08:00:00');

    $this->assertSame('22:00', $settings->getOutOfHoursStart());
    $this->assertSame('08:00', $settings->getOutOfHoursEnd());
    $this->assertTrue($settings->isOutOfHours(epochAt(23, 0)));
});

test('invalid time stored blank and disables window', function () {
    $settings = new Settings();
    $settings->setOutOfHours('99:99', '08:00');

    $this->assertSame('', $settings->getOutOfHoursStart());
    $this->assertFalse($settings->isOutOfHours(epochAt(2, 0)));
});
