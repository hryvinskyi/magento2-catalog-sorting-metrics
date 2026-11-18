<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Test\Integration;

use Hryvinskyi\CatalogSortingMetrics\Api\SearchDocumentRefresherInterface;

/**
 * Records the products handed over for a search document rebuild instead of touching the search engine, and fails
 * on request the way an unreachable engine would
 */
class RecordingSearchDocumentRefresher implements SearchDocumentRefresherInterface
{
    /**
     * @var list<int>
     */
    private array $productIds = [];

    /**
     * @var bool
     */
    private bool $failing = false;

    /**
     * Make every following rebuild fail, or succeed again
     *
     * @param bool $failing Whether rebuilds fail
     * @return void
     */
    public function setFailing(bool $failing): void
    {
        $this->failing = $failing;
    }

    /**
     * @inheritDoc
     */
    public function refresh(array $productIds): void
    {
        if ($this->failing) {
            throw new \RuntimeException('The search engine is not reachable.');
        }

        array_push($this->productIds, ...$productIds);
    }

    /**
     * Get the products rebuilt since the last call, sorted, and forget them
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
