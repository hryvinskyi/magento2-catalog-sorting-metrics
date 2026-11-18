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
 * A metric the integration test registers whose computation always fails in the database, enabled in every store
 */
class FailingTestMetric implements MetricInterface
{
    public const CODE = 'hryvinskyi_test_failing';
    public const FIELD = 'sort_hryvinskyi_test_failing';

    /**
     * @param MissingTableTestMetricSource $source Selects from a table that does not exist
     */
    public function __construct(
        private readonly MissingTableTestMetricSource $source
    ) {
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
        return true;
    }

    /**
     * @inheritDoc
     */
    public function getSource(): MetricSourceInterface
    {
        return $this->source;
    }
}
