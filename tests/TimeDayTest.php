<?php

declare(strict_types=1);

namespace Uengage\I18n\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Uengage\I18n\I18n;

/** Runs the same fixtures as the JS package (packages/i18n/test/fixtures/time-day.json). */
class TimeDayTest extends TestCase
{
    private static function fixtures(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../../i18n/test/fixtures/time-day.json'), true);
    }

    public function testDayBounds(): void
    {
        foreach (self::fixtures()['bounds'] as $f) {
            $time = I18n::forConfig(['timezone' => $f['timezone']])->time;
            $label = json_encode($f['timezone']) . ' ' . $f['date'];
            $this->assertSame($f['startOfDay'], $time->startOfDay($f['date']), "startOfDay $label");
            $this->assertSame($f['endOfDay'], $time->endOfDay($f['date']), "endOfDay $label");
        }
    }

    public function testToday(): void
    {
        foreach (self::fixtures()['today'] as $f) {
            $time = I18n::forConfig(['timezone' => $f['timezone']])->time;
            $this->assertSame($f['today'], $time->today(strtotime($f['now'])), json_encode($f));
        }
    }

    public function testToInstant(): void
    {
        $time = I18n::forConfig(['timezone' => 'America/Toronto'])->time;
        foreach (self::fixtures()['instants'] as $f) {
            $this->assertSame($f['instant'], gmdate('Y-m-d\TH:i:s\Z', $time->toInstant($f['input'])), $f['input']);
        }
    }

    public function testFromLocal(): void
    {
        foreach (self::fixtures()['fromLocal'] as $f) {
            $time = I18n::forConfig(['timezone' => $f['timezone']])->time;
            $this->assertSame($f['ist'], $time->fromLocal($f['local']), json_encode($f));
        }
    }

    public function testToLocal(): void
    {
        foreach (self::fixtures()['toLocal'] as $f) {
            $time = I18n::forConfig(['timezone' => $f['timezone']])->time;
            $this->assertSame($f['local'], $time->toLocal($f['input']), json_encode($f));
        }
    }

    public function testFromLocalRejectsBadInput(): void
    {
        $time = I18n::forConfig(['timezone' => 'America/Toronto'])->time;
        foreach (['', '2026-10-22', '2026-02-30 10:00', '2026-10-22 24:00', '2026-10-22T10:00:00Z', 'nope'] as $bad) {
            try {
                $time->fromLocal($bad);
                $this->fail("expected InvalidArgumentException for fromLocal('{$bad}')");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('time:', $e->getMessage());
            }
        }
        $this->assertSame('2026-10-22 10:00:00', $time->toLocal($time->fromLocal('2026-10-22 10:00:00')));
    }

    public function testDefaultsToBusinessToday(): void
    {
        $time = I18n::forConfig(['timezone' => 'Asia/Kolkata'])->time;
        $this->assertSame(gmdate('Y-m-d', time() + 19800), $time->today());
        $this->assertSame($time->today() . ' 00:00:00', $time->startOfDay());
        $this->assertSame($time->today() . ' 23:59:59', $time->endOfDay());
    }

    public function testRejectsBadDates(): void
    {
        $time = I18n::forConfig(['timezone' => 'America/Toronto'])->time;
        foreach (['', '2026-02-30', '22:05', '2026-10-22 10:00:00', '0000-00-00'] as $bad) {
            foreach (['startOfDay', 'endOfDay'] as $method) {
                try {
                    $time->{$method}($bad);
                    $this->fail("expected InvalidArgumentException for {$method}('{$bad}')");
                } catch (InvalidArgumentException $e) {
                    $this->assertStringContainsString('time:', $e->getMessage());
                }
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $time->toInstant('2026-10-22');
    }
}
