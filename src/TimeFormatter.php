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
    /** locale => [date pattern, time pattern, replacements on the formatted string] */
    const PATTERNS = [
        'en-IN' => ['j M Y', 'g:i a', ['Sep ' => 'Sept ']],
        'en-NZ' => ['j M Y', 'g:i a', ['Sep ' => 'Sept ']],
        'en-AE' => ['j M Y', 'g:i A', []],
        'en-CA' => ['M j, Y', 'g:i a', ['am' => 'a.m.', 'pm' => 'p.m.']],
        'en-US' => ['M j, Y', 'g:i A', []],
        'pt-PT' => ['d/m/Y', 'H:i', []],
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
        if (is_string($input) && preg_match(self::DATE_ONLY, trim($input), $m)) {
            return $this->render(self::wall($input, (int) $m[1], (int) $m[2], (int) $m[3]), true, false);
        }
        return $this->render($this->toLocal($input), true, false);
    }

    /** @param DateTimeInterface|int|string $input "22:05" is a wall-clock time and is not shifted */
    public function formatTime($input): string
    {
        if (is_string($input) && preg_match(self::TIME_ONLY, trim($input), $m)) {
            $seconds = isset($m[3]) ? (int) $m[3] : 0;
            return $this->render(self::wall($input, 1970, 1, 1, (int) $m[1], (int) $m[2], $seconds), false, true);
        }
        return $this->render($this->toLocal($input), false, true);
    }

    /** @param DateTimeInterface|int|string $input int = unix seconds */
    public function formatDateTime($input): string
    {
        return $this->render($this->toLocal($input), true, true);
    }

    private function render(DateTimeInterface $at, bool $date, bool $time): string
    {
        list($datePattern, $timePattern, $replace) = self::PATTERNS[$this->locale] ?? self::PATTERNS['en-IN'];
        $parts = [];
        if ($date) {
            $parts[] = $at->format($datePattern);
        }
        if ($time) {
            $parts[] = $at->format($timePattern);
        }
        return strtr(implode(', ', $parts), $replace);
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
