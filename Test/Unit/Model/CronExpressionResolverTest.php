<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Test\Unit\Model;

use Hryvinskyi\CatalogSortingMetrics\Model\CronExpressionResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Daily, weekly and monthly schedules at a time of day become cron expressions; an unknown frequency runs daily,
 * and a time outside the day is refused.
 */
class CronExpressionResolverTest extends TestCase
{
    /**
     * @param string $frequency Frequency code
     * @param int $hour Hour
     * @param int $minute Minute
     * @param string $expected Cron expression
     * @return void
     */
    #[DataProvider('schedules')]
    public function testBuildsTheCronExpression(string $frequency, int $hour, int $minute, string $expected): void
    {
        self::assertSame($expected, (new CronExpressionResolver())->resolve($frequency, $hour, $minute));
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int, 3: string}>
     */
    public static function schedules(): array
    {
        return [
            'daily at four' => ['D', 4, 0, '0 4 * * *'],
            'weekly on Mondays' => ['W', 23, 30, '30 23 * * 1'],
            'monthly on the first' => ['M', 0, 5, '5 0 1 * *'],
            'unknown frequency runs daily' => ['', 4, 0, '0 4 * * *'],
        ];
    }

    /**
     * @param int $hour Hour
     * @param int $minute Minute
     * @return void
     */
    #[DataProvider('timesOutsideTheDay')]
    public function testRefusesATimeOutsideTheDay(int $hour, int $minute): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new CronExpressionResolver())->resolve('D', $hour, $minute);
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function timesOutsideTheDay(): array
    {
        return [
            'hour 24' => [24, 0],
            'negative hour' => [-1, 0],
            'minute 60' => [4, 60],
            'negative minute' => [4, -1],
        ];
    }
}
