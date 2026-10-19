<?php

declare(strict_types=1);

namespace Uengage\I18n\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Uengage\I18n\Country;
use Uengage\I18n\I18n;
use Uengage\I18n\Resolver\BusinessConfigResolverInterface;
use Uengage\I18n\Resolver\PlatformResolver;

class ForBusinessIdTest extends TestCase
{
    public function testFormatsWithResolvedLocaleAndCaches(): void
    {
        $resolver = new class implements BusinessConfigResolverInterface {
            public $calls = 0;

            public function resolve(int $businessId): array
            {
                $this->calls++;
                return ['currency' => 'CAD', 'country' => 'Canada', 'timezone' => 'America/Toronto'];
            }
        };
        I18n::configure(['resolver' => $resolver]);

        $i18n = I18n::forBusinessId(7);
        $this->assertSame('$1,234.50', $i18n->currency->format(1234.5));
        $this->assertSame('America/Toronto', $i18n->timezone);
        $this->assertSame($i18n, I18n::forBusinessId(7));
        $this->assertSame(1, $resolver->calls);
    }

    public function testRejectsInvalidIdAndMissingConfigure(): void
    {
        I18n::configure(['resolver' => new class implements BusinessConfigResolverInterface {
            public function resolve(int $businessId): array
            {
                return ['currency' => 'INR', 'country' => null, 'timezone' => null];
            }
        }]);
        $this->expectException(InvalidArgumentException::class);
        I18n::forBusinessId(0);
    }

    public function testPlatformResolverReadsLocaleGroupThroughSdkClient(): void
    {
        $business = new class {
            public $args;

            public function get(int $id, ?array $groups = null): array
            {
                $this->args = [$id, $groups];
                return ['id' => $id, 'parent' => null,
                    'locale' => ['currency' => 'EUR', 'country' => null, 'timezone' => null]];
            }
        };
        $client = new \stdClass();
        $client->business = $business;

        I18n::configure(['platform' => $client]);
        $this->assertSame('1234,56' . "\xC2\xA0" . '€', I18n::forBusinessId(9)->currency->format(1234.56));
        $this->assertSame([9, ['locale']], $business->args);
    }

    public function testPlatformResolverRejectsForeignObjects(): void
    {
        $this->expectException(\RuntimeException::class);
        new PlatformResolver(new \stdClass());
    }

    public function testCountryNormalise(): void
    {
        $this->assertSame('CA', Country::normalise('Canada'));
        $this->assertSame('IN', Country::normalise(' in '));
        $this->assertSame('AE', Country::normalise('U.A.E.'));
        $this->assertNull(Country::normalise('Atlantis'));
        $this->assertNull(Country::normalise(null));
    }

    public function testVersionIsSemver(): void
    {
        // preg_match, not assertRegExp (removed in PHPUnit 10, deprecated => warning under
        // failOnWarning on 9.6) or assertMatchesRegularExpression (absent on 7.5).
        $this->assertSame(1, preg_match('/^\d+\.\d+\.\d+$/', I18n::VERSION));
    }
}
