<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Model\Index;

use Hryvinskyi\CatalogSorting\Api\Index\DocumentFieldProviderInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\MetricRegistryInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Writes the stored value of every metric enabled in the store into the product search documents
 *
 * Every registered metric's field is declared for the index mapping, whatever the stores' settings. Values are read
 * with one query per document batch, only for the metrics enabled in the batch's store; a store where none is
 * enabled is answered without a query. A product without a stored value gets no field, and sorts with the
 * field's missing value.
 */
class MetricDocumentFields implements DocumentFieldProviderInterface
{
    private const METRIC_TABLE = 'hryvinskyi_catalog_sorting_metric';

    /**
     * @param MetricRegistryInterface $registry Every registered metric
     * @param ResourceConnection $resourceConnection Reads the stored values
     */
    public function __construct(
        private readonly MetricRegistryInterface $registry,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getFieldTypes(): array
    {
        $types = [];
        foreach ($this->registry->all() as $metric) {
            $types[$metric->getDocumentField()] = $metric->getFieldType();
        }

        return $types;
    }

    /**
     * @inheritDoc
     */
    public function getValues(int $storeId, array $productIds): array
    {
        $fields = [];
        foreach ($this->registry->all() as $code => $metric) {
            if ($metric->isEnabledIn($storeId)) {
                $fields[$code] = $metric->getDocumentField();
            }
        }

        if ($fields === [] || $productIds === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::METRIC_TABLE), ['metric_code', 'product_id', 'value'])
            ->where('metric_code IN (?)', array_keys($fields))
            ->where('store_id = ?', $storeId)
            ->where('product_id IN (?)', $productIds);

        $values = [];
        foreach ($connection->fetchAll($select) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $field = $fields[is_string($row['metric_code'] ?? null) ? $row['metric_code'] : ''] ?? null;
            $productId = $row['product_id'] ?? null;
            $value = $row['value'] ?? null;
            if ($field !== null && is_numeric($productId) && is_numeric($value)) {
                $values[(int)$productId][$field] = (float)$value;
            }
        }

        return $values;
    }
}
