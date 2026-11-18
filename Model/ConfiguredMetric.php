<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Model;

use Hryvinskyi\CatalogSorting\Api\Data\FieldType;
use Hryvinskyi\CatalogSortingMetrics\Api\MetricInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\MetricSourceInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * A metric configured entirely in `di.xml`, enabled per store by a Yes/No setting
 *
 * A method module declares a virtual type of this class with its code, document field, field type, the path of
 * its store-scope Yes/No setting and its source. Every argument is checked at construction, so a misconfigured
 * metric fails loudly instead of being refreshed under a code the table cannot hold or written to a field the
 * index cannot map.
 */
class ConfiguredMetric implements MetricInterface
{
    private const CODE_PATTERN = '/^[a-z0-9_]{1,32}$/';

    /**
     * @var FieldType
     */
    private readonly FieldType $fieldType;

    /**
     * @param ScopeConfigInterface $scopeConfig Reads the Yes/No setting per store
     * @param MetricSourceInterface $source Computes the metric
     * @param string $code Metric code: 1 to 32 lower-case letters, digits or underscores
     * @param string $documentField Search document field the metric is written to
     * @param string $fieldType Mapping type of the field, one of the {@see FieldType} values
     * @param string $enabledPath Config path of the Yes/No setting that enables the metric, read at store scope
     * @throws \InvalidArgumentException When the code is malformed, the field or path is blank, or the type unknown
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly MetricSourceInterface $source,
        private readonly string $code,
        private readonly string $documentField,
        string $fieldType,
        private readonly string $enabledPath
    ) {
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'The metric code "%s" must be 1 to 32 lower-case letters, digits or underscores.',
                $code
            ));
        }

        if (trim($documentField) === '') {
            throw new \InvalidArgumentException(sprintf('Metric "%s" names no document field.', $code));
        }

        if (trim($enabledPath) === '') {
            throw new \InvalidArgumentException(sprintf('Metric "%s" names no setting that enables it.', $code));
        }

        $this->fieldType = FieldType::tryFrom($fieldType) ?? throw new \InvalidArgumentException(sprintf(
            'Metric "%s" names the field type "%s", which is not one of: %s.',
            $code,
            $fieldType,
            implode(', ', array_map(static fn (FieldType $type): string => $type->value, FieldType::cases()))
        ));
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * @inheritDoc
     */
    public function getDocumentField(): string
    {
        return $this->documentField;
    }

    /**
     * @inheritDoc
     */
    public function getFieldType(): FieldType
    {
        return $this->fieldType;
    }

    /**
     * @inheritDoc
     */
    public function isEnabledIn(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag($this->enabledPath, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function getSource(): MetricSourceInterface
    {
        return $this->source;
    }
}
