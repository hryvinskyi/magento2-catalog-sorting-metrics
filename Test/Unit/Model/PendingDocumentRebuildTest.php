<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Test\Unit\Model;

use Hryvinskyi\CatalogSortingMetrics\Api\PendingProductsInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\ProductPageCachePurgerInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\SearchDocumentRefresherInterface;
use Hryvinskyi\CatalogSortingMetrics\Model\PendingDocumentRebuild;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The rebuild hands pending products over chunk by chunk, purges each chunk's pages after its documents were rebuilt,
 * releases a chunk only after both succeeded, and stops at the first failing chunk, leaving it and the later ones
 * pending.
 */
class PendingDocumentRebuildTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $log = [];

    /**
     * @var list<int>
     */
    private array $pendingIds = [];

    /**
     * @var list<int>|null
     */
    private ?array $failingRebuildChunk = null;

    /**
     * @var list<int>|null
     */
    private ?array $failingPurgeChunk = null;

    /**
     * @var LoggerInterface&MockObject
     */
    private $logger;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * @return void
     */
    public function testRebuildsPurgesAndReleasesEachChunkInThatOrder(): void
    {
        $this->pendingIds = [1, 2, 3];

        $rebuilt = $this->rebuild(2)->rebuild();

        self::assertSame(3, $rebuilt);
        self::assertSame(
            ['rebuild 1,2', 'purge 1,2', 'release 1,2', 'rebuild 3', 'purge 3', 'release 3'],
            $this->log
        );
    }

    /**
     * @return void
     */
    public function testNothingPendingRebuildsNothing(): void
    {
        self::assertSame(0, $this->rebuild(2)->rebuild());
        self::assertSame([], $this->log);
    }

    /**
     * @return void
     */
    public function testARefresherFailureKeepsTheFailedChunkAndLaterOnesPendingAndIsRethrown(): void
    {
        $this->pendingIds = [1, 2, 3, 4, 5];
        $this->failingRebuildChunk = [3, 4];
        $this->logger->expects(self::once())->method('error')->with(self::stringContains('3 products stay pending'));

        try {
            $this->rebuild(2)->rebuild();
            self::fail('The refresher failure was not rethrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('rebuild failed', $exception->getMessage());
        }

        self::assertSame(['rebuild 1,2', 'purge 1,2', 'release 1,2', 'rebuild 3,4'], $this->log);
    }

    /**
     * @return void
     */
    public function testAPurgeFailureKeepsTheChunkPendingAndIsRethrown(): void
    {
        $this->pendingIds = [1, 2];
        $this->failingPurgeChunk = [1, 2];

        try {
            $this->rebuild(1000)->rebuild();
            self::fail('The purge failure was not rethrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('purge failed', $exception->getMessage());
        }

        self::assertSame(['rebuild 1,2', 'purge 1,2'], $this->log);
    }

    /**
     * @return void
     */
    public function testRefusesAChunkSizeBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->rebuild(0);
    }

    /**
     * Build the rebuild under test over recording fakes
     *
     * @param int $chunkSize Chunk size
     * @return PendingDocumentRebuild
     */
    private function rebuild(int $chunkSize): PendingDocumentRebuild
    {
        return new PendingDocumentRebuild(
            $this->pendingProducts(),
            $this->refresher(),
            $this->purger(),
            $this->logger,
            $chunkSize
        );
    }

    /**
     * Build a pending products fake over the configured ids that records each release
     *
     * @return PendingProductsInterface
     */
    private function pendingProducts(): PendingProductsInterface
    {
        $pending = $this->createMock(PendingProductsInterface::class);
        $pending->method('getProductIds')->willReturnCallback(fn (): array => $this->pendingIds);
        $pending->method('release')->willReturnCallback(function (array $ids): void {
            $this->log[] = 'release ' . $this->describeIds($ids);
        });

        return $pending;
    }

    /**
     * Build a recording document refresher that fails for the configured chunk
     *
     * @return SearchDocumentRefresherInterface
     */
    private function refresher(): SearchDocumentRefresherInterface
    {
        $refresher = $this->createMock(SearchDocumentRefresherInterface::class);
        $refresher->method('refresh')->willReturnCallback(function (array $ids): void {
            $this->log[] = 'rebuild ' . $this->describeIds($ids);
            if ($ids === $this->failingRebuildChunk) {
                throw new \RuntimeException('rebuild failed');
            }
        });

        return $refresher;
    }

    /**
     * Build a recording page cache purger that fails for the configured chunk
     *
     * @return ProductPageCachePurgerInterface
     */
    private function purger(): ProductPageCachePurgerInterface
    {
        $purger = $this->createMock(ProductPageCachePurgerInterface::class);
        $purger->method('purge')->willReturnCallback(function (array $ids): void {
            $this->log[] = 'purge ' . $this->describeIds($ids);
            if ($ids === $this->failingPurgeChunk) {
                throw new \RuntimeException('purge failed');
            }
        });

        return $purger;
    }

    /**
     * Describe product ids for the log
     *
     * @param array<mixed> $ids Product ids
     * @return string
     */
    private function describeIds(array $ids): string
    {
        return implode(',', array_map(static fn (mixed $id): string => is_int($id) ? (string)$id : '?', $ids));
    }
}
