<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Api;

/**
 * Rebuilds the search documents of every product awaiting a rebuild and purges their cached pages
 */
interface PendingDocumentRebuildInterface
{
    /**
     * Rebuild the search documents of every pending product, purge their cached pages, then release them
     *
     * Products are handled in chunks; a chunk is released only after its documents were rebuilt and its pages
     * purged. The first chunk that fails stops the rebuild: it and every later chunk stay pending for the next run.
     *
     * @return int Number of products rebuilt and released
     * @throws \Throwable When rebuilding documents or purging pages fails
     */
    public function rebuild(): int;
}
