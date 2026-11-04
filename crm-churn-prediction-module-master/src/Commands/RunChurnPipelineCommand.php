<?php
declare(strict_types=1);

namespace Crm\ChurnPredictionModule\Commands;

use Crm\ApplicationModule\Commands\DecoratedCommandTrait;
use Crm\ApplicationModule\Models\DataProvider\DataProviderManager;
use Crm\ChurnPredictionModule\DataProviders\ChurnFeatureDataProviderInterface;
use Crm\ChurnPredictionModule\Models\BigQuery\StagingMergeSqlBuilder;
use Crm\ChurnPredictionModule\Models\BigQueryConfig;
use Crm\ChurnPredictionModule\Models\ChurnExport\ChurnFeatureCalculator;
use Crm\ChurnPredictionModule\Models\ChurnExport\TierMaxPriceSnapshotBuilder;
use Crm\SubscriptionsModule\Repositories\ContentAccessRepository;
use Google\Cloud\BigQuery\BigQueryClient;
use Nette\Database\Explorer;
use Nette\Utils\DateTime;
use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tracy\Debugger;

/**
 * Builds the churn-prediction ABT (analytical base table) in BigQuery. Two modes:
 *
 *   training — used to (re)train the model.
 *   scoring — predict churn for current subscriptions
 *
 */
class RunChurnPipelineCommand extends Command
{
    use DecoratedCommandTrait;

    // Offset represents number of days before subscriptions ends
    // Each subscription is "observed" at multiple offsets
    private const DEFAULT_OFFSETS = '1,3,7,14,21,28,42,60,90';

    private const SUBSCRIPTION_CHUNK_SIZE = 2000;

    private const MODE_TRAINING = 'training';
    private const MODE_SCORING = 'scoring';

    private const FEATURES_DATAPROVIDER_PATH = 'churn_prediction.dataprovider.features';

    private ?BigQueryClient $bigQueryClient;

    private int $churnDays;
    private int $trainingWindowDays;

    /** @var int[] */
    private array $offsets;

    private DateTime $now;
    private string $exportDate;
    // true: the BigQuery table is replaced; false: rows are merged by (subscription_id, days_to_expiry)
    private bool $replaceTable;
    private string $mode;
    private string $modelName;

    /**
     * Per-month snapshots of price statistics per (content_access_codes, length) tier,
     * built from subscription_types that existed and were in active use as of each month-start.
     * Keyed as $tierPriceByMonth['YYYY-MM'][$contentAccessCodes][$length] => ['max' => …, 'median' => …].
     *
     * Used to compute payment_pct_of_tier_max_price and payment_pct_of_tier_median_price,
     * leakage-free "how does this user's payment compare to their tier" features.
     *
     * @var array<string, array<string, array<int, array{max: float, median: float}>>>
     */
    private array $tierPriceByMonth = [];

    /** @var ChurnFeatureDataProviderInterface[] */
    private array $featureProviders = [];

    /** @var list<string> Beam property tokens counted as engagement (pageview/timespent/diversity filter) */
    private array $activeTokens = [];

    private string $engagementFeaturesSqlPath = __DIR__ . '/bigquery/engagement_features.sql';

    public function __construct(
        private readonly BigQueryConfig $bigQueryConfig,
        private readonly ContentAccessRepository $contentAccessRepository,
        private readonly Explorer $database,
        private readonly TierMaxPriceSnapshotBuilder $tierMaxPriceSnapshotBuilder,
        private readonly ChurnFeatureCalculator $featureCalculator,
        private readonly DataProviderManager $dataProviderManager,
        private readonly StagingMergeSqlBuilder $stagingMergeSqlBuilder,
    ) {
        parent::__construct();
    }

    /**
     * @param list<string> $activeTokens Beam property tokens counted as engagement
     */
    public function setActiveTokens(array $activeTokens): void
    {
        $this->activeTokens = $activeTokens;
    }

    /**
     * Engagement features SQL template; defaults to the bundled engagement_features.sql.
     */
    public function setEngagementFeaturesSqlPath(string $engagementFeaturesSqlPath): void
    {
        $this->engagementFeaturesSqlPath = $engagementFeaturesSqlPath;
    }

