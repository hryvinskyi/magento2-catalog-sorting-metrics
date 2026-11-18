<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Test\Integration;

use Hryvinskyi\CatalogSortingMetrics\Api\ProductPageCachePurgerInterface;

/**
 * Records the products whose cached pages would be purged instead of purging them
 */
class RecordingProductPageCachePurger implements ProductPageCachePurgerInterface
{
    /**
     * @var list<int>
     */
    private array $productIds = [];

    /**
     * @inheritDoc
     */
    public function purge(array $productIds): void
    {
        array_push($this->productIds, ...$productIds);
    }

    /**
     * Get the products purged since the last call, sorted, and forget them
     *
     * @return list<int>
     */
    public function takeProductIds(): array
    {
        $productIds = $this->productIds;
        $this->productIds = [];
        sort($productIds);

        return $productIds;
    }
}
