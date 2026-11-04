<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddChurnPredictionsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('churn_predictions')
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('subscription_id', 'integer', ['null' => false])
            ->addColumn('churn_probability', 'float', ['null' => false, 'default' => false])
            ->addColumn('days_to_expiry', 'integer', ['null' => false, 'default' => false])
            ->addColumn('model_name', 'string', ['null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addColumn('scored_at', 'datetime', ['null' => false])
            ->addIndex('user_id')
            ->addIndex('subscription_id')
            ->addForeignKey('user_id','users')
            ->addForeignKey('subscription_id','subscriptions')
            ->create();
    }

    public function down(): void
    {
        $this->table('churn_predictions')->drop()->update();
    }
}
