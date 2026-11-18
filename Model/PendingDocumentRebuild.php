<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Model;

use Hryvinskyi\CatalogSortingMetrics\Api\PendingDocumentRebuildInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\PendingProductsInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\ProductPageCachePurgerInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\SearchDocumentRefresherInterface;
use Psr\Log\LoggerInterface;

/**
 * Rebuilds pending products chunk by chunk: documents first, then cached pages, then the release
 *
 * A chunk leaves the pending products only after its documents were rebuilt and its pages purged, so a run that
 * dies in between (killed process, search engine down) leaves it for the next run. The first chunk that fails
 * stops the rebuild, and it and every later chunk stay pending.
 */
class PendingDocumentRebuild implements PendingDocumentRebuildInterface
{
    /**
     * @var int
     */
    private readonly int $chunkSize;

    /**
     * @param PendingProductsInterface $pendingProducts Lists and releases the pending products
     * @param SearchDocumentRefresherInterface $documentRefresher Rebuilds the documents of a chunk
     * @param ProductPageCachePurgerInterface $pageCachePurger Purges the cached pages of a rebuilt chunk
     * @param LoggerInterface $logger Records each rebuild
     * @param int $chunkSize Products rebuilt per call to the document refresher; above zero
     * @throws \InvalidArgumentException When the chunk size is not above zero
     */
    public function __construct(
        private readonly PendingProductsInterface $pendingProducts,
        private readonly SearchDocumentRefresherInterface $documentRefresher,
        private readonly ProductPageCachePurgerInterface $pageCachePurger,
        private readonly LoggerInterface $logger,
        int $chunkSize = 1000
    ) {
        if ($chunkSize < 1) {
            throw new \InvalidArgumentException(sprintf(
                'The pending document rebuild chunk size must be above zero; %d given.',
                $chunkSize
            ));
        }

        $this->chunkSize = $chunkSize;
    }

    /**
     * @inheritDoc
     */
    public function rebuild(): int
    {
        $started = microtime(true);
        $ids = $this->pendingProducts->getProductIds();

        $rebuilt = 0;
        foreach (array_chunk($ids, $this->chunkSize) as $chunk) {
            try {
                $this->documentRefresher->refresh($chunk);
                $this->pageCachePurger->purge($chunk);
            } catch (\Throwable $exception) {
                $this->logger->error(sprintf(
                    'Catalog sorting metrics: rebuilding search documents or purging cached pages failed; '
                    . '%d products stay pending for the next refresh.',
                    count($ids) - $rebuilt
                ), ['exception' => $exception]);
                throw $exception;
            }

            $this->pendingProducts->release($chunk);
            $rebuilt += count($chunk);
        }

        $this->logger->info(sprintf(
            'Catalog sorting metrics: search documents of %d products rebuilt, %.2f s.',
            $rebuilt,
            microtime(true) - $started
        ));

        return $rebuilt;
    }
}
