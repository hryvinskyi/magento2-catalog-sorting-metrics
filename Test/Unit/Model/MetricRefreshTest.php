<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Test\Unit\Model;

use Hryvinskyi\CatalogSortingMetrics\Api\MetricInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\MetricValueStoreInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\PendingDocumentRebuildInterface;
use Hryvinskyi\CatalogSortingMetrics\Model\MetricRefresh;
use Hryvinskyi\CatalogSortingMetrics\Model\MetricRegistry;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The refresh takes the lock or does nothing; stores each metric for the stores where it is enabled, or deletes it
 * everywhere when it is enabled nowhere, and returns every metric's count when none failed; keeps going past a
 * failing metric; always runs the pending rebuild once it holds the lock; and then, instead of a result, fails
 * naming every failed metric, unless the rebuild failed, whose failure wins.
 *
 * The value store and the rebuild are recording fakes of their ports; stores 3 and 4 exist.
 */
class MetricRefreshTest extends TestCase
{
    private const LOCK = 'hryvinskyi_catalog_sorting_metrics_refresh';

    /**
     * @var list<string>
     */
    private array $log = [];

    /**
     * @var array<string, \Throwable>
     */
    private array $failingCodes = [];

    /**
     * @var \Throwable|null
     */
    private ?\Throwable $rebuildFailure = null;

    /**
     * @var LockManagerInterface&MockObject
     */
    private $lockManager;

    /**
     * @var LoggerInterface&MockObject
     */
    private $logger;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * @return void
     */
    public function testDoesNothingWhileAnotherRefreshHoldsTheLock(): void
    {
        $this->lockManager->expects(self::once())->method('lock')->with(self::LOCK, 0)->willReturn(false);
        $this->lockManager->expects(self::never())->method('unlock');

        $result = $this->refresh([$this->metric('bestsellers', [3])])->refresh();

        self::assertSame([], $result);
        self::assertSame([], $this->log);
    }

    /**
     * @return void
     */
    public function testStoresEachMetricForItsEnabledStoresAndThenRebuilds(): void
    {
        $this->takeTheLock();

        $result = $this->refresh([
            $this->metric('bestsellers', [3, 4]),
            $this->metric('most_viewed', [4]),
        ])->refresh();

        self::assertSame(['bestsellers' => 7, 'most_viewed' => 7], $result);
        self::assertSame(['store bestsellers 3,4', 'store most_viewed 4', 'rebuild'], $this->log);
    }

    /**
     * @return void
     */
    public function testAMetricEnabledNowhereIsDeletedEverywhereAndReportsTheProductsOfTheDeletedRows(): void
    {
        $this->takeTheLock();

        $result = $this->refresh([$this->metric('bestsellers', [])])->refresh();

        self::assertSame(['bestsellers' => 2], $result);
        self::assertSame(['delete bestsellers', 'rebuild'], $this->log);
    }

