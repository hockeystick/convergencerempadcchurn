-- ============================================================================
-- Engagement feature computation for the churn ABT (analytical base table): daily
-- upsert of rows exported today.
--
-- The command substitutes all placeholders before running — do NOT run this file directly.
-- ============================================================================

DECLARE active_tokens ARRAY<STRING> DEFAULT [{{ACTIVE_TOKENS}}];

-- Global date bounds, computed only over today's exported rows so partition pruning
-- is tight on this incremental run.
DECLARE min_pv_date DATE;
DECLARE max_pv_date DATE;

SET (min_pv_date, max_pv_date) = (
    SELECT AS STRUCT
    DATE_SUB(MIN(DATE(observed_at)), INTERVAL 90 DAY),
    DATE_SUB(MAX(DATE(observed_at)), INTERVAL 1 DAY)
        FROM {{TABLE_NAME}}
    WHERE export_date = CURRENT_DATE()
    );

-- ============================================================================
-- 0. Bootstrap: create the target table if it doesn't already exist so this
--    script also works on a fresh dataset (without first running the backfill).
--    Schema must stay in sync with the SELECT at the bottom of this file.
-- ============================================================================
CREATE TABLE IF NOT EXISTS {{DATASET}}.churn_engagement_features (
    user_id                                       INT64,
    observed_date                                 DATE,

    -- 30-day pageview features
    unique_articles_seen_30                       INT64,
    unique_pages_seen_30                          INT64,
    unique_pageviews_30                           INT64,
    unique_days_seen_30                           INT64,
    user_last_seen_days_30                        INT64,

    -- 90-day pageview features
    unique_articles_seen_90                       INT64,
    unique_pages_seen_90                          INT64,
    unique_pageviews_90                           INT64,
    unique_days_seen_90                           INT64,
    user_last_seen_days_90                        INT64,

    -- Timespent
    sum_total_timespent_30                        INT64,
    sum_total_timespent_90                        INT64,

    -- Delta features (30d vs prev-30d)
    unique_articles_seen_30d_vs_prev_30d_change   INT64,
    unique_days_seen_30d_vs_prev_30d_change       INT64,

    -- Content diversity (article pageviews only)
    distinct_tags_30d                             INT64,
    distinct_tags_90d                             INT64,
    distinct_authors_30d                          INT64,
    distinct_authors_90d                          INT64,
    distinct_devices_30d                          INT64,
    top_tag_share_30d                             FLOAT64,
    top_tag_share_90d                             FLOAT64,

)
PARTITION BY observed_date
CLUSTER BY user_id;

-- ============================================================================
-- 1. Remove existing engagement rows for the (user_id, observed_date) pairs we're about to
--    (re)compute. Handles re-exports correctly: if an ABT row gets re-exported
--    with a new export_date, its engagement row gets recomputed.
-- ============================================================================
DELETE FROM {{DATASET}}.churn_engagement_features eng
WHERE EXISTS (
    SELECT 1
    FROM {{TABLE_NAME}} abt
    WHERE abt.user_id    = eng.user_id
      AND DATE(abt.observed_at) = eng.observed_date
      AND abt.export_date = CURRENT_DATE()
);

