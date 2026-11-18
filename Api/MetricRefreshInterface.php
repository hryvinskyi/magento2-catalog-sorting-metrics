<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Api;

/**
 * Recomputes metrics, stores the values that changed and rebuilds the search documents of exactly those products
 *
 * @api
 */
interface MetricRefreshInterface
{
    /**
     * Refresh the given metrics, or every registered metric when none is given
     *
     * For each metric enabled in at least one store, its values are recomputed for the stores where it is enabled
     * and stored in one transaction: values that are new or different are written, and every other stored row of
     * the metric is deleted, whether its value is gone or its store no longer has the metric enabled. A metric
     * enabled in no store is not computed: all its stored rows are deleted. Every product whose stored row is written
     * or deleted is recorded as awaiting a rebuild in the same transaction, except a product that no longer exists,
     * which has no search document to rebuild.
     *
     * A metric that fails keeps its stored values as they were; the other metrics are refreshed all the same.
     * Afterwards, whatever failed, the search documents of every product awaiting a rebuild are rebuilt, including
     * those a previous run left behind, and the page cache of those products is purged.
     *
     * A result is returned only when no metric failed, and then it holds every refreshed metric, one enabled in no
     * store included with the number of products whose rows were deleted. It is empty only when another refresh is
     * running, in which case nothing is done, or when no metric is registered.
     *
     * @param list<string> $codes Metric codes; empty for every registered metric
     * @return array<string, int> Metric code => number of products whose stored value was written or deleted, the
     *     deleted rows of a metric enabled in no store included
     * @throws \InvalidArgumentException When a code is not registered; nothing is refreshed
     * @throws \RuntimeException When one or more metrics failed and the rebuild succeeded; the message names every
     *     failed metric code, and the first failure is the previous exception
     * @throws \Throwable When rebuilding documents or purging the page cache fails, also when metrics failed before;
     *     products not yet rebuilt stay pending for the next run
     */
    public function refresh(array $codes = []): array;
}