    /**
     * @return void
     */
    public function testAFailingMetricBetweenTwoGoodOnesStillStoresBothRebuildsAndThenFailsNamingIt(): void
    {
        $this->takeTheLock();
        $failure = new \RuntimeException('source failed');
        $this->failingCodes = ['most_viewed' => $failure];
        $this->logger->expects(self::once())->method('error')->with(
            self::stringContains('"most_viewed"'),
            ['exception' => $failure]
        );

        try {
            $this->refresh([
                $this->metric('bestsellers', [3]),
                $this->metric('most_viewed', [3]),
                $this->metric('wishlist', [4]),
            ])->refresh();
            self::fail('The metric failure was not reported.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('most_viewed', $exception->getMessage());
            self::assertStringNotContainsString('bestsellers', $exception->getMessage());
            self::assertSame($failure, $exception->getPrevious());
        }

        self::assertSame(
            ['store bestsellers 3', 'store most_viewed 3', 'store wishlist 4', 'rebuild'],
            $this->log
        );
    }

    /**
     * @return void
     */
    public function testEveryFailedMetricIsNamedAndTheFirstFailureIsThePreviousOne(): void
    {
        $this->takeTheLock();
        $first = new \RuntimeException('first failed');
        $this->failingCodes = ['bestsellers' => $first, 'wishlist' => new \RuntimeException('second failed')];

        try {
            $this->refresh([
                $this->metric('bestsellers', []),
                $this->metric('most_viewed', [3]),
                $this->metric('wishlist', [4]),
            ])->refresh();
            self::fail('The metric failures were not reported.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('bestsellers, wishlist', $exception->getMessage());
            self::assertSame($first, $exception->getPrevious());
        }

        self::assertSame(
            ['delete bestsellers', 'store most_viewed 3', 'store wishlist 4', 'rebuild'],
            $this->log
        );
    }

    /**
     * @return void
     */
    public function testARebuildFailureAfterAMetricFailureIsTheOneThrown(): void
    {
        $this->takeTheLock();
        $this->failingCodes = ['bestsellers' => new \RuntimeException('source failed')];
        $this->rebuildFailure = new \RuntimeException('rebuild failed');

        try {
            $this->refresh([$this->metric('bestsellers', [3])])->refresh();
            self::fail('The rebuild failure was not rethrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame($this->rebuildFailure, $exception);
        }

        self::assertSame(['store bestsellers 3', 'rebuild'], $this->log);
    }

    /**
     * @return void
     */
    public function testRefreshesOnlyTheRequestedMetrics(): void
    {
        $this->takeTheLock();

        $result = $this->refresh([
            $this->metric('bestsellers', [3]),
            $this->metric('most_viewed', [4]),
        ])->refresh(['most_viewed']);

        self::assertSame(['most_viewed' => 7], $result);
        self::assertSame(['store most_viewed 4', 'rebuild'], $this->log);
    }

    /**
     * @return void
     */
    public function testAnUnknownCodeIsRefusedBeforeTheLockIsTaken(): void
    {
        $this->lockManager->expects(self::never())->method('lock');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"wished"');

        $this->refresh([$this->metric('bestsellers', [3])])->refresh(['wished']);
    }

    /**
     * Let the run take the lock and expect it to be released
     *
     * @return void
     */
    private function takeTheLock(): void
    {
        $this->lockManager->expects(self::once())->method('lock')->with(self::LOCK, 0)->willReturn(true);
        $this->lockManager->expects(self::once())->method('unlock')->with(self::LOCK);
    }

    /**
     * Build the refresh under test over a registry of the given metrics and stores 3 and 4
     *
     * @param list<MetricInterface> $metrics Metrics
     * @return MetricRefresh
     */
    private function refresh(array $metrics): MetricRefresh
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->store(3), $this->store(4)]);

        return new MetricRefresh(
            new MetricRegistry($metrics),
            $storeManager,
            $this->lockManager,
            $this->valueStore(),
            $this->documentRebuild(),
            $this->logger
        );
    }

    /**
     * Build a value store fake that records each call, fails for the configured codes and reports 7 products
     *
     * @return MetricValueStoreInterface
     */
    private function valueStore(): MetricValueStoreInterface
    {
        $valueStore = $this->createMock(MetricValueStoreInterface::class);
        $valueStore->method('store')->willReturnCallback(function (MetricInterface $metric, array $storeIds): int {
            $code = $metric->getCode();
            $this->log[] = 'store ' . $code . ' ' . implode(',', array_map(
                static fn (mixed $storeId): string => is_int($storeId) ? (string)$storeId : '?',
                $storeIds
            ));
            if (isset($this->failingCodes[$code])) {
                throw $this->failingCodes[$code];
            }

            return 7;
        });
        $valueStore->method('deleteEverywhere')->willReturnCallback(function (string $code): int {
            $this->log[] = 'delete ' . $code;
            if (isset($this->failingCodes[$code])) {
                throw $this->failingCodes[$code];
            }

            return 2;
        });

        return $valueStore;
    }

    /**
     * Build a rebuild fake that records each call and fails when configured to
     *
     * @return PendingDocumentRebuildInterface
     */
    private function documentRebuild(): PendingDocumentRebuildInterface
    {
        $rebuild = $this->createMock(PendingDocumentRebuildInterface::class);
        $rebuild->method('rebuild')->willReturnCallback(function (): int {
            $this->log[] = 'rebuild';
            if ($this->rebuildFailure !== null) {
                throw $this->rebuildFailure;
            }

            return 0;
        });

        return $rebuild;
    }

    /**
     * Build a metric double enabled in the given stores
     *
     * @param string $code Metric code
     * @param list<int> $enabledStoreIds Stores where it is enabled
     * @return MetricInterface
     */
    private function metric(string $code, array $enabledStoreIds): MetricInterface
    {
        $metric = $this->createMock(MetricInterface::class);
        $metric->method('getCode')->willReturn($code);
        $metric->method('getDocumentField')->willReturn('sort_' . $code);
        $metric->method('isEnabledIn')->willReturnCallback(
            static fn (int $storeId): bool => in_array($storeId, $enabledStoreIds, true)
        );

        return $metric;
    }

    /**
     * Build a store double
     *
     * @param int $storeId Store id
     * @return StoreInterface
     */
    private function store(int $storeId): StoreInterface
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn($storeId);

        return $store;
    }
}