    public function configure()
    {
        $this->setName('churn:run_pipeline')
            ->setAliases(['churn:bigquery_data_export']) // legacy name
            ->setDescription('Runs the churn pipeline: export observations to BigQuery, refresh engagement features and, in scoring mode, predict churn')
            ->addOption(
                name: 'local-folder',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Local folder to store exported data.',
                default: sys_get_temp_dir(),
            )
            ->addOption(
                name: 'churn-days',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Number of days defining churn (label window after subscription end_time).',
                default: 28,
            )
            ->addOption(
                name: 'now-date',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Reference "now" timestamp (Y-m-d H:i:s). Defaults to current datetime.',
                default: date('Y-m-d H:i:s'),
            )
            ->addOption(
                name: 'training-window-days',
                mode: InputOption::VALUE_REQUIRED,
                description: 'How far back from now-date to include subscription end_times in the training set.',
                default: 365,
            )
            ->addOption(
                name: 'offsets',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Comma-separated offsets (in days before subscription end_time) at which to create observation rows.',
                default: self::DEFAULT_OFFSETS,
            )
            ->addOption(
                name: 'replace-training-data',
                mode: InputOption::VALUE_NONE,
                description: 'Replace all training data with this run\'s rows instead of merging them by (subscription_id, days_to_expiry). Use for the initial load or a full rebuild (training mode only).',
            )
            ->addOption(
                name: 'mode',
                mode: InputOption::VALUE_REQUIRED,
                description: '"training" (default) exports ended subscriptions with churn labels; "scoring" exports subscriptions expiring in the next churn-days window, unlabelled, for ML.PREDICT.',
                default: self::MODE_TRAINING,
            )
            ->addOption(
                name: 'skip-engagement-refresh',
                mode: InputOption::VALUE_NONE,
                description: 'Skip the post-export BigQuery engagement features refresh.',
            )
            ->addOption(
                name: 'model',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Model name (within BigQuery dataset) ML.PREDICT scores against. Required in scoring mode (unless --skip-predict); no default, to avoid silently scoring against a stale model.',
            )
            ->addOption(
                name: 'skip-predict',
                mode: InputOption::VALUE_NONE,
                description: 'Skip the post-export ML.PREDICT step that writes churn_predictions (scoring mode only).',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->bigQueryClient = $this->bigQueryConfig->getBigQueryClient();
        if (!$this->bigQueryClient) {
            $output->writeln("<error>BigQuery client not initialized.</error>");
            Debugger::log("Churn predictions - BigQuery client not initialized", Debugger::WARNING);
            return self::FAILURE;
        }

        $localFolderOption = $input->getOption('local-folder');
        $localFolder = realpath($localFolderOption);
        $this->churnDays = (int) $input->getOption('churn-days');
        $this->trainingWindowDays = (int) $input->getOption('training-window-days');

        $this->offsets = array_map(fn($x) => (int) trim($x), explode(',', $input->getOption('offsets')));
        $this->now = DateTime::from($input->getOption('now-date'))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $this->exportDate = date('Y-m-d');
        $this->mode = strtolower((string) $input->getOption('mode'));
        $this->modelName = trim((string) $input->getOption('model'));

        if ($localFolder === false || !is_dir($localFolder) || !is_writable($localFolder)) {
            $output->writeln("<error>Local folder '{$localFolderOption}' does not exist or is not writable.</error>");
            return self::FAILURE;
        }

        if (empty($this->offsets)) {
            $output->writeln("<error>No valid offsets provided.</error>");
            return self::FAILURE;
        }

        if (!in_array($this->mode, [self::MODE_TRAINING, self::MODE_SCORING], true)) {
            $output->writeln("<error>Invalid --mode; use 'training' or 'scoring'.</error>");
            return self::FAILURE;
        }

        if ($this->mode === self::MODE_SCORING && $input->getOption('replace-training-data')) {
            $output->writeln("<error>--replace-training-data applies only to training mode; scoring always replaces its table.</error>");
            return self::FAILURE;
        }

        $this->replaceTable = $this->mode === self::MODE_SCORING || $input->getOption('replace-training-data');

        if ($this->mode === self::MODE_TRAINING && $this->trainingWindowDays <= $this->churnDays) {
            $output->writeln(sprintf(
                "<error>--training-window-days (%d) must be greater than --churn-days (%d); "
                . "otherwise the training eligibility window is empty and nothing is exported.</error>",
                $this->trainingWindowDays,
                $this->churnDays,
            ));
            return self::FAILURE;
        }

        if ($this->mode === self::MODE_SCORING && !$input->getOption('skip-predict') && empty($this->modelName)) {
            $output->writeln("<error>--model is required in scoring mode (or pass --skip-predict to export without predicting).</error>");
            Debugger::log("Churn predictions - no model provided", Debugger::WARNING);
            return self::FAILURE;
        }

        if (empty($this->activeTokens) && !$input->getOption('skip-engagement-refresh')) {
            $output->writeln("<error>No Beam tokens set; call setActiveTokens() on the runChurnPipelineCommand service "
                . "(or pass --skip-engagement-refresh).</error>");
            Debugger::log("Churn predictions - no active Beam tokens configured", Debugger::WARNING);
            return self::FAILURE;
        }

        /** @var ChurnFeatureDataProviderInterface[] $providers */
        $providers = $this->dataProviderManager->getProviders(
            self::FEATURES_DATAPROVIDER_PATH,
            ChurnFeatureDataProviderInterface::class,
        );
        $this->featureProviders = $providers;
        foreach ($this->featureProviders as $provider) {
            $provider->beforeExport($this->output);
        }
        $this->loadTierMaxPriceSnapshots();

        $this->line(sprintf(
            "Exporting churn data (<info>mode=%s</info>) for <info>now=[%s]</info>, <info>churn_days=[%d]</info>, <info>training_window_days=[%d]</info>, <info>offsets=[%s]</info>",
            $this->mode,
            $this->now,
            $this->churnDays,
            $this->trainingWindowDays,
            implode(',', $this->offsets),
        ));

        $tableName = $this->bigQueryTableName();
        $fileName = $localFolder . "/{$tableName}.json";
        $this->output->writeln("Exporting data to file: <comment>{$fileName}</comment>.");

        try {
            $this->exportDataToFile($fileName);
            $success = $this->exportDataToBigQuery($tableName, $fileName);
        } finally {
            FileSystem::delete($fileName);
        }

        if (!$success) {
            $this->output->writeln('<error>Data export to BigQuery failed.</error>');
            Debugger::log("Churn predictions - command was unable to export data", Debugger::WARNING);
            return self::FAILURE;
        }

        $this->output->writeln('Data exported');

        if ($input->getOption('skip-engagement-refresh')) {
            $this->output->writeln('Skipping engagement features refresh (--skip-engagement-refresh).');
        } elseif (!$this->refreshEngagementFeatures($this->exportDate)) {
            $this->output->writeln('<error>Engagement features refresh failed.</error>');
            Debugger::log("Churn predictions - command was unable to refresh engagement features", Debugger::WARNING);
            return self::FAILURE;
        }

        // ML.PREDICT + persist predictions. Scoring mode only — training rows carry
        // no future-expiry semantics and the scoring input view isn't built for them.
        if ($this->mode === self::MODE_SCORING) {
            if ($input->getOption('skip-predict')) {
                $this->output->writeln('Skipping ML.PREDICT (--skip-predict).');
            } elseif (!$this->runPredict($this->exportDate)) {
                $this->output->writeln('<error>ML.PREDICT step failed.</error>');
                Debugger::log("Churn predictions - command was unable to compute predictions", Debugger::WARNING);
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function bigQueryTableName(): string
    {
        return $this->mode === self::MODE_SCORING ? 'churn_scoring_subscription_features' : 'churn_training_subscription_features';
    }

    /**
     * Comma-separated SQL string-literal list of the active Beam property tokens,
     * substituted into the engagement query's `DECLARE active_tokens ARRAY<STRING>`.
     */
    private function buildActiveTokensSqlList(): string
    {
        $quoted = array_map(fn(string $token) => "'" . addslashes($token) . "'", array_values($this->activeTokens));
        return implode(', ', $quoted);
    }

    private function loadTierMaxPriceSnapshots(): void
    {
        if ($this->mode === self::MODE_SCORING) {
            $start = \DateTimeImmutable::createFromInterface($this->now);
        } else {
            $maxOffset = max($this->offsets);
            $start = \DateTimeImmutable::createFromInterface($this->now)
                ->modify("-{$this->trainingWindowDays} days")
                ->modify("-{$maxOffset} days")
                ->modify("-30 days");
        }
        $end = \DateTimeImmutable::createFromInterface($this->now);

        $this->line("Loading monthly tier price snapshots...");
        $startedAt = microtime(true);
        $this->tierPriceByMonth = $this->tierMaxPriceSnapshotBuilder->build($start, $end);
        $this->output->writeln(sprintf(
            "  ...loaded %d months in %.1fs.",
            count($this->tierPriceByMonth),
            microtime(true) - $startedAt,
        ));
    }

    private function resolveEngagementFeaturesSql(string $exportDate): ?string
    {
        $sql = file_get_contents($this->engagementFeaturesSqlPath);
        if ($sql === false) {
            $this->output->writeln("<error>Could not read engagement features SQL file at {$this->engagementFeaturesSqlPath}.</error>");
            return null;
        }

        $dataset = $this->bigQueryConfig->getBigQueryDataset();

        // Pin the BQ run to the same export_date the ABT was written with.
        $sql = str_replace('CURRENT_DATE()', "DATE '{$exportDate}'", $sql);

        // Resolve every dataset-qualified table reference to the configured dataset.
        $sql = str_replace('{{DATASET}}', $dataset, $sql);

        // Point the engagement refresh at the table this run wrote (training vs scoring).
        // Both modes write into the same churn_engagement_features target; only the source table differs.
        $sql = str_replace(
            '{{TABLE_NAME}}',
            "{$dataset}.{$this->bigQueryTableName()}",
            $sql,
        );

        // Inject the engagement Beam property tokens (setActiveTokens()).
        $sql = str_replace('{{ACTIVE_TOKENS}}', $this->buildActiveTokensSqlList(), $sql);

        return $sql;
    }

    private function refreshEngagementFeatures(string $exportDate): bool
    {
        $sql = $this->resolveEngagementFeaturesSql($exportDate);
        if ($sql === null) {
            return false;
        }

        $this->output->writeln("Running engagement features refresh for export_date={$exportDate}...");
        $queryConfig = $this->bigQueryClient->query($sql);
        $job = $this->bigQueryClient->runJob($queryConfig);

        while (!$job->isComplete()) {
            sleep(5);
            $job->reload();
            $this->output->write('.');
        }

        if (isset($job->info()['status']['errorResult'])) {
            $error = $job->info()['status']['errorResult']['message'];
            $this->output->writeln("<error>{$error}</error>");
            return false;
        }

        $this->output->writeln("\nEngagement features refresh complete.");
        return true;
    }

    /**
     * Reads the ML.PREDICT SQL template and applies all PHP-side substitutions.
     */
    private function resolvePredictSql(string $exportDate): ?string
    {
        $sqlPath = __DIR__ . '/bigquery/churn_predict.sql';
        $sql = file_get_contents($sqlPath);
        if ($sql === false) {
            $this->output->writeln("<error>Could not read predict SQL file at {$sqlPath}.</error>");
            return null;
        }

        $dataset = $this->bigQueryConfig->getBigQueryDataset();

        // Resolve every dataset-qualified table reference to the configured dataset.
        $sql = str_replace('{{DATASET}}', $dataset, $sql);

        // Dataset-qualified model name, used both as the MODEL reference and stored
        // verbatim in churn_predictions.model_name for audit/drift tracking.
        $sql = str_replace('{{MODEL}}', "{$dataset}.{$this->modelName}", $sql);

        // Pin predictions to the same date the scoring ABT was written with.
        $sql = str_replace('{{DATE}}', $exportDate, $sql);

        return $sql;
    }

    private function runPredict(string $exportDate): bool
    {
        $this->output->writeln("Running scoring mode.");
        $sql = $this->resolvePredictSql($exportDate);
        if ($sql === null) {
            return false;
        }

        $this->output->writeln("Running ML.PREDICT (model={$this->bigQueryConfig->getBigQueryDataset()}.{$this->modelName}) for prediction_date={$exportDate}...");
        $queryConfig = $this->bigQueryClient->query($sql);
        $job = $this->bigQueryClient->runJob($queryConfig);

        while (!$job->isComplete()) {
            sleep(5);
            $job->reload();
            $this->output->write('.');
        }

        if (isset($job->info()['status']['errorResult'])) {
            $error = $job->info()['status']['errorResult']['message'];
            $this->output->writeln("<error>{$error}</error>");
            return false;
        }

        $this->output->writeln("\nML.PREDICT complete; churn_predictions updated.");
        return true;
    }

    private function exportDataToFile(string $fileName): void
    {
        $file = FileSystem::open($fileName, 'w');

        $this->output->writeln('Fetching eligible subscription ids...');
        $eligibleSubscriptionIds = $this->fetchEligibleSubscriptionIds();
        $this->output->writeln(sprintf('  <info>%d</info> eligible subscriptions.', count($eligibleSubscriptionIds)));

        $startTime = microtime(true);
        $batchSize = 500;
        $batch = [];
        $totalRows = 0;
        $eligibleSubscriptionIdsChunks = array_chunk($eligibleSubscriptionIds, self::SUBSCRIPTION_CHUNK_SIZE);

        $progressBar = new ProgressBar($this->output, count($eligibleSubscriptionIdsChunks));
        $progressBar->setFormat(' %current%/%max% chunks [%bar%] %percent:3s%% | %elapsed:6s%/%estimated:-6s% | %message% rows');
        $progressBar->setMessage('0');
        $progressBar->setRedrawFrequency(10);
        $progressBar->start();

        foreach ($eligibleSubscriptionIdsChunks as $subscriptionIds) {
            $sql = $this->buildMainQuerySql($subscriptionIds);

            foreach ($this->database->query($sql) as $row) {
                $batch[] = (array) $row;

                if (count($batch) >= $batchSize) {
                    $this->writeBatchToFile($file, $batch);
                    $totalRows += count($batch);
                    $batch = [];
                }
            }

            $progressBar->setMessage((string) $totalRows);
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->output->writeln('');

        if (!empty($batch)) {
            $this->writeBatchToFile($file, $batch);
            $totalRows += count($batch);
        }

        fclose($file);
        $this->output->writeln(sprintf(
            "\nWrote <info>%d</info> rows to file in <info>%.1fs</info>.",
            $totalRows,
            microtime(true) - $startTime,
        ));
    }

    private function fetchEligibleSubscriptionIds(): array
    {
        if ($this->mode === self::MODE_SCORING) {
            $maxOffset = max($this->offsets);
            $sql = <<<SQL
                SELECT s.id
                FROM subscriptions s
                WHERE s.is_paid = 1
                  AND s.start_time <= ?
                  AND s.end_time > ?
                  -- this removes subscriptions that end up later than 90 days from now, 
                  -- model is not trained to predict on them
                  AND s.end_time <= DATE_ADD(?, INTERVAL {$maxOffset} DAY)
                  AND s.length >= 28
                  AND s.end_time > DATE_ADD(s.start_time, INTERVAL 1 HOUR)
                  AND NOT EXISTS (
                      SELECT 1 FROM subscriptions s2
                      WHERE s2.user_id = s.user_id
                        AND s2.is_paid = 1
                        AND s2.end_time > s.end_time
                  )
                ORDER BY s.user_id, s.id
            SQL;
            $params = [$this->now, $this->now, $this->now];
        } else {
            $sql = <<<SQL
                SELECT s.id
                FROM subscriptions s
                WHERE s.is_paid = 1
                  AND s.end_time > DATE_SUB(?, INTERVAL {$this->trainingWindowDays} DAY)
                  AND s.end_time <= DATE_SUB(?, INTERVAL {$this->churnDays} DAY)
                  AND s.length >= 28
                  AND s.end_time > DATE_ADD(s.start_time, INTERVAL 1 HOUR)
                ORDER BY s.user_id, s.id
            SQL;
            $params = [$this->now, $this->now];
        }

        $ids = [];
        foreach ($this->database->query($sql, ...$params) as $row) {
            $ids[] = (int) $row['id'];
        }
        return $ids;
    }

    private function buildMainQuerySql(array $subscriptionIds): string
    {
        $concatenatedSubscriptionIds = implode(',', $subscriptionIds);

        // Offsets
        $offsetSelects = [];
        foreach ($this->offsets as $i => $o) {
            $offsetSelects[] = $i === 0 ? "SELECT {$o} AS offset_days" : "SELECT {$o}";
        }
        $offsetsUnion = implode(' UNION ALL ', $offsetSelects);

        // Retrieve content accesses
        $contentAccessNames = $this->contentAccessRepository->all()->fetchPairs(null, 'name');
        $columns = [];
        foreach ($contentAccessNames as $name) {
            $columns[] = "MAX(content_access.name = '{$name}') AS content_access_{$name}";
        }
        $contentAccessColumns = implode(",\n", $columns);

        $lengthFamilyCase = <<<SQL
            CASE
                WHEN eligible_subscriptions.length BETWEEN 28  AND 31  THEN 'monthly'
                WHEN eligible_subscriptions.length BETWEEN 84  AND 93  THEN 'quarterly'
                WHEN eligible_subscriptions.length BETWEEN 178 AND 186 THEN 'half_year'
                WHEN eligible_subscriptions.length BETWEEN 360 AND 371 THEN 'yearly'
                WHEN eligible_subscriptions.length BETWEEN 720 AND 740 THEN 'two_year'
                ELSE 'other'
            END AS length_family
        SQL;


        if ($this->mode === self::MODE_SCORING) {
            // observation_rows in scoring mode represent all eligible subscriptions
            // model can predict on - their ending time <= max offset for given subscription_type.
            // e.g. for monthly subscriptions, model can only predict for subscriptions ending sooner than 28 days
            $observationRows = <<<SQL
                observation_rows AS (
                    SELECT
                        eligible_subscriptions.id AS subscription_id,
                        eligible_subscriptions.user_id,
                        eligible_subscriptions.subscription_type_id,
                        eligible_subscriptions.is_recurrent,
                        eligible_subscriptions.length,
                        {$lengthFamilyCase},
                        eligible_subscriptions.start_time,
                        eligible_subscriptions.end_time,
                        DATEDIFF(eligible_subscriptions.end_time, '{$this->now}') AS offset_days,
                        CAST('{$this->now}' AS DATETIME) AS observed_at
                    FROM eligible_subscriptions
                    WHERE DATEDIFF(eligible_subscriptions.end_time, '{$this->now}') <= (
                        SELECT MAX(offsets.offset_days) FROM offsets WHERE offsets.offset_days <= eligible_subscriptions.length
                    )
                )
            SQL;
            // No churned label at scoring time.
            $churnedColumn = '';
        } else {
            // in training mode, observation rows are eligible_subscriptions X offsets
            $observationRows = <<<SQL
                observation_rows AS (
                    SELECT
                        eligible_subscriptions.id AS subscription_id,
                        eligible_subscriptions.user_id,
                        eligible_subscriptions.subscription_type_id,
                        eligible_subscriptions.is_recurrent,
                        eligible_subscriptions.length,
                        {$lengthFamilyCase},
                        eligible_subscriptions.start_time,
                        eligible_subscriptions.end_time,
                        offsets.offset_days,
                        DATE_SUB(eligible_subscriptions.end_time, INTERVAL offsets.offset_days DAY) AS observed_at
                    FROM eligible_subscriptions
                    JOIN offsets
                    WHERE DATE_SUB(eligible_subscriptions.end_time, INTERVAL offsets.offset_days DAY) > DATE_ADD(eligible_subscriptions.start_time, INTERVAL 1 HOUR)
                      AND offsets.offset_days <= eligible_subscriptions.length
                      AND (eligible_subscriptions.next_sub_start IS NULL
                           OR eligible_subscriptions.next_sub_start > DATE_SUB(eligible_subscriptions.end_time, INTERVAL offsets.offset_days DAY))
                )
            SQL;
            // in training mode, we can say whether user has churned for given combination of subscription and offset
            // model trains on this column
            $churnedColumn = <<<SQL
                 , (
                     SELECT IF(COUNT(*) > 0, 0, 1)
                     FROM subscriptions
                     WHERE subscriptions.user_id   = users.id
                       AND subscriptions.is_paid
                       AND subscriptions.start_time <  DATE_ADD(observation_rows.end_time, INTERVAL {$this->churnDays} DAY)
                       AND subscriptions.end_time   >= DATE_ADD(observation_rows.end_time, INTERVAL {$this->churnDays} DAY)
                 ) AS churned
            SQL;
        }

        return <<<SQL
            WITH
                offsets AS (
                    {$offsetsUnion}
                ),
                eligible_subscriptions AS (
                    SELECT s.id, s.user_id, s.subscription_type_id, s.is_recurrent, s.length, s.start_time, s.end_time,
                           (SELECT MIN(s2.start_time)
                            FROM subscriptions s2
                            WHERE s2.user_id = s.user_id
                              AND s2.start_time > s.start_time
                              AND s2.is_paid = 1) AS next_sub_start
                    FROM subscriptions s
                    WHERE s.id IN ({$concatenatedSubscriptionIds})
                ),
                {$observationRows}
            SELECT t.* FROM (
                     SELECT
                         users.id AS user_id,
                         observation_rows.subscription_id,
                         observation_rows.offset_days AS days_to_expiry,
                         -- 'Y-m-d' string so BigQuery autodetect export_date
                         -- as a real DATE (not a local-midnight TIMESTAMP).
                         '{$this->exportDate}' AS export_date,
                         observation_rows.observed_at,
                         observation_rows.end_time AS subscription_end_time,

                         DATEDIFF(observation_rows.observed_at, users.created_at) AS days_after_registration,
                         users.source,
                         users.registration_channel,

                         ANY_VALUE(observation_rows.is_recurrent) AS subscription_is_recurrent,
                         ANY_VALUE(observation_rows.length)       AS subscription_length,
                         ANY_VALUE(observation_rows.length_family) AS length_family,

                         MAX(payments.amount) AS payment_amount,
                         ANY_VALUE(payment_gateways.code) AS payment_gateway,

                         {$contentAccessColumns},
                         GROUP_CONCAT(DISTINCT content_access.name ORDER BY content_access.name SEPARATOR ',') AS content_access_codes 
                         {$churnedColumn}

                     FROM observation_rows
                              JOIN users ON users.id = observation_rows.user_id
                              JOIN subscription_types ON observation_rows.subscription_type_id = subscription_types.id
                              JOIN subscription_type_content_access ON subscription_types.id = subscription_type_content_access.subscription_type_id
                              JOIN content_access ON subscription_type_content_access.content_access_id = content_access.id
                              LEFT JOIN payments ON observation_rows.subscription_id = payments.subscription_id
                                                AND payments.status IN ('paid', 'prepaid', 'authorized')
                                                AND payments.created_at <= observation_rows.observed_at
                              LEFT JOIN payment_gateways ON payment_gateways.id = payments.payment_gateway_id
                     GROUP BY users.id, observation_rows.subscription_id, observation_rows.offset_days, observation_rows.observed_at, observation_rows.end_time
                     ORDER BY users.id, observation_rows.subscription_id, observation_rows.offset_days
                 ) AS t
            SQL;
    }

    private function writeBatchToFile($file, array $batch): void
    {
        $userIds = array_values(array_unique(array_column($batch, 'user_id')));
        $userSubscriptions = $this->fetchUserSubscriptionsHistory($userIds);
        $subscriptionPayments = $this->fetchSubscriptionPayments($userIds);
        $userPayments = $this->fetchUserPayments($userIds);
        $userCancellations = $this->fetchUserCancellations($userIds);
        $userRecurrentPayments = $this->fetchUserRecurrentPayments($userIds);
        $providerFeatures = $this->computeProviderFeatures($batch);

        foreach ($batch as $rowIndex => $rowData) {
            $userId = $rowData['user_id'];
            $observedAt = $rowData['observed_at'];
            $userSubs = $userSubscriptions[$userId] ?? [];

            $calc = $this->featureCalculator;
            $tenure = $calc->computeLongestContinuousTenure($userSubs, $observedAt, $this->churnDays);
            $daysSubscribedTotal = $calc->computeDaysSubscribedTotal($userSubs, $observedAt);
            $daysSinceFirstSubscription = $calc->computeDaysSinceFirstSubscription($userSubs, $observedAt);
            $subscribedDaysRatio = $daysSinceFirstSubscription > 0
                ? $daysSubscribedTotal / $daysSinceFirstSubscription
                : null;
            $historyFeatures = $calc->computeSubscriptionHistoryFeatures($userSubs, $subscriptionPayments, $observedAt);
            $previousSubFeatures = $calc->computePreviousSubscriptionFeatures(
                $userSubs,
                (int) $rowData['subscription_id'],
            );
            $subscriberSegment = $calc->computeSubscriberSegment(
                $userSubs,
                (int) $rowData['subscription_id'],
            );

            $paymentFeatures = $calc->computePaymentFeatures($userPayments[$userId] ?? [], $observedAt);
            $cancellationFeatures = $calc->computeCancellationFeatures($userCancellations[$userId] ?? [], $observedAt);
            $activeRecurringFeatures = $calc->computeActiveRecurringFeatures($userRecurrentPayments[$userId] ?? [], $observedAt);
            $tierPriceFeature = $calc->computeTierPriceFeature($rowData, $this->tierPriceByMonth);

            $data = [];
            foreach ($rowData as $column => $value) {
                $data[$column] = $value instanceof \DateTimeInterface
                    ? $value->format(\DateTimeInterface::RFC3339)
                    : $value;
            }
            $data['longest_continuous_subscription_tenure'] = $tenure;
            $data['days_subscribed_total'] = $daysSubscribedTotal;
            $data['days_since_first_subscription'] = $daysSinceFirstSubscription;
            $data['subscribed_days_ratio_since_first_subscription'] = $subscribedDaysRatio;
            $data['unique_days_subscribed_30'] = $calc->computeDaysSubscribedInWindow($userSubs, $observedAt, 30);
            $data['unique_days_subscribed_90'] = $calc->computeDaysSubscribedInWindow($userSubs, $observedAt, 90);
            $data += $historyFeatures;
            $data += $previousSubFeatures;
            $data += $paymentFeatures;
            $data += $cancellationFeatures;
            $data += $activeRecurringFeatures;
            $data += $providerFeatures[$rowIndex];
            $data += $tierPriceFeature;
            $data['subscriber_segment'] = $subscriberSegment;
            fwrite($file, Json::encode($data) . "\n");
        }
    }

    /**
     * Features from registered ABT feature providers, aligned positionally with $batch.
     * Columns follow provider priority and each provider's columns() order, so the ABT column order
     * stays stable (append mode merges staging into the target positionally).
     *
     * @param list<array<string, mixed>> $batch
     * @return list<array<string, mixed>>
     */
    private function computeProviderFeatures(array $batch): array
    {
        $result = array_fill(0, count($batch), []);
        if (empty($this->featureProviders)) {
            return $result;
        }

        $rows = [];
        foreach ($batch as $rowData) {
            $rows[] = [
                'user_id' => (int) $rowData['user_id'],
                'subscription_id' => (int) $rowData['subscription_id'],
                'observed_at' => $rowData['observed_at'],
            ];
        }

        foreach ($this->featureProviders as $provider) {
            $features = $provider->computeFeatures($rows);

            foreach (array_keys($rows) as $rowIndex) {
                $rowFeatures = $features[$rowIndex] ?? [];
                foreach ($provider->columns() as $column => $default) {
                    $result[$rowIndex][$column] = array_key_exists($column, $rowFeatures)
                        ? $rowFeatures[$column]
                        : $default;
                }
            }
        }

        return $result;
    }

    private function fetchUserSubscriptionsHistory(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $sql = <<<SQL
            SELECT
                s.user_id,
                s.id,
                s.start_time,
                s.end_time,
                s.length,
                s.is_paid,
                s.is_recurrent,
                s.created_at AS sub_created_at,
                su.upgrade_created_at,
                COALESCE(MAX(subscription_type_tags.tag = 'trial'), 0) AS is_trial
            FROM subscriptions s
            LEFT JOIN (
                SELECT base_subscription_id, MIN(created_at) AS upgrade_created_at
                FROM subscription_upgrades
                GROUP BY base_subscription_id
            ) su ON su.base_subscription_id = s.id
            LEFT JOIN subscription_type_tags ON subscription_type_tags.subscription_type_id = s.subscription_type_id
            WHERE s.user_id IN (?)
            GROUP BY s.id
            ORDER BY s.user_id, s.start_time, s.id
        SQL;

        $result = [];
        foreach ($this->database->query($sql, $userIds) as $row) {
            $result[$row['user_id']][] = [
                'id' => (int) $row['id'],
                'start_time' => $row['start_time'],
                'end_time' => $row['end_time'],
                'length' => (int) $row['length'],
                'is_paid' => (int) $row['is_paid'],
                'is_recurrent' => (int) $row['is_recurrent'],
                'sub_created_at' => $row['sub_created_at'],
                'upgrade_created_at' => $row['upgrade_created_at'],
                'is_trial' => (int) $row['is_trial'],
            ];
        }
        return $result;
    }

    private function fetchSubscriptionPayments(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $sql = <<<SQL
            SELECT subscription_id, created_at, amount
            FROM payments
            WHERE user_id IN (?)
              AND subscription_id IS NOT NULL
              AND status IN ('paid', 'prepaid', 'family', 'upgrade')
        SQL;

        $result = [];
        foreach ($this->database->query($sql, $userIds) as $row) {
            $result[(int) $row['subscription_id']][] = [
                'created_at' => $row['created_at'],
                'amount' => (float) $row['amount'],
            ];
        }
        return $result;
    }

    private function fetchUserPayments(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $sql = <<<SQL
            SELECT p.user_id, p.id, p.status, p.created_at,
                   COALESCE(SUM(IF(pi.type = 'donation', pi.amount, 0)), 0) AS donation_amount,
                   COALESCE(SUM(IF(pi.type = 'product', pi.amount, 0)), 0) AS product_amount,
                   SUM(IF(pi.type = 'product', 1, 0)) AS product_count
            FROM payments p
            LEFT JOIN payment_items pi ON pi.payment_id = p.id AND pi.type IN ('donation', 'product')
            WHERE p.user_id IN (?)
            GROUP BY p.id
        SQL;

        $result = [];
        foreach ($this->database->query($sql, $userIds) as $row) {
            $result[(int) $row['user_id']][] = [
                'status' => $row['status'],
                'created_at' => $row['created_at'],
                'donation_amount' => (float) $row['donation_amount'],
                'product_amount' => (float) $row['product_amount'],
                'product_count' => (int) $row['product_count'],
            ];
        }
        return $result;
    }

    private function fetchUserCancellations(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $sql = <<<SQL
            SELECT user_id, updated_at
            FROM recurrent_payments
            WHERE user_id IN (?)
              AND state = 'user_stop'
            ORDER BY user_id, updated_at DESC
        SQL;

        $result = [];
        foreach ($this->database->query($sql, $userIds) as $row) {
            $result[(int) $row['user_id']][] = $row['updated_at'];
        }
        return $result;
    }

    /**
     * Loads all recurrent_payments rows per user, ordered most-recent-first.
     * The compute step picks the row whose created_at ≤ observed_at to approximate
     * "the active recurrent_payment at observed_at" without a history table.
     */
    private function fetchUserRecurrentPayments(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $sql = <<<SQL
            SELECT user_id, created_at, updated_at, expires_at, charge_at, state, cycle
            FROM recurrent_payments
            WHERE user_id IN (?)
            ORDER BY user_id, created_at DESC
        SQL;

        $result = [];
        foreach ($this->database->query($sql, $userIds) as $row) {
            $result[(int) $row['user_id']][] = [
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'expires_at' => $row['expires_at'],
                'charge_at' => $row['charge_at'],
                'state' => $row['state'],
                'cycle' => $row['cycle'] !== null ? (int) $row['cycle'] : null,
            ];
        }
        return $result;
    }

    private function exportDataToBigQuery(string $tableName, string $fileName): bool
    {
        $dataset = $this->bigQueryClient->dataset($this->bigQueryConfig->getBigQueryDataset());

        if ($this->replaceTable) {
            return $this->loadFileToTable($dataset->table($tableName), $fileName, 'WRITE_TRUNCATE');
        }

        $stagingTableName = "{$tableName}_staging";
        if (!$this->loadFileToTable($dataset->table($stagingTableName), $fileName, 'WRITE_TRUNCATE')) {
            return false;
        }
        return $this->mergeStagingIntoTarget($tableName, $stagingTableName);
    }

    private function loadFileToTable($table, string $fileName, string $writeDisposition): bool
    {
        $handle = fopen($fileName, 'r');
        if ($handle === false) {
            $this->output->writeln("<error>Could not open {$fileName} for reading.</error>");
            return false;
        }

        try {
            $loadConfig = $table->load($handle)
                ->sourceFormat('NEWLINE_DELIMITED_JSON')
                ->autodetect(true)
                ->writeDisposition($writeDisposition);
            $job = $this->bigQueryClient->runJob($loadConfig);

            $this->output->writeln("Loading {$table->id()}...");
            while (!$job->isComplete()) {
                sleep(5);
                $job->reload();
                $this->output->write('.');
            }

            if (isset($job->info()['status']['errorResult'])) {
                $error = $job->info()['status']['errorResult']['message'];
                $this->output->writeln("<error>{$error}</error>");
                return false;
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        return true;
    }

    private function mergeStagingIntoTarget(string $tableName, string $stagingTableName): bool
    {
        $datasetName = $this->bigQueryConfig->getBigQueryDataset();
        $stagingFields = $this->bigQueryClient->dataset($datasetName)->table($stagingTableName)->info()['schema']['fields'];

        $sql = $this->stagingMergeSqlBuilder->build(
            dataset: $datasetName,
            targetTable: $tableName,
            stagingTable: $stagingTableName,
            columns: array_column($stagingFields, 'name'),
            keyColumns: ['subscription_id', 'days_to_expiry'],
        );

        $queryConfig = $this->bigQueryClient->query($sql);
        $job = $this->bigQueryClient->runJob($queryConfig);

        $this->output->writeln("Merging staging into {$tableName}...");
        while (!$job->isComplete()) {
            sleep(5);
            $job->reload();
            $this->output->write('.');
        }

        if (isset($job->info()['status']['errorResult'])) {
            $error = $job->info()['status']['errorResult']['message'];
            $this->output->writeln("<error>{$error}</error>");
            return false;
        }

        return true;
    }
}
