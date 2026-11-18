<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Model;

use Hryvinskyi\CatalogSortingMetrics\Api\MetricInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\MetricRefreshInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\MetricRegistryInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\MetricValueStoreInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\PendingDocumentRebuildInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Refreshes metrics one by one, then rebuilds the search documents of every product whose stored value changed
 *
 * Rules the refresh keeps:
 * - Two refreshes never run at once: a run that cannot take the lock does nothing.
 * - A metric is stored for the stores where it is enabled; a metric enabled nowhere has every stored row deleted.
 *   Either way, every product whose stored row is written or deleted awaits a rebuild from the same transaction on,
 *   except a product that no longer exists.
 * - A metric that fails is logged and keeps its stored values; the other metrics are still refreshed.
 * - The pending rebuild runs on every run that took the lock, whatever metric failed, so products recorded by the
 *   metrics that succeeded, or left by an earlier run, are rebuilt.
 * - A run with a failed metric still fails once the rebuild is done, naming every failed metric, so the scheduler
 *   and the command line report it. When the rebuild fails too, the rebuild's failure is the one thrown.
 */
class MetricRefresh implements MetricRefreshInterface
{
    private const LOCK_NAME = 'hryvinskyi_catalog_sorting_metrics_refresh';

    /**
     * @param MetricRegistryInterface $registry Every registered metric
     * @param StoreManagerInterface $storeManager Lists the stores a metric may be enabled in
     * @param LockManagerInterface $lockManager Keeps two refreshes from running at once
     * @param MetricValueStoreInterface $valueStore Stores or deletes a metric's values
     * @param PendingDocumentRebuildInterface $documentRebuild Rebuilds the documents of the pending products
     * @param LoggerInterface $logger Records each metric and each failure
     */
    public function __construct(
        private readonly MetricRegistryInterface $registry,
        private readonly StoreManagerInterface $storeManager,
        private readonly LockManagerInterface $lockManager,
        private readonly MetricValueStoreInterface $valueStore,
        private readonly PendingDocumentRebuildInterface $documentRebuild,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function refresh(array $codes = []): array
    {
        $metrics = $this->metricsToRefresh($codes);
        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            $this->logger->info('Catalog sorting metrics: another refresh is running; this one did nothing.');

            return [];
        }

        $changed = [];
        $failures = [];
        try {
            foreach ($metrics as $code => $metric) {
                try {
                    $changed[$code] = $this->refreshMetric($metric);
                } catch (\Throwable $exception) {
                    $failures[$code] = $exception;
                    $this->logger->error(sprintf(
                        'Catalog sorting metric "%s" failed to refresh; its stored values stay as they were.',
                        $code
                    ), ['exception' => $exception]);
                }
            }

            $this->documentRebuild->rebuild();
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }

        $firstFailure = reset($failures);
        if ($firstFailure !== false) {
            throw new \RuntimeException(sprintf(
                'Catalog sorting metrics failed to refresh: %s. The other metrics were refreshed and rebuilt.',
                implode(', ', array_keys($failures))
            ), 0, $firstFailure);
        }

        return $changed;
    }

    /**
     * Get the metrics to refresh, every registered one when no code is given
     *
     * @param list<string> $codes Metric codes
     * @return array<string, MetricInterface> Metric code => metric
     * @throws \InvalidArgumentException When a code is not registered
     */
    private function metricsToRefresh(array $codes): array
    {
        if ($codes === []) {
            return $this->registry->all();
        }

        $metrics = [];
        foreach ($codes as $code) {
            $metrics[$code] = $this->registry->get($code);
        }

        return $metrics;
    }

    /**
     * Store a metric for the stores where it is enabled, or delete its rows when it is enabled nowhere
     *
     * @param MetricInterface $metric Metric to refresh
     * @return int Number of products whose stored value was written or deleted
     * @throws \Throwable When storing or deleting fails
     */
    private function refreshMetric(MetricInterface $metric): int
    {
        $started = microtime(true);
        $code = $metric->getCode();
        $storeIds = $this->enabledStoreIds($metric);
        if ($storeIds === []) {
            $deleted = $this->valueStore->deleteEverywhere($code);
            $this->logger->info(sprintf(
                'Catalog sorting metric "%s" is enabled in no store: stored rows of %d products deleted, %.2f s.',
                $code,
                $deleted,
                microtime(true) - $started
            ));

            return $deleted;
        }

        $count = $this->valueStore->store($metric, $storeIds);
        $this->logger->info(sprintf(
            'Catalog sorting metric "%s": %d products changed, %.2f s.',
            $code,
            $count,
            microtime(true) - $started
        ));

        return $count;
    }

    /**
     * Get the id of every store view the metric is enabled in
     *
     * @param MetricInterface $metric Metric
     * @return list<int>
     */
    private function enabledStoreIds(MetricInterface $metric): array
    {
        $storeIds = [];
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int)$store->getId();
            if ($metric->isEnabledIn($storeId)) {
                $storeIds[] = $storeId;
            }
        }

        return $storeIds;
    }
}
