<?php

declare(strict_types=1);

namespace Uengage\I18n\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Uengage\I18n\I18n;

/** Runs the same fixtures as the JS package (packages/i18n/test/fixtures). */
class CurrencyFormatTest extends TestCase
{
    /** @return array<string, array{0: array, 1: bool}> */
    public function fixtures(): array
    {
        $path = __DIR__ . '/../../i18n/test/fixtures/currency-format.json';
        $rows = json_decode((string) file_get_contents($path), true);
        $cases = [];
        foreach ([true, false] as $useIntl) {
            if ($useIntl && !class_exists('NumberFormatter')) {
                continue;
            }
            foreach ($rows as $i => $row) {
                $name = sprintf('%s #%d %s/%s/%s', $useIntl ? 'intl' : 'builtin', $i,
                    json_encode($row['currency']), json_encode($row['country']), json_encode($row['amount']));
                $cases[$name] = [$row, $useIntl];
            }
        }
        return $cases;
    }

    /** @dataProvider fixtures */
    public function testMatchesSharedFixtures(array $row, bool $useIntl): void
    {
        $i18n = new I18n(['currency' => $row['currency'], 'country' => $row['country']], $useIntl);
        $this->assertSame($row['expected'], $i18n->currency->format($row['amount'], $row['options'] ?? []));
    }

    public function testRejectsBadAmounts(): void
    {
        $currency = I18n::forConfig(['currency' => 'INR', 'country' => 'IN'])->currency;
        foreach ([NAN, INF, '', 'abc', '1,234', '1e3', null] as $bad) {
            try {
                $currency->format($bad);
                $this->fail('expected InvalidArgumentException for ' . var_export($bad, true));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('currency->format', $e->getMessage());
            }
        }
    }

    public function testRejectsBadOptions(): void
    {
        $currency = I18n::forConfig(['currency' => 'INR', 'country' => 'IN'])->currency;
        foreach ([['decimals' => -1], ['decimals' => 1.5], ['decimals' => 11], ['notation' => 'long'], ['showSymbol' => 'no']] as $bad) {
            try {
                $currency->format(1, $bad);
                $this->fail('expected InvalidArgumentException for ' . json_encode($bad));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('currency->format', $e->getMessage());
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $currency->format(NAN, ['notation' => 'compact']);
    }

    public function testLocaleResolution(): void
    {
        $this->assertSame('en-CA', I18n::forConfig(['currency' => 'USD', 'country' => 'Canada'])->locale);
        $this->assertSame('pt-PT', I18n::forConfig(['currency' => 'EUR'])->locale);
        $this->assertSame('en-NZ', I18n::forConfig(['currency' => 'NZD', 'country' => 'Atlantis'])->locale);
        $this->assertSame('en-IN', I18n::forConfig(['currency' => 'JPY'])->locale);
        $this->assertSame('fr-CA', I18n::forConfig(['currency' => 'CAD', 'locale' => 'fr-CA'])->locale);
    }
}
