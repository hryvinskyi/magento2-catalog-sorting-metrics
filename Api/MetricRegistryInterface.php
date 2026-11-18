<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Api;

/**
 * Every registered metric, by code
 *
 * The one place a metric is registered, so a metric is always both refreshed and written into search documents,
 * never only one of the two. Codes and document fields are unique across the registry.
 *
 * @api
 */
interface MetricRegistryInterface
{
    /**
     * Get every registered metric in registration order
     *
     * @return array<string, MetricInterface> Metric code => metric
     */
    public function all(): array;

    /**
     * Check whether a metric with the given code is registered
     *
     * @param string $code Metric code
     * @return bool
     */
    public function has(string $code): bool;

    /**
     * Get the metric registered with the given code
     *
     * @param string $code Metric code
     * @return MetricInterface
     * @throws \InvalidArgumentException When no metric has the code; the message lists the registered codes
     */
    public function get(string $code): MetricInterface;
}
