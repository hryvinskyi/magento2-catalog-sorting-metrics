<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Test\Unit\Model\Config\Backend;

use Hryvinskyi\CatalogSortingMetrics\Model\Config\Backend\RefreshFrequency;
use Hryvinskyi\CatalogSortingMetrics\Model\CronExpressionResolver;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Saving the frequency writes the refresh job's cron expression from it and the start time of the same save, or
 * the stored start time when the save carried none; a start time outside the day is refused.
 */
class RefreshFrequencyTest extends TestCase
{
    private const CRON_PATH = 'crontab/default/jobs/hryvinskyi_catalog_sorting_metrics_refresh/schedule/cron_expr';

    /**
     * @var WriterInterface&MockObject
     */
    private $writer;

    /**
     * @var ScopeConfigInterface&MockObject
     */
    private $scopeConfig;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->writer = $this->createMock(WriterInterface::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
    }

    /**
     * @return void
     */
    public function testWritesTheCronExpressionOfTheSavedFrequencyAndTime(): void
    {
        $this->writer->expects(self::once())->method('save')->with(self::CRON_PATH, '30 5 * * 1');

        $this->backendModel('W', ['time' => ['05', '30', '00']])->afterSave();
    }

    /**
     * @return void
     */
    public function testFallsBackToTheStoredStartTime(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['catalog_sorting/metrics/time', 'default', null, '04,15,00'],
        ]);
        $this->writer->expects(self::once())->method('save')->with(self::CRON_PATH, '15 4 1 * *');

        $this->backendModel('M', null)->afterSave();
    }

    /**
     * @return void
     */
    public function testRefusesAStartTimeOutsideTheDay(): void
    {
        $this->writer->expects(self::never())->method('save');

        $this->expectException(LocalizedException::class);

        $this->backendModel('D', ['time' => ['25', '00', '00']])->afterSave();
    }

    /**
     * Build the backend model as the config save hands it the frequency and the group's other fields
     *
     * @param string $frequency Saved frequency
     * @param array<string, mixed>|null $fieldsetData Values of the group's fields in the same save
     * @return RefreshFrequency
     */
    private function backendModel(string $frequency, ?array $fieldsetData): RefreshFrequency
    {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(ManagerInterface::class));
        $data = ['value' => $frequency, 'path' => 'catalog_sorting/metrics/frequency'];
        if ($fieldsetData !== null) {
            $data['fieldset_data'] = $fieldsetData;
        }

        return new RefreshFrequency(
            $context,
            $this->createMock(Registry::class),
            $this->scopeConfig,
            $this->createMock(TypeListInterface::class),
            $this->writer,
            new CronExpressionResolver(),
            null,
            null,
            $data
        );
    }
}
