<?php

declare(strict_types=1);

namespace Uengage\I18n;

final class LocaleMap
{
    const DEFAULT_COUNTRY = 'IN';
    const DEFAULT_CURRENCY = 'INR';

    /** ISO country -> default BCP-47 locale. */
    const COUNTRY_LOCALE = [
        'IN' => 'en-IN',
        'CA' => 'en-CA',
        'AE' => 'en-AE',
        'NZ' => 'en-NZ',
        'PT' => 'pt-PT',
        'US' => 'en-US',
    ];

    /** Home country of a currency; EUR -> PT because Portugal is Meridian's only EUR market. */
    const CURRENCY_COUNTRY = [
        'INR' => 'IN',
        'CAD' => 'CA',
        'AED' => 'AE',
        'NZD' => 'NZ',
        'EUR' => 'PT',
        'USD' => 'US',
    ];

    /** explicit locale -> country -> currency's home country -> India. */
    public static function resolve(string $currency, ?string $country, ?string $locale = null): string
    {
        if ($locale !== null && $locale !== '') {
            return $locale;
        }
        if ($country === null || !isset(self::COUNTRY_LOCALE[$country])) {
            $country = self::CURRENCY_COUNTRY[$currency] ?? self::DEFAULT_COUNTRY;
        }
        return self::COUNTRY_LOCALE[$country];
    }
}
