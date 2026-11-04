# Churn Prediction Module

This module trains, evaluates, and serves a subscription churn prediction model using BigQuery ML. For current subscriptions close to expiration, an XGBoost classifier estimates the probability that a subscriber will **not** renew within `churn-days` (28 days by default).

### Data Pipeline Overview

The module aggregates features from CRM subscription data and Beam event data and stores them in the following BigQuery tables:  

* **Subscription Features:** Represents snapshots of subscriptions at defined observation points, exported from MySQL by the CRM's PHP pipeline:
  * *Training rows (table `churn_training_subscription_features`):* Past subscriptions evaluated at `1, 3, 7, 14, 21, 28, 42, 60, and 90` days prior subscription ends. To each row, a `churned` label is added, representing information whether user has churned in the past.
  * *Scoring rows (table `churn_scoring_subscription_features`):* Active, non-renewed subscriptions before expiration.
* **Engagement Features:** (table `churn_engagement_features`) Aggregates subscriber behavioral activity in BigQuery from Beam event logs.
* **Unified Views:** (tables `churn_training_all_features`, `churn_scoring_all_features`) Joins subscription snapshots with engagement records for training and scoring.

Table `churn_training_all_features` is then used for training a model. 

For scoring, table `churn_scoring_all_features` is used - predicted probabilities are stored in another table `churn_predictions`.

---

## 0. Prerequisites

Before setting up the module, ensure the following infrastructure and dependencies are in place:

* **REMP CRM:** An active installation with functional subscriptions and payments modules.
* **Google Cloud Project & BigQuery Dataset:** A BigQuery dataset and a service account assigned both the **BigQuery Data Editor** and **BigQuery Job User** roles.
* **Beam Event Tables:** The dataset must contain `pageviews` and `pageviews_time_spent` tables. They are not created by this module; load them from Beam's Elasticsearch with  [beam-bigquery-export](https://github.com/remp2020/beam-bigquery-export) tool.
* **Subscription Type Tagging:** Ensure trial subscription types are tagged with `trial` tag. This improves model performance.

---

## 1. Installation & Configuration

Install the module via Composer:

```bash
composer require remp/crm-churn-prediction-module
```

Register the extension and configure your services in your NEON configuration:

```neon
extensions:
	churn_prediction: Crm\ChurnPredictionModule\DI\ChurnPredictionModuleExtension

churn_prediction:
	bigquery:
		service_account_path: @environmentConfig::get('BIGQUERY_SERVICE_ACCOUNT_JSON_PATH')
		project: @environmentConfig::get('BIGQUERY_PROJECT')
		dataset: @environmentConfig::get('BIGQUERY_DATASET')

services:
	runChurnPipelineCommand:
		setup:
			- setActiveTokens(['<beam property token>', '<beam property token>'])
			# Optional: Defaults to bundled src/Commands/bigquery/engagement_features.sql
			- setEngagementFeaturesSqlPath(%appDir%/path/to/your_engagement_features.sql)
```

