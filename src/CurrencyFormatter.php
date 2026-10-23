<?php

declare(strict_types=1);

namespace Uengage\I18n;

use InvalidArgumentException;
use NumberFormatter;

/**
 * Symbol + locale separators, 2 decimals by default, half-up rounding (same as the JS package).
 * Uses ICU (ext-intl) when present; otherwise (and always for pt-PT) a built-in table covering
 * the Meridian markets.
 */
final class CurrencyFormatter
{
    const NBSP = "\xC2\xA0";

    /** Short symbol where the currency is "at home" (locale country == currency country). */
    const SYMBOL = ['INR' => '₹', 'CAD' => '$', 'NZD' => '$', 'EUR' => '€', 'USD' => '$', 'AED' => "AED\xC2\xA0"];
    /** Disambiguated symbol for $-currencies shown in a foreign locale. */
    const FOREIGN_SYMBOL = ['CAD' => 'CA$', 'NZD' => 'NZ$', 'USD' => 'US$'];

    /** @var string */
    public $code;
    /** @var string */
    public $locale;
    /** @var bool */
    private $useIntl;
    /** @var NumberFormatter[] keyed by "decimals|symbol" */
    private $icu = [];

    /**
     * @param bool|null $useIntl null = use ext-intl when loaded; false forces the built-in table (tests)
     */
    public function __construct(string $code, string $locale, ?bool $useIntl = null)
    {
        $this->code = $code;
        $this->locale = $locale;
        if ($useIntl === null) {
            $useIntl = class_exists('NumberFormatter');
        }
        // pt-PT: the legacy NumberFormatter ignores CLDR minimumGroupingDigits=2 and prints
        // "1 234,56 €" where Intl (JS) prints "1234,56 €". Use the table so both packages agree.
        if ($locale === 'pt-PT') {
            $useIntl = false;
        }
        $this->useIntl = $useIntl;
    }

    /**
     * Options (same as the JS package): `notation` 'standard' (default) | 'compact', `decimals`
     * int 0-10 (default 2), `showSymbol` bool (default true).
     * Compact: en-IN "₹1.20 Lac" / "₹3.00 Cr" (full format below 1 lakh); others "$1.2K", "$3M",
     * "1,2 mil €" from a built-in table (ext-intl has no compact notation).
     *
     * @param int|float|string $amount number or plain decimal string ("1234.50")
     * @param array $options
     */
    public function format($amount, array $options = []): string
    {
        $value = $this->toFloat($amount);
        $notation = $options['notation'] ?? 'standard';
        $decimals = $options['decimals'] ?? null;
        $symbol = $options['showSymbol'] ?? true;
        if ($notation !== 'standard' && $notation !== 'compact') {
            throw new InvalidArgumentException("currency->format: notation must be 'standard' or 'compact'");
        }
        if ($decimals !== null && (!is_int($decimals) || $decimals < 0 || $decimals > 10)) {
            throw new InvalidArgumentException('currency->format: decimals must be an integer 0-10');
        }
        if (!is_bool($symbol)) {
            throw new InvalidArgumentException('currency->format: showSymbol must be a bool');
        }
        if ($notation === 'standard') {
            return $this->formatStandard($value, $decimals ?? 2, $symbol);
        }
        return $this->formatCompact($value, $decimals, $symbol);
    }

    private function formatStandard(float $value, int $decimals, bool $symbol): string
    {
        $icu = $this->icu($decimals, $symbol);
        if ($icu !== null) {
            $out = $symbol ? $icu->formatCurrency($value, $this->code) : $icu->format($value);
            if ($out !== false) {
                return $out;
            }
        }
        return $this->formatBuiltIn($value, $decimals, $symbol);
    }

    private function icu(int $decimals, bool $symbol): ?NumberFormatter
    {
        if (!$this->useIntl) {
            return null;
        }
        $key = $decimals . '|' . ($symbol ? 1 : 0);
        if (!isset($this->icu[$key])) {
            $f = new NumberFormatter($this->locale, $symbol ? NumberFormatter::CURRENCY : NumberFormatter::DECIMAL);
            $f->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
            $f->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
            $f->setAttribute(NumberFormatter::ROUNDING_MODE, NumberFormatter::ROUND_HALFUP);
            $this->icu[$key] = $f;
        }
        return $this->icu[$key];
    }

