<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Test\Integration;

use Hryvinskyi\CatalogSorting\Api\Data\FieldType;
use Hryvinskyi\CatalogSortingMetrics\Api\MetricInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\MetricSourceInterface;

/**
 * A metric the integration test registers, enabled in the stores the test names and disabled in those it takes back
 */
class TestMetric implements MetricInterface
{
    public const CODE = 'hryvinskyi_test';
    public const FIELD = 'sort_hryvinskyi_test';

    /**
     * @var array<int, true>
     */
    private array $enabledStoreIds = [];

    /**
     * @param TestMetricSource $source Reads the test activity table
     */
    public function __construct(
        private readonly TestMetricSource $source
    ) {
    }

    /**
     * Enable the metric in the given stores, in addition to those it is enabled in already
     *
     * @param int ...$storeIds Store ids
     * @return void
     */
    public function enableIn(int ...$storeIds): void
    {
        foreach ($storeIds as $storeId) {
            $this->enabledStoreIds[$storeId] = true;
        }
    }

    /**
     * Disable the metric in the given stores, keeping it enabled in the others
     *
     * @param int ...$storeIds Store ids
     * @return void
     */
    public function disableIn(int ...$storeIds): void
    {
        foreach ($storeIds as $storeId) {
            unset($this->enabledStoreIds[$storeId]);
        }
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return self::CODE;
    }

    /**
     * @inheritDoc
     */
    public function getDocumentField(): string
    {
        return self::FIELD;
    }

    /**
     * @inheritDoc
     */
    public function getFieldType(): FieldType
    {
        return FieldType::Double;
    }

    /**
     * @inheritDoc
     */
    public function isEnabledIn(int $storeId): bool
    {
        return isset($this->enabledStoreIds[$storeId]);
    }

    /**
     * @inheritDoc
     */
    public function getSource(): MetricSourceInterface
    {
        return $this->source;
    }
}
