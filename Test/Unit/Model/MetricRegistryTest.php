<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Test\Unit\Model;

use Hryvinskyi\CatalogSortingMetrics\Api\MetricInterface;
use Hryvinskyi\CatalogSortingMetrics\Model\MetricRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The registry keys the pool's metrics by code in registration order, answers whether a code is registered, and
 * refuses an item that is not a metric, a code used twice, a document field used twice and an unknown code.
 */
class MetricRegistryTest extends TestCase
{
    /**
     * @return void
     */
    public function testKeysTheMetricsByCodeInRegistrationOrder(): void
    {
        $sold = $this->metric('bestsellers', 'sort_bestsellers');
        $viewed = $this->metric('most_viewed', 'sort_most_viewed');

        $registry = new MetricRegistry(['soldItem' => $sold, 'viewedItem' => $viewed]);

        self::assertSame(['bestsellers' => $sold, 'most_viewed' => $viewed], $registry->all());
        self::assertTrue($registry->has('most_viewed'));
        self::assertFalse($registry->has('wished'));
        self::assertSame($viewed, $registry->get('most_viewed'));
    }

    /**
     * @return void
     */
    public function testAnEmptyPoolHasNoMetric(): void
    {
        $registry = new MetricRegistry();

        self::assertSame([], $registry->all());
        self::assertFalse($registry->has('bestsellers'));
    }

    /**
     * @return void
     */
    public function testRefusesAnItemThatIsNotAMetricByItsKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"strayItem"');

        new MetricRegistry(['strayItem' => new \stdClass()]);
    }

    /**
     * @return void
     */
    public function testRefusesACodeUsedTwice(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"bestsellers"');

        new MetricRegistry([
            'first' => $this->metric('bestsellers', 'sort_bestsellers'),
            'second' => $this->metric('bestsellers', 'sort_other'),
        ]);
    }

    /**
     * @return void
     */
    public function testRefusesADocumentFieldUsedTwice(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"sort_shared"');

        new MetricRegistry([
            'first' => $this->metric('bestsellers', 'sort_shared'),
            'second' => $this->metric('most_viewed', 'sort_shared'),
        ]);
    }

    /**
     * @return void
     */
    public function testAnUnknownCodeIsRefusedWithTheRegisteredCodes(): void
    {
        $registry = new MetricRegistry([
            'soldItem' => $this->metric('bestsellers', 'sort_bestsellers'),
            'viewedItem' => $this->metric('most_viewed', 'sort_most_viewed'),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"wished". Registered codes: bestsellers, most_viewed.');

        $registry->get('wished');
    }

    /**
     * Build a metric double
     *
     * @param string $code Metric code
     * @param string $field Document field
     * @return MetricInterface
     */
    private function metric(string $code, string $field): MetricInterface
    {
        $metric = $this->createMock(MetricInterface::class);
        $metric->method('getCode')->willReturn($code);
        $metric->method('getDocumentField')->willReturn($field);

        return $metric;
    }
}
