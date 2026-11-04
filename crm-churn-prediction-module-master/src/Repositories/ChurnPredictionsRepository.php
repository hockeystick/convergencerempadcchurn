<?php

namespace Crm\ChurnPredictionModule\Repositories;

use Crm\ApplicationModule\Models\Database\Repository;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;

class ChurnPredictionsRepository extends Repository
{
    protected $tableName = 'churn_predictions';

    final public function update(ActiveRow &$row, $data)
    {
        $data['updated_at'] = new DateTime();
        return parent::update($row, $data);
    }

    final public function upsert(
        int $userId,
        int $subscriptionId,
        DateTime $scoredAt,
        int $daysToExpiry,
        float $churnProbability,
        string $modelName,
    ) {
        $data = [
            'scored_at' => $scoredAt,
            'user_id' => $userId,
            'subscription_id' => $subscriptionId,
            'churn_probability' => $churnProbability,
            'model_name' => $modelName,
            'days_to_expiry' => $daysToExpiry,
        ];

        $row = $this->getTable()->where([
            'subscription_id' => $subscriptionId,
        ])->fetch();

        if ($row) {
            $this->update($row, $data);
            return $row;
        }

        $data['created_at'] = new DateTime();
        $data['updated_at'] = new DateTime();
        return $this->getTable()->insert($data);
    }
}
