<?php

declare(strict_types=1);

namespace Uengage\I18n;

use DateTime;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use InvalidArgumentException;

/** Built-in table, not ext-intl: PHP's ICU puts U+202F before "pm" where JS (and the shared fixtures) use a space. */
final class TimeFormatter
{
    const FALLBACK_TIMEZONE = 'Asia/Kolkata';
    const DATE_TIME = '/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/';
    const DATE_TIME_WITH_OFFSET = '/^(\d{4})-(\d{2})-(\d{2})T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:\d{2})$/';
    const DATE_ONLY = '/^(\d{4})-(\d{2})-(\d{2})$/';
    const TIME_ONLY = '/^(\d{2}):(\d{2})(?::(\d{2}))?$/';
    const ZONE_NAME = '#^(?:UTC|[A-Za-z]+(?:/[A-Za-z0-9_+-]+)+)$#i';
    const PATTERNS = [
        'en-IN' => ['date' => 'j M Y', 'time' => 'g:i:s a', 'hour' => 'g a', 'weekday' => 'D', 'dayMonth' => 'j M', 'monthYear' => 'M Y'],
        'en-NZ' => ['date' => 'j M Y', 'time' => 'g:i:s a', 'hour' => 'g a', 'weekday' => 'D', 'dayMonth' => 'j M', 'monthYear' => 'M Y'],
        'en-AE' => ['date' => 'j M Y', 'time' => 'g:i:s A', 'hour' => 'g A', 'weekday' => 'D', 'dayMonth' => 'j M', 'monthYear' => 'M Y'],
        'en-CA' => ['date' => 'M j, Y', 'time' => 'g:i:s a', 'hour' => 'g a', 'weekday' => 'D', 'dayMonth' => 'M j', 'monthYear' => 'M Y'],
        'en-US' => ['date' => 'M j, Y', 'time' => 'g:i:s A', 'hour' => 'g A', 'weekday' => 'D', 'dayMonth' => 'M j', 'monthYear' => 'M Y'],
        'pt-PT' => ['date' => 'd/m/Y', 'time' => 'H:i:s', 'hour' => 'H', 'weekday' => 'D', 'dayMonth' => 'j/m', 'monthYear' => 'm/Y'],
    ];
    /** CLDR spellings DateTime::format() can't produce: en-IN/en-NZ "Sept", en-CA "a.m." */
    const REPLACE = [
        'en-IN' => ['/\bSep\b/' => 'Sept'],
        'en-NZ' => ['/\bSep\b/' => 'Sept'],
        'en-CA' => ['/\b([ap])m\b/' => '$1.m.'],
    ];

    /** @var string */
    public $timezone;
    /** @var string */
    public $locale;
    /** @var DateTimeZone */
    private $zone;

    public function __construct(?string $timezone, string $locale)
    {
        $this->timezone = self::zone($timezone);
        $this->locale = $locale;
        $this->zone = new DateTimeZone($this->timezone);
    }

    /** @param DateTimeInterface|int|string $input "2026-10-19" is a calendar date and is not shifted */
    public function formatDate($input): string
    {
        return $this->datePart($input, 'date');
    }

    /** @param DateTimeInterface|int|string $input "22:05" is a wall-clock time and is not shifted */
    public function formatTime($input): string
    {
        return $this->timePart($input, 'time');
    }

    /** @param DateTimeInterface|int|string $input int = unix seconds */
    public function formatDateTime($input): string
    {
        return $this->render($this->toLocal($input), 'dateTime');
    }

    /** @param DateTimeInterface|int|string $input */
    public function formatHour($input): string
    {
        return $this->timePart($input, 'hour');
    }

    /** @param DateTimeInterface|int|string $input */
    public function formatWeekday($input): string
    {
        return $this->datePart($input, 'weekday');
    }

    /** @param DateTimeInterface|int|string $input */
    public function formatDayMonth($input): string
    {
        return $this->datePart($input, 'dayMonth');
    }

    /** @param DateTimeInterface|int|string $input */
    public function formatMonthYear($input): string
    {
        return $this->datePart($input, 'monthYear');
    }

    /** @param mixed $input a date-only "2026-10-19" is a calendar date and is not shifted */
    private function datePart($input, string $style): string
    {
        if (is_string($input) && preg_match(self::DATE_ONLY, trim($input), $m)) {
            return $this->render(self::wall($input, (int) $m[1], (int) $m[2], (int) $m[3]), $style);
        }
        return $this->render($this->toLocal($input), $style);
    }

    /** @param mixed $input a time-only "22:05" is a wall-clock time and is not shifted */
    private function timePart($input, string $style): string
    {
        if (is_string($input) && preg_match(self::TIME_ONLY, trim($input), $m)) {
            $seconds = isset($m[3]) ? (int) $m[3] : 0;
            return $this->render(self::wall($input, 1970, 1, 1, (int) $m[1], (int) $m[2], $seconds), $style);
        }
        return $this->render($this->toLocal($input), $style);
    }

    private function render(DateTimeInterface $at, string $style): string
    {
        $locale = isset(self::PATTERNS[$this->locale]) ? $this->locale : 'en-IN';
        $p = self::PATTERNS[$locale];
        $out = $style === 'dateTime' ? $at->format($p['date']) . ', ' . $at->format($p['time']) : $at->format($p[$style]);
        $replace = self::REPLACE[$locale] ?? [];
        return (string) preg_replace(array_keys($replace), array_values($replace), $out);
    }

    /** @param mixed $input */
    private function toLocal($input): DateTime
    {
        $s = is_string($input) ? trim($input) : '';
        if ($input instanceof DateTimeInterface) {
            $at = new DateTime('@' . $input->getTimestamp());
        } elseif (is_int($input)) {
            $at = new DateTime('@' . $input);
        } elseif (preg_match(self::DATE_TIME, $s, $m)) {
            // Naive DB datetimes are IST wall time with no offset.
            $seconds = isset($m[6]) ? (int) $m[6] : 0;
            $at = self::wall($input, (int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], $seconds, self::FALLBACK_TIMEZONE);
        } elseif (preg_match(self::DATE_TIME_WITH_OFFSET, $s, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            try {
                $at = new DateTime($s);
            } catch (Exception $e) {
                throw self::invalid($input);
            }
        } else {
            throw self::invalid($input);
        }
        return $at->setTimezone($this->zone);
    }

    /** @param mixed $input */
    private static function wall($input, int $y, int $mo, int $d, int $h = 0, int $mi = 0, int $s = 0, string $zone = 'UTC'): DateTime
    {
        if (!checkdate($mo, $d, $y) || $h > 23 || $mi > 59 || $s > 59) {
            throw self::invalid($input);
        }
        return (new DateTime('now', new DateTimeZone($zone)))->setDate($y, $mo, $d)->setTime($h, $mi, $s);
    }

    // Same rule as the JS package: an IANA Area/Location name or UTC, any case; offsets and abbreviations fall back.
    private static function zone(?string $timezone): string
    {
        if ($timezone !== null && preg_match(self::ZONE_NAME, $timezone)) {
            foreach (DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC) as $id) {
                if (strcasecmp($id, $timezone) === 0) {
                    return $id;
                }
            }
        }
        return self::FALLBACK_TIMEZONE;
    }

    /** @param mixed $input */
    private static function invalid($input): InvalidArgumentException
    {
        return new InvalidArgumentException('time: not a date/time (got ' . json_encode($input) . ')');
    }
}
