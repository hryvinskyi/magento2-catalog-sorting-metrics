<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Api;

/**
 * Drops the cached pages that show any of the given products
 *
 * @api
 */
interface ProductPageCachePurgerInterface
{
    /**
     * Purge every cached page tagged with one of the given products
     *
     * @param list<int> $productIds Product ids; nothing is purged when empty
     * @return void
     */
    public function purge(array $productIds): void;
}
