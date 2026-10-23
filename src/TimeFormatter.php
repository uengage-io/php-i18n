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
        return $this->render($this->inZone($input), 'dateTime');
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

    /** @param DateTimeInterface|int|string $input unix seconds; a naive "YYYY-MM-DD HH:mm[:ss]" is IST */
    public function toInstant($input): int
    {
        return $this->inZone($input)->getTimestamp();
    }

    /** The business's current date, "YYYY-MM-DD". @param int|null $now unix seconds */
    public function today(?int $now = null): string
    {
        return (new DateTime('@' . ($now ?? time())))->setTimezone($this->zone)->format('Y-m-d');
    }

    /** IST DB lower bound of a business-local day (default today): "2026-10-22 09:30:00" in Toronto. */
    public function startOfDay(?string $date = null): string
    {
        return self::ist($this->midnight($this->day($date)));
    }

    /** IST DB upper bound of a business-local day (default today), inclusive to the second. */
    public function endOfDay(?string $date = null): string
    {
        $next = (new DateTime($this->day($date), new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
        return self::ist($this->midnight($next)->modify('-1 second'));
    }

    public function fromLocal(string $value): string
    {
        if (!preg_match(self::DATE_TIME, trim($value), $m)) {
            throw self::invalid($value);
        }
        $seconds = isset($m[6]) ? (int) $m[6] : 0;
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || $seconds > 59) {
            throw self::invalid($value);
        }
        return self::ist($this->localToInstant(sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[1], $m[2], $m[3], $m[4], $m[5], $seconds)));
    }

    /** @param DateTimeInterface|int|string $input a DB value as the business's local wall time (e.g. for edit forms) */
    public function toLocal($input): string
    {
        return $this->inZone($input)->format('Y-m-d H:i:s');
    }

    private function day(?string $date): string
    {
        $value = trim($date ?? $this->today());
        if (!preg_match(self::DATE_ONLY, $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw self::invalid($date);
        }
        return $value;
    }

    private function midnight(string $date): DateTime
    {
        return $this->localToInstant($date . ' 00:00:00');
    }

    // Same rule as the JS package, not DateTime's own (which picks the later of a doubled time in some zones):
    // a wall time skipped by DST moves forward, one that happens twice resolves to the first.
    private function localToInstant(string $local): DateTime
    {
        $target = (new DateTime($local, new DateTimeZone('UTC')))->getTimestamp();
        $early = $target - $this->zone->getOffset(new DateTime('@' . ($target - 86400)));
        $late = $target - $this->zone->getOffset(new DateTime('@' . ($target + 86400)));
        foreach ([$early, $late] as $at) {
            if ($at + $this->zone->getOffset(new DateTime('@' . $at)) === $target) {
                return new DateTime('@' . $at);
            }
        }
        return new DateTime('@' . $early);
    }

    private static function ist(DateTime $at): string
    {
        return $at->setTimezone(new DateTimeZone(self::FALLBACK_TIMEZONE))->format('Y-m-d H:i:s');
    }

    /** @param mixed $input a date-only "2026-10-19" is a calendar date and is not shifted */
    private function datePart($input, string $style): string
    {
        if (is_string($input) && preg_match(self::DATE_ONLY, trim($input), $m)) {
            return $this->render(self::wall($input, (int) $m[1], (int) $m[2], (int) $m[3]), $style);
        }
        return $this->render($this->inZone($input), $style);
    }

    /** @param mixed $input a time-only "22:05" is a wall-clock time and is not shifted */
    private function timePart($input, string $style): string
    {
        if (is_string($input) && preg_match(self::TIME_ONLY, trim($input), $m)) {
            $seconds = isset($m[3]) ? (int) $m[3] : 0;
            return $this->render(self::wall($input, 1970, 1, 1, (int) $m[1], (int) $m[2], $seconds), $style);
        }
        return $this->render($this->inZone($input), $style);
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
    private function inZone($input): DateTime
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