    private function formatCompact(float $value, ?int $decimals, bool $symbol): string
    {
        $abs = abs($value);
        if ($this->locale === 'en-IN') {
            $d = $decimals ?? 2;
            if ($abs < 1e5) {
                return $this->formatStandard($value, $d, $symbol);
            }
            // 99,99,999 rounds to 100.00 Lac: use Cr instead.
            $cr = $abs >= 1e7 || round($abs / 1e5, $d) >= 100;
            return $this->formatStandard($value / ($cr ? 1e7 : 1e5), $d, $symbol) . ($cr ? ' Cr' : ' Lac');
        }

        $pt = $this->locale === 'pt-PT';
        // pt-PT stops at "mM": ICU data for the trillion unit differs between versions.
        $units = $pt ? ['', 'mil', 'M', 'mM'] : ['', 'K', 'M', 'B', 'T'];
        $i = $abs >= 1e3 ? min(count($units) - 1, (int) floor(log10($abs) / 3)) : 0;
        // Default: up to 2 significant digits below 100 of a unit, whole numbers from 100.
        $round = function ($v) use ($decimals) {
            if ($decimals !== null) {
                return round($v, $decimals);
            }
            return $v < 10 ? round($v, 1) : round($v);
        };
        $n = $round($abs / pow(1000, $i));
        if ($n >= 1000 && $i < count($units) - 1) {
            $i++;
            $n = $round($abs / pow(1000, $i));
        }
        $dp = $decimals ?? 1;
        $num = number_format($n, $dp, $pt ? ',' : '.', '');
        if ($pt && $n >= 1e4) {
            // Whole part of 5+ digits is grouped (pt-PT minimum grouping 2), only reached at >= 1 trillion.
            $bits = explode(',', $num);
            $bits[0] = $this->group($bits[0], [3], self::NBSP);
            $num = implode(',', $bits);
        }
        if ($decimals === null && $dp > 0) {
            $num = rtrim(rtrim($num, '0'), $pt ? ',' : '.');
        }
        $sign = $value < 0 ? '-' : '';
        $unit = $units[$i];
        if ($pt) {
            $out = $num . ($unit === '' ? '' : self::NBSP . $unit);
            return $sign . ($symbol ? $out . self::NBSP . trim($this->symbol(), self::NBSP . ' ') : $out);
        }
        return $sign . ($symbol ? $this->symbol() : '') . $num . $unit;
    }

    /** @param mixed $amount */
    private function toFloat($amount): float
    {
        if (is_string($amount)) {
            if (!preg_match('/^\s*-?\d+(\.\d+)?\s*$/', $amount)) {
                throw new InvalidArgumentException("currency->format: not a numeric amount (got \"{$amount}\")");
            }
            return (float) $amount;
        }
        if ((!is_int($amount) && !is_float($amount)) || (is_float($amount) && !is_finite($amount))) {
            throw new InvalidArgumentException('currency->format: amount must be a finite number');
        }
        return (float) $amount;
    }

    private function symbol(): string
    {
        $country = substr($this->locale, -2);
        $home = LocaleMap::CURRENCY_COUNTRY[$this->code] ?? null;
        if ($home === $country || !isset(self::FOREIGN_SYMBOL[$this->code])) {
            return self::SYMBOL[$this->code] ?? $this->code . self::NBSP;
        }
        return self::FOREIGN_SYMBOL[$this->code];
    }

    private function formatBuiltIn(float $value, int $decimals, bool $withSymbol): string
    {
        $negative = $value < 0;
        $fixed = number_format(round(abs($value), $decimals), $decimals, '.', '');
        $parts = explode('.', $fixed);
        $int = $parts[0];
        $frac = $parts[1] ?? '';
        $dot = $decimals > 0 ? '.' : '';

        if ($this->locale === 'pt-PT') {
            // ICU pt-PT: minimum grouping 2 (no separator for 4-digit numbers), NBSP group, comma decimal.
            $int = strlen($int) >= 5 ? $this->group($int, [3], self::NBSP) : $int;
            $num = $int . ($decimals > 0 ? ',' : '') . $frac;
            if (!$withSymbol) {
                return ($negative ? '-' : '') . $num;
            }
            $symbol = trim($this->symbol(), self::NBSP . ' ');
            return ($negative ? '-' : '') . $num . self::NBSP . $symbol;
        }

        $int = $this->locale === 'en-IN' ? $this->group($int, [3, 2], ',') : $this->group($int, [3], ',');
        return ($negative ? '-' : '') . ($withSymbol ? $this->symbol() : '') . $int . $dot . $frac;
    }

    /** @param int[] $sizes first = lowest group, last repeats */
    private function group(string $digits, array $sizes, string $sep): string
    {
        $parts = [];
        $i = 0;
        while (strlen($digits) > $sizes[min($i, count($sizes) - 1)]) {
            $size = $sizes[min($i, count($sizes) - 1)];
            array_unshift($parts, substr($digits, -$size));
            $digits = substr($digits, 0, -$size);
            $i++;
        }
        array_unshift($parts, $digits);
        return implode($sep, $parts);
    }
}
