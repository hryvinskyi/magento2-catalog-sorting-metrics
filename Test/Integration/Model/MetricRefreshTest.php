<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Test\Integration\Model;

use Hryvinskyi\CatalogSorting\Model\Index\DocumentFields;
use Hryvinskyi\CatalogSortingMetrics\Api\CronExpressionResolverInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\MetricRefreshInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\MetricRegistryInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\MetricValueStoreInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\PendingDocumentRebuildInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\PendingProductsInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\ProductPageCachePurgerInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\SearchDocumentRefresherInterface;
use Hryvinskyi\CatalogSortingMetrics\Console\Command\RefreshMetricsCommand;
use Hryvinskyi\CatalogSortingMetrics\Model\CronExpressionResolver;
use Hryvinskyi\CatalogSortingMetrics\Model\FulltextSearchDocumentRefresher;
use Hryvinskyi\CatalogSortingMetrics\Model\MetricRefresh;
use Hryvinskyi\CatalogSortingMetrics\Model\MetricRegistry;
use Hryvinskyi\CatalogSortingMetrics\Model\PendingDocumentRebuild;
use Hryvinskyi\CatalogSortingMetrics\Model\ProductPageCachePurger;
use Hryvinskyi\CatalogSortingMetrics\Model\ResourceModel\MetricValueStore;
use Hryvinskyi\CatalogSortingMetrics\Test\Integration\FailingTestMetric;
use Hryvinskyi\CatalogSortingMetrics\Test\Integration\RecordingProductPageCachePurger;
use Hryvinskyi\CatalogSortingMetrics\Test\Integration\RecordingSearchDocumentRefresher;
use Hryvinskyi\CatalogSortingMetrics\Test\Integration\ReplacesSearchEngineAndPageCache;
use Hryvinskyi\CatalogSortingMetrics\Test\Integration\TestMetric;
use Hryvinskyi\CatalogSortingMetrics\Test\Integration\TestMetricSource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Console\CommandListInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Ddl\Table;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Test\Fixture\Store as StoreFixture;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * The refresh against the real database: it stores the values above zero of the stores where the metric is enabled,
 * reports and hands over exactly the products whose value changed, keeps them pending while the search engine
 * fails and hands them over again on the next run, and the stored values reach the product search documents.
 * Products whose stored rows are deleted, because the metric was switched off in a store or everywhere, are handed
 * over too, and a metric whose computation fails does not keep the other metric's products from being handed over.
 * A statement that fails inside the value store's transaction leaves the stored rows and the pending products as
 * they were, and storing leaves no staging table behind on the connection. A stored row left behind by a deleted
 * product is deleted like any other, without blocking the run.
 *
 * The refresh commits its own transaction, so no scenario runs inside the test transaction: every scenario cleans
 * up the products, activity, stored rows and pending rows it created. A test metric is registered on the real
 * registry, and recording doubles stand in for the search engine and the page cache, which the run neither needs
 * nor may write to. Every scenario runs on a fresh application, because the container is reconfigured.
 */
#[AppArea('global')]
class MetricRefreshTest extends TestCase
{
    use ReplacesSearchEngineAndPageCache;

    private const METRIC_TABLE = 'hryvinskyi_catalog_sorting_metric';
    private const PENDING_TABLE = 'hryvinskyi_catalog_sorting_metric_pending';
    private const STAGING_TABLE = 'hryvinskyi_catalog_sorting_metric_staging';
    private const METRIC_PRODUCT_KEY = 'HRYVINSKYI_CAT_SORTING_METRIC_PRD_ID_CAT_PRD_ENTT_ENTT_ID';
    private const SKU_PREFIX = 'hryvinskyi-metrics-test-';

