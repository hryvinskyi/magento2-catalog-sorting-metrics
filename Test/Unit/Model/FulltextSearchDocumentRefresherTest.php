<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Test\Unit\Model;

use Hryvinskyi\CatalogSortingMetrics\Model\FulltextSearchDocumentRefresher;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Documents are rebuilt by one partial reindex of the catalog search indexer with exactly the given ids, and no
 * ids means no reindex.
 */
class FulltextSearchDocumentRefresherTest extends TestCase
{
    /**
     * @return void
     */
    public function testRebuildsTheGivenProductsThroughTheCatalogSearchIndexer(): void
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->expects(self::once())->method('reindexList')->with([10, 20]);
        $indexer->expects(self::never())->method('invalidate');
        $indexer->expects(self::never())->method('reindexAll');
        $registry = $this->createMock(IndexerRegistry::class);
        $registry->expects(self::once())->method('get')->with('catalogsearch_fulltext')->willReturn($indexer);

        (new FulltextSearchDocumentRefresher($registry))->refresh([10, 20]);
    }

    /**
     * @return void
     */
    public function testNoProductsMeansNoReindex(): void
    {
        $registry = $this->createMock(IndexerRegistry::class);
        $registry->expects(self::never())->method('get');

        (new FulltextSearchDocumentRefresher($registry))->refresh([]);
    }
}
