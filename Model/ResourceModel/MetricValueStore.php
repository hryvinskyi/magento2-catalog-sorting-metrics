<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Model\ResourceModel;

use Hryvinskyi\CatalogSortingMetrics\Api\MetricInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\MetricValueStoreInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\PendingProductsInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\DB\Sql\ExpressionFactory;

/**
 * Stored metric values and the products awaiting a search document rebuild, in MySQL
 *
 * A metric is computed into a staging table with one `INSERT … SELECT`, so its values never travel through PHP;
 * the difference to the stored rows is then written in one transaction together with the products it touches.
 * Every product whose stored row is written or deleted, for any reason, is recorded as pending in the same
 * transaction as the write or the delete, unless the product no longer exists: a row left behind by a deleted
 * product is deleted all the same, and there is no document to rebuild for it.
 *
 * Every statement goes through the one default connection object: the staging table is a temporary table, which
 * exists only on the connection that created it. It is created and dropped outside any transaction, because a
 * server enforcing GTID consistency refuses temporary-table DDL inside one, and it is never named twice in one
 * statement, because MySQL cannot reopen a temporary table within a statement. It holds one metric at a time, so
 * its primary key is the store and the product, the pair every join onto it uses.
 */
class MetricValueStore implements MetricValueStoreInterface, PendingProductsInterface
{
    private const METRIC_TABLE = 'hryvinskyi_catalog_sorting_metric';
    private const PENDING_TABLE = 'hryvinskyi_catalog_sorting_metric_pending';
    private const STAGING_TABLE = 'hryvinskyi_catalog_sorting_metric_staging';
    private const CODE_BIND = 'hryvinskyi_metric_code';
    private const COLUMNS = ['metric_code', 'store_id', 'product_id', 'value'];

    /**
     * @var int
     */
    private readonly int $chunkSize;

    /**
     * @param ResourceConnection $resourceConnection Gives the connection and table names
     * @param ExpressionFactory $expressionFactory Creates the bound metric code column of the staging insert
     * @param int $chunkSize Products recorded as pending per statement; above zero
     * @throws \InvalidArgumentException When the chunk size is not above zero
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ExpressionFactory $expressionFactory,
        int $chunkSize = 1000
    ) {
        if ($chunkSize < 1) {
            throw new \InvalidArgumentException(sprintf(
                'The metric value store chunk size must be above zero; %d given.',
                $chunkSize
            ));
        }

        $this->chunkSize = $chunkSize;
    }

    /**
     * @inheritDoc
     */
    public function store(MetricInterface $metric, array $storeIds): int
    {
        $connection = $this->resourceConnection->getConnection();
        $code = $metric->getCode();
        $staging = $this->table(self::STAGING_TABLE);
        $connection->dropTemporaryTable($staging);
        try {
            $connection->createTemporaryTable($this->stagingTable($connection, $staging));
            $this->fillStaging($connection, $metric, $staging, $storeIds);
            $changedIds = $this->changedProductIds($connection, $code, $staging);
            $this->writeChanges($connection, $code, $staging, $changedIds);
        } finally {
            $connection->dropTemporaryTable($staging);
        }

        return count($changedIds);
    }

    /**
     * @inheritDoc
     */
    public function deleteEverywhere(string $code): int
    {
        $connection = $this->resourceConnection->getConnection();
        $main = $this->table(self::METRIC_TABLE);
        $productIds = $this->productIds($connection->fetchCol(
            $connection->select()->distinct()->from($main, ['product_id'])->where('metric_code = ?', $code)
        ));
        if ($productIds === []) {
            return 0;
        }

        $this->changeAndRecordPending(
            $connection,
            static function () use ($connection, $main, $code): void {
                $connection->delete($main, ['metric_code = ?' => $code]);
            },
            $productIds
        );

        return count($productIds);
    }

    /**
     * @inheritDoc
     */
    public function getProductIds(): array
    {
        $connection = $this->resourceConnection->getConnection();

        return $this->productIds($connection->fetchCol(
            $connection->select()->from($this->table(self::PENDING_TABLE), ['product_id'])->order('product_id ASC')
        ));
    }

