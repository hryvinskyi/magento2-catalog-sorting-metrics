<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Api;

/**
 * Turns the refresh schedule chosen in the admin into a cron expression
 */
interface CronExpressionResolverInterface
{
    /**
     * Get the cron expression that runs at the given time with the given frequency
     *
     * @param string $frequency `D` daily, `W` weekly (Mondays) or `M` monthly (on the 1st); anything else is daily
     * @param int $hour Hour of the day, 0 to 23
     * @param int $minute Minute of the hour, 0 to 59
     * @return string
     * @throws \InvalidArgumentException When the hour or minute is out of range
     */
    public function resolve(string $frequency, int $hour, int $minute): string;
}
