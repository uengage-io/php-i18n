<?php

declare(strict_types=1);

namespace Uengage\I18n;

use InvalidArgumentException;
use NumberFormatter;

/**
 * Symbol + locale separators, always 2 decimals, half-up rounding (same as the JS package).
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
    /** @var NumberFormatter|null */
    private $icu;

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
        if ($useIntl) {
            $this->icu = new NumberFormatter($locale, NumberFormatter::CURRENCY);
            $this->icu->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 2);
            $this->icu->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 2);
            $this->icu->setAttribute(NumberFormatter::ROUNDING_MODE, NumberFormatter::ROUND_HALFUP);
        }
    }

    /**
     * @param int|float|string $amount number or plain decimal string ("1234.50")
     */
    public function format($amount): string
    {
        $value = $this->toFloat($amount);
        if ($this->icu !== null) {
            $out = $this->icu->formatCurrency($value, $this->code);
            if ($out !== false) {
                return $out;
            }
        }
        return $this->formatBuiltIn($value);
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

    private function formatBuiltIn(float $value): string
    {
        $negative = $value < 0;
        $fixed = number_format(round(abs($value), 2), 2, '.', '');
        list($int, $frac) = explode('.', $fixed);

        if ($this->locale === 'pt-PT') {
            // ICU pt-PT: minimum grouping 2 (no separator for 4-digit numbers), NBSP group, comma decimal.
            $int = strlen($int) >= 5 ? $this->group($int, [3], self::NBSP) : $int;
            $num = $int . ',' . $frac;
            $symbol = trim($this->symbol(), self::NBSP . ' ');
            return ($negative ? '-' : '') . $num . self::NBSP . $symbol;
        }

        $int = $this->locale === 'en-IN' ? $this->group($int, [3, 2], ',') : $this->group($int, [3], ',');
        return ($negative ? '-' : '') . $this->symbol() . $int . '.' . $frac;
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