    /**
     * @var list<int>
     */
    private array $productIds = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $connection = $this->connection();
        $activity = $this->table(TestMetricSource::TABLE);
        if (!$connection->isTableExists($activity)) {
            $connection->createTable(
                $connection->newTable($activity)
                    ->addColumn('product_id', Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => false])
                    ->addColumn('store_id', Table::TYPE_SMALLINT, null, ['unsigned' => true, 'nullable' => false])
                    ->addColumn('amount', Table::TYPE_DECIMAL, [20, 4], ['nullable' => false])
            );
        }
        $this->productIds = [$this->createProduct(1), $this->createProduct(2), $this->createProduct(3)];
        $this->registerTheTestMetric();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $connection = $this->connection();
        $connection->delete($this->table(self::METRIC_TABLE), ['metric_code = ?' => TestMetric::CODE]);
        if ($this->productIds !== []) {
            $connection->delete($this->table(self::PENDING_TABLE), ['product_id IN (?)' => $this->productIds]);
            $connection->delete($this->table('catalog_product_entity'), ['entity_id IN (?)' => $this->productIds]);
        }
        $connection->dropTable($this->table(TestMetricSource::TABLE));
        $this->productIds = [];
    }

    /**
     * @return void
     */
    #[AppIsolation(true)]
    #[DbIsolation(false)]
    public function testEveryContractResolvesThroughTheContainer(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $commands = $objectManager->get(CommandListInterface::class);
        self::assertInstanceOf(CommandListInterface::class, $commands);
        // A plugin on the console command base class makes the container hand out a generated subclass, so the
        // registered command is found by type rather than by its exact class name
        $refreshCommands = array_values(array_filter(
            $commands->getCommands(),
            static fn (object $command): bool => $command instanceof RefreshMetricsCommand
        ));
        $objectManager->configure(['preferences' => [
            SearchDocumentRefresherInterface::class => FulltextSearchDocumentRefresher::class,
            ProductPageCachePurgerInterface::class => ProductPageCachePurger::class,
        ]]);

        self::assertInstanceOf(MetricRegistry::class, $objectManager->get(MetricRegistryInterface::class));
        self::assertInstanceOf(MetricRefresh::class, $objectManager->create(MetricRefreshInterface::class));
        self::assertInstanceOf(MetricValueStore::class, $objectManager->create(MetricValueStoreInterface::class));
        self::assertInstanceOf(MetricValueStore::class, $objectManager->create(PendingProductsInterface::class));
        self::assertInstanceOf(
            PendingDocumentRebuild::class,
            $objectManager->create(PendingDocumentRebuildInterface::class)
        );
        self::assertInstanceOf(
            FulltextSearchDocumentRefresher::class,
            $objectManager->create(SearchDocumentRefresherInterface::class)
        );
        self::assertInstanceOf(
            ProductPageCachePurger::class,
            $objectManager->create(ProductPageCachePurgerInterface::class)
        );
        self::assertInstanceOf(
            CronExpressionResolver::class,
            $objectManager->get(CronExpressionResolverInterface::class)
        );
        self::assertCount(1, $refreshCommands);
        self::assertSame('catalog-sorting:metrics:refresh', $refreshCommands[0]->getName());
    }

    /**
     * @return void
     */
    #[AppIsolation(true)]
    #[DbIsolation(false)]
    public function testTheFirstRefreshStoresTheValuesAboveZeroAndAnIdenticalSecondOneChangesNothing(): void
    {
        [$first, $second, $third] = $this->productIds;
        $store = $this->defaultStoreId();
        $this->metric()->enableIn($store);
        $this->setActivity([[$first, $store, 5], [$second, $store, 2], [$third, $store, 0]]);

        self::assertSame([TestMetric::CODE => 2], $this->refresh());
        self::assertSame([$first => 5.0, $second => 2.0], $this->storedValues($store));
        self::assertSame([$first, $second], $this->refresher()->takeProductIds());
        self::assertSame([$first, $second], $this->purger()->takeProductIds());
        self::assertSame([], $this->pendingIds());

        self::assertSame([TestMetric::CODE => 0], $this->refresh());
        self::assertSame([$first => 5.0, $second => 2.0], $this->storedValues($store));
        self::assertSame([], $this->refresher()->takeProductIds());
        self::assertSame([], $this->purger()->takeProductIds());
    }

    /**
     * @return void
     */
    #[AppIsolation(true)]
    #[DbIsolation(false)]
    public function testAChangedARemovedAndAnAddedValueAreExactlyTheProductsHandedOver(): void
    {
        [$first, $second, $third] = $this->productIds;
        $store = $this->defaultStoreId();
        $this->metric()->enableIn($store);
        $this->setActivity([[$first, $store, 5], [$second, $store, 2]]);
        $this->refresh();
        $this->refresher()->takeProductIds();
        $this->purger()->takeProductIds();

        $this->setActivity([[$first, $store, 6], [$third, $store, 1]]);

        self::assertSame([TestMetric::CODE => 3], $this->refresh());
        self::assertSame([$first => 6.0, $third => 1.0], $this->storedValues($store));
        self::assertSame([$first, $second, $third], $this->refresher()->takeProductIds());
        self::assertSame([$first, $second, $third], $this->purger()->takeProductIds());
    }

    /**
     * @return void
     */
    #[AppIsolation(true)]
    #[DbIsolation(false)]
    public function testProductsStayPendingWhileTheSearchEngineFailsAndAreHandedOverAgainNextRun(): void
    {
        [$first, $second] = $this->productIds;
        $store = $this->defaultStoreId();
        $this->metric()->enableIn($store);
        $this->setActivity([[$first, $store, 5], [$second, $store, 2]]);
        $this->refresher()->setFailing(true);

        try {
            $this->refresh();
            self::fail('The search engine failure was not rethrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('The search engine is not reachable.', $exception->getMessage());
        }

        self::assertSame([$first => 5.0, $second => 2.0], $this->storedValues($store));
        self::assertSame([$first, $second], $this->pendingIds());
        self::assertSame([], $this->purger()->takeProductIds());

        $this->refresher()->setFailing(false);

        self::assertSame([TestMetric::CODE => 0], $this->refresh());
        self::assertSame([$first, $second], $this->refresher()->takeProductIds());
        self::assertSame([$first, $second], $this->purger()->takeProductIds());
        self::assertSame([], $this->pendingIds());
    }

    /**
     * @return void
     */
    #[AppIsolation(true)]
    #[DbIsolation(false)]
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    public function testOnlyEnabledStoresAreStoredAndEnablingASecondStoreReportsItsProducts(): void
    {
        [$first, $second] = $this->productIds;
        $store = $this->defaultStoreId();
        $secondStore = $this->fixtureStoreId('second_store');
        $this->metric()->enableIn($store);
        $this->setActivity([[$first, $store, 5], [$second, $secondStore, 3]]);

        self::assertSame([TestMetric::CODE => 1], $this->refresh());
        self::assertSame([$first => 5.0], $this->storedValues($store));
        self::assertSame([], $this->storedValues($secondStore));
        self::assertSame([$first], $this->refresher()->takeProductIds());

        $this->metric()->enableIn($secondStore);

        self::assertSame([TestMetric::CODE => 1], $this->refresh());
        self::assertSame([$second => 3.0], $this->storedValues($secondStore));
        self::assertSame([$second], $this->refresher()->takeProductIds());
    }

    /**
     * @return void
     */
    #[AppIsolation(true)]
    #[DbIsolation(false)]
    public function testSwitchingTheMetricOffEverywhereDeletesItsRowsAndHandsTheirProductsOver(): void
    {
        [$first, $second] = $this->productIds;
        $store = $this->defaultStoreId();
        $this->metric()->enableIn($store);
        $this->setActivity([[$first, $store, 5], [$second, $store, 2]]);
        $this->refresh();
        $this->refresher()->takeProductIds();
        $this->purger()->takeProductIds();

        $this->metric()->disableIn($store);

        self::assertSame([TestMetric::CODE => 2], $this->refresh());
        self::assertSame([], $this->storedValues($store));
        self::assertSame([$first, $second], $this->refresher()->takeProductIds());
        self::assertSame([$first, $second], $this->purger()->takeProductIds());
        self::assertSame([], $this->pendingIds());

        $this->metric()->enableIn($store);

        self::assertSame([TestMetric::CODE => 2], $this->refresh());
        self::assertSame([$first => 5.0, $second => 2.0], $this->storedValues($store));
        self::assertSame([$first, $second], $this->refresher()->takeProductIds());
    }

    /**
     * @return void
     */
    #[AppIsolation(true)]
    #[DbIsolation(false)]
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    public function testSwitchingTheMetricOffInOneStoreDeletesThatStoresRowsAndHandsTheirProductsOver(): void
    {
        [$first, $second, $third] = $this->productIds;
        $store = $this->defaultStoreId();
        $secondStore = $this->fixtureStoreId('second_store');
        $this->metric()->enableIn($store, $secondStore);
        $this->setActivity([[$first, $store, 5], [$second, $secondStore, 3], [$third, $secondStore, 4]]);
        self::assertSame([TestMetric::CODE => 3], $this->refresh());
        $this->refresher()->takeProductIds();
        $this->purger()->takeProductIds();

        $this->metric()->disableIn($secondStore);

        self::assertSame([TestMetric::CODE => 2], $this->refresh());
        self::assertSame([$first => 5.0], $this->storedValues($store));
        self::assertSame([], $this->storedValues($secondStore));
        self::assertSame([$second, $third], $this->refresher()->takeProductIds());
        self::assertSame([$second, $third], $this->purger()->takeProductIds());
        self::assertSame([], $this->pendingIds());
    }

    /**
     * @return void
     */
    #[AppIsolation(true)]
    #[DbIsolation(false)]
    public function testAMetricFailingAfterAGoodOneStillLetsTheGoodOnesProductsBeRebuiltAndFailsTheRun(): void
    {
        [$first, $second] = $this->productIds;
        $store = $this->defaultStoreId();
        $this->registerTheFailingMetricAfterTheTestMetric();
        $this->metric()->enableIn($store);
        $this->setActivity([[$first, $store, 5], [$second, $store, 2]]);

        try {
            $this->refresh([TestMetric::CODE, FailingTestMetric::CODE]);
            self::fail('The failed metric was not reported.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(FailingTestMetric::CODE, $exception->getMessage());
            self::assertNotNull($exception->getPrevious());
        }

        self::assertSame([$first => 5.0, $second => 2.0], $this->storedValues($store));
        self::assertSame([$first, $second], $this->refresher()->takeProductIds());
        self::assertSame([$first, $second], $this->purger()->takeProductIds());
        self::assertSame([], $this->pendingIds());
    }

    /**
     * @return void
     */
    #[AppIsolation(true)]
    #[DbIsolation(false)]
    public function testAStoreThatFailsInsideItsTransactionLeavesEverythingAsItWasAndNoStagingTable(): void
    {
        [$first, $second] = $this->productIds;
        $store = $this->defaultStoreId();
        $this->metric()->enableIn($store);
        $this->setActivity([[$first, $store, 5], [$second, $store, 2]]);
        $this->refresh();
        $missingProduct = $this->missingProductId();
        // The second value is gone and the first changed, so the rows are deleted and written in the transaction;
        // the value of a product that does not exist passes staging but fails the write on the product key
        $this->setActivity([[$first, $store, 6], [$missingProduct, $store, 1]]);
        $valueStore = Bootstrap::getObjectManager()->get(MetricValueStoreInterface::class);
        self::assertInstanceOf(MetricValueStoreInterface::class, $valueStore);

        $failure = $this->failureOf(fn (): int => $valueStore->store($this->metric(), [$store]));
        self::assertStringContainsString('a foreign key constraint fails', $failure);
        self::assertStringContainsString(self::METRIC_PRODUCT_KEY, $failure);
        self::assertSame([$first => 5.0, $second => 2.0], $this->storedValues($store));
        self::assertSame([], $this->pendingIds());
        self::assertStringContainsString('1146', $this->failureOf(fn (): mixed => $this->connection()->fetchOne(
            $this->connection()->select()->from($this->table(self::STAGING_TABLE), ['product_id'])->limit(1)
        )));

        $this->setActivity([[$first, $store, 6]]);

        self::assertSame(2, $valueStore->store($this->metric(), [$store]));
        self::assertSame([$first => 6.0], $this->storedValues($store));
        self::assertSame([$first, $second], $this->pendingIds());
    }

    /**
     * @return void
     */
    #[AppIsolation(true)]
    #[DbIsolation(false)]
    public function testARowLeftBehindByADeletedProductIsDeletedWhenTheMetricIsSwitchedOffEverywhere(): void
    {
        $this->recordPendingProductsOneAtATime();
        [$first, $second] = $this->productIds;
        $store = $this->defaultStoreId();
        $this->metric()->enableIn($store);
        $this->setActivity([[$first, $store, 5], [$second, $store, 2]]);
        $this->refresh();
        $this->refresher()->takeProductIds();
        $this->purger()->takeProductIds();
        $missingProduct = $this->missingProductId();
        $this->insertStoredRowWithoutKeyChecks($store, $missingProduct, 3);

        $this->metric()->disableIn($store);

        self::assertSame([TestMetric::CODE => 3], $this->refresh());
        self::assertSame([], $this->storedValues($store));
        self::assertSame([$first, $second], $this->refresher()->takeProductIds());
        self::assertSame([$first, $second], $this->purger()->takeProductIds());
        self::assertSame([], $this->pendingIds());
    }

    /**
     * @return void
     */
    #[AppIsolation(true)]
    #[DbIsolation(false)]
    public function testARowLeftBehindByADeletedProductIsDeletedWhenTheMetricIsStored(): void
    {
        $this->recordPendingProductsOneAtATime();
        [$first, $second] = $this->productIds;
        $store = $this->defaultStoreId();
        $this->metric()->enableIn($store);
        $missingProduct = $this->missingProductId();
        $this->insertStoredRowWithoutKeyChecks($store, $missingProduct, 3);
        $this->setActivity([[$first, $store, 5], [$second, $store, 2]]);

        self::assertSame([TestMetric::CODE => 3], $this->refresh());
        self::assertSame([$first => 5.0, $second => 2.0], $this->storedValues($store));
        self::assertSame([$first, $second], $this->refresher()->takeProductIds());
        self::assertSame([$first, $second], $this->purger()->takeProductIds());
        self::assertSame([], $this->pendingIds());

        self::assertSame([TestMetric::CODE => 0], $this->refresh());
    }

    /**
     * @return void
     */
    #[AppIsolation(true)]
    #[DbIsolation(false)]
    public function testTheStoredValuesReachTheProductSearchDocumentsOfTheEnabledStore(): void
    {
        [$first, $second] = $this->productIds;
        $store = $this->defaultStoreId();
        $this->metric()->enableIn($store);
        $this->setActivity([[$first, $store, 5]]);
        $this->refresh();
        $documentFields = Bootstrap::getObjectManager()->get(DocumentFields::class);
        self::assertInstanceOf(DocumentFields::class, $documentFields);

        $fields = $documentFields->getFields([$first, $second], $store);

        self::assertSame([$first => 5.0], $this->valuesOfTheTestMetric($fields));
        self::assertSame('double', $documentFields->getFieldTypes()[TestMetric::FIELD] ?? null);
    }

    /**
     * Keep the test metric's field of each product document, leaving out the fields other registered providers add
     *
     * @param array<int, array<string, int|float>> $fields Product id => field name => value
     * @return array<int, int|float> Product id => test metric value, only for products whose document carries it
     */
    private function valuesOfTheTestMetric(array $fields): array
    {
        $values = [];
        foreach ($fields as $productId => $productFields) {
            if (array_key_exists(TestMetric::FIELD, $productFields)) {
                $values[$productId] = $productFields[TestMetric::FIELD];
            }
        }

        return $values;
    }

    /**
     * Register the test metric on the real registry and the recording doubles in place of the search engine and
     * the page cache, and drop the shared instances built without them
     *
     * @return void
     */
    private function registerTheTestMetric(): void
    {
        $this->registerMetrics(['hryvinskyiTestMetric' => ['instance' => TestMetric::class]]);
    }

    /**
     * Register the failing test metric after the test metric, so it is refreshed second
     *
     * @return void
     */
    private function registerTheFailingMetricAfterTheTestMetric(): void
    {
        $this->registerMetrics([
            'hryvinskyiTestMetric' => ['instance' => TestMetric::class],
            'hryvinskyiFailingTestMetric' => ['instance' => FailingTestMetric::class],
        ]);
    }

    /**
     * Register the given metrics on the real registry and the recording doubles in place of the search engine and
     * the page cache, and drop the shared instances built without them
     *
     * @param array<string, array{instance: class-string}> $metrics Registry items
     * @return void
     */
    private function registerMetrics(array $metrics): void
    {
        $this->objectManager()->configure([
            MetricRegistry::class => [
                'arguments' => ['metrics' => $metrics],
            ],
        ]);
        $this->replaceTheSearchEngineAndThePageCache();
    }

    /**
     * Make the value store record pending products one per statement, so every product id is its own chunk
     *
     * @return void
     */
    private function recordPendingProductsOneAtATime(): void
    {
        $objectManager = $this->objectManager();
        $objectManager->configure([
            MetricValueStore::class => [
                'arguments' => ['chunkSize' => 1],
            ],
        ]);
        foreach ([
            MetricValueStore::class,
            MetricValueStoreInterface::class,
            PendingProductsInterface::class,
            PendingDocumentRebuild::class,
            PendingDocumentRebuildInterface::class,
            MetricRefresh::class,
            MetricRefreshInterface::class,
        ] as $type) {
            $objectManager->removeSharedInstance($type);
        }
    }

    /**
     * Run the refresh of the given metrics, the test metric by default, through the real container
     *
     * @param list<string> $codes Metric codes
     * @return array<string, int>
     */
    private function refresh(array $codes = [TestMetric::CODE]): array
    {
        $refresh = Bootstrap::getObjectManager()->get(MetricRefreshInterface::class);
        self::assertInstanceOf(MetricRefreshInterface::class, $refresh);

        return $refresh->refresh($codes);
    }

    /**
     * Replace the activity the test metric is computed from
     *
     * @param list<array{0: int, 1: int, 2: int|float}> $rows Product id, store id and amount per row
     * @return void
     */
    private function setActivity(array $rows): void
    {
        $connection = $this->connection();
        $activity = $this->table(TestMetricSource::TABLE);
        $connection->delete($activity);
        if ($rows !== []) {
            $connection->insertArray($activity, ['product_id', 'store_id', 'amount'], $rows);
        }
    }

    /**
     * Run a call that must fail and get its failure message
     *
     * @param callable(): mixed $call Call expected to throw
     * @return string
     */
    private function failureOf(callable $call): string
    {
        try {
            $call();
        } catch (\Throwable $exception) {
            return $exception->getMessage();
        }

        self::fail('The call was expected to fail.');
    }

    /**
     * Get the id of a product that existed and was deleted, so no product has it
     *
     * @return int
     */
    private function missingProductId(): int
    {
        $productId = $this->createProduct(9);
        $this->connection()->delete($this->table('catalog_product_entity'), ['entity_id = ?' => $productId]);

        return $productId;
    }

    /**
     * Store a test metric row with foreign key checks off, the way a load that skips them leaves rows behind
     *
     * @param int $storeId Store id
     * @param int $productId Product id, which need not exist
     * @param int|float $value Value
     * @return void
     */
    private function insertStoredRowWithoutKeyChecks(int $storeId, int $productId, int|float $value): void
    {
        $connection = $this->connection();
        $connection->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $connection->insert($this->table(self::METRIC_TABLE), [
                'metric_code' => TestMetric::CODE,
                'store_id' => $storeId,
                'product_id' => $productId,
                'value' => $value,
            ]);
        } finally {
            $connection->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * Get the stored values of the test metric in a store
     *
     * @param int $storeId Store id
     * @return array<int, float> Product id => value
     */
    private function storedValues(int $storeId): array
    {
        $connection = $this->connection();
        $rows = $connection->fetchPairs(
            $connection->select()
                ->from($this->table(self::METRIC_TABLE), ['product_id', 'value'])
                ->where('metric_code = ?', TestMetric::CODE)
                ->where('store_id = ?', $storeId)
                ->order('product_id ASC')
        );

        $values = [];
        foreach ($rows as $productId => $value) {
            $values[(int)$productId] = is_numeric($value) ? (float)$value : 0.0;
        }

        return $values;
    }

    /**
     * Get the pending product ids among the test products, ascending
     *
     * @return list<int>
     */
    private function pendingIds(): array
    {
        $connection = $this->connection();
        $ids = $connection->fetchCol(
            $connection->select()
                ->from($this->table(self::PENDING_TABLE), ['product_id'])
                ->where('product_id IN (?)', $this->productIds)
                ->order('product_id ASC')
        );

        return array_values(array_map(static fn (mixed $id): int => is_numeric($id) ? (int)$id : 0, $ids));
    }

    /**
     * Create a bare product row the metric tables can refer to
     *
     * @param int $number Number that makes the SKU unique
     * @return int Product id
     */
    private function createProduct(int $number): int
    {
        $connection = $this->connection();
        $attributeSetId = $connection->fetchOne(
            $connection->select()
                ->from($this->table('eav_entity_type'), ['default_attribute_set_id'])
                ->where('entity_type_code = ?', 'catalog_product')
        );
        $sku = self::SKU_PREFIX . $number . '-' . uniqid();
        $connection->insert($this->table('catalog_product_entity'), [
            'attribute_set_id' => is_numeric($attributeSetId) ? (int)$attributeSetId : 4,
            'type_id' => 'simple',
            'sku' => $sku,
        ]);
        $productId = $connection->fetchOne(
            $connection->select()
                ->from($this->table('catalog_product_entity'), ['entity_id'])
                ->where('sku = ?', $sku)
        );
        self::assertIsNumeric($productId);

        return (int)$productId;
    }

    /**
     * Get the shared test metric the registry holds
     *
     * @return TestMetric
     */
    private function metric(): TestMetric
    {
        $metric = Bootstrap::getObjectManager()->get(TestMetric::class);
        self::assertInstanceOf(TestMetric::class, $metric);

        return $metric;
    }

    /**
     * Get the shared recording document refresher the refresh holds
     *
     * @return RecordingSearchDocumentRefresher
     */
    private function refresher(): RecordingSearchDocumentRefresher
    {
        $refresher = Bootstrap::getObjectManager()->get(RecordingSearchDocumentRefresher::class);
        self::assertInstanceOf(RecordingSearchDocumentRefresher::class, $refresher);

        return $refresher;
    }

    /**
     * Get the shared recording page cache purger the refresh holds
     *
     * @return RecordingProductPageCachePurger
     */
    private function purger(): RecordingProductPageCachePurger
    {
        $purger = Bootstrap::getObjectManager()->get(RecordingProductPageCachePurger::class);
        self::assertInstanceOf(RecordingProductPageCachePurger::class, $purger);

        return $purger;
    }

    /**
     * Get the id of the default store view
     *
     * @return int
     */
    private function defaultStoreId(): int
    {
        $storeManager = Bootstrap::getObjectManager()->get(StoreManagerInterface::class);
        self::assertInstanceOf(StoreManagerInterface::class, $storeManager);

        return (int)$storeManager->getDefaultStoreView()?->getId();
    }

    /**
     * Get the id of a store created by a data fixture
     *
     * @param string $name Fixture name
     * @return int
     */
    private function fixtureStoreId(string $name): int
    {
        $store = DataFixtureStorageManager::getStorage()->get($name);
        self::assertInstanceOf(StoreInterface::class, $store);

        return (int)$store->getId();
    }

    /**
     * Get a table name with the installation's prefix
     *
     * @param string $name Table name
     * @return string
     */
    private function table(string $name): string
    {
        $resourceConnection = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        self::assertInstanceOf(ResourceConnection::class, $resourceConnection);

        return $resourceConnection->getTableName($name);
    }

    /**
     * Get the default database connection
     *
     * @return AdapterInterface
     */
    private function connection(): AdapterInterface
    {
        $resourceConnection = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        self::assertInstanceOf(ResourceConnection::class, $resourceConnection);

        return $resourceConnection->getConnection();
    }

    /**
     * Get the test application's object manager
     *
     * @return ObjectManager
     */
    private function objectManager(): ObjectManager
    {
        $objectManager = Bootstrap::getObjectManager();
        if (!$objectManager instanceof ObjectManager) {
            throw new \LogicException('The integration tests run without the test object manager.');
        }

        return $objectManager;
    }
}
