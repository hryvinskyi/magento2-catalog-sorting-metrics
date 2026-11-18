<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Api;

/**
 * The products whose stored metric value changed and whose search documents are not rebuilt yet
 *
 * Products are recorded by the metric value store, together with the change; they stay recorded across runs until
 * they are released.
 */
interface PendingProductsInterface
{
    /**
     * Get every product awaiting a search document rebuild, ascending
     *
     * @return list<int> Product ids
     */
    public function getProductIds(): array;

    /**
     * Release products whose search documents were rebuilt and whose cached pages were purged
     *
     * @param list<int> $productIds Product ids; nothing is released when empty
     * @return void
     */
    public function release(array $productIds): void;
}
