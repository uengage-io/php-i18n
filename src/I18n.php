<?php

declare(strict_types=1);

namespace Uengage\I18n;

use InvalidArgumentException;
use Uengage\I18n\Resolver\BusinessConfigResolverInterface;
use Uengage\I18n\Resolver\PlatformResolver;

/**
 * $i18n = I18n::forBusinessId($businessId);
 * $i18n->currency->format($amount);
 */
final class I18n
{
    /** Must equal the `php-i18n-v*` release tag (checked by the publish action). */
    const VERSION = '0.1.0';

    const DEFAULT_TTL = 300;

    /** @var CurrencyFormatter */
    public $currency;
    /** @var string|null */
    public $country;
    /** @var string */
    public $locale;
    /** @var string|null */
    public $timezone;

    /** @var BusinessConfigResolverInterface|null */
    private static $resolver;
    /** @var array<int, array{0: int, 1: I18n}> */
    private static $cache = [];
    /** @var int */
    private static $ttl = self::DEFAULT_TTL;

    /**
     * @param array{currency?: ?string, country?: ?string, locale?: ?string, timezone?: ?string} $config
     * @param bool|null $useIntl see CurrencyFormatter
     */
    public function __construct(array $config = [], ?bool $useIntl = null)
    {
        // Legacy `currency` is a free varchar(4); anything that is not a 3-letter code is treated as unset.
        $raw = strtoupper(trim((string) ($config['currency'] ?? '')));
        $code = preg_match('/^[A-Z]{3}$/', $raw) ? $raw : LocaleMap::DEFAULT_CURRENCY;
        $this->country = Country::normalise($config['country'] ?? null);
        $this->locale = LocaleMap::resolve($code, $this->country, $config['locale'] ?? null);
        $this->timezone = $config['timezone'] ?? null;
        $this->currency = new CurrencyFormatter($code, $this->locale, $useIntl);
    }

    /**
     * @param array{platform?: object, resolver?: BusinessConfigResolverInterface, ttl?: int} $opts
     *        `platform` is a Uengage\PlatformSdk\Client; `resolver` replaces it (tests).
     */
    public static function configure(array $opts): void
    {
        if (isset($opts['resolver'])) {
            self::$resolver = $opts['resolver'];
        } elseif (isset($opts['platform'])) {
            self::$resolver = new PlatformResolver($opts['platform']);
        } else {
            throw new InvalidArgumentException("I18n::configure needs 'platform' or 'resolver'");
        }
        self::$ttl = isset($opts['ttl']) ? (int) $opts['ttl'] : self::DEFAULT_TTL;
        self::$cache = [];
    }

    /** @param array{currency?: ?string, country?: ?string, locale?: ?string, timezone?: ?string} $config */
    public static function forConfig(array $config): I18n
    {
        return new self($config);
    }

    public static function forBusinessId(int $businessId): I18n
    {
        if ($businessId <= 0) {
            throw new InvalidArgumentException("I18n::forBusinessId: businessId must be a positive integer (got {$businessId})");
        }
        if (self::$resolver === null) {
            throw new InvalidArgumentException('I18n::forBusinessId: call I18n::configure([...]) first');
        }
        $hit = self::$cache[$businessId] ?? null;
        if ($hit !== null && $hit[0] > time()) {
            return $hit[1];
        }
        $raw = self::$resolver->resolve($businessId);
        $instance = new self([
            'currency' => $raw['currency'] ?? null,
            'country' => $raw['country'] ?? null,
            'timezone' => $raw['timezone'] ?? null,
        ]);
        self::$cache[$businessId] = [time() + self::$ttl, $instance];
        return $instance;
    }
}