    /**
     * @inheritDoc
     */
    public function release(array $productIds): void
    {
        if ($productIds === []) {
            return;
        }

        $this->resourceConnection->getConnection()->delete(
            $this->table(self::PENDING_TABLE),
            ['product_id IN (?)' => $productIds]
        );
    }

    /**
     * Define the staging table: the stored table's columns without its foreign keys, keyed by store and product
     *
     * A temporary table cannot carry foreign keys, so it is not copied from the stored table's definition.
     *
     * @param AdapterInterface $connection The default connection
     * @param string $name Staging table name
     * @return Table
     */
    private function stagingTable(AdapterInterface $connection, string $name): Table
    {
        return $connection->newTable($name)
            ->addColumn('metric_code', Table::TYPE_TEXT, 32, ['nullable' => false])
            ->addColumn('store_id', Table::TYPE_SMALLINT, null, [
                'unsigned' => true,
                'nullable' => false,
                'primary' => true,
            ])
            ->addColumn('product_id', Table::TYPE_INTEGER, null, [
                'unsigned' => true,
                'nullable' => false,
                'primary' => true,
            ])
            ->addColumn('value', Table::TYPE_DECIMAL, [20, 4], ['nullable' => false])
            ->setOption('type', 'InnoDB')
            ->setOption('charset', 'utf8mb4')
            ->setOption('collate', 'utf8mb4_general_ci');
    }

    /**
     * Copy the source's rows above zero of the given stores into the staging table, in one statement
     *
     * @param AdapterInterface $connection The default connection
     * @param MetricInterface $metric Metric to compute
     * @param string $staging Staging table name
     * @param non-empty-list<int> $storeIds Stores where the metric is enabled
     * @return void
     */
    private function fillStaging(
        AdapterInterface $connection,
        MetricInterface $metric,
        string $staging,
        array $storeIds
    ): void {
        $source = $metric->getSource()->select();
        $select = $connection->select()
            ->from(['s' => $source], [
                'metric_code' => $this->expressionFactory->create(['expression' => ':' . self::CODE_BIND]),
                'store_id' => 's.store_id',
                'product_id' => 's.product_id',
                'value' => 's.value',
            ])
            ->where('s.value > 0')
            ->where('s.store_id IN (?)', $storeIds);

        $bind = $source->getBind();
        $bind[self::CODE_BIND] = $metric->getCode();

        $connection->query($connection->insertFromSelect($select, $staging, self::COLUMNS), $bind);
    }

    /**
     * Get the products whose stored row will be written or deleted
     *
     * A staging row is written when it has no stored row or a different value; a stored row is deleted when
     * staging has no row for its store and product, which also covers every store the metric is no longer
     * enabled in. Two statements, each naming the staging table once, merged here.
     *
     * @param AdapterInterface $connection The default connection
     * @param string $code Metric code
     * @param string $staging Staging table name
     * @return list<int>
     */
    private function changedProductIds(AdapterInterface $connection, string $code, string $staging): array
    {
        $main = $this->table(self::METRIC_TABLE);
        $newOrDifferent = $connection->select()
            ->distinct()
            ->from(['st' => $staging], ['product_id'])
            ->joinLeft(['m' => $main], $this->storedRowOfStagingRow($connection, $code), [])
            ->where('m.product_id IS NULL OR m.value <> st.value');
        $absent = $connection->select()
            ->distinct()
            ->from(['m' => $main], ['product_id'])
            ->joinLeft(['st' => $staging], $this->stagingRowOfStoredRow(), [])
            ->where('m.metric_code = ?', $code)
            ->where('st.product_id IS NULL');

        $ids = array_merge($connection->fetchCol($newOrDifferent), $connection->fetchCol($absent));

        return array_values(array_unique($this->productIds($ids)));
    }

