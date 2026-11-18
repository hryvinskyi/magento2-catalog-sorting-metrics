<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Test\Integration;

use Hryvinskyi\CatalogSortingMetrics\Api\MetricSourceInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;

/**
 * Computes a metric from a table that does not exist, so the database refuses the statement that stages it
 */
class MissingTableTestMetricSource implements MetricSourceInterface
{
    public const TABLE = 'hryvinskyi_catalog_sorting_metrics_test_missing';

    /**
     * @param ResourceConnection $resourceConnection Gives the connection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @inheritDoc
     */
    public function select(): Select
    {
        return $this->resourceConnection->getConnection()->select()->from(
            $this->resourceConnection->getTableName(self::TABLE),
            ['product_id', 'store_id', 'value']
        );
    }
}
