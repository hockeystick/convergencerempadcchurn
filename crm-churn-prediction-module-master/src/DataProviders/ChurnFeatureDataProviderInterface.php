<?php

namespace Crm\ChurnPredictionModule\DataProviders;

use Crm\ApplicationModule\Models\DataProvider\DataProviderInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Adds custom feature columns to the churn training and scoring data (one row per subscription observation).
 *
 * Register under 'churn_prediction.dataprovider.features'.
 */
interface ChurnFeatureDataProviderInterface extends DataProviderInterface
{
    /**
     * Feature columns (name => default value) in the order they are written. Every ABT row
     * always contains all of them, so the BigQuery schema stays stable.
     *
     * @return array<string, mixed>
     */
    public function columns(): array;

    /**
     * Called once per export run, before any rows are computed (e.g. for sanity checks).
     */
    public function beforeExport(OutputInterface $output): void;

    /**
     * Computes features for one batch of observations. Use only data available at observed_at.
     *
     * @param list<array{user_id: int, subscription_id: int, observed_at: \DateTimeInterface}> $rows
     * @return list<array<string, mixed>> features for each row, in the same order; missing
     *         columns fall back to their default from columns()
     */
    public function computeFeatures(array $rows): array;
}
