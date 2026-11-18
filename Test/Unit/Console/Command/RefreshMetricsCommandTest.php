<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Test\Unit\Console\Command;

use Hryvinskyi\CatalogSortingMetrics\Api\MetricInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\MetricRefreshInterface;
use Hryvinskyi\CatalogSortingMetrics\Console\Command\RefreshMetricsCommand;
use Hryvinskyi\CatalogSortingMetrics\Model\MetricRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command refreshes every metric or the ones named with --metric and prints the changed count per metric;
 * an unknown code fails with the registered codes and refreshes nothing; an empty result is reported as a refresh
 * that did not run.
 */
class RefreshMetricsCommandTest extends TestCase
{
    /**
     * @var MetricRefreshInterface&MockObject
     */
    private $refresh;

    /**
     * @var CommandTester
     */
    private CommandTester $tester;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->refresh = $this->createMock(MetricRefreshInterface::class);
        $registry = new MetricRegistry([
            'sold' => $this->metric('bestsellers'),
            'viewed' => $this->metric('most_viewed'),
        ]);
        $this->tester = new CommandTester(new RefreshMetricsCommand($this->refresh, $registry));
    }

    /**
     * @return void
     */
    public function testIsNamedForTheCatalogSortingMetrics(): void
    {
        $command = new RefreshMetricsCommand($this->refresh, new MetricRegistry());

        self::assertSame('catalog-sorting:metrics:refresh', $command->getName());
    }

    /**
     * @return void
     */
    public function testRefreshesEveryMetricAndPrintsTheChangedCounts(): void
    {
        $this->refresh->expects(self::once())->method('refresh')->with([])
            ->willReturn(['bestsellers' => 12, 'most_viewed' => 0]);

        $exitCode = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(
            "bestsellers: 12 products changed\nmost_viewed: 0 products changed\n",
            $this->tester->getDisplay()
        );
    }

    /**
     * @return void
     */
    public function testRefreshesOnlyTheNamedMetricsOnce(): void
    {
        $this->refresh->expects(self::once())->method('refresh')->with(['most_viewed'])
            ->willReturn(['most_viewed' => 3]);

        $exitCode = $this->tester->execute(['--metric' => ['most_viewed', 'most_viewed']]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame("most_viewed: 3 products changed\n", $this->tester->getDisplay());
    }

    /**
     * @return void
     */
    public function testAnUnknownCodeFailsWithTheRegisteredCodesAndRefreshesNothing(): void
    {
        $this->refresh->expects(self::never())->method('refresh');

        $exitCode = $this->tester->execute(['--metric' => ['bestsellers', 'wished']]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString(
            'Unknown metric code: wished. Registered codes: bestsellers, most_viewed.',
            $this->tester->getDisplay()
        );
    }

    /**
     * @return void
     */
    public function testSaysSoWhenNoMetricWasRefreshed(): void
    {
        $this->refresh->method('refresh')->willReturn([]);

        $exitCode = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(
            "No metric was refreshed: no metric is registered, or another refresh is running.\n",
            $this->tester->getDisplay()
        );
    }

    /**
     * Build a metric double
     *
     * @param string $code Metric code
     * @return MetricInterface
     */
    private function metric(string $code): MetricInterface
    {
        $metric = $this->createMock(MetricInterface::class);
        $metric->method('getCode')->willReturn($code);
        $metric->method('getDocumentField')->willReturn('sort_' . $code);

        return $metric;
    }
}
