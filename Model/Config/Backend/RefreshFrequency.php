<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Model\Config\Backend;

use Hryvinskyi\CatalogSortingMetrics\Api\CronExpressionResolverInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * Saves the refresh frequency and writes the refresh job's cron expression from it and the chosen start time
 *
 * The start time is read from the same form save; when the form did not send it, the stored start time is used.
 */
class RefreshFrequency extends Value
{
    public const CRON_EXPRESSION_PATH = 'crontab/default/jobs/hryvinskyi_catalog_sorting_metrics_refresh'
        . '/schedule/cron_expr';
    private const TIME_PATH = 'catalog_sorting/metrics/time';
    private const TIME_FIELD = 'time';

    /**
     * @param Context $context Model context
     * @param Registry $registry Registry the parent value model requires
     * @param ScopeConfigInterface $config Reads the stored start time
     * @param TypeListInterface $cacheTypeList Cache types the parent value model invalidates
     * @param WriterInterface $configWriter Writes the cron expression
     * @param CronExpressionResolverInterface $cronExpressionResolver Builds the cron expression
     * @param AbstractResource|null $resource Resource model
     * @param AbstractDb|null $resourceCollection Resource collection
     * @param array<string,mixed> $data Model data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly WriterInterface $configWriter,
        private readonly CronExpressionResolverInterface $cronExpressionResolver,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Write the refresh job's cron expression after the frequency was saved
     *
     * @return $this
     * @throws LocalizedException When the start time is not a time of day
     */
    public function afterSave()
    {
        [$hour, $minute] = $this->startTime();
        $frequency = $this->getData('value');
        try {
            $expression = $this->cronExpressionResolver->resolve(
                is_string($frequency) ? $frequency : '',
                $hour,
                $minute
            );
        } catch (\InvalidArgumentException $exception) {
            throw new LocalizedException(__('The popularity data refresh start time is not a valid time of day.'));
        }

        $this->configWriter->save(self::CRON_EXPRESSION_PATH, $expression);

        return parent::afterSave();
    }

    /**
     * Get the hour and minute of the start time, from the form save or else from the stored setting
     *
     * @return array{0: int, 1: int}
     */
    private function startTime(): array
    {
        $fieldset = $this->getData('fieldset_data');
        $time = is_array($fieldset) ? ($fieldset[self::TIME_FIELD] ?? null) : null;
        if (!is_array($time)) {
            $stored = $this->_config->getValue(self::TIME_PATH);
            $time = explode(',', is_string($stored) ? $stored : '');
        }

        return [$this->timePart($time, 0), $this->timePart($time, 1)];
    }

    /**
     * Read one part of a time value as an integer; a missing part or anything that is not digits counts as 0
     *
     * @param array<mixed> $time Hour, minute and second as the form or the stored setting holds them
     * @param int $position 0 for the hour, 1 for the minute
     * @return int
     */
    private function timePart(array $time, int $position): int
    {
        $part = $time[$position] ?? null;
        if (is_int($part)) {
            return $part;
        }

        return is_string($part) && ctype_digit(trim($part)) ? (int)trim($part) : 0;
    }
}
