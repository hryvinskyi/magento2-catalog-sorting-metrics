<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Test\Integration;

use Hryvinskyi\CatalogSorting\Model\Index\DocumentFields;
use Hryvinskyi\CatalogSortingMetrics\Api\MetricRefreshInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\MetricRegistryInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\PendingDocumentRebuildInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\ProductPageCachePurgerInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\SearchDocumentRefresherInterface;
use Hryvinskyi\CatalogSortingMetrics\Model\Index\MetricDocumentFields;
use Hryvinskyi\CatalogSortingMetrics\Model\MetricRefresh;
use Hryvinskyi\CatalogSortingMetrics\Model\MetricRegistry;
use Hryvinskyi\CatalogSortingMetrics\Model\PendingDocumentRebuild;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;

/**
 * Binds the recording search engine and page cache doubles for a test that runs a metric refresh through the real
 * container
 *
 * Every shared instance that holds the refresher, the purger or the metric registry is dropped, so the next one
 * the container builds takes the doubles and the registry as configured for the test.
 */
trait ReplacesSearchEngineAndPageCache
{
    /**
     * Bind the recording doubles in place of the search engine and the page cache, and drop the shared instances
     * built without them
     *
     * @return void
     */
    private function replaceTheSearchEngineAndThePageCache(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        if (!$objectManager instanceof ObjectManager) {
            throw new \LogicException('The integration test object manager is required.');
        }

        $objectManager->configure([
            'preferences' => [
                SearchDocumentRefresherInterface::class => RecordingSearchDocumentRefresher::class,
                ProductPageCachePurgerInterface::class => RecordingProductPageCachePurger::class,
            ],
        ]);
        foreach ([
            MetricRegistry::class,
            MetricRegistryInterface::class,
            MetricRefresh::class,
            MetricRefreshInterface::class,
            PendingDocumentRebuild::class,
            PendingDocumentRebuildInterface::class,
            MetricDocumentFields::class,
            DocumentFields::class,
        ] as $type) {
            $objectManager->removeSharedInstance($type);
        }
    }
}
