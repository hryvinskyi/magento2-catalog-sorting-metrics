<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Cron;

use Hryvinskyi\CatalogSortingMetrics\Api\MetricRefreshInterface;

/**
 * Scheduled refresh of every registered catalog sorting metric
 */
class RefreshMetrics
{
    /**
     * @param MetricRefreshInterface $metricRefresh Refreshes the metrics
     */
    public function __construct(
        private readonly MetricRefreshInterface $metricRefresh
    ) {
    }

    /**
     * Refresh every registered metric
     *
     * @return void
     * @throws \Throwable When the refresh fails; the cron run records it
     */
    public function execute(): void
    {
        $this->metricRefresh->refresh();
    }
}
