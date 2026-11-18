# Magento 2 Catalog Sorting Metrics

Precomputed per-store, per-product values behind the popularity sort orders (Best Sellers, Most Viewed and the like). A method module registers a metric; this module recomputes it on a schedule, stores what changed, rebuilds the search documents of exactly those products and purges their cached pages. No metric is computed on a storefront request.

## What it does for a store

- **A scheduled refresh**, daily at 04:00 by default, or weekly or monthly at a chosen time, and on demand from the command line.
- **Only changed products are touched.** Each metric is computed in SQL into a temporary staging table with one `INSERT … SELECT`, so its values never pass through PHP. The difference to the stored values is written in one transaction, together with the list of products whose value changed.
- **A partial reindex, not a full one.** The search documents of the changed products are rebuilt through the `catalogsearch_fulltext` indexer's partial reindex, which runs at once whether the indexer is on save or by schedule. Nothing is invalidated.
- **Cached pages follow.** After each rebuilt chunk, the cached pages tagged with those products are purged through the platform's `clean_cache_by_tags` event, which full-page cache invalidation listens to.
- **Values only where a method is on.** A metric is computed, stored and written into search documents only for the store views where its sort method is enabled. Switching it off in every store deletes its stored values.
- **Failures stay contained.** Two refreshes never run at once. A metric that fails keeps its stored values and the others are refreshed all the same; products not yet rebuilt stay pending for the next run.
- **Sorting costs nothing per request.** The storefront sorts on the stored numeric document field; there is no join and no computation per query.

## Requirements

- PHP 8.1 to 8.5.
- `hryvinskyi/magento2-catalog-sorting` ^1.0.
- The platform's Store, Catalog, CatalogSearch, Config, Backend and Cron modules (what `composer.json` and `etc/module.xml` declare).
- Magento cron running, for the scheduled refresh.
- At least one metric module, such as `hryvinskyi/magento2-catalog-sorting-bestsellers`. This module registers no metric of its own.

## Installation

```bash
composer require hryvinskyi/magento2-catalog-sorting-metrics
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

Each metric adds a field to the product search documents. After installing metric modules, run one full `catalogsearch_fulltext` reindex so the index mapping holds the new fields; listings keep working before it, and every product sorts as having no value. Then enable the sort methods per store view and run `bin/magento catalog-sorting:metrics:refresh` once instead of waiting for the schedule.

## Configuration

**Stores › Configuration › Catalog › Catalog Sorting › Popularity Data Refresh**, default scope only.

| Path | Label | Default |
|---|---|---|
| `catalog_sorting/metrics/frequency` | Frequency | Daily |
| `catalog_sorting/metrics/time` | Start Time | 04:00 |

Saving the group writes the cron expression of the job: daily, weekly on Mondays, or monthly on the 1st, at the start time. A start time that is not a time of day refuses the save.

## Cron job and command

- Cron job `hryvinskyi_catalog_sorting_metrics_refresh`, group `default`, expression at `crontab/default/jobs/hryvinskyi_catalog_sorting_metrics_refresh/schedule/cron_expr` (default `0 4 * * *`). It refreshes every registered metric.
- `bin/magento catalog-sorting:metrics:refresh [--metric=<code>]`: every registered metric, or those named (the option repeats). It prints `<code>: N products changed` per metric. An unknown code fails and lists the registered ones. When another refresh holds the lock, the command does nothing and says so.

A run in which a metric failed ends with an error naming every failed code, after the other metrics were stored and rebuilt. Each metric's count and time are written to the application log.

## Tables

| Table | Holds |
|---|---|
| `hryvinskyi_catalog_sorting_metric` | The stored value of each metric per store and product (`metric_code`, `store_id`, `product_id`, `value`); only values above zero. Rows go with their store or product. |
| `hryvinskyi_catalog_sorting_metric_pending` | Products whose stored value changed and whose search documents are not rebuilt yet. |

The staging table `hryvinskyi_catalog_sorting_metric_staging` is a temporary table that exists only during a refresh.

## Limits

- A store view that switches a method on gets its values at the next refresh.
- The purge drops the cached pages that showed a changed product. A cached listing page a product rises into, but did not show before, keeps its old order until that page's cache entry expires.
- When the search engine is down, the stored values are updated but the documents lag; the products stay pending and are rebuilt by the next run.

## Extending

A metric module registers its metric in the `metrics` argument of `Model\MetricRegistry`, usually as a virtual type of `Model\ConfiguredMetric` with:

- `source`: an `Api\MetricSourceInterface`;
- `code`: 1 to 32 lower-case letters, digits or underscores;
- `documentField`: the search document field the value is written to;
- `fieldType`: one of the core module's field types (`integer`, `long`, `float`, `double`, `keyword`, `boolean`);
- `enabledPath`: the store-scope Yes/No setting that switches the method on.

From `hryvinskyi/magento2-catalog-sorting-bestsellers`:

```xml
<virtualType name="HryvinskyiCatalogSortingBestsellersMetric"
             type="Hryvinskyi\CatalogSortingMetrics\Model\ConfiguredMetric">
    <arguments>
        <argument name="source" xsi:type="object">Hryvinskyi\CatalogSortingBestsellers\Model\ResourceModel\BestsellersMetricSource</argument>
        <argument name="code" xsi:type="string">bestsellers</argument>
        <argument name="documentField" xsi:type="string">sort_bestsellers</argument>
        <argument name="fieldType" xsi:type="string">double</argument>
        <argument name="enabledPath" xsi:type="string">catalog_sorting/bestsellers/enabled</argument>
    </arguments>
</virtualType>
<type name="Hryvinskyi\CatalogSortingMetrics\Model\MetricRegistry">
    <arguments>
        <argument name="metrics" xsi:type="array">
            <item name="bestsellers" xsi:type="object">HryvinskyiCatalogSortingBestsellersMetric</item>
        </argument>
    </arguments>
</type>
```

Every argument is checked at construction; two metrics with one code or one document field fail the registry. The refresh, the document field provider and the command all read this one registry, so a registered metric is always both refreshed and written into documents.

`MetricSourceInterface::select()` returns a select, not values: exactly the columns `product_id`, `store_id` and `value`, one row per product and store, only values above zero, of existing products and stores (store id above zero), on the default connection. Values it filters by are quoted, or bound on the select with named placeholders, never positional ones; the bind name `:hryvinskyi_metric_code` is taken by the refresh.

To offer the metric as a sort order, the module also registers its code in the `sortFields` pool of `hryvinskyi/magento2-catalog-sorting` and the `options` pool of `hryvinskyi/magento2-catalog-sorting-options`. Register all three in a global `etc/di.xml`: the refresh runs from cron and the command line, the document fields at index time.

## Public contracts

`Api\MetricInterface`, `Api\MetricSourceInterface`, `Api\MetricRegistryInterface`, `Api\MetricRefreshInterface`, `Api\SearchDocumentRefresherInterface` and `Api\ProductPageCachePurgerInterface` are `@api`.
