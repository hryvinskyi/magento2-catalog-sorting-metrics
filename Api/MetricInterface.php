<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Api;

use Hryvinskyi\CatalogSorting\Api\Data\FieldType;

/**
 * One per-store, per-product metric a sort method contributes: its code, the search document field it is written
 * to, the stores it is enabled in, and the SQL that computes it
 *
 * A method module registers it as one item of the `metrics` argument of
 * `Hryvinskyi\CatalogSortingMetrics\Model\MetricRegistry`; the refresh, the document fields and the command line
 * all read that one registry.
 *
 * @api
 */
interface MetricInterface
{
    /**
     * Get the metric code: at most 32 characters of lower-case letters, digits and underscores
     *
     * @return string
     */
    public function getCode(): string;

    /**
     * Get the product search document field the metric is written to
     *
     * @return string
     */
    public function getDocumentField(): string;

    /**
     * Get the mapping type of the document field
     *
     * @return FieldType
     */
    public function getFieldType(): FieldType;

    /**
     * Check whether the sort method of this metric is enabled in a store
     *
     * Values are computed, stored and written into search documents only for the stores where it is.
     *
     * @param int $storeId Store id
     * @return bool
     */
    public function isEnabledIn(int $storeId): bool;

    /**
     * Get the source that computes the metric
     *
     * @return MetricSourceInterface
     */
    public function getSource(): MetricSourceInterface;
}
