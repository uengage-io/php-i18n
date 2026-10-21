<?php

declare(strict_types=1);

namespace Uengage\I18n\Resolver;

use RuntimeException;

/**
 * Reads the public `locale` group from the platform Business API through
 * uengage.io/php-platform-sdk (duck-typed: any object with `->business->get($id, $groups)`).
 */
final class PlatformResolver implements BusinessConfigResolverInterface
{
    /** @var object */
    private $client;

    /** @param object $platformClient a Uengage\PlatformSdk\Client */
    public function __construct($platformClient)
    {
        if (!is_object($platformClient) || !isset($platformClient->business)
            || !method_exists($platformClient->business, 'get')) {
            throw new RuntimeException(
                'PlatformResolver needs a uengage.io/php-platform-sdk Client (->business->get())'
            );
        }
        $this->client = $platformClient;
    }

    public function resolve(int $businessId): array
    {
        $record = $this->client->business->get($businessId, ['locale']);
        if (!isset($record['locale']) || !is_array($record['locale'])) {
            throw new RuntimeException("i18n: business {$businessId} returned no locale group");
        }
        return $record['locale'];
    }
}
