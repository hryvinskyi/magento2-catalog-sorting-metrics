<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Test\Unit\Model;

use Hryvinskyi\CatalogSortingMetrics\Model\ProductPageCachePurger;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Indexer\CacheContextFactory;
use PHPUnit\Framework\TestCase;

/**
 * Each purge registers the product cache tag of every given product in a cache context of its own and dispatches
 * the event the page caches listen to with that context; no products means no event.
 */
class ProductPageCachePurgerTest extends TestCase
{
    /**
     * @return void
     */
    public function testDispatchesTheProductTagsOfEveryProductInAFreshContextPerCall(): void
    {
        $first = new CacheContext();
        $second = new CacheContext();
        $factory = $this->createMock(CacheContextFactory::class);
        $factory->expects(self::exactly(2))->method('create')->willReturnOnConsecutiveCalls($first, $second);
        $dispatched = [];
        $eventManager = $this->createMock(ManagerInterface::class);
        $eventManager->method('dispatch')->willReturnCallback(
            static function (string $name, array $data) use (&$dispatched): void {
                $dispatched[] = [$name, $data];
            }
        );
        $purger = new ProductPageCachePurger($factory, $eventManager);

        $purger->purge([10, 20]);
        $purger->purge([30]);

        self::assertSame(
            [
                ['clean_cache_by_tags', ['object' => $first]],
                ['clean_cache_by_tags', ['object' => $second]],
            ],
            $dispatched
        );
        self::assertSame(['cat_p_10', 'cat_p_20'], $first->getIdentities());
        self::assertSame(['cat_p_30'], $second->getIdentities());
    }

    /**
     * @return void
     */
    public function testNoProductsMeansNoEvent(): void
    {
        $factory = $this->createMock(CacheContextFactory::class);
        $factory->expects(self::never())->method('create');
        $eventManager = $this->createMock(ManagerInterface::class);
        $eventManager->expects(self::never())->method('dispatch');

        (new ProductPageCachePurger($factory, $eventManager))->purge([]);
    }
}