    /**
     * Write the difference to the stored rows and record the changed products as pending, in one transaction
     *
     * New and different staging rows are written; stored rows staging has no row for are deleted, a row left behind
     * by a product that no longer exists among them.
     *
     * @param AdapterInterface $connection The default connection
     * @param string $code Metric code
     * @param string $staging Staging table name
     * @param list<int> $changedIds Products whose stored row is written or deleted
     * @return void
     * @throws \Throwable When a statement fails, after rolling back
     */
    private function writeChanges(AdapterInterface $connection, string $code, string $staging, array $changedIds): void
    {
        $main = $this->table(self::METRIC_TABLE);
        $absent = $connection->select()
            ->from(['m' => $main], [])
            ->joinLeft(['st' => $staging], $this->stagingRowOfStoredRow(), [])
            ->where('m.metric_code = ?', $code)
            ->where('st.product_id IS NULL');
        $newOrDifferent = $connection->select()
            ->from(['st' => $staging], self::COLUMNS)
            ->joinLeft(['m' => $main], $this->storedRowOfStagingRow($connection, $code), [])
            ->where('m.product_id IS NULL OR m.value <> st.value');
        $upsert = $connection->select()->from(['c' => $newOrDifferent], self::COLUMNS);

        $this->changeAndRecordPending(
            $connection,
            static function () use ($connection, $absent, $upsert, $main): void {
                $connection->query($connection->deleteFromSelect($absent, 'm'));
                $connection->query($connection->insertFromSelect(
                    $upsert,
                    $main,
                    self::COLUMNS,
                    AdapterInterface::INSERT_ON_DUPLICATE
                ));
            },
            $changedIds
        );
    }

    /**
     * Run a change of the stored rows and record its products as pending, in one transaction
     *
     * Both write paths go through here, so a stored value never changes without its product awaiting a rebuild,
     * and a failing statement undoes the change and the recording alike.
     *
     * @param AdapterInterface $connection The default connection
     * @param \Closure $change Runs the statements that write or delete stored rows
     * @param list<int> $productIds Products whose stored rows the change writes or deletes
     * @return void
     * @throws \Throwable When a statement fails, after rolling back
     */
    private function changeAndRecordPending(AdapterInterface $connection, \Closure $change, array $productIds): void
    {
        $connection->beginTransaction();
        try {
            $change();
            $this->recordPending($connection, $productIds);
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }

    /**
     * Get the join condition from a staging row to the stored row of the same metric, store and product
     *
     * @param AdapterInterface $connection The default connection
     * @param string $code Metric code
     * @return string
     */
    private function storedRowOfStagingRow(AdapterInterface $connection, string $code): string
    {
        return $connection->quoteInto('m.metric_code = ?', $code)
            . ' AND m.store_id = st.store_id AND m.product_id = st.product_id';
    }

    /**
     * Get the join condition from a stored row to the staging row of the same store and product
     *
     * Staging holds the one metric being stored, so its key is the store and the product.
     *
     * @return string
     */
    private function stagingRowOfStoredRow(): string
    {
        return 'st.store_id = m.store_id AND st.product_id = m.product_id';
    }

    /**
     * Record the products that exist as pending a search document rebuild; one already pending stays pending once
     *
     * A stored row can outlive its product when rows were loaded or products deleted with foreign key checks off.
     * Such a product has no document to rebuild, and recording it would fail on the pending table's product key and
     * roll the change back on every run, so the row could never be deleted. Only ids found in the product table are
     * recorded.
     *
     * @param AdapterInterface $connection The default connection, inside the transaction of the change
     * @param list<int> $productIds Product ids
     * @return void
     */
    private function recordPending(AdapterInterface $connection, array $productIds): void
    {
        $pending = $this->table(self::PENDING_TABLE);
        $products = $this->table('catalog_product_entity');
        foreach (array_chunk($productIds, $this->chunkSize) as $chunk) {
            $existing = $connection->select()
                ->from(['e' => $products], ['product_id' => 'e.entity_id'])
                ->where('e.entity_id IN (?)', $chunk);
            $connection->query($connection->insertFromSelect(
                $existing,
                $pending,
                ['product_id'],
                AdapterInterface::INSERT_ON_DUPLICATE
            ));
        }
    }

    /**
     * Read product ids as the database returns them
     *
     * @param array<mixed> $values Column values
     * @return list<int>
     */
    private function productIds(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            if (is_string($value) && ctype_digit($value)) {
                $value = (int)$value;
            }
            if (is_int($value)) {
                $ids[] = $value;
            }
        }

        return $ids;
    }

    /**
     * Get a table name with the installation's prefix
     *
     * @param string $name Table name without prefix
     * @return string
     */
    private function table(string $name): string
    {
        return $this->resourceConnection->getTableName($name);
    }
}
