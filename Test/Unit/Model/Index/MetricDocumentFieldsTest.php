<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Test\Unit\Model\Index;

use Hryvinskyi\CatalogSorting\Api\Data\FieldType;
use Hryvinskyi\CatalogSortingMetrics\Api\MetricInterface;
use Hryvinskyi\CatalogSortingMetrics\Model\Index\MetricDocumentFields;
use Hryvinskyi\CatalogSortingMetrics\Model\MetricRegistry;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Every registered metric's field is declared with its type; values are read in one query for the metrics enabled
 * in the batch's store and mapped to their fields as numbers, and a store where no metric is enabled is answered
 * without a query.
 */
class MetricDocumentFieldsTest extends TestCase
{
    private const STORE_ID = 3;

    /**
     * @var ResourceConnection&MockObject
     */
    private $resourceConnection;

    /**
     * @var AdapterInterface&MockObject
     */
    private $connection;

    /**
     * @var list<array{0: string, 1: mixed}>
     */
    private array $conditions = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(
            function (string $condition, mixed $value = null) use ($select): Select {
                $this->conditions[] = [$condition, $value];

                return $select;
            }
        );
        $this->connection->method('select')->willReturn($select);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')->willReturnArgument(0);
    }

    /**
     * @return void
     */
    public function testDeclaresEveryRegisteredFieldWithItsTypeWhateverTheStores(): void
    {
        $fields = $this->documentFields([
            $this->metric('bestsellers', 'sort_bestsellers', FieldType::Double, []),
            $this->metric('most_viewed', 'sort_most_viewed', FieldType::Long, []),
        ]);

        self::assertSame(
            ['sort_bestsellers' => FieldType::Double, 'sort_most_viewed' => FieldType::Long],
            $fields->getFieldTypes()
        );
    }

    /**
     * @return void
     */
    public function testReadsTheEnabledMetricsOfTheStoreInOneQueryAndMapsThemToTheirFields(): void
    {
        $this->connection->expects(self::once())->method('fetchAll')->willReturn([
            ['metric_code' => 'bestsellers', 'product_id' => '10', 'value' => '12.5000'],
            ['metric_code' => 'most_viewed', 'product_id' => '10', 'value' => '7.0000'],
            ['metric_code' => 'bestsellers', 'product_id' => '20', 'value' => '1.0000'],
        ]);
        $fields = $this->documentFields([
            $this->metric('bestsellers', 'sort_bestsellers', FieldType::Double, [self::STORE_ID]),
            $this->metric('most_viewed', 'sort_most_viewed', FieldType::Long, [self::STORE_ID]),
            $this->metric('wished', 'sort_wished', FieldType::Long, [4]),
        ]);

        $values = $fields->getValues(self::STORE_ID, [10, 20, 30]);

        self::assertSame(
            [
                10 => ['sort_bestsellers' => 12.5, 'sort_most_viewed' => 7.0],
                20 => ['sort_bestsellers' => 1.0],
            ],
            $values
        );
        self::assertSame(
            [
                ['metric_code IN (?)', ['bestsellers', 'most_viewed']],
                ['store_id = ?', self::STORE_ID],
                ['product_id IN (?)', [10, 20, 30]],
            ],
            $this->conditions
        );
    }

    /**
     * @return void
     */
    public function testAStoreWithoutAnEnabledMetricIsAnsweredWithoutAQuery(): void
    {
        $this->resourceConnection->expects(self::never())->method('getConnection');
        $fields = $this->documentFields([
            $this->metric('bestsellers', 'sort_bestsellers', FieldType::Double, [4]),
        ]);

        self::assertSame([], $fields->getValues(self::STORE_ID, [10]));
    }

    /**
     * Build the provider under test over a registry of the given metrics
     *
     * @param list<MetricInterface> $metrics Metrics
     * @return MetricDocumentFields
     */
    private function documentFields(array $metrics): MetricDocumentFields
    {
        return new MetricDocumentFields(new MetricRegistry($metrics), $this->resourceConnection);
    }

    /**
     * Build a metric double enabled in the given stores
     *
     * @param string $code Metric code
     * @param string $field Document field
     * @param FieldType $type Field type
     * @param list<int> $enabledStoreIds Stores where it is enabled
     * @return MetricInterface
     */
    private function metric(string $code, string $field, FieldType $type, array $enabledStoreIds): MetricInterface
    {
        $metric = $this->createMock(MetricInterface::class);
        $metric->method('getCode')->willReturn($code);
        $metric->method('getDocumentField')->willReturn($field);
        $metric->method('getFieldType')->willReturn($type);
        $metric->method('isEnabledIn')->willReturnCallback(
            static fn (int $storeId): bool => in_array($storeId, $enabledStoreIds, true)
        );

        return $metric;
    }
}
