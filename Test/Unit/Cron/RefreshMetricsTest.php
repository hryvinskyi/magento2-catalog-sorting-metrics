<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Test\Unit\Cron;

use Hryvinskyi\CatalogSortingMetrics\Api\MetricRefreshInterface;
use Hryvinskyi\CatalogSortingMetrics\Cron\RefreshMetrics;
use PHPUnit\Framework\TestCase;

/**
 * The scheduled job refreshes every registered metric once, and a failure reaches the cron run.
 */
class RefreshMetricsTest extends TestCase
{
    /**
     * @return void
     */
    public function testRefreshesEveryMetric(): void
    {
        $refresh = $this->createMock(MetricRefreshInterface::class);
        $refresh->expects(self::once())->method('refresh')->with([])->willReturn(['bestsellers' => 2]);

        (new RefreshMetrics($refresh))->execute();
    }

    /**
     * @return void
     */
    public function testAFailureReachesTheCronRun(): void
    {
        $refresh = $this->createMock(MetricRefreshInterface::class);
        $refresh->method('refresh')->willThrowException(new \RuntimeException('search engine down'));

        $this->expectExceptionMessage('search engine down');

        (new RefreshMetrics($refresh))->execute();
    }
}
