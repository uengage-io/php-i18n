<?php

declare(strict_types=1);

namespace Uengage\I18n\Resolver;

interface BusinessConfigResolverInterface
{
    /**
     * @return array{currency: ?string, country: ?string, timezone: ?string} the raw `locale` group
     */
    public function resolve(int $businessId): array;
}
