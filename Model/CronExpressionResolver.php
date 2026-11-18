<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Model;

use Hryvinskyi\CatalogSortingMetrics\Api\CronExpressionResolverInterface;
use Magento\Cron\Model\Config\Source\Frequency;

/**
 * Builds the cron expression of a daily, weekly (Mondays) or monthly (the 1st) run at a given time
 */
class CronExpressionResolver implements CronExpressionResolverInterface
{
    /**
     * @inheritDoc
     */
    public function resolve(string $frequency, int $hour, int $minute): string
    {
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            throw new \InvalidArgumentException(sprintf(
                'The refresh start time %02d:%02d is not a time of day.',
                $hour,
                $minute
            ));
        }

        return sprintf(
            '%d %d %s * %s',
            $minute,
            $hour,
            $frequency === Frequency::CRON_MONTHLY ? '1' : '*',
            $frequency === Frequency::CRON_WEEKLY ? '1' : '*'
        );
    }
}
