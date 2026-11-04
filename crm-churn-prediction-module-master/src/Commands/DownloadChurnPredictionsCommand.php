<?php
declare(strict_types=1);

namespace Crm\ChurnPredictionModule\Commands;

use Crm\ApplicationModule\Commands\DecoratedCommandTrait;
use Crm\ChurnPredictionModule\Models\BigQueryConfig;
use Crm\ChurnPredictionModule\Repositories\ChurnPredictionsRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Crm\UsersModule\Repositories\UsersRepository;
use Google\Cloud\BigQuery\BigQueryClient;
use Google\Cloud\BigQuery\Timestamp;
use Google\Cloud\Core\ExponentialBackoff;
use Nette\Utils\DateTime;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DownloadChurnPredictionsCommand extends Command
{
    use DecoratedCommandTrait;

    private const BATCH_SIZE = 1000;

    private ?BigQueryClient $bigQueryClient = null;

    public function __construct(
        private readonly BigQueryConfig $bigQueryConfig,
        private readonly ChurnPredictionsRepository $churnPredictionsRepository,
        private readonly UsersRepository $usersRepository,
        private readonly SubscriptionsRepository $subscriptionsRepository,
    ) {
        parent::__construct();
    }

    public function configure()
    {
        $this->setName('churn:download_churn_predictions')
            ->setDescription('Downloads churn predictions from BigQuery into CRM DB');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->bigQueryClient = $this->bigQueryConfig->getBigQueryClient();
        if (!$this->bigQueryClient) {
            $output->writeln("<error>BigQuery client not initialized.</error>");
            return self::FAILURE;
        }


        $output->writeln(" * Preparing to download churn predictions from BigQuery");

        $tableName = $this->bigQueryConfig->getFullyQualifiedTableName('churn_predictions');

        $jobConfig = $this->bigQueryClient->query(<<<BQSQL
            SELECT *
FROM `$tableName`
WHERE prediction_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 3 DAY)
QUALIFY ROW_NUMBER() OVER (
  PARTITION BY subscription_id
  ORDER BY prediction_date DESC, scored_at DESC
) = 1
BQSQL,);

        $output->writeln(' * Creating job with query.');
        $job = $this->bigQueryClient->startQuery($jobConfig);

        $backoff = new ExponentialBackoff(10);
        $backoff->execute(function (OutputInterface $output) use ($job) {
            $output->writeln(' * Waiting for job to complete.');
            $job->reload();
            if (!$job->isComplete()) {
                throw new \Exception('Job has not yet completed', 500);
            }
        }, [$output]);
        $output->writeln(' * Job finished. Processing results.');

        $queryResults = $job->queryResults([
            'maxResults' => 1000,
        ]);

        $count = 0;
        $skipped = 0;
        $batch = [];
        foreach ($queryResults as $row) {
            $batch[] = $row;

            if (count($batch) >= self::BATCH_SIZE) {
                [$stored, $missing] = $this->processBatch($batch);
                $count += $stored;
                $skipped += $missing;
                $batch = [];
                $output->writeln(" * {$count} churn predictions stored, {$skipped} skipped (unknown user/subscription).");
            }
        }

        if ($batch) {
            [$stored, $missing] = $this->processBatch($batch);
            $count += $stored;
            $skipped += $missing;
        }

        $output->writeln('');
        if ($count === 0) {
            $output->writeln(" * <error>No churn predictions found.</error>");
        } else {
            $output->writeln(" * <comment>{$count} churn predictions downloaded, {$skipped} skipped (unknown user/subscription).</comment>");
        }

        $output->writeln('Done.');

        return Command::SUCCESS;
    }

    private function processBatch(array $batch): array
    {
        $userIds = array_unique(array_map(static fn ($row) => (int) $row['user_id'], $batch));
        $subscriptionIds = array_unique(array_map(static fn ($row) => (int) $row['subscription_id'], $batch));

        $existingUserIds = $this->usersRepository->getTable()
            ->where('id', $userIds)
            ->fetchPairs('id', 'id');
        $existingSubscriptionIds = $this->subscriptionsRepository->getTable()
            ->where('id', $subscriptionIds)
            ->fetchPairs('id', 'id');

        $stored = 0;
        $skipped = 0;
        foreach ($batch as $row) {
            if (!isset($existingUserIds[$row['user_id']], $existingSubscriptionIds[$row['subscription_id']])) {
                $skipped++;
                continue;
            }

            /** @var Timestamp $scoredAtTimestamp */
            $scoredAtTimestamp = $row['scored_at'];
            $scoredAt = DateTime::from($scoredAtTimestamp->get())->setTimezone(new \DateTimeZone(date_default_timezone_get()));

            $this->churnPredictionsRepository->upsert(
                userId: $row['user_id'],
                subscriptionId: $row['subscription_id'],
                scoredAt: $scoredAt,
                daysToExpiry: $row['days_to_expiry'],
                churnProbability: $row['churn_probability'],
                modelName: $row['model_name'],
            );
            $stored++;
        }

        return [$stored, $skipped];
    }
}
