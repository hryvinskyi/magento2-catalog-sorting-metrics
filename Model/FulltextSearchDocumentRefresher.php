<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Model;

use Hryvinskyi\CatalogSortingMetrics\Api\SearchDocumentRefresherInterface;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Framework\Indexer\IndexerRegistry;

/**
 * Rebuilds product search documents through the catalog search indexer's partial reindex
 *
 * The partial reindex runs at once whatever mode the indexer is in (on save or by schedule), rebuilds the given
 * products in every store, adds their parent products, and splits the ids into batches of its own configured
 * size. Nothing is invalidated and no full rebuild is started.
 */
class FulltextSearchDocumentRefresher implements SearchDocumentRefresherInterface
{
    /**
     * @param IndexerRegistry $indexerRegistry Gives the catalog search indexer
     */
    public function __construct(
        private readonly IndexerRegistry $indexerRegistry
    ) {
    }

    /**
     * @inheritDoc
     */
    public function refresh(array $productIds): void
    {
        if ($productIds === []) {
            return;
        }

        $this->indexerRegistry->get(Fulltext::INDEXER_ID)->reindexList($productIds);
    }
}