Configuration parameters:
* `setEngagementFeaturesSqlPath()`: Custom engagement feature SQL template path (see [Section 8](#8-custom-features)).

Execute CRM migrations to create the local `churn_predictions` table:

```bash
php bin/command.php phinx:migrate
```

---

## 2. Initial Data Generation

Run the following queries to create required BigQuery tables and fill them with initial data. This may take some time, depending on the size of your data:

```bash
# 1. Export historical training records
php bin/command.php churn:run_pipeline --mode=training --replace-training-data --training-window-days=365

# 2. Export current active subscriptions for scoring
php bin/command.php churn:run_pipeline --mode=scoring --skip-predict
```

### Command Options

* `--training-window-days`: Determines the window size for training. Subscriptions ending between `now - training-window-days` and `now - churn-days` are exported. (this parameter must always be larger than `churn-days`).
  * A value of `365` covers a complete year cycle (recommended for model to correctly work with yearly subscriptions)
  * Shorter windows bias the model toward monthly plans.
* `--replace-training-data`: Overwrites existing training tables instead of incrementally appending to them.
* `--skip-predict`: Generates `churn_scoring_subscription_features` without attempting to run inference.

---

## 3. Create BigQuery Views

Execute both queries in BigQuery once to prepare the unified views, replacing `YOUR_DATASET` with your actual BigQuery dataset name.

```sql
CREATE OR REPLACE VIEW `YOUR_DATASET.churn_training_all_features` AS
SELECT
    sub.*,
    eng.* EXCEPT (user_id, observed_date),
    CASE
        WHEN MOD(ABS(FARM_FINGERPRINT(CAST(sub.user_id AS STRING))), 10) < 7 THEN 'train'
        WHEN MOD(ABS(FARM_FINGERPRINT(CAST(sub.user_id AS STRING))), 10) < 9 THEN 'eval'
        ELSE 'test'
    END AS split
FROM `YOUR_DATASET.churn_training_subscription_features` sub
LEFT JOIN `YOUR_DATASET.churn_engagement_features` eng
       ON eng.user_id       = sub.user_id
      AND eng.observed_date = DATE(sub.observed_at);

CREATE OR REPLACE VIEW `YOUR_DATASET.churn_scoring_all_features` AS
SELECT
    sub.*,
    eng.* EXCEPT (user_id, observed_date)
FROM `YOUR_DATASET.churn_scoring_subscription_features` sub
LEFT JOIN `YOUR_DATASET.churn_engagement_features` eng
       ON eng.user_id       = sub.user_id
      AND eng.observed_date = DATE(sub.observed_at);
```

* **Data Split:** The `split` column creates a user-level hash split (70% train, 20% validation/early-stopping, 10% test), required for model training.

---

## 4. Train and Evaluate the Model

Train the model directly in BigQuery ML. The model handles all standard subscription intervals (monthly, quarterly, yearly):

```sql
CREATE OR REPLACE MODEL `YOUR_DATASET.churn_xgboost`
OPTIONS (
    MODEL_TYPE='BOOSTED_TREE_CLASSIFIER',
    INPUT_LABEL_COLS=['churned'],
    AUTO_CLASS_WEIGHTS=FALSE,
    BOOSTER_TYPE='GBTREE',
    EARLY_STOP=TRUE,
    MIN_REL_PROGRESS=0.001,
    MAX_ITERATIONS=200,
    MAX_TREE_DEPTH=8,
    LEARN_RATE=0.107,
    L1_REG=1e-14,
    L2_REG=3.09,
    SUBSAMPLE=0.67,
    COLSAMPLE_BYTREE=0.83,
    MIN_SPLIT_LOSS=1.04,
    DATA_SPLIT_METHOD='CUSTOM', DATA_SPLIT_COL='data_split'
) AS
SELECT
    * EXCEPT (user_id, subscription_id, observed_at, subscription_end_time,
              export_date, content_access_codes, split),
    (split = 'eval') AS data_split
FROM `YOUR_DATASET.churn_training_all_features`
WHERE split IN ('train', 'eval');
```
> The hyperparameters above were tuned on Denník N's data. Use them as a starting point, and retune them on your own data, or after significant changes to the features.

After successful run, a new XGboost model named `churn_xgboost` will be created in your BigQuery dataset.

You may periodically update the model using the same query.

### Evaluation

After the model is built, you may evaluate its performance against the test split:

```sql
SELECT * FROM ML.EVALUATE(
    MODEL `YOUR_DATASET.churn_xgboost`,
    (SELECT * FROM `YOUR_DATASET.churn_training_all_features`
     WHERE split = 'test' AND days_to_expiry = 28)
);
```

To see features' importance, try:

```sql
SELECT
    feature,
    importance_gain,
    ROUND(100 * importance_gain / SUM(importance_gain) OVER (), 2) AS gain_pct
FROM ML.FEATURE_IMPORTANCE(MODEL `YOUR_DATASET.churn_xgboost`)
ORDER BY importance_gain DESC
LIMIT 50;
```

---

## 5. Generate Scoring Predictions

Run the scoring pipeline against the trained model:

```bash
php bin/command.php churn:run_pipeline --mode=scoring --model=churn_xgboost
```

This exports subscriptions approaching expiry, refreshes their engagement metrics, and runs `ML.PREDICT` over `churn_scoring_all_features`. Daily churn probabilities are written to the BigQuery table `churn_predictions`. Each scoring run fully replaces the previous scoring dataset.

---

## 6. Synchronize Predictions to CRM

Download prediction outputs from BigQuery into the CRM database:

```bash
php bin/command.php churn:download_churn_predictions
```

This command upserts the latest predicted score per subscription into the local MySQL `churn_predictions` table managed by `ChurnPredictionsRepository`.

---

## 7. Production Automation (Cron)

Configure the daily cron jobs in the following sequence:

```cron
# 03:05 - Beam data: export yesterday's pageviews data to BigQuery (beam-bigquery-export, separate project)
5 3 * * *   cd /path/to/beam-bigquery-export && ./beam-bigquery-export.sh --env=/path/to/.env

# 04:00 - Append new training data
0 4 * * *   flock -n /tmp/churn_training.lock php bin/command.php churn:run_pipeline --mode=training --training-window-days=33

# 05:30 - Export scoring data and run scoring for actual subscriptions
30 5 * * *  flock -n /tmp/churn_scoring.lock php bin/command.php churn:run_pipeline --mode=scoring --model=churn_xgboost

# 06:30 - Pull latest predictions into CRM database
30 6 * * *  php bin/command.php churn:download_churn_predictions
```

### Operational Considerations

- **Warning:** Never use `--replace-training-data` in automated cron schedules; doing so deletes accumulated training history.
- All commands are idempotent, so a failed run can simply be repeated. A missed training run is caught up by the next one, for up to 5 missed days.
- Scoring is executed independently against the deployed `--model`. Model retraining (Step 4) has to be done manually.

---

## 8. Custom Features

### PHP Features (Per Exported Snapshot)

To compute custom CRM-side attributes, implement `Crm\ChurnPredictionModule\DataProviders\ChurnFeatureDataProviderInterface` and register the provider under `churn_prediction.dataprovider.features` in your module's `registerDataProviders()` method:

* `columns()`: Defines feature column definitions and default fallback values.
* `beforeExport()`: Executes pre-export hooks or sanity assertions.
* `computeFeatures(array $rows)`: Receives a batch of `user_id`, `subscription_id`, and `observed_at` records and returns an ordered array of feature mappings. Must evaluate only attributes known as of `observed_at`.

### Engagement Features (Per User & Observation Date)

To implement custom features:

1. Copy the default template at `src/Commands/bigquery/engagement_features.sql`.
2. Append your analytical SQL metrics while retaining existing template parameters.
4. Supply the path using `setEngagementFeaturesSqlPath()` in your NEON configuration.

> Modifying or introducing features alters the model input schema; retrain and evaluate the model (Step 4) after updating features.
