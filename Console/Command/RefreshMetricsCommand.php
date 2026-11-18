<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMetrics\Console\Command;

use Hryvinskyi\CatalogSortingMetrics\Api\MetricRefreshInterface;
use Hryvinskyi\CatalogSortingMetrics\Api\MetricRegistryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Refreshes catalog sorting metrics on demand: every registered one, or those named with `--metric`
 *
 * Every command is built on every console call, including installs and upgrades that run before this module's
 * tables exist, so the refresh and the registry are injected as lazy proxies and built only when this command runs.
 */
class RefreshMetricsCommand extends Command
{
    private const NAME = 'catalog-sorting:metrics:refresh';
    private const OPTION_METRIC = 'metric';

    /**
     * @param MetricRefreshInterface $metricRefresh Refreshes the metrics
     * @param MetricRegistryInterface $registry Tells which metric codes exist
     */
    public function __construct(
        private readonly MetricRefreshInterface $metricRefresh,
        private readonly MetricRegistryInterface $registry
    ) {
        parent::__construct(self::NAME);
    }

    /**
     * Declare the command's description and its repeatable metric option
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setDescription(
            'Recompute the popularity-based catalog sort metrics and rebuild the search entries of the products '
            . 'whose value changed.'
        );
        $this->addOption(
            self::OPTION_METRIC,
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Metric code to refresh; repeat for several. Every metric when omitted.'
        );
    }

    /**
     * Refresh the requested metrics and print how many products changed per metric
     *
     * @param InputInterface $input Console input
     * @param OutputInterface $output Console output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $codes = $this->requestedCodes($input);
        $unknown = array_values(array_filter($codes, fn (string $code): bool => !$this->registry->has($code)));
        if ($unknown !== []) {
            $registered = array_keys($this->registry->all());
            $output->writeln(sprintf(
                '<error>Unknown metric code: %s. Registered codes: %s.</error>',
                implode(', ', $unknown),
                $registered === [] ? 'none' : implode(', ', $registered)
            ));

            return Command::FAILURE;
        }

        $changed = $this->metricRefresh->refresh($codes);
        if ($changed === []) {
            $output->writeln(
                'No metric was refreshed: no metric is registered, or another refresh is running.'
            );

            return Command::SUCCESS;
        }

        foreach ($changed as $code => $count) {
            $output->writeln(sprintf('%s: %d products changed', $code, $count));
        }

        return Command::SUCCESS;
    }

    /**
     * Get the distinct metric codes given with the metric option
     *
     * @param InputInterface $input Console input
     * @return list<string>
     */
    private function requestedCodes(InputInterface $input): array
    {
        $option = $input->getOption(self::OPTION_METRIC);
        if (!is_array($option)) {
            return [];
        }

        $codes = [];
        foreach ($option as $code) {
            if (is_string($code) && trim($code) !== '') {
                $codes[] = trim($code);
            }
        }

        return array_values(array_unique($codes));
    }
}
