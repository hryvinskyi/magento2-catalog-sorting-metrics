<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Api;

/**
 * Rebuilds the product search documents of the given products, in every store
 *
 * @api
 */
interface SearchDocumentRefresherInterface
{
    /**
     * Rebuild the search documents of the given products
     *
     * Returns only after the documents were written; any failure is thrown.
     *
     * @param list<int> $productIds Product ids, never empty
     * @return void
     */
    public function refresh(array $productIds): void;
}
