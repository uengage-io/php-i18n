<?php

declare(strict_types=1);

namespace Uengage\I18n\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Uengage\I18n\I18n;

/** Runs the same fixtures as the JS package (packages/i18n/test/fixtures). */
class TimeFormatTest extends TestCase
{
    /** @return array<string, array{0: array}> */
    public function fixtures(): array
    {
        $path = __DIR__ . '/../../i18n/test/fixtures/time-format.json';
        $rows = json_decode((string) file_get_contents($path), true);
        $cases = [];
        foreach ($rows as $i => $row) {
            $cases[sprintf('#%d %s/%s/%s(%s)', $i, $row['country'], json_encode($row['timezone']), $row['method'], $row['input'])] = [$row];
        }
        return $cases;
    }

    /** @dataProvider fixtures */
    public function testMatchesSharedFixtures(array $row): void
    {
        $i18n = I18n::forConfig(['country' => $row['country'], 'timezone' => $row['timezone']]);
        $this->assertSame($row['expected'], $i18n->time->{$row['method']}($row['input']));
    }

    public function testTakesDateTimeAndUnixSecondsAsInstants(): void
    {
        $time = I18n::forConfig(['country' => 'CA', 'timezone' => 'America/Toronto'])->time;
        $at = new DateTimeImmutable('2026-10-19T16:35:00Z');
        $this->assertSame('Oct 19, 2026, 12:35 p.m.', $time->formatDateTime($at));
        $this->assertSame('Oct 19, 2026, 12:35 p.m.', $time->formatDateTime($at->getTimestamp()));
    }

    public function testRejectsBadInput(): void
    {
        $time = I18n::forConfig(['country' => 'CA', 'timezone' => 'America/Toronto'])->time;
        $bad = [
            ['formatDateTime', ''],
            ['formatDateTime', 'abc'],
            ['formatDateTime', '0000-00-00 00:00:00'],
            ['formatDateTime', '2026-02-30 10:00:00'],
            ['formatDateTime', '2026-02-30T10:00:00Z'],
            ['formatDateTime', '2026-10-19 24:00:00'],
            ['formatDateTime', '2026-10-19 22:60'],
            ['formatDateTime', '19/10/2026'],
            ['formatDateTime', 1.5],
            ['formatDateTime', null],
            ['formatTime', '2026-10-19'],
            ['formatDate', '22:05'],
            ['formatTime', '25:00'],
        ];
        foreach ($bad as list($method, $input)) {
            try {
                $time->{$method}($input);
                $this->fail("expected InvalidArgumentException for {$method}(" . var_export($input, true) . ')');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('time:', $e->getMessage());
            }
        }
    }

    public function testExposesResolvedTimezone(): void
    {
        $this->assertSame('America/Toronto', I18n::forConfig(['timezone' => 'America/Toronto'])->time->timezone);
        $this->assertSame('Asia/Kolkata', I18n::forConfig(['timezone' => null])->time->timezone);
        $this->assertSame('Asia/Kolkata', I18n::forConfig(['timezone' => 'Mars/Base'])->time->timezone);
    }
}
