<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Model;

use Hryvinskyi\CatalogSortingMetrics\Api\ProductPageCachePurgerInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Indexer\CacheContextFactory;

/**
 * Purges the cached pages of products by their product cache tag
 *
 * Each call registers the products' cache tags in a fresh cache context, never the shared one the platform's
 * indexers fill and flush themselves, and dispatches `clean_cache_by_tags` with it. That event is what the
 * built-in full-page cache and the Varnish invalidation listen to. A listing page carries the tags of the products
 * it shows, so every cached listing showing one of the products is dropped.
 */
class ProductPageCachePurger implements ProductPageCachePurgerInterface
{
    private const EVENT_NAME = 'clean_cache_by_tags';

    /**
     * @param CacheContextFactory $cacheContextFactory Creates a cache context per call
     * @param ManagerInterface $eventManager Dispatches the purge event
     */
    public function __construct(
        private readonly CacheContextFactory $cacheContextFactory,
        private readonly ManagerInterface $eventManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function purge(array $productIds): void
    {
        if ($productIds === []) {
            return;
        }

        $context = $this->cacheContextFactory->create();
        $context->registerEntities(Product::CACHE_TAG, $productIds);
        $this->eventManager->dispatch(self::EVENT_NAME, ['object' => $context]);
    }
}
