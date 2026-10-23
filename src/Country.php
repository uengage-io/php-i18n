<?php

declare(strict_types=1);

namespace Uengage\I18n;

/**
 * `addo_business.country` is free text (e.g. "Canada") and mostly null, so
 * normalise whatever shows up to an ISO 3166-1 alpha-2 code, or null.
 */
final class Country
{
    const ALIASES = [
        'IN' => 'IN', 'IND' => 'IN', 'INDIA' => 'IN',
        'CA' => 'CA', 'CAN' => 'CA', 'CANADA' => 'CA',
        'AE' => 'AE', 'ARE' => 'AE', 'UAE' => 'AE', 'DUBAI' => 'AE', 'UNITED ARAB EMIRATES' => 'AE',
        'PT' => 'PT', 'PRT' => 'PT', 'PORTUGAL' => 'PT',
        'NZ' => 'NZ', 'NZL' => 'NZ', 'NEW ZEALAND' => 'NZ',
        'US' => 'US', 'USA' => 'US', 'UNITED STATES' => 'US', 'UNITED STATES OF AMERICA' => 'US',
    ];

    public static function normalise(?string $raw): ?string
    {
        $key = strtoupper(str_replace('.', '', trim((string) $raw)));
        if ($key === '') {
            return null;
        }
        return self::ALIASES[$key] ?? null;
    }
}
