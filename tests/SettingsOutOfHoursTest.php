<?php

declare(strict_types=1);

namespace Reach\Tests;

use PHPUnit\Framework\TestCase;
use Reach\Core\Settings;

/**
 * Out-of-hours window behaviour on {@see Settings}.
 *
 * The test bootstrap does not define wp_date(), so isOutOfHours() falls
 * back to gmdate() — i.e. the window is evaluated in UTC here. Every
 * epoch below is therefore built with gmmktime() so "now" is an exact,
 * timezone-free clock time and the assertions stay deterministic.
 */
final class SettingsOutOfHoursTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__reach_options'] = [];
    }

    /** Epoch for a given UTC wall-clock time on a fixed reference day. */
    private function at(int $hour, int $minute): int
    {
        return gmmktime($hour, $minute, 0, 1, 1, 2021);
    }

    public function testDisabledWhenUnset(): void
    {
        $settings = new Settings();
        $this->assertFalse($settings->isOutOfHours($this->at(3, 0)));
    }

    public function testDisabledWhenOnlyOneBoundSet(): void
    {
        $settings = new Settings();
        $settings->setOutOfHours('22:00', '');
        $this->assertSame('', $settings->getOutOfHoursEnd());
        $this->assertFalse($settings->isOutOfHours($this->at(23, 0)));
    }

    public function testEqualBoundsTreatedAsOff(): void
    {
        $settings = new Settings();
        $settings->setOutOfHours('09:00', '09:00');
        $this->assertFalse($settings->isOutOfHours($this->at(9, 0)));
        $this->assertFalse($settings->isOutOfHours($this->at(15, 0)));
    }

    public function testSameDayWindow(): void
    {
        $settings = new Settings();
        $settings->setOutOfHours('09:00', '17:00');

        $this->assertTrue($settings->isOutOfHours($this->at(9, 0)));   // start inclusive
        $this->assertTrue($settings->isOutOfHours($this->at(12, 30)));
        $this->assertTrue($settings->isOutOfHours($this->at(16, 59)));
        $this->assertFalse($settings->isOutOfHours($this->at(8, 59)));
        $this->assertFalse($settings->isOutOfHours($this->at(17, 0)));  // end exclusive
        $this->assertFalse($settings->isOutOfHours($this->at(23, 0)));
    }

    public function testWindowSpanningMidnight(): void
    {
        $settings = new Settings();
        $settings->setOutOfHours('22:00', '08:00');

        $this->assertTrue($settings->isOutOfHours($this->at(22, 0)));  // start inclusive
        $this->assertTrue($settings->isOutOfHours($this->at(23, 30)));
        $this->assertTrue($settings->isOutOfHours($this->at(0, 0)));
        $this->assertTrue($settings->isOutOfHours($this->at(7, 59)));
        $this->assertFalse($settings->isOutOfHours($this->at(8, 0)));  // end exclusive
        $this->assertFalse($settings->isOutOfHours($this->at(12, 0)));
        $this->assertFalse($settings->isOutOfHours($this->at(21, 59)));
    }

    public function testNormalisesSecondsAndStores(): void
    {
        $settings = new Settings();
        // An <input type="time" step="1"> can submit H:i:s — the seconds
        // should be dropped to a clean H:i.
        $settings->setOutOfHours('22:00:30', '08:00:00');

        $this->assertSame('22:00', $settings->getOutOfHoursStart());
        $this->assertSame('08:00', $settings->getOutOfHoursEnd());
        $this->assertTrue($settings->isOutOfHours($this->at(23, 0)));
    }

    public function testInvalidTimeStoredBlankAndDisablesWindow(): void
    {
        $settings = new Settings();
        $settings->setOutOfHours('99:99', '08:00');

        $this->assertSame('', $settings->getOutOfHoursStart());
        $this->assertFalse($settings->isOutOfHours($this->at(2, 0)));
    }
}