-- ============================================================================
-- 2. Compute and insert engagement rows for today's exported ABT rows.
-- ============================================================================
INSERT INTO {{DATASET}}.churn_engagement_features
WITH
    observations AS (
        SELECT DISTINCT
            user_id,
            DATE(observed_at) AS observed_date
        FROM {{TABLE_NAME}}
        WHERE export_date = CURRENT_DATE()
    ),

    -- ========================================================================
    -- 30-DAY PAGEVIEW METRICS
    -- ========================================================================
    unique_30 AS (
        SELECT
            obs.user_id,
            obs.observed_date,
            COUNT(DISTINCT pv.remp_pageview_id)                                                  AS unique_pageviews,
            COUNT(DISTINCT pv.canonical_url)                                                     AS unique_pages_seen,
            COUNT(DISTINCT DATE(pv.time))                                                        AS unique_days_seen,
            DATE(MAX(pv.time))                                                                   AS user_last_seen,
            NULLIF(COUNT(DISTINCT IF(pv.content_type = 'article', pv.article_id, NULL)), 0)      AS unique_articles_seen
        FROM observations obs
                 JOIN {{DATASET}}.pageviews pv
                      ON pv.user_id = CAST(obs.user_id AS STRING)
                          AND DATE(pv.time) BETWEEN DATE_SUB(obs.observed_date, INTERVAL 30 DAY)
                             AND DATE_SUB(obs.observed_date, INTERVAL 1 DAY)
                          AND DATE(pv.time) BETWEEN min_pv_date AND max_pv_date
                          AND pv.token IN UNNEST(active_tokens)
        GROUP BY obs.user_id, obs.observed_date
    ),

    -- ========================================================================
    -- 90-DAY PAGEVIEW METRICS
    -- ========================================================================
    unique_90 AS (
        SELECT
            obs.user_id,
            obs.observed_date,
            COUNT(DISTINCT pv.remp_pageview_id)                                                  AS unique_pageviews,
            COUNT(DISTINCT pv.canonical_url)                                                     AS unique_pages_seen,
            COUNT(DISTINCT DATE(pv.time))                                                        AS unique_days_seen,
            DATE(MAX(pv.time))                                                                   AS user_last_seen,
            NULLIF(COUNT(DISTINCT IF(pv.content_type = 'article', pv.article_id, NULL)), 0)      AS unique_articles_seen
        FROM observations obs
                 JOIN {{DATASET}}.pageviews pv
                      ON pv.user_id = CAST(obs.user_id AS STRING)
                          AND DATE(pv.time) BETWEEN DATE_SUB(obs.observed_date, INTERVAL 90 DAY)
                             AND DATE_SUB(obs.observed_date, INTERVAL 1 DAY)
                          AND DATE(pv.time) BETWEEN min_pv_date AND max_pv_date
                          AND pv.token IN UNNEST(active_tokens)
        GROUP BY obs.user_id, obs.observed_date
    ),

    -- ========================================================================
    -- PREVIOUS 30-DAY PAGEVIEW METRICS — for delta features
    -- ========================================================================
    unique_prev_30 AS (
        SELECT
            obs.user_id,
            obs.observed_date,
            COUNT(DISTINCT DATE(pv.time))                                                        AS unique_days_seen,
            NULLIF(COUNT(DISTINCT IF(pv.content_type = 'article', pv.article_id, NULL)), 0)      AS unique_articles_seen
        FROM observations obs
                 JOIN {{DATASET}}.pageviews pv
                      ON pv.user_id = CAST(obs.user_id AS STRING)
                          AND DATE(pv.time) BETWEEN DATE_SUB(obs.observed_date, INTERVAL 60 DAY)
                             AND DATE_SUB(obs.observed_date, INTERVAL 31 DAY)
                          AND DATE(pv.time) BETWEEN min_pv_date AND max_pv_date
                          AND pv.token IN UNNEST(active_tokens)
        GROUP BY obs.user_id, obs.observed_date
    ),

    -- ========================================================================
    -- 30-DAY TIMESPENT
    -- ========================================================================
    timespent_30 AS (
        SELECT
            obs.user_id,
            obs.observed_date,
            SUM(pts.timespent)                                   AS sum_total_timespent
        FROM observations obs
                 JOIN {{DATASET}}.pageviews_time_spent pts
                      ON pts.user_id = CAST(obs.user_id AS STRING)
                          AND DATE(pts.time) BETWEEN DATE_SUB(obs.observed_date, INTERVAL 30 DAY)
                             AND DATE_SUB(obs.observed_date, INTERVAL 1 DAY)
                          AND DATE(pts.time) BETWEEN min_pv_date AND max_pv_date
                          AND pts.token IN UNNEST(active_tokens)
        GROUP BY obs.user_id, obs.observed_date
    ),

    -- ========================================================================
    -- 90-DAY TIMESPENT
    -- ========================================================================
    timespent_90 AS (
        SELECT
            obs.user_id,
            obs.observed_date,
            SUM(pts.timespent)                                   AS sum_total_timespent
        FROM observations obs
                 JOIN {{DATASET}}.pageviews_time_spent pts
                      ON pts.user_id = CAST(obs.user_id AS STRING)
                          AND DATE(pts.time) BETWEEN DATE_SUB(obs.observed_date, INTERVAL 90 DAY)
                             AND DATE_SUB(obs.observed_date, INTERVAL 1 DAY)
                          AND DATE(pts.time) BETWEEN min_pv_date AND max_pv_date
                          AND pts.token IN UNNEST(active_tokens)
        GROUP BY obs.user_id, obs.observed_date
    ),

    -- ========================================================================
    -- CONTENT DIVERSITY — distinct counts of tags / authors / devices across
    -- article pageviews. Filtered to content_type = 'article'
    -- ========================================================================
    content_diversity AS (
        SELECT
            obs.user_id,
            obs.observed_date,
            COUNT(DISTINCT IF(DATE(pv.time) >= DATE_SUB(obs.observed_date, INTERVAL 30 DAY) AND pv.author_id IS NOT NULL,
                              pv.author_id, NULL))                                                                 AS distinct_authors_30d,
            COUNT(DISTINCT IF(DATE(pv.time) >= DATE_SUB(obs.observed_date, INTERVAL 90 DAY) AND pv.author_id IS NOT NULL,
                              pv.author_id, NULL))                                                                 AS distinct_authors_90d,
            COUNT(DISTINCT IF(DATE(pv.time) >= DATE_SUB(obs.observed_date, INTERVAL 30 DAY)
                                AND pv.derived_ua_device IS NOT NULL
                                AND pv.derived_ua_device != 'Unknown',
                              pv.derived_ua_device, NULL))                                                         AS distinct_devices_30d,
            COUNT(DISTINCT IF(DATE(pv.time) >= DATE_SUB(obs.observed_date, INTERVAL 30 DAY), tag, NULL))              AS distinct_tags_30d,
            COUNT(DISTINCT IF(DATE(pv.time) >= DATE_SUB(obs.observed_date, INTERVAL 90 DAY), tag, NULL))              AS distinct_tags_90d
        FROM observations obs
                 JOIN {{DATASET}}.pageviews pv
                      ON pv.user_id = CAST(obs.user_id AS STRING)
                          AND DATE(pv.time) BETWEEN DATE_SUB(obs.observed_date, INTERVAL 90 DAY)
                             AND DATE_SUB(obs.observed_date, INTERVAL 1 DAY)
                          AND DATE(pv.time) BETWEEN min_pv_date AND max_pv_date
                          AND pv.token IN UNNEST(active_tokens)
                          AND pv.content_type = 'article'
                 CROSS JOIN UNNEST(IF(pv.tags IS NOT NULL AND pv.tags != '', SPLIT(pv.tags, ','), [CAST(NULL AS STRING)])) AS tag
        GROUP BY obs.user_id, obs.observed_date
    ),

    -- ========================================================================
    -- TOP-TAG SHARE — per (user, observed_at, tag) mention counts, then collapse to
    -- the user's reading concentration: top tag's mentions / total mentions.
    -- 1.0 = single-topic reader; low values = broad reader.
    -- ========================================================================
    tag_mentions AS (
        SELECT
            obs.user_id,
            obs.observed_date,
            tag,
            COUNTIF(DATE(pv.time) >= DATE_SUB(obs.observed_date, INTERVAL 30 DAY)) AS mentions_30d,
            COUNTIF(DATE(pv.time) >= DATE_SUB(obs.observed_date, INTERVAL 90 DAY)) AS mentions_90d
        FROM observations obs
                 JOIN {{DATASET}}.pageviews pv
                      ON pv.user_id = CAST(obs.user_id AS STRING)
                          AND DATE(pv.time) BETWEEN DATE_SUB(obs.observed_date, INTERVAL 90 DAY)
                             AND DATE_SUB(obs.observed_date, INTERVAL 1 DAY)
                          AND DATE(pv.time) BETWEEN min_pv_date AND max_pv_date
                          AND pv.token IN UNNEST(active_tokens)
                          AND pv.content_type = 'article'
                          AND pv.tags IS NOT NULL AND pv.tags != ''
                 CROSS JOIN UNNEST(SPLIT(pv.tags, ',')) AS tag
        GROUP BY obs.user_id, obs.observed_date, tag
    ),

    top_tag_share AS (
        SELECT
            user_id,
            observed_date,
            SAFE_DIVIDE(MAX(mentions_30d), NULLIF(SUM(mentions_30d), 0)) AS top_tag_share_30d,
            SAFE_DIVIDE(MAX(mentions_90d), NULLIF(SUM(mentions_90d), 0)) AS top_tag_share_90d
        FROM tag_mentions
        GROUP BY user_id, observed_date
    )

