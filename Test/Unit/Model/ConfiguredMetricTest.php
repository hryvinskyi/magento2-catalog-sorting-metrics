<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Test\Unit\Model;

use Hryvinskyi\CatalogSorting\Api\Data\FieldType;
use Hryvinskyi\CatalogSortingMetrics\Api\MetricSourceInterface;
use Hryvinskyi\CatalogSortingMetrics\Model\ConfiguredMetric;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A metric configured in di.xml hands back its code, field, type and source, is enabled per store by its Yes/No
 * setting, and refuses a malformed code, a blank field or setting path and an unknown field type at construction.
 */
class ConfiguredMetricTest extends TestCase
{
    private const PATH = 'catalog_sorting/bestsellers/enabled';

    /**
     * @var ScopeConfigInterface&MockObject
     */
    private $scopeConfig;

    /**
     * @var MetricSourceInterface&MockObject
     */
    private $source;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->source = $this->createMock(MetricSourceInterface::class);
    }

    /**
     * @return void
     */
    public function testHandsBackItsConfiguration(): void
    {
        $metric = $this->metric('bestsellers', 'sort_bestsellers', 'double');

        self::assertSame('bestsellers', $metric->getCode());
        self::assertSame('sort_bestsellers', $metric->getDocumentField());
        self::assertSame(FieldType::Double, $metric->getFieldType());
        self::assertSame($this->source, $metric->getSource());
    }

    /**
     * @return void
     */
    public function testIsEnabledInAStoreWhereItsSettingIsOn(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path, string $scope, int $storeId): bool => $path === self::PATH
                && $scope === ScopeInterface::SCOPE_STORE
                && $storeId === 3
        );
        $metric = $this->metric('bestsellers', 'sort_bestsellers', 'double');

        self::assertTrue($metric->isEnabledIn(3));
        self::assertFalse($metric->isEnabledIn(4));
    }

    /**
     * @return void
     */
    public function testAcceptsACodeOfThirtyTwoCharacters(): void
    {
        $code = str_repeat('a', 32);

        self::assertSame($code, $this->metric($code, 'sort_a', 'long')->getCode());
    }

    /**
     * @param string $code Malformed code
     * @return void
     */
    #[DataProvider('malformedCodes')]
    public function testRefusesAMalformedCode(string $code): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('"%s"', $code));

        $this->metric($code, 'sort_x', 'double');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedCodes(): array
    {
        return [
            'empty' => [''],
            'upper case' => ['BestSellers'],
            'dash' => ['best-sellers'],
            'space' => ['best sellers'],
            'thirty-three characters' => [str_repeat('a', 33)],
        ];
    }

    /**
     * @return void
     */
    public function testRefusesABlankDocumentField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"bestsellers"');

        $this->metric('bestsellers', '  ', 'double');
    }

    /**
     * @return void
     */
    public function testRefusesABlankSettingPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"bestsellers"');

        $this->metric('bestsellers', 'sort_bestsellers', 'double', ' ');
    }

    /**
     * @return void
     */
    public function testRefusesAnUnknownFieldType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"decimal"');

        $this->metric('bestsellers', 'sort_bestsellers', 'decimal');
    }

    /**
     * Build the metric under test
     *
     * @param string $code Metric code
     * @param string $field Document field
     * @param string $type Field type
     * @param string $path Setting path
     * @return ConfiguredMetric
     */
    private function metric(string $code, string $field, string $type, string $path = self::PATH): ConfiguredMetric
    {
        return new ConfiguredMetric($this->scopeConfig, $this->source, $code, $field, $type, $path);
    }
}
