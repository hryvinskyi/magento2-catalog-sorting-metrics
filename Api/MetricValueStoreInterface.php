<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Api;

/**
 * Keeps the stored values of a metric equal to what its source computes, and records every product whose stored
 * value was written or deleted as awaiting a search document rebuild
 *
 * Whatever is written or deleted is recorded in the same transaction as the write or the delete, so a stored value
 * never changes without its product awaiting a rebuild. A row whose product no longer exists is deleted without
 * being recorded, since there is no document to rebuild. A call that fails leaves the stored values and the
 * products awaiting a rebuild as they were.
 */
interface MetricValueStoreInterface
{
    /**
     * Store the values the metric's source computes for the given stores
     *
     * Only values above zero of the given stores are stored. Values that are new or different are written; every
     * other stored row of the metric is deleted, whether its value is gone from the source or its store is not
     * among the given ones. The products of the written and the deleted rows are recorded as awaiting a rebuild,
     * except a product that no longer exists.
     *
     * @param MetricInterface $metric Metric to store
     * @param non-empty-list<int> $storeIds Stores where the metric is enabled
     * @return int Number of distinct products whose stored value was written or deleted
     * @throws \Throwable When computing or storing fails; nothing stored or recorded changes
     */
    public function store(MetricInterface $metric, array $storeIds): int;

    /**
     * Delete every stored row of a metric and record their products that still exist as awaiting a rebuild
     *
     * @param string $code Metric code
     * @return int Number of distinct products whose stored rows were deleted
     * @throws \Throwable When deleting fails; nothing stored or recorded changes
     */
    public function deleteEverywhere(string $code): int;
}