SELECT
    obs.user_id,
    obs.observed_date,

    -- 30-day pageview features
    u30.unique_articles_seen                                                                     AS unique_articles_seen_30,
    u30.unique_pages_seen                                                                        AS unique_pages_seen_30,
    u30.unique_pageviews                                                                         AS unique_pageviews_30,
    u30.unique_days_seen                                                                         AS unique_days_seen_30,
    DATE_DIFF(DATE_SUB(obs.observed_date, INTERVAL 1 DAY), u30.user_last_seen, DAY)                 AS user_last_seen_days_30,

    -- 90-day pageview features
    u90.unique_articles_seen                                                                     AS unique_articles_seen_90,
    u90.unique_pages_seen                                                                        AS unique_pages_seen_90,
    u90.unique_pageviews                                                                         AS unique_pageviews_90,
    u90.unique_days_seen                                                                         AS unique_days_seen_90,
    DATE_DIFF(DATE_SUB(obs.observed_date, INTERVAL 1 DAY), u90.user_last_seen, DAY)                 AS user_last_seen_days_90,

    -- Timespent
    t30.sum_total_timespent                                                                      AS sum_total_timespent_30,
    t90.sum_total_timespent                                                                      AS sum_total_timespent_90,

    -- Delta features (30d vs prev-30d)
    COALESCE(u30.unique_articles_seen, 0) - COALESCE(up30.unique_articles_seen, 0)               AS unique_articles_seen_30d_vs_prev_30d_change,
    COALESCE(u30.unique_days_seen, 0)     - COALESCE(up30.unique_days_seen, 0)                   AS unique_days_seen_30d_vs_prev_30d_change,

    -- Content diversity (article pageviews only)
    COALESCE(cdiv.distinct_tags_30d,    0) AS distinct_tags_30d,
    COALESCE(cdiv.distinct_tags_90d,    0) AS distinct_tags_90d,
    COALESCE(cdiv.distinct_authors_30d, 0) AS distinct_authors_30d,
    COALESCE(cdiv.distinct_authors_90d, 0) AS distinct_authors_90d,
    COALESCE(cdiv.distinct_devices_30d, 0) AS distinct_devices_30d,
    tts.top_tag_share_30d                  AS top_tag_share_30d,
    tts.top_tag_share_90d                  AS top_tag_share_90d,

FROM observations obs
         LEFT JOIN unique_30        u30  USING (user_id, observed_date)
         LEFT JOIN unique_90        u90  USING (user_id, observed_date)
         LEFT JOIN unique_prev_30   up30 USING (user_id, observed_date)
         LEFT JOIN timespent_30     t30  USING (user_id, observed_date)
         LEFT JOIN timespent_90     t90  USING (user_id, observed_date)
         LEFT JOIN content_diversity cdiv USING (user_id, observed_date)
         LEFT JOIN top_tag_share    tts  USING (user_id, observed_date);
