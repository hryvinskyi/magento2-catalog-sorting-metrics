<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Api;

use Magento\Framework\DB\Select;

/**
 * Computes one metric for every product and store, as SQL the refresh inserts from
 *
 * A source is a persistence adapter: the refresh copies its rows into a staging table with one
 * `INSERT … SELECT`, so the values never travel through PHP. That is why the contract hands over a database select
 * instead of values.
 *
 * @api
 */
interface MetricSourceInterface
{
    /**
     * Get the select that computes the metric
     *
     * The select runs on the default connection and returns exactly the columns `product_id`, `store_id` and
     * `value`, one row per product and store, and only rows whose value is above zero, of products that exist in
     * `catalog_product_entity` and of stores that exist (store id above zero). Values it filters by are quoted, or
     * bound on the select itself with named placeholders (`:name`), never positional ones: the refresh wraps the
     * select in a statement with a named bind of its own, `:hryvinskyi_metric_code`, and carries the select's binds
     * over. The refresh adds its own conditions around the select, never inside it.
     *
     * @return Select
     */
    public function select(): Select;
}
