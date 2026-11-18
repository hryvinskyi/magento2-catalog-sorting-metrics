<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Test\Unit\Model\ResourceModel;

use Hryvinskyi\CatalogSortingMetrics\Model\ResourceModel\MetricValueStore;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\ExpressionFactory;
use PHPUnit\Framework\TestCase;

/**
 * The value store refuses a pending chunk size below one; its statements run against the real database in the
 * integration tests of the refresh.
 */
class MetricValueStoreTest extends TestCase
{
    /**
     * @return void
     */
    public function testRefusesAChunkSizeBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('0 given');

        new MetricValueStore(
            $this->createMock(ResourceConnection::class),
            $this->createMock(ExpressionFactory::class),
            0
        );
    }
}
