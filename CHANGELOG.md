# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-11-18

### Added
- The `metrics` pool of `Model\MetricRegistry` and `Model\ConfiguredMetric`, which declares a metric in `di.xml`
  with its SQL source, document field, field type and store-scope Yes/No setting.
- The refresh: each metric computed into a temporary staging table with one `INSERT … SELECT`, the difference to the
  stored values written in one transaction with the changed products recorded as pending, then a partial
  `catalogsearch_fulltext` reindex and a page-cache purge of those products, chunk by chunk. A lock keeps two
  refreshes apart; a failed metric keeps its values.
- The tables `hryvinskyi_catalog_sorting_metric` and `hryvinskyi_catalog_sorting_metric_pending`.
- The cron job `hryvinskyi_catalog_sorting_metrics_refresh` (daily at 04:00 by default) and the settings
  **Popularity Data Refresh › Frequency** and **Start Time**.
- The command `bin/magento catalog-sorting:metrics:refresh [--metric=<code>]`.
- The document field provider `metrics`, which writes the stored values of the metrics enabled in a store into its
  product search documents.
