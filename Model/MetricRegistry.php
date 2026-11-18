<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Model;

use Hryvinskyi\CatalogSortingMetrics\Api\MetricInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\MetricRegistryInterface;

/**
 * The one pool of metrics, filled by method modules in `di.xml`
 *
 * A method module adds its metric as one item of the `metrics` argument. The pool is checked at construction: an
 * item that is not a metric is refused by its item key, and two metrics with one code or one document field are
 * refused by that code or field, since either would make one metric overwrite the other's stored values or document
 * field.
 */
class MetricRegistry implements MetricRegistryInterface
{
    /**
     * @var array<string, MetricInterface>
     */
    private readonly array $metrics;

    /**
     * @param array<mixed> $metrics Metrics, keyed by item name
     * @throws \InvalidArgumentException When an item is not a metric, or a code or document field is used twice
     */
    public function __construct(array $metrics = [])
    {
        $this->metrics = $this->validated($metrics);
    }

    /**
     * @inheritDoc
     */
    public function all(): array
    {
        return $this->metrics;
    }

    /**
     * @inheritDoc
     */
    public function has(string $code): bool
    {
        return isset($this->metrics[$code]);
    }

    /**
     * @inheritDoc
     */
    public function get(string $code): MetricInterface
    {
        return $this->metrics[$code] ?? throw new \InvalidArgumentException(sprintf(
            'No catalog sorting metric has the code "%s". Registered codes: %s.',
            $code,
            $this->metrics === [] ? 'none' : implode(', ', array_keys($this->metrics))
        ));
    }

    /**
     * Check every pool item and key the metrics by code
     *
     * @param array<mixed> $metrics Items from the pool
     * @return array<string, MetricInterface> Metric code => metric
     * @throws \InvalidArgumentException When an item is not a metric, or a code or document field is used twice
     */
    private function validated(array $metrics): array
    {
        $byCode = [];
        $fieldOwners = [];
        foreach ($metrics as $key => $metric) {
            if (!$metric instanceof MetricInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'Catalog sorting metric "%s" must implement %s.',
                    $key,
                    MetricInterface::class
                ));
            }

            $code = $metric->getCode();
            if (isset($byCode[$code])) {
                throw new \InvalidArgumentException(sprintf(
                    'Two catalog sorting metrics have the code "%s"; a code belongs to one metric.',
                    $code
                ));
            }

            $field = $metric->getDocumentField();
            if (isset($fieldOwners[$field])) {
                throw new \InvalidArgumentException(sprintf(
                    'The metrics "%s" and "%s" both write the document field "%s"; a field belongs to one metric.',
                    $fieldOwners[$field],
                    $code,
                    $field
                ));
            }

            $byCode[$code] = $metric;
            $fieldOwners[$field] = $code;
        }

        return $byCode;
    }
}
