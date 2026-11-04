<?php

namespace Crm\ChurnPredictionModule\Models;

use Google\Cloud\BigQuery\BigQueryClient;
use Tracy\Debugger;
use Tracy\ILogger;

class BigQueryConfig
{
    private ?BigQueryClient $bigQueryClient = null;

    public function __construct(
        private readonly ?string $serviceAccountPath,
        private readonly ?string $bigQueryProject,
        private readonly ?string $bigQueryDataset,
    ) {
    }

    public function getBigQueryClient(): ?BigQueryClient
    {
        if (!$this->serviceAccountPath || !realpath($this->serviceAccountPath)) {
            Debugger::log(
                "ChurnPredictionModule: Missing 'churnPrediction.bigquery.service_account_path' configuration value (or wrong value) in neon file, have you set it up?",
                ILogger::WARNING,
            );
            return null;
        }
        if (!$this->bigQueryProject) {
            Debugger::log(
                "ChurnPredictionModule: Missing 'churnPrediction.bigquery.project' configuration value in neon file, have you set it up?",
                ILogger::WARNING,
            );
            return null;
        }
        if (!$this->bigQueryDataset) {
            Debugger::log(
                "ChurnPredictionModule: Missing 'churnPrediction.bigquery.dataset' configuration value in neon file, have you set it up?",
                ILogger::WARNING,
            );
            return null;
        }
        if (!$this->bigQueryClient) {
            $this->bigQueryClient = new BigQueryClient([
                'keyFilePath' => $this->getServiceAccountFilePath(),
            ]);
        }

        return $this->bigQueryClient;
    }

    public function getServiceAccountFilePath(): ?string
    {
        return $this->serviceAccountPath;
    }

    public function getBigQueryProject(): ?string
    {
        return $this->bigQueryProject;
    }

    public function getBigQueryDataset(): ?string
    {
        return $this->bigQueryDataset;
    }

    public function getFullyQualifiedTableName(string $tableName): string
    {
        return $this->getBigQueryProject() . '.' . $this->getBigQueryDataset() . '.' . $tableName;
    }
}
