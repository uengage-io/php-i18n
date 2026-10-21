# uengage.io/i18n (PHP ≥ 7.1)

PHP port of `@uengage.io/i18n`: per-business currency formatting.

```php
\Uengage\I18n\I18n::configure(['platform' => $platformClient]); // Uengage\PlatformSdk\Client
$i18n = \Uengage\I18n\I18n::forBusinessId($businessId);
echo $i18n->currency->format($amount); // "₹1,23,456.50"
echo $i18n->time->formatDateTime('2026-10-19 22:05:00'); // "19 Oct 2026, 10:05 pm"
```

- `forBusinessId` calls `$platformClient->business->get($id, ['locale'])` (needs `uengage.io/php-platform-sdk`) and caches per request/process for 300 s (`'ttl'` option).
- `I18n::forConfig(['currency' => 'CAD', 'country' => 'Canada'])` builds an instance without a lookup.
- `format()` takes int, float or a plain decimal string; anything else throws `InvalidArgumentException`.
- Locale rules and the country/currency tables are identical to the JS package; see `packages/i18n/README.md`.
- `time->formatDate / formatTime / formatDateTime` follow the JS rules (naive strings are IST); an `int` is unix seconds. Always a built-in table, never ext-intl: PHP's ICU puts U+202F before "pm" where JS prints a plain space. A locale outside the six falls back to `en-IN`.

## ICU

Uses `NumberFormatter` when ext-intl is loaded, otherwise a built-in table for IN, CA, AE, NZ, PT, US.
`pt-PT` always uses the table: PHP's `NumberFormatter` ignores CLDR `minimumGroupingDigits=2` and would print
`1 234,56 €` where JS prints `1234,56 €`.

## Develop

```bash
composer install && vendor/bin/phpunit
```

Tests read `../i18n/test/fixtures/currency-format.json` (the JS package's expected strings) and run each
case with intl and with the built-in table.
