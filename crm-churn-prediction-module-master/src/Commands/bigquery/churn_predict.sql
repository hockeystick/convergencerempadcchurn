-- Daily churn scoring: run ML.PREDICT over the scoring input view and append one
-- row per scored subscription into the predictions history table. Run after the
-- scoring ABT export and the engagement features refresh, so churn_scoring_all_features is current.
--
-- Placeholders substituted PHP-side (see resolvePredictSql):
--   {{MODEL}}  dataset-qualified model name (e.g. {{DATASET}}.churn_xgboost_..._capped)
--   {{DATE}}   scoring/export date as a bare Y-m-d body for a DATE literal
--
-- ML.PREDICT uses only the columns matching trained features; every other column
-- in the input SELECT (user_id, subscription_id, length_family, ...) passes through
-- to the output untouched, which is how we carry the keys into the predictions table.

CREATE TABLE IF NOT EXISTS {{DATASET}}.churn_predictions (
    prediction_date       DATE,
    scored_at             TIMESTAMP,
    user_id               INT64,
    subscription_id       INT64,
    length_family         STRING,
    days_to_expiry        INT64,
    subscription_end_time TIMESTAMP,
    churn_probability     FLOAT64,
    model_name            STRING
)
PARTITION BY prediction_date
CLUSTER BY subscription_id;

-- Idempotent: replace today's partition so reruns are safe.
DELETE FROM {{DATASET}}.churn_predictions WHERE prediction_date = DATE '{{DATE}}';

INSERT INTO {{DATASET}}.churn_predictions
    (prediction_date, scored_at, user_id, subscription_id, length_family,
     days_to_expiry, subscription_end_time, churn_probability, model_name)
SELECT
    DATE '{{DATE}}'                                                    AS prediction_date,
    CURRENT_TIMESTAMP()                                                AS scored_at,
    user_id,
    subscription_id,
    length_family,
    days_to_expiry,
    subscription_end_time,
    (SELECT prob FROM UNNEST(predicted_churned_probs) WHERE label = 1) AS churn_probability,
    '{{MODEL}}'                                                        AS model_name
FROM ML.PREDICT(
    MODEL `{{MODEL}}`,
    (SELECT * FROM {{DATASET}}.churn_scoring_all_features)
);
