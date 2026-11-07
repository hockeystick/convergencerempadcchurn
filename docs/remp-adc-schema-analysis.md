# REMP, the churn prediction module and the ADC schema

This document covers five things. How REMP stores and moves audience data. What the REMP CRM churn prediction module needs from that data. What the Audience Data Commons (ADC) schema models. How REMP fields map onto ADC. What a future streaming design would have to solve.

Everything stated as fact was checked in the code in this repository. Statements that go beyond the code are marked `Inferred`. Where a line number is given, it refers to the file as checked in.

## 0. Scope and conventions

### 0.1 What is in the repository

| Folder | Contents |
|---|---|
| `remp-master/` | REMP Beam (analytics, Laravel plus two Go services), Mailer (Nette), Campaign (Laravel), Sso (Laravel), shared PHP libraries under `Composer/`, the shared browser library under `Package/remp`, a `Docker/` folder with Telegraf, Elasticsearch, MySQL, nginx, PHP and adminer files, and a `docker-compose.yml` that also defines Kafka and Zookeeper services |
| `crm-churn-prediction-module-master/` | The REMP CRM churn prediction module, a PHP package installed into the CRM, with BigQuery SQL |
| `adc-schema-main/` | The ADC JSON-LD schema, eight taxonomies and a validator script |

### 0.2 What is not in the repository

The REMP CRM application itself is not present. `remp-master/` holds the application folders `Beam`, `Campaign`, `Composer`, `Docker`, `Mailer`, `Package` and `Sso`, a `changelogs/` folder, and top level files (README, CHANGELOG, Makefile, docker-compose files and install scripts). No `Crm\` namespace exists under `remp-master`. The only `Crm\` namespace in the repository is `Crm\ChurnPredictionModule` inside the churn module (`crm-churn-prediction-module-master/src/Models/BigQueryConfig.php`, line 3).

To close that gap, the CRM was read from its public source: the `remp2020/crm-skeleton` repository and the nineteen `remp2020/crm-*-module` repositories it requires, cloned read only on 2026-11-07 at their latest commits (dated 2026-11-06). Section 1.8 describes their schema. CRM tables named elsewhere in this document are therefore verified against those migrations, and citations into them carry the repository name first, for example `crm-users-module/src/migrations/...`. Two REMP facing CRM packages are not in that set and were not read: `crm-remp-campaign-module` and whatever module bridges CRM commerce events to the Beam Tracker.

The `beam-bigquery-export` tool that loads Beam events into BigQuery is also not in the repository. It is referenced only in the churn module README (`crm-churn-prediction-module-master/README.md`, lines 27, 217 and 218). A case-insensitive search for `bigquery` across `remp-master/` and `adc-schema-main/` returns nothing.

### 0.3 Path shorthands

| Shorthand | Expands to |
|---|---|
| `beam-module/` | `remp-master/Beam/extensions/beam-module/` |
| `beam-go/` | `remp-master/Beam/go/` |
| `mailer-module/` | `remp-master/Mailer/extensions/mailer-module/src/` |
| `campaign-module/` | `remp-master/Campaign/extensions/campaign-module/` |
| `churn/` | `crm-churn-prediction-module-master/` |
| `adc/` | `adc-schema-main/` |
| `crm-<name>-module/` | the `remp2020/crm-<name>-module` repository, cloned outside this repository (see 0.2) |

Beam migrations live in `beam-module/database/migrations/` (78 files) plus one in `remp-master/Beam/database/migrations/`. Mailer migrations live in `mailer-module/migrations/` (86 files for Phinx, the PHP migration tool Mailer uses). Campaign migrations live in `campaign-module/database/migrations/` (69 files). SSO has three migrations in `remp-master/Sso/database/migrations/`. The churn module has one migration in `churn/src/migrations/`.

## 1. How REMP designed its data schema

### 1.1 The shape of the system

REMP is four applications with separate databases and no shared user table.

| Component | Store | What it holds |
|---|---|---|
| Beam Tracker (Go) | none, writes to Kafka or Pub/Sub | Receives browser and server events |
| Telegraf plus Elasticsearch | Elasticsearch indexes | Raw events, one document per pageview, commerce step, custom event, entity or impression |
| Beam Segments (Go, also called Journal) | reads Elasticsearch and Beam MySQL | Segment evaluation and aggregate queries over events |
| Beam admin (Laravel) | MySQL database `beam` | Articles, authors, sections, tags, conversions, aggregates, segments, config |
| Mailer (Nette) | MySQL plus Redis | Newsletter lists, per-list consent, templates, jobs, send logs |
| Campaign (Laravel) | MySQL plus Redis | Campaigns, banners, variants, schedules, hourly stats |
| Sso (Laravel) | MySQL plus Redis | Staff logins and machine API tokens |
| CRM (separate repositories, section 1.8) | MySQL plus Redis | Users, addresses, subscriptions, payments, recurring payments, segments, scenarios, sales funnels, shop orders |

No component stores a central reader record. The publisher's page sets the CRM user id in the browser library, which forwards it unchanged. Beam stores it as a string column and Mailer as an integer column. Section 1.7 covers this in detail.

### 1.2 Beam MySQL schema

The compiled schema dump at `beam-module/database/schema/mysql-schema.sql` contains 40 tables. Every table uses InnoDB with `utf8mb4_unicode_ci`. Almost every table has an auto increment integer primary key `id`. The exceptions are `views_per_browser_mv` (primary key `browser_id`, a string) and `views_per_user_mv` (primary key `user_id`, a string).

Three migrations listed in the dump do not exist on disk: `2019_01_29_142418_seed_config_values`, `2019_08_15_124622_seed_config_values2` and `2020_08_11_082125_alter_configs_remove_nullable_config_category`. Inferred: they were squashed into the dump.

#### Tenancy: accounts and properties

| Table | Created by | Keys and notes |
|---|---|---|
| `accounts` | `2017_03_29_134957_accounts_table.php` | `id`, `uuid` (char 36), `name`. Index on `uuid` from `2017_09_07_124520_uuid_indices.php`. Not unique. |
| `properties` | `2017_04_04_060513_properties_table.php` | `id`, `uuid`, `name`, `account_id` FK to `accounts.id`. Index on `uuid`. |

The property `uuid` is the "property token" that the browser sends as `system.property_token`. The Tracker validates it against `SELECT * FROM properties WHERE uuid = ?` (`beam-go/model/property.go`, line 47). Pageview, commerce and custom event documents in Elasticsearch carry it as the field `token` (`beam-go/cmd/tracker/controller/track.go`, line 359). Entity documents are validated against it but do not store it (`track.go`, line 253). Impression documents neither validate nor store it: the `Impressions` payload has no `system` block (`design/user_types.go`, lines 192 to 200). Inferred: this is the only tenant key in REMP.

#### Articles and taxonomy

| Table | Created by | Keys and notes |
|---|---|---|
| `articles` | `2018_02_12_112807_articles_table.php` | `external_id` made UNIQUE by `2018_02_16_115820_unique_article_ids.php`. `property_uuid` FK to `properties.uuid`. `url` (varchar 768, not unique), `title`, `content_type` (added by `2020_08_27_111750_add_content_type_to_articles.php`, NOT NULL with no column default; existing rows were backfilled with `article` and the v2 API falls back to `Article::DEFAULT_CONTENT_TYPE`, which is `article`), `image_url`, `published_at`, and six cached totals `pageviews_all`, `pageviews_signed_in`, `pageviews_subscribers`, `timespent_all`, `timespent_signed_in`, `timespent_subscribers`. |
| `article_titles` | `2019_01_03_142538_create_article_titles.php` | `article_id` FK, `variant`, `title` (nullable since `2019_10_03_181106`, NULL marks a deleted variant). Append only history of title variants. |
| `authors`, `sections` | `2018_02_12_112807_articles_table.php` | `id`, `name`. Nullable indexed `external_id` added by `2020_03_18_122311_add_external_id_column_to_sections_tags_authors_tables.php`. |
| `tags` | `2020_01_31_140157_article_tags.php` | Same shape as authors. |
| `tag_categories` | `2021_05_06_135333_create_tag_categories_table.php` | `name`, `external_id` (NOT NULL, no index). |
| `article_author`, `article_section` | `2018_02_12_112807_articles_table.php` | Pivots. No unique pair index. |
| `article_tag` | `2020_01_31_140157_article_tags.php` | Pivot. |
| `tag_tag_category` | `2021_05_06_142318_create_tag_tag_categories_table.php` | Pivot. |
| `article_meta` | `remp-master/Beam/database/migrations/2023_09_07_080805_create_article_meta_table.php` | `article_id` FK, `key`, `value`. Written by the gender balance job with keys `article_image_men_count` and `article_image_women_count`. |

Articles arrive over HTTP. `beam-module/src/Http/Controllers/Api/v2/ArticleController.php` does `Article::updateOrCreate(['external_id' => ...])`. Authors, sections and tags are matched by `external_id` first, then by `name` when the stored row has no `external_id`. Pivot rows are detached and re-attached on every upsert.

The article's CMS id (`articles.external_id`) is the join key between events and MySQL. Every aggregation command resolves the Elasticsearch `article_id` tag against `articles.external_id`: `Article::whereIn('external_id', ...)` in the pageview, timespent, article views and dashboard commands, and `Article::where('external_id', ...)` in `AggregateConversionEvents.php` and `ProcessConversionSources.php`. The `url` column is not a join key for events; the article detail routes accept it as an alternative lookup (`beam-module/src/Http/Controllers/ArticleDetailsController.php`, lines 379 and 418).

#### Pageview aggregates

| Table | Created by | Grain and writer |
|---|---|---|
| `article_pageviews` | `2018_02_15_065425_article_pageviews.php`; `signed_in` and `subscribers` columns from `2018_03_26_221138_article_pageviews_signed_in_subscriber_columns.php` | One row per article per 20 minute window: `article_id`, `time_from`, `time_to`, `sum`, `signed_in`, `subscribers`. Written by `pageviews:aggregate-load` (`beam-module/src/Console/Commands/AggregatePageviewLoadJob.php`). Compressed to one row per day after 90 days by `pageviews:compress-aggregations`. |
| `article_timespents` | `2018_02_20_083104_article_timespent.php`; `2018_03_29_122457_article_timespent_signed_subscribed.php` | Same shape, sums of seconds. Written by `pageviews:aggregate-timespent`. |
| `article_aggregated_views` | `2018_07_11_133723_create_article_aggregated_views_table.php`, dropped and recreated by `2019_01_31_142723_create_article_aggregated_views_with_id_table.php` | One row per `(article_id, user_id, browser_id, date)` with `pageviews` and `timespent`. UNIQUE on those four columns. Written by `pageviews:aggregate-articles-views`. Rows older than 30 days are deleted by the same command. |
| `views_per_browser_mv`, `views_per_user_mv` | `2018_08_01_112202_create_views_per_browser_mv_table.php`, `2018_08_02_065721_create_views_per_user_mv_table.php` | 30, 60 and 90 day totals per browser or user. Rebuilt only when `pageviews:aggregate-articles-views` runs without `--skip-temp-aggregation`. The scheduler passes that flag, so the scheduler never refreshes them. |
| `article_views_snapshots` | `2019_06_13_111140_create_article_views_snapshots_table.php`, trimmed by `2020_02_18_201353_remove_unused_columns_in_article_views_snapshots.php` | Concurrent readers per minute: `time`, `property_token`, `external_article_id`, `referer_medium`, `count`. Written by `pageviews:snapshot` every minute. Thinned by `pageviews:compress-snapshots` using the retention rules in `beam-module/src/Helpers/Journal/JournalInterval.php`. |
| `dashboard_articles` | `2022_02_25_130852_dashboard_articles.php` | `article_id`, `unique_browsers`, `last_dashboard_time`. |
| `session_devices`, `session_referers` | created by `2018_03_22_105849_pageview_devices_and_referers.php`, dropped by `2024_07_29_045343_drop_pageview_devices_and_referers.php` | No longer exist. |

`article_aggregated_views` is the only MySQL table that holds a per user or per browser time series, and it is kept for 30 days. Inferred: any feature pipeline that wants longer per user history must read Elasticsearch or the BigQuery export.

#### Conversions

RTM parameters are REMP's campaign attribution fields: `rtm_source`, `rtm_medium`, `rtm_campaign`, `rtm_content` and `rtm_variant`. They are REMP's equivalent of UTM query parameters and appear as `rtm_*` columns and event fields throughout the stack.

| Table | Created by | Keys and notes |
|---|---|---|
| `conversions` | `2018_02_14_074236_article_conversions.php` | `transaction_id` UNIQUE. `article_id` FK NOT NULL. `user_id` string nullable, indexed, added by `2018_09_03_133431_add_user_id_to_conversions_table.php`. `amount`, `currency`, `paid_at` (NOT NULL since `2020_10_09_131803`). Flags `events_aggregated` and `source_processed`. Never deleted. |
| `conversion_pageview_events` | `2018_12_10_114720_create_conversion_pageview_events_table.php` | `conversion_id` FK, `time`, `minutes_to_conversion`, `event_prior_conversion`, `article_id` FK, `locked`, `signed_in`, `timespent`, `rtm_*`. |
| `conversion_commerce_events` | `2018_12_10_114711_create_conversion_commerce_events_table.php` | Plus `step`, `funnel_id`, `amount`, `currency`. |
| `conversion_commerce_event_products` | `2018_12_10_123942_create_conversion_commerce_event_products_table.php` | `product_id` per commerce event, cascade delete. |
| `conversion_general_events` | `2018_12_10_122114_create_conversion_general_events_table.php` | Plus `action`, `category`. |
| `conversion_sources` | `2020_07_28_091554_create_conversion_sources_table.php` | `type` is `session_first` or `session_last`, `referer_medium`, `referer_source`, `referer_host_with_path`, `article_id`. |

The `utm_*` columns on the three event tables were renamed to `rtm_*` by `2020_12_04_145757_convert_utm_to_rtm.php`. Event rows older than 90 days are deleted daily by `data:delete-old-aggregations`.

A conversion arrives through `beam-module/src/Http/Controllers/ConversionController.php` with `article_external_id`, `transaction_id`, `amount`, `currency`, `paid_at` and `user_id`. The upsert is idempotent on `transaction_id`. Inferred: `transaction_id` is the CRM payment reference and `user_id` is the CRM user id. The evidence is the Tracker's commerce payload, which describes `transaction_id` as "Public ID of transaction (variable symbol)" (a bank transfer reference), and `ProcessConversionSources`, which matches `conversions.transaction_id` against commerce events.

The conversion event tables are one of two places in Beam that pair browsers with users; `article_aggregated_views` also stores `user_id` and `browser_id` on the same row for 30 days (`AggregateArticlesViews.php`, lines 181, 246 and 316 to 317). `beam-module/src/Console/Commands/AggregateConversionEvents.php` asks the Journal for all `browser_id` values that appeared on events carrying the conversion's `user_id` in the previous 14 days, then lists pageviews by those browsers. No table keeps this mapping beyond those 30 days.

#### Segments

| Table | Created by | Keys and notes |
|---|---|---|
| `segments` | `2017_06_27_072000_create_segments_table.php` | `code` UNIQUE (`2017_12_01_094140_unique_codes.php`), `name`, `active`, `segment_group_id` FK NOT NULL (`2018_07_20_102942_create_segment_groups_table.php`), `criteria` JSON (`2019_01_24_152049_segments_add_criteria.php`). |
| `segment_rules` | `2017_06_27_072000_create_segments_table.php` | `segment_id` FK, `event_category`, `event_action` (renamed from `event_name` by `2017_08_23_071800_event_name_to_action.php`), `timespan` in minutes, `count`, `operator` (`2017_09_20_135555_segment_rule_operator.php`), `fields` JSON, `flags` JSON (`2017_09_29_080257_segment_rule_flags.php`). |
| `segment_groups` | `2018_07_20_102942_create_segment_groups_table.php` | `code`, `type` (`rule` or `explicit`), `sorting`. Seeded codes `remp-segments`, `authors-segments`, `sections-segments`. |
| `segment_users` | `2018_07_12_075004_create_segment_users_table.php` | `segment_id` FK, `user_id` string. No unique index, no index on `user_id`. |
| `segment_browsers` | `2018_07_24_111455_create_segment_browsers_table.php` | `segment_id` FK, `browser_id` string. |

Rule segments are evaluated live by the Go Segments service. Explicit segments are membership lists that `segments:compute-author-segments` and `segments:compute-section-segments` rebuild daily from `article_aggregated_views`.

#### Other tables

| Table | Created by | Purpose |
|---|---|---|
| `entities`, `entity_params` | `2018_08_15_162705_create_segment_entities_table.php`, `2018_10_01_071044_create_entity_params_table.php` | Publisher defined entity schemas for the `/track/entity` endpoint. The seeder creates entity `user` with params `id` and `email`. |
| `newsletters` | `2018_06_08_073818_create_newsletters_table.php` | Beam to Mailer bridge. `segment` holds `provider::code`, `mailer_generator_id` and `mail_type_code` reference Mailer objects by value. |
| `configs`, `config_categories` | `2018_11_29_091254_create_configs_table.php`, `2019_09_05_093404_create_config_categories_table.php` | Thresholds for dashboards and author or section segments. UNIQUE `(name, property_id)`. |
| `referer_medium_labels` | `2019_12_03_123733_create_referer_medium_labels_table.php` | Display labels. |
| `jobs`, `failed_jobs`, `migrations` | `2018_08_22_075217_create_jobs_table.php`, `2018_08_22_075309_create_failed_jobs_table.php` | Laravel queue. |

There is no `users` table in Beam. `remp-master/Beam/config/auth.php` defines three guards: `jwt` (driver `jwt`), `web` (driver `session`) and `api` (driver `token`, no provider). Inferred: administrators authenticate through the external SSO and the API through SSO tokens, so Beam never stores people.

### 1.3 Beam event pipeline

The chain is: browser library, Tracker, Kafka or Pub/Sub, Telegraf (a metrics agent that reads Kafka and writes Elasticsearch), Elasticsearch, Segments (Journal), Laravel aggregation into MySQL, and outside this repository a daily export to BigQuery.

#### The browser library (remplib.js)

The shared identity code lives in `remp-master/Package/remp/js/remplib.js` (npm `@remp/js-commons`). The Beam tracker is `beam-module/resources/assets/js/remplib.js`.

| Identifier | Where it comes from | Storage key and lifetime |
|---|---|---|
| `browser_id` | `rempConfig.browserId` if the publisher sets it, else a UUIDv4 generated in the browser | localStorage key `browser_id` plus a parallel cookie, 1,051,200 minutes (2 years), lines 28 and 117 to 134 |
| `remp_session_id` | UUIDv4 | key `remp_session_id`, 15 minute sliding lifetime (reads extend it), lines 136 to 149 |
| `remp_pageview_id` | UUIDv4 per page load | in memory only, lines 158 to 168 |
| `commerce_session_id` | UUIDv4 generated at checkout | key `commerce_session_id`, lines 170 to 184 |
| `userId`, `userSubscribed`, `subscriptionIds` | only from `rempConfig`, set by the publisher's page | not stored by the library, lines 46 to 54 and 105 to 115 |

There is no login detection in the library. Whatever the page puts in `rempConfig.userId` is sent as is, with no type coercion.

Every tracking call builds two blocks in `addSystemUserParams` (`beam-module/resources/assets/js/remplib.js`, lines 690 to 756). The `system` block holds `property_token` and `time` as `new Date().toISOString()`. The `user` block holds `id`, `browser_id`, `subscriber`, `subscription_ids`, `url`, `canonical_url`, `referer`, `user_agent`, `adblock`, window size, `cookies`, `websockets`, `source` with `rtm_*`, `remp_session_id` and `remp_pageview_id`. The library never sends `ip_address`. The event time is the client clock.

The library sends these calls:

| Call | Endpoint | Notes |
|---|---|---|
| `trackPageview()` | `POST /track/pageview`, `action: "load"` | On init and on re-init in single page apps |
| `trackTimespent()` | `POST /track/pageview`, `action: "timespent"` | Off by default. Sends the cumulative `totalTimeSpent` for the pageview on a widening schedule (every `round(0.3 * sqrt(totalTimeSpent)) * timeSpentInterval` seconds) and once on `beforeunload` (lines 453 to 470, 869 to 871, 901 to 906) |
| `sendTrackedProgress()` | `POST /track/pageview`, `action: "progress"` | Off by default. `page_ratio`, `article_ratio` (lines 971 to 994) |
| `trackEvent(category, action, tags, fields, source, value)` | `POST /track/event` | Generates `remp_event_id` (lines 422 to 441) |
| `trackCheckout`, `trackPayment`, `trackPurchase`, `trackRefund` and the `...WithSource` variants | `POST /track/commerce` | New `remp_commerce_id` per call, shared `commerce_session_id` (lines 472 to 658) |
| impressions.js | `POST /track/impressions` | Elements at least 50 percent visible for `itemMinVisibleDuration` (default 2 seconds) |

The tracker's own calls use `XMLHttpRequest` (`post`, lines 665 to 678). impressions.js uses `fetch` on an interval and `navigator.sendBeacon` when the page becomes hidden. Inferred: the tracker's sends on `beforeunload` are best effort because the browser can cancel an in-flight request.

#### The Tracker (Go)

The Tracker is a Go service built with goa v3, a framework that generates the HTTP layer from design files in `beam-go/cmd/tracker/design/`. Endpoints (`design/resources.go`, lines 19 to 94):

| Endpoint | Payload type | Required |
|---|---|---|
| `POST /track/pageview` | `Pageview` | `system`, `user`, `action` in `load`, `timespent`, `progress` |
| `POST /track/commerce` | `Commerce` | `system`, `user`, `step` in `checkout`, `payment`, `purchase`, `refund` |
| `POST /track/event` | `Event` | `system`, `category`, `action` |
| `POST /track/entity` | `Entity` | `entity_def` with `id`, `name`, `data`; `system` |
| `POST /track/impressions` | `Impressions` | `rpid`, `d` |

The payload types are in `beam-go/cmd/tracker/design/user_types.go`.

| Type | Lines | Fields | Required |
|---|---|---|---|
| `System` | 34 to 42 | `property_token` (UUID), `time` (RFC 3339) | both |
| `User` | 7 to 32 | `id` ("ID of logged user"), `subscriber`, `subscription_ids`, `browser_id` ("Anonymized ID of user's browser"), `url`, `canonical_url`, `adblock`, `window_height`, `window_width`, `cookies`, `websockets`, `user_agent`, `ip_address`, `source`, `remp_session_id`, `remp_pageview_id`, `referer`, `explicit_referer_medium` | none |
| `Article` | 94 to 106 | `id`, `category`, `tags`, `author_id`, `content_type`, `locked`, `variants` | `id` |
| `Source` | 133 to 148 | `rtm_source`, `rtm_medium`, `rtm_campaign`, `rtm_content`, `rtm_variant`, plus deprecated `utm_*` and `banner_variant` | none |
| `TimeSpent` | 108 to 115 | `seconds`, `unload` | `seconds` |
| `Progress` | 117 to 131 | `page_ratio`, `article_ratio`, `unload` | `page_ratio` |
| `CommerceCheckout` | 150 to 154 | `funnel_id` | `funnel_id` |
| `CommercePayment` | 156 to 163 | `funnel_id`, `transaction_id`, `product_ids`, `revenue` (`amount`, `currency`) | `transaction_id`, `product_ids`, `revenue` |
| `Event` | 77 to 92 | `category`, `action`, `value`, `tags`, `fields`, `remp_event_id`, `article_id` | `category`, `action` |

The controller (`beam-go/cmd/tracker/controller/track.go`) turns a payload into one flat document:

| Derived field | Source | Lines |
|---|---|---|
| `token` | `system.property_token` | 359 |
| `user_id` tag and `signed_in = true` | present only when `user.id` is set; otherwise `signed_in = false` | 481 to 486 |
| `browser_id`, `remp_session_id`, `remp_pageview_id` tags | `user.*` | 488 to 497 |
| `subscriber`, `subscription_ids` | `user.*` | 500, 491 |
| `derived_ua_device`, `derived_ua_os`, `derived_ua_os_version`, `derived_ua_platform`, `derived_ua_browser`, `derived_ua_browser_version` | the uasurfer user agent parser applied to `user.user_agent` | 375 to 380 |
| `derived_referer_medium`, `derived_referer_source`, `derived_referer_host_with_path` | referer parser; `direct` when missing, `internal` when the host matches the current URL or `TRACKER_INTERNAL_HOSTS`, `email` when `rtm_medium` is `email`, overridden by `explicit_referer_medium` | 384 to 464 |
| `rtm_*` tags | `source.rtm_*` with `utm_*` fallback | 416 to 459 |
| `article_id`, `author_id`, `category` tags; `locked`, `tags` (comma joined), `content_type`, `is_article` fields | `article.*` | 321 to 354 |

Each endpoint writes one measurement. Pageview `load` writes `pageviews`. Pageview `timespent` writes `pageviews_time_spent` with the field `timespent`, capped by `TRACKER_TIMESPENT_LIMIT`. Pageview `progress` writes `pageviews_progress`. The other endpoints write `commerce`, `events_v2`, `entities` and `impressions`. The names are constants in `beam-go/model/pageview.go` (lines 8 to 20), `event.go` (line 9), `commerce.go` (lines 9 to 11) and `entity.go` (line 5). The event timestamp is parsed from `system.time` with `time.Parse(time.RFC3339, ...)`, so it is client supplied. The entity endpoint never calls `payloadToTagsFields`, so entity documents carry no `token`, `user_id` or `browser_id` (lines 273 to 281).

#### Kafka or Pub/Sub

`pushInternal` (`track.go`, lines 510 to 529) marshals the merged map to JSON and sends it as a single field `_json`. `beam-go/cmd/tracker/controller/kafka_event_producer.go` wraps that in an Influx line protocol point and sends it to the topic `beam_events` (lines 56 to 66). Influx line protocol is the InfluxDB text format: one measurement name, a set of fields and a timestamp per line. The point is built with `influxClient.NewPoint(table, nil, data, time)`, so it carries no Influx tags at all, only the `_json` field. No Kafka message key is set. The producer uses `WaitForLocal` acknowledgements (the broker confirms after writing its own log, before replication), Snappy compression and a 500 ms flush. The Pub/Sub producer in `pubsub_event_producer.go` sends the same line as the message data. The dev topic is created as `beam_events:1:1` (`remp-master/docker-compose.yml`, line 215), one partition and one replica.

Inferred: with no message key, ordering is only guaranteed within a partition, and with the dev topic that means globally. A production topic with more partitions would not preserve order per browser or user.

#### Telegraf to Elasticsearch

`remp-master/Docker/telegraf/telegraf.conf` consumes `beam_events` with consumer group `beam_consumers`, offset `oldest`, data format `influx`, batch size 1000 and a 1 second flush. The outputs are a custom `remp_elasticsearch` plugin from the `remp2020/telegraf` fork (image `remp/telegraf:1.31.1`), whose source is not in this repository.

| Measurement | Index | Document id | Special handling |
|---|---|---|---|
| `pageviews` | `pageviews` | `remp_pageview_id` | |
| `pageviews_time_spent` | `pageviews_time_spent` | `remp_pageview_id` | `updated_fields = ["timespent"]`, whitelist `token`, `subscriber`, `signed_in`, `locked`, `timespent`, `unload` |
| `pageviews_progress` | `pageviews_progress` | `remp_pageview_id` | `updated_fields = ["page_progress", "article_progress"]`, whitelist `token`, `subscriber`, `signed_in`, `locked`, `page_progress`, `article_progress`, `unload` |
| `events_v2` | `events` | `remp_event_id` | |
| `commerce` | `commerce` | `remp_commerce_id` | |
| `pageviews_time_spent` | `concurrents_by_browser` | `browser_id` | Only the last activity per browser: `time`, `article_id`, `token`, `derived_referer_*`, `url`, `canonical_url`, `derived_ua_device` |
| `impressions` | `impressions` | `remp_pageview_id_block_type` | `added_fields = ["element_ids"]` |
| `entities` | `entities` | `remp_entity_id` | |

Inferred from the config options: timespent and progress are not append only events. Each ping overwrites the `timespent` or progress fields of the single document keyed by `remp_pageview_id`, so Elasticsearch holds the latest cumulative value per pageview. And `concurrents_by_browser` holds one document per browser.

Both producers emit the point with no tags and one `_json` field. Whether the plugin re-splits `_json` into tags and fields before applying `field_whitelist` cannot be confirmed without its source. The churn SQL joins `pageviews_time_spent.user_id`, and the Laravel jobs group timespent by `article_id`, `user_id` and `browser_id`, so those keys are present in the index in practice (inferred from those consumers working).

Elasticsearch is version 8.6.1 in Docker (`remp-master/Docker/elasticsearch/Dockerfile`). Mappings are dynamic except that the Segments service pushes `subscriber` and `signed_in` as booleans on the three pageview indexes at start (`beam-go/cmd/segments/main.go`, lines 246 to 276; an explicit `revenue` mapping for `commerce` is commented out at line 252). The Docker start script `remp-master/Docker/elasticsearch/create-indexes.sh` (line 5) tries to declare `revenue` as `double` on `commerce`, but it posts to `/commerce/_doc`, so it indexes a document rather than setting a mapping. For filters the Segments service checks the index mapping and uses the `.keyword` subfield when it exists, falling back to the plain field otherwise (`beam-go/model/db_elastic.go`, lines 657 to 677). The unique count queries hardcode `user_id.keyword` and `browser_id.keyword` (`pageview_elastic.go`, lines 211 to 220).

REMP ships no automated Elasticsearch retention. There is no lifecycle policy or delete job under `remp-master/Docker/elasticsearch/` or in the Segments service. The Beam README section "Elasticsearch shards are getting too big" (`remp-master/Beam/README.md`, lines 657 to 782) tells operators to roll indexes over behind aliases and delete old ones by hand. The Laravel commands `service:elastic-write-alias-rollover` and `service:elastic-data-retention` exist for that (`beam-module/src/Console/Commands/ElasticDataRetention.php`) and are not in the scheduler. Inferred: the raw event history available at any site is whatever its operator kept.

#### Segments and the Journal API

The Segments service (`beam-go/cmd/segments/`) is an API over Elasticsearch and the Beam MySQL segment tables. All routes are reads except `POST /segments/detail`, which creates or updates a segment. Routes are in `design/resources.go`.

Segment routes: `GET /segments/`, `GET /segments/show`, `GET /segments/groups`, `GET /segments/{segment_code}/users/check/{user_id}`, `GET /segments/{segment_code}/browsers/check/{browser_id}`, `GET /segments/{segment_code}/users`, `GET /segments/criteria`, `POST /segments/detail`, `POST /segments/count`, `POST /segments/related`.

Journal routes: `POST /journal/pageviews/actions/{action}/count|sum|avg|unique/{item}`, `POST /journal/pageviews/list`, `POST /journal/commerce/steps/{step}/count|sum`, `POST /journal/commerce/count|sum|list`, `POST /journal/events/categories/{category}/actions/{action}/count`, `POST /journal/events/count|list`, `POST /journal/concurrents/count`, `GET /journal/flags`, and category and action listings. Request bodies carry `filter_by` (list of `{tag, values, inverse}`), `group_by`, `time_after`, `time_before` and optional histograms (`design/user_types.go`).

A rule segment is "count of `event_category`/`event_action` events in the last `timespan` minutes, filtered by `fields` and `flags`, compared with `operator` to `count`" (`beam-go/model/segment_rule.go`). `event_category` `pageview` reads the `pageviews` index, `commerce` reads `commerce`, anything else reads `events` (`getRuleEventCount`, `segment.go` lines 409 to 439). The flag `is_article` becomes an exists filter on `article_id`.

Identity resolution in Segments is strict: `CheckUser` filters on the `user_id` tag and `CheckBrowser` on the `browser_id` tag (`segment.go`, lines 319 to 351). Nothing joins the two tags, so a user and the same person's anonymous browser are evaluated separately. `GET /segments/{segment_code}/users` groups by `user_id` (`segment.go`, line 506), so anonymous browsers never appear in a user list.

Callers: Beam Laravel uses the PHP client `remp-master/Composer/remp-commons/src/journal/Journal.php`. For count, sum, avg, unique and concurrents calls it rounds times down to 10 seconds, adds a `token` filter for the selected property and caches responses in Redis for 60 seconds. List calls are sent unrounded, without the token filter and uncached. Campaign calls the check endpoints at showtime (the request the browser sends to Campaign on each page load to decide which banners to show, see 1.5) and the Journal for banner stats. Mailer calls `segments` and `segments/{code}/users` and lists `commerce` purchases with `rtm_medium = email` to attribute newsletter conversions.

#### Laravel aggregation into MySQL

`beam-module/src/Scheduler.php` registers these jobs.

| Command | Schedule | Reads | Writes |
|---|---|---|---|
| `pageviews:aggregate --now='+20 minutes'` | every minute | Journal `pageviews/load` count and `pageviews/timespent` sum grouped by `article_id`, `signed_in`, `subscriber` | `article_pageviews`, `article_timespents`, `articles.pageviews_*`, `articles.timespent_*` |
| `pageviews:aggregate` | `1-59/20 * * * *` | same, closed 20 minute window | same |
| `pageviews:snapshot` | every minute | Journal `concurrents/count` grouped by `article_id`, `derived_referer_medium`, `token` | `article_views_snapshots` |
| `pageviews:aggregate-articles-views --skip-temp-aggregation` | daily 01:00 | Journal counts and sums grouped by `article_id`, `user_id`, `browser_id` for yesterday | `article_aggregated_views` |
| `segments:compute-author-segments` | daily 02:00 | `article_aggregated_views` joined to `article_author` | `segments`, `segment_users`, `segment_browsers` |
| `segments:compute-section-segments` | daily 03:00 | same with `article_section` | same |
| `conversions:aggregate-events` | daily 03:30 | Journal lists by `browser_id` and `user_id` | `conversion_*_events` |
| `pageviews:compress-snapshots` | daily 02:30 | | thins `article_views_snapshots` |
| `data:delete-old-aggregations` | daily 00:10 | | deletes `conversion_*_events` rows older than `AGGREGATED_DATA_RETENTION_PERIOD` days (default 90) |
| `pageviews:compress-aggregations` | daily 00:20 | | rolls `article_pageviews` and `article_timespents` 20 minute rows older than the retention period (default 90 days) into daily rows |
| `dashboard:refresh` | every minute | Journal unique browsers | `dashboard_articles` |
| `newsletters:send` | every minute | `newsletters` | Mailer jobs over HTTP |

MySQL therefore holds aggregates keyed by article, 20 minute window, day, user and browser. In this repository, raw per event history is written only to Elasticsearch. The BigQuery copy is produced by the external export tool.

#### BigQuery export

Nothing in `remp-master/` references BigQuery. The churn module README says the BigQuery dataset "must contain `pageviews` and `pageviews_time_spent` tables" loaded "from Beam's Elasticsearch with beam-bigquery-export" (`churn/README.md`, line 27). Its cron example runs that tool at 03:05 to "export yesterday's pageviews data to BigQuery" (lines 217 to 218). Inferred: the export is a daily batch of the two Elasticsearch indexes into BigQuery tables with the same names and flat columns. The columns the churn SQL reads are listed in section 2.3.

### 1.4 Mailer schema

Mailer uses MySQL through Nette Database, and Redis for Hermes (Mailer's internal asynchronous message queue) and for the send cache. The repositories under `mailer-module/Repositories/` each declare a `$tableName`.

#### Lists, templates and jobs

| Table | Created by | Keys and notes |
|---|---|---|
| `mail_type_categories` | `20170829092343_initial_migration.php` | `code` UNIQUE (`20210602094201`). Seeded `newsletters` and `system`. |
| `mail_types` | `20170829092343_initial_migration.php` | A newsletter list. `code` UNIQUE (`20210528085728`), `locked`, `auto_subscribe`, `is_multi_variant`, `priority`, `mail_type_category_id` FK, `mailer_alias`, `subscribe_mail_template_id` and `unsubscribe_mail_template_id` FKs, `deleted_at`. |
| `mail_type_variants` | `20170829092343_initial_migration.php` | `mail_type_id` FK, `code`, `title`, `deleted_at`. |
| `mail_layouts`, `mail_layout_translations` | initial migration; `20220308090027` | Layout HTML and text, per locale. |
| `mail_snippets`, `mail_snippet_translations` | `20210217101856_snippets.php`; `20220308090047` | Reusable fragments. |
| `mail_templates` | `20170829092343_initial_migration.php` | One email. `code` UNIQUE (`20190213082954`), `public_code` UNIQUE 8 character value (`20210629105924`), `mail_type_id` FK, `mail_layout_id` FK, `extras` JSON, `params` JSON, `click_tracking`, `deleted_at`. |
| `mail_template_translations` | `20220304112945` | Per locale subject and body. |
| `mail_source_template` | `20180314235544_mail_source_template.php` | Generator templates, `code` UNIQUE (`20180726110436`). |
| `mail_jobs` | `20170829092343_initial_migration.php` | A send. `segments` JSON with `include` and `exclude` lists of `{provider, code}` (`20230112144141`, which dropped `segment_code` and `segment_provider`), `mail_type_variant_id`, `status`, `context`. |
| `mail_job_batch` | `20170829092343_initial_migration.php` | `mail_job_id` FK, `method`, `max_emails`, `start_at`, `status`, counters. Statuses are in `mailer-module/Repositories/BatchesRepository.php`, lines 20 to 29. |
| `mail_job_batch_templates` | `20170829092343_initial_migration.php` | `mail_job_id`, `mail_job_batch_id`, `mail_template_id`, `weight`, and counters `sent`, `delivered`, `opened`, `clicked`, `dropped`, `spam_complained`, `hard_bounced` (`20171016121149`), `converted` (`20190130160455`). |
| `mail_job_queue` | `20170829092343_initial_migration.php` | `email`, `mail_batch_id` FK, `mail_template_id` FK, `params` JSON (`20190529135100`, holds the whole user record), `context`. Keyed by email only. |

#### Consent

| Table | Created by | Keys and notes |
|---|---|---|
| `mail_user_subscriptions` | `20170829092343_initial_migration.php`, lines 133 to 142 | `user_id` integer, `user_email`, `mail_type_id` FK, `subscribed` boolean, `rtm_source`, `rtm_medium`, `rtm_campaign`, `rtm_content` (added as `utm_*` by `20171026095546`, renamed by `20201207111939_mail_user_subscription_rename_utm_to_rtm.php`), `created_at`, `updated_at`. `id` became BIGINT in `20221123090507`. No unique key on `(user_email, mail_type_id)` or `(user_id, mail_type_id)` in any migration. |
| `mail_user_subscription_variants` | `20180124064156_mail_user_subscription_types.php` | `mail_user_subscription_id` FK, `mail_type_variant_id` FK, UNIQUE pair. |

One row represents one decision per person per list. Inferred: no row means no decision was recorded.

Three write paths exist. `POST /api/v1/users/user-registered` creates a row for every non deleted list the email has no row for yet, subscribed when `mail_types.auto_subscribe` is set and explicitly unsubscribed otherwise (`mailer-module/Api/v1/Handlers/Users/UserRegisteredHandler.php`, lines 61 to 72). Subscribing stores the `rtm_*` of the request that caused it and emits the Hermes message `user-subscribed`. Unsubscribing flips the flag, overwrites the `rtm_*` columns, deletes variant rows and emits `user-unsubscribed` (`mailer-module/Repositories/UserSubscriptionsRepository.php`, lines 164 to 274).

Each flip overwrites the previous state. There is no history table, no consent text version, and no IP or user agent capture.

Consent in Mailer is per newsletter list and per variant. There is no per channel consent (SMS, push, web) anywhere in Mailer.

#### Logs and stats

| Table | Created by | Keys and notes |
|---|---|---|
| `mail_logs` | `20170829092344_mail_logs.php` | One row per sent email: `email`, `user_id` integer nullable (added by `20221108122614`), `subject`, `mail_template_id`, `mail_job_id`, `mail_job_batch_id`, `mail_sender_id`, `context`, and one timestamp each for `delivered_at`, `dropped_at`, `spam_complained_at`, `hard_bounced_at`, `clicked_at`, `opened_at`. `created_at` is the send time. |
| `mail_log_conversions` | `20190130160455_mail_conversion_tracking.php` | `mail_log_id` FK, `converted_at`. One row per log. |
| `mail_template_stats` | `20190320130956_create_mail_template_stats_table.php` | Daily counters per template. |
| `mail_type_stats` | `20181121203530_create_mail_type_stats_table.php` | Subscriber count snapshots per list. |
| `mail_template_links`, `mail_template_link_clicks` | `20230306183108_create_mail_template_links_table.php` | Per link click counts via the `rtm_click` hash. No person key. |
| `autologin_tokens` | `20180417124934_autologin_tokens.php` | `token` UNIQUE, `email`, `user_id` (never written), validity window, `used_count`, `max_count`. |
| `hermes_tasks` | `20190606062548_adding_hermes_tasks_table.php`, recreated with the current layout by `20190716063513_hermes_retry.php` | Failed async messages only. The live queue is in Redis. |
| `configs` | initial migration | Settings. |

Opens, clicks, bounces and drops come only from the email service provider (Mailgun) through the webhooks `POST /api/v1/mailers/mailgun` (form payload) and `POST /api/v2/mailers/mailgun` (JSON payload), or the polling command `mailgun:events`. Each event is matched to a log row by `mail_sender_id`, which Mailer generates per recipient and passes to Mailgun as a user variable. Each `*_at` column holds one timestamp, so repeated opens overwrite rather than accumulate.

#### How Mailer identifies people

Mailer keeps no user list of its own. `mailer-module/Models/Users/IUser.php` defines `list(array $userIds, int $page, bool $includeDeactivated = false): array` returning `[$userId => ['id' => ..., 'email' => ...]]`. The CRM implementation (`mailer-module/Models/Users/Crm.php`) posts `api/v1/users/list`. Segment providers return user ids only, and Mailer resolves ids to emails before queueing.

`user_id` is an integer in the API schema (`mailer-module/Api/v1/Handlers/Users/subscribe.schema.json`, lines 36 to 38) and in the migrations. `mail_user_subscriptions` is read and written by `(user_email, mail_type_id)` first, with a code comment that user id should also be checked (`UserSubscriptionsRepository.php`, lines 177 to 182). `mail_logs` is keyed by `email` and copies `user_id` from the subscription row at send time.

#### Mailer to Beam

When `Tracker\Remp` is configured as the `ITracker` implementation, Mailer pushes events into the Beam Tracker through `mailer-module/Models/Tracker/Remp.php` (`POST /track/event`). The shipped example config wires `Tests\Unit\NullTracker` instead and leaves the `Remp` tracker commented out (`remp-master/Mailer/app/config/config.local.neon.example`, lines 98 to 101). The user block carries only `id`, cast to string, plus `ip_address`, `url` and `user_agent`, which Mailer leaves empty (`mailer-module/Models/Tracker/User.php`). Events:

| Category | Action | Fields | Emitted by |
|---|---|---|---|
| `mail` | `sent` | `email`, `template_code`, `mail_job_batch_id` | `MailSentEventHandler` on the `MailSentEvent` dispatched by `worker:mail` (`MailWorkerCommand.php`, line 294); the subscription exists only as a commented out line in `config.local.neon.example` (line 106) |
| `mail-type` | `subscribe`, `unsubscribe`, `subscribe-variant` | `mail_type`, `mail_type_variant`, `rtm_*` | `TrackSubscribeUnsubscribeHandler` on Hermes `user-subscribed`, `user-subscribed-variant`, `user-unsubscribed`, registered in `remp-master/Mailer/app/config/config.neon` (lines 35 to 37) |
| `mail-type` | `auto-unsubscribe` | none | `mail:unsubscribe-inactive-users` |
| `newsletter` | mail type code | `article_id`, `mail_template_id`, `mail_job_batch_id` | `TrackNewsletterArticlesHandler`, not registered in any shipped config |

Newsletter links are stamped with `rtm_source` = list code, `rtm_medium` = `email`, `rtm_campaign` = template code and `rtm_content` = batch id (`mailer-module/Models/ContentGenerator/Replace/RtmReplaceTrait.php`, lines 21 to 24). `mail:conversion-stats` later reads `commerce` purchases with `rtm_medium = email` from the Journal, maps Beam `user_id` to email through the CRM, and fills `mail_log_conversions`.

### 1.5 Campaign schema

Campaign's schema dump at `campaign-module/database/schema/mysql-schema.sql` lists 23 tables.

| Table | Created by | Keys and notes |
|---|---|---|
| `campaigns` | `2017_05_26_090050_banner_campaigns.php` | `uuid`, `public_id` (6 chars, `2021_05_21_072312_add_public_id.php`), `name`, `signed_in` nullable, `once_per_session`, `devices` JSON, `operating_systems` JSON, `languages` JSON, `pageview_rules` JSON, `pageview_attributes` JSON, `using_adblock`, `url_filter`, `url_patterns`, `source_filter`, `source_patterns`. No `active` column since `2018_03_22_162154_campaign_drop_active_flag.php`; activity is derived from `schedules`. |
| `banners` | `2017_04_06_065921_banners_table.php` | `uuid`, `public_id`, `name`, `template`, position and display settings, `js`, `js_includes`, `css_includes`, `manual_events_tracking`. |
| nine `*_templates` tables | `2017_09_18_143615_banner_templates.php` and later | One row per banner holding the template specific text and style. |
| `campaign_banners` | `2018_01_21_101505_campaign_banners_pivot_table.php` | A variant: `campaign_id` FK, `banner_id` FK nullable, `control_group`, `proportion`, `weight`, `uuid` (`2018_05_15_081111_variants_uuid.php`), `public_id`, `deleted_at`. |
| `campaign_segments` | `2017_06_28_132802_campaign_segments.php` | `campaign_id` FK, `code`, `provider` (`remp_segment` or `crm_segment`), `inclusive` (`2019_10_10_175000`). |
| `countries`, `campaign_country` | `2018_02_22_101337_campaign_geotargeting.php` | ISO code whitelist or blacklist per campaign. |
| `schedules` | `2017_10_30_115547_campaign_scheduler.php` | `campaign_id` FK, `start_time`, `end_time`, `status` in `ready`, `executed`, `paused`, `stopped`. |
| `campaign_banner_stats` | `2019_03_20_122709_create_campaign_banner_stats_table.php` | Hourly `click_count`, `show_count`, `payment_count`, `purchase_count` per variant. |
| `campaign_banner_purchase_stats` | `2019_03_26_092200_create_campaign_banner_purchase_stats_table.php` | Hourly purchase `sum` per currency per variant. |
| `snippets` | `2021_07_21_074210_add_variables_table.php`, renamed by `2023_03_20_121715_rename_variables_to_snippets.php` | Named text values. |
| `collections`, `campaign_collections` | `2023_09_12_121136`, `2023_09_12_121415` | Admin grouping. |
| `failed_jobs`, `migrations` | | Laravel. |

Campaign has no visitor table, no impression or click table and no per user history. Per visitor state lives in the browser under the localStorage keys `campaigns` and `campaigns_session`, keyed by campaign `public_id`, with fields `bannerId`, `variantId`, `seen`, `count`, `closedAt`, `clickedAt` (`campaign-module/resources/assets/js/remplib.js`, lines 354 to 486). The variant a visitor saw is remembered there; no server side table holds it.

At showtime the browser sends `version`, `userId`, `browserId`, `language`, `url`, `referer`, `sessionReferer`, `campaigns`, `campaignsSession`, `cache`, `userAgent`, `usingAdblock` and `pageviewAttributes` (lines 39 to 61), plus `debug` when the `#campaignDebug` hash is set and a debug key is configured (line 56). `browserId` is required and `userId` is optional (`campaign-module/src/Http/Showtime/Showtime.php`, lines 100 to 111). The subscriber flag is not sent and is never read in Campaign (a search for `userSubscribed`, `isUserSubscriber` and `subscriptionIds` under `campaign-module/src` and `resources` finds nothing). Inferred: subscription based targeting therefore goes through segments.

Segment evaluation picks `checkUser` when `userId` is present and `checkBrowser` otherwise (lines 851 to 881). The Beam provider calls the Segments check endpoints live. The CRM provider (`campaign-module/src/Contracts/Crm/Segment.php`) checks a Redis set `provider|code`. `CacheSegmentJob` rebuilds that set from `user-segments/users`: a forced refresh every hour, plus an every minute run that only fills a missing cache. Both the set and its timestamp key expire after 24 hours (`campaign-module/src/Scheduler.php`, lines 37 to 45; `Jobs/CacheSegmentJob.php`, lines 45 to 76). The provider's `checkBrowser` always returns false with the comment "CRM segments don't support browser tracking" (lines 90 to 94).

Campaign writes no event rows itself. Unless the banner has `manual_events_tracking` set, the rendered banner JavaScript sends a `banner` event to the Beam tracker. The action is `show`, `click` or `close`, and the rtm fields are shown below (`campaign-module/public/showtime.php`, lines 317 to 350; `BannerPreview.vue`, lines 476 to 515).

```js
remplib.tracker.trackEvent("banner", "show", null, null, {
  rtm_source: "remp_campaign",
  rtm_medium: "<display type>",
  rtm_campaign: "<campaign uuid>",   // only when served inside a campaign
  rtm_content: "<banner uuid>",
  rtm_variant: "<variant uuid>"      // only when served inside a campaign
});
```

Every link in a banner carries the same `rtm_*` parameters. `campaigns:aggregate-stats` runs every minute when a Beam Segments address is configured (`campaign-module/src/Scheduler.php`, lines 14 to 21) and pulls counts of `banner/show`, `banner/click` and `commerce` `payment` and `purchase` filtered by `rtm_variant` from the Journal into the hourly stats tables.

### 1.6 SSO and the CRM contract

#### SSO

SSO has three migrations. `2017_07_07_122646_users.php` creates `users` (`id`, `email` UNIQUE, `name`, timestamps) and `google_users` (`user_id` FK, `google_id`, `nickname`, `name`). `2017_11_29_102649_create_api_tokens_table.php` creates `api_tokens` (`name`, `token`, `active`, `deleted_at`). `2018_03_14_143051_last_logged_at.php` adds `users.last_logout_at`.

The `users` here are the people who log in to REMP admin screens with Google (the only login provider implemented, `remp-master/Sso/app/Http/Controllers/Auth/GoogleController.php`); they are not readers. The JWT carries `provider`, `id`, `name`, `email` and `scopes`, and `scopes` is always an empty array (`remp-master/Sso/app/Models/User.php`, lines 61 to 67). `api_tokens.token` is a UUID checked by `GET /api/auth/check-token`, which returns 200 for any existing token regardless of `active` (`remp-master/Sso/app/Http/Controllers/AuthController.php`, lines 145 to 153). No SSO table or route carries a reader id.

Beam and Campaign use `remp-master/Composer/laravel-sso`, which registers `jwt` (and an alias `jwtx`) guard drivers for staff and a `token` guard driver for API calls. The token guard's user object identifies itself by the token string (`src/Contracts/Token/User.php`, lines 30 to 33), so an `auth:api` route in Beam or Campaign is authenticated by the token, not by a person. Mailer embeds its own client under `mailer-module/Models/Sso/`.

#### CRM endpoints referenced in REMP

These are the endpoints REMP calls on the CRM. Each was confirmed to exist in the CRM module repositories (section 1.8).

| Method and path | Caller | Purpose | CRM side |
|---|---|---|---|
| `POST api/v1/users/list` | Mailer `Models/Users/Crm.php` | Resolve user ids to `{id, email}` | `crm-users-module/src/UsersModule.php`, line 468; API token; pages of 1000; never returns anonymised users; `with_uuid` adds `uuid` |
| `GET api/v1/user-segments/list` | Mailer `Models/Segment/Crm.php` | List CRM segments | `crm-segment-module/src/SegmentModule.php`, lines 77 to 79; only segments on the `users` table |
| `GET api/v1/user-segments/users?code=` | Mailer `Models/Segment/Crm.php` | User ids in a segment | `SegmentModule.php`, lines 85 to 87; streams `{id, email}` with the id cast to a string |
| `GET user-segments/list`, `GET user-segments/users?code=` | Campaign `Contracts/Crm/Segment.php` | Same, base URL from `CRM_SEGMENT_API_ADDR` without the `api/v1` prefix | same handlers; `user-segments/check` also exists (lines 93 to 95) and accepts `resolver_type` `id` or `email` |
| `POST api/v1/users/set-email-validated`, `POST api/v2/users/set-email-validated` | Mailer `Models/Crm/Client.php` | Mark an address as having received mail | `UsersModule.php`, lines 514 to 537; writes `users.email_validated_at` |
| `GET api/v1/users/touch?id=` | Mailer `Models/Crm/Client.php` | Ask the CRM to refresh cached user data after a subscription change | `UsersModule.php`, lines 542 to 544; regenerates the Redis user data for all of the user's access tokens |
| `POST /api/v1/users/login`, `GET /api/v1/user/info`, `GET api/v1/token/check` | Mailer `Models/Auth/` | Admin login and token validation against the CRM | `UsersModule.php`, lines 426 and 429; `crm-api-module/src/ApiModule.php`, line 64 |

No code in REMP reads subscription or payment data from the CRM; a search for CRM API paths finds only the endpoints listed above. REMP receives the `subscriber` and `subscription_ids` flags from the page, and commerce events through the Tracker. The Beam README (`remp-master/Beam/README.md`, lines 163 to 178) recommends calling `/track/commerce` from the CRM at checkout, payment, purchase and refund.

In the other direction, these REMP endpoints are documented for the CRM to call:

- Mailer: `users/subscribe`, `users/un-subscribe`, `users/user-registered`, `users/email-changed`, `users/delete`.
- Beam: the article and conversion upsert endpoints.
- Campaign: the segment cache add user and remove user endpoints.

Inferred: these are the CRM's responsibilities, because the READMEs say so and nothing in REMP calls them.

### 1.7 How users, articles, subscriptions, payments and events are identified and linked

| Thing | Beam | Mailer | Campaign | CRM (section 1.8) |
|---|---|---|---|---|
| Logged in reader | `user_id` string tag on events; `conversions.user_id`, `segment_users.user_id`, `article_aggregated_views.user_id`, all `varchar(191)` with no FK | `user_id` integer plus `user_email` | `userId` string in the showtime request, not persisted by showtime itself (Campaign holds user ids only in Redis: CRM segment member sets and one time banner keys) | `users.id` `int(11)`, `users.email` UNIQUE, `users.uuid` UNIQUE (2024), deprecated HMAC `hashed_id` |
| Anonymous reader | `browser_id` UUIDv4 from the browser, 2 year storage; `segment_browsers.browser_id`, `article_aggregated_views.browser_id` | not present | `browserId`, required at showtime | no column; `device_tokens.device_id` for mobile apps, and a `browser_id` cookie forwarded in the `login-attempt` Hermes message but never stored |
| Session | `remp_session_id`, 15 minute sliding, Elasticsearch only | not present | `campaigns_session` client storage | not present |
| Pageview | `remp_pageview_id`, Elasticsearch document id | not present | not present | not present |
| Article | `articles.external_id` (CMS id), `article_id` tag on events; `url` is display only | numeric first path segment of links, in one optional handler | not present | not present |
| Subscription state | `subscriber` boolean and `subscription_ids` array on each event, not stored in MySQL | `mail_user_subscriptions.subscribed` per list | not present | `subscriptions` rows (`is_paid`, `start_time`, `end_time`, `length` in days, `is_recurrent`, `type`, `next_subscription_id`, `internal_status`); active means `start_time <= now < end_time`; cancellation shortens `end_time` |
| Payment | `commerce` events with `transaction_id`, `revenue.amount`, `revenue.currency`, `product_ids`, `funnel_id`; `conversions.transaction_id` UNIQUE | `mail_log_conversions` via Beam commerce events | `payment_count`, `purchase_count` per variant from Beam | `payments` (`variable_symbol`, `amount` decimal, `status`, `paid_at`, `payment_gateway_id`, `subscription_id`, `ip`, `user_agent`, `rtm_*` in `payment_meta`), `payment_items` (`type`, `amount`, `vat`), `recurrent_payments` (`state`, `charge_at`, `chain_id`, `cycle`); no currency column |
| Newsletter consent | `mail-type` events from Mailer | `mail_user_subscriptions` plus variants | not present | no table; the CRM calls Mailer and caches subscribed list codes in Redis |
| Campaign exposure | `banner` events with `rtm_campaign`, `rtm_content`, `rtm_variant` | not present | hourly counts; client side `seen`, `closedAt`, `clickedAt` | not present |
| Tenant | `properties.uuid` = event `token` | not present | not present | not present |

Three facts about identity hold across the whole stack:

1. The CRM user id has no single type. Mailer validates and stores it as an integer. The Tracker (`beam-go/cmd/tracker/design/user_types.go`, line 8) and Campaign showtime (`Showtime.php`, lines 100 to 103) accept any string without validation. The Segments API restricts ids to `^[a-zA-Z0-9_\-@.]+$` (`beam-go/cmd/segments/design/resources.go`, line 10). The Journal PHP client casts every filter value to string. Inferred: in a REMP CRM deployment the id is the CRM `users.id` integer rendered as a decimal string on the Beam side.
2. Nothing in the code read for this document verifies that a `user_id` seen in the Tracker exists in the CRM. The value is trusted as sent by the page.
3. Nothing links `browser_id` histories to a `user_id` permanently. The Tracker records both when both are present. `AggregateConversionEvents` (scheduled) and `ProcessPageviewLoyalVisitors` (a manual command, not scheduled) rebuild the link by co-occurrence for one purpose each, and the Segments service evaluates the two separately.

### 1.8 CRM schema (from the remp2020 CRM module repositories)

The CRM is not in this repository. For completeness it was read from the public `remp2020` module repositories, cloned read only on 2026-11-07 at their latest commits (dated 2026-11-06): `crm-skeleton` plus the nineteen `crm-*-module` packages it requires. Paths in this section are repository relative with the repository name first, for example `crm-users-module/src/migrations/...`. Two REMP facing packages are not among them and were not read: the `crm-remp-campaign-module` that the onboarding README refers to, and whatever module forwards CRM commerce events to the Beam Tracker (see "What the CRM does not contain" below).

#### Modules and their tables

| Module | Tables (creating migration in `src/migrations/` of that module) |
|---|---|
| `crm-users-module` | `users`, `user_meta`, `user_actions_log`, `access_tokens`, `login_attempts`, `registration_attempts`, `groups`, `user_groups`, `addresses`, `address_types`, `countries`, `address_change_requests`, `addresses_meta`, `autologin_tokens`, `password_reset_tokens`, `user_email_confirmations`, `device_tokens`, `change_passwords_logs`, `user_connected_accounts`, `user_stats`, `admin_access`, `admin_groups`, `admin_groups_access`, `admin_user_groups` (most in `20180831074542_users_module_init_migration.php`) |
| `crm-api-module` | `api_tokens`, `api_access`, `api_access_tokens`, `api_logs`, `api_token_stats`, `user_source_accesses`, `idempotent_keys`, `api_token_meta` |
| `crm-subscriptions-module` | `subscriptions`, `subscription_types`, `subscription_type_names`, `subscription_extension_methods`, `subscription_length_methods`, `content_access`, `subscription_type_content_access`, `subscription_type_items`, `subscription_type_item_meta`, `subscription_types_meta`, `subscriptions_meta`, `subscription_type_tags`, `subscription_gaps` (`20180831074642_subscriptions_module_init_migration.php` and later) |
| `crm-payments-module` | `payments`, `payment_items`, `payment_item_meta`, `payment_meta`, `payment_gateways`, `payment_gateway_meta`, `payment_logs`, `recurrent_payments`, `payment_methods`, `payment_cards`, `parsed_mail_logs`, `variable_symbols`, `retention_analysis_jobs`, `vat_rates`, `subscription_pauses` (`20190418061253_payments_module_init_migration.php` and later) |
| `crm-upgrades-module` | `upgrade_schemas`, `upgrade_options`, `subscription_type_upgrade_schemas`, `subscription_upgrades`; adds `payments.upgrade_type` |
| `crm-gifts-module` | `payment_gift_coupons` |
| `crm-products-module` | `products`, `product_templates`, `product_template_properties`, `product_properties`, `product_bundles`, `product_tags`, `tags`, `orders`, `postal_fees`, `country_postal_fees`, `country_postal_fee_conditions`, `distribution_centers`; adds `payment_items.product_id` and `payment_items.postal_fee_id` |
| `crm-salesfunnel-module` | `sales_funnels`, `sales_funnels_meta`, `sales_funnels_payment_gateways`, `sales_funnels_stats`, `sales_funnels_subscription_types`, `sales_funnels_conversion_distributions`, `sales_funnel_tags`; adds `payments.sales_funnel_id` |
| `crm-invoices-module` | `invoices`, `invoice_numbers`, `invoice_items`, `vat_id_consultations`; adds `payments.invoice_id`, `payments.invoice_number_id` |
| `crm-stripe-module` | `stripe_checkout_sessions` |
| `crm-segment-module` | `segments`, `segment_groups`, `segments_values` |
| `crm-scenarios-module` | `scenarios`, `scenarios_triggers`, `scenarios_elements`, `scenarios_trigger_elements`, `scenarios_element_elements`, `scenarios_jobs`, `scenarios_job_history`, `scenarios_element_stats`, `scenarios_trigger_stats`, `scenarios_generated_events`, `scenarios_selected_variants`, `scenarios_repeating_trigger_schedule`, `scenarios_versions` |
| `crm-application-module` | `configs`, `config_categories`, `hermes_tasks`, `audit_logs`, `cache`, `calendar`, `measurements` and four related tables |
| `crm-onboarding-module` | `onboarding_goals`, `user_onboarding_goals` |
| `crm-print-module` | `print_subscriptions`, `print_claims`, `address_redirects` |
| `crm-issues-module` | `magazines`, `issues`, `issue_pages`, `issue_source_files`, `subscription_type_magazines` |
| `crm-admin-module` | `snippets` |
| `crm-remp-mailer-module`, `crm-dashboard-module` | no tables |

#### Users

`users` is created by `crm-users-module/src/migrations/20180831074542_users_module_init_migration.php` (lines 15 to 51). `id` is `int(11) AUTO_INCREMENT`. `email` is `varchar(255) NOT NULL` with a UNIQUE key. Other columns as of the latest migration: `first_name`, `last_name`, `public_name` (NOT NULL, defaults to the email), `password`, `ext_id` (an id in an external system, indexed), `uuid` (UUIDv4, UNIQUE, added by `20240222134455_add_uuid_into_users_table.php`), `role` (`user` or `admin`), `active`, `confirmed_at`, `email_validated_at` (added 2021), `current_sign_in_at`, `current_sign_in_ip`, `created_at`, `updated_at`, `invoice`, `note`, `locale`, `is_institution`, `institution_name`, `supporter`, `source` (`varchar(255) NOT NULL DEFAULT 'unknown'`, indexed), `registration_channel` (default `crm`, added 2021), `sales_funnel_id`, `referer` (varchar 2000), `disable_auto_invoice`, `deleted_at`. The columns `address` and `phone_number` were moved out to `user_meta` in 2020 and `last_sign_in_at` and `last_sign_in_ip` were dropped in 2024.

A deterministic `hashed_id` (`hash_hmac('sha256', users.id, CRM_KEY)`) exists in `crm-users-module/src/Models/Auth/UserManager.php` (lines 264 to 276) and is marked deprecated in favour of `uuid`.

Values of `users.source` fixed by the module are `unknown`, `api`, `signup-form`, `google_sso`, `apple_sso`, plus `funnel` (sales funnels), `shop` (products) and `gift_coupon` (gifts); any API caller can set another string. `registration_channel` values set by the module are `crm`, `google` and `apple`.

Related tables: `user_meta` (`user_id`, `key`, `value`, `is_public`, UNIQUE on user and key; only `is_public = 1` rows are exported to the API), `addresses` (`user_id`, `type`, `first_name`, `last_name`, `street`, `number`, `city`, `zip`, `country_id` to `countries.iso_code`, `company_*`, `phone_number`, `is_default`, `deleted_at`), `login_attempts` (`user_id`, `email`, `status` with 16 literals, `source`, `ip`, `user_agent`, `browser`, `browser_version`, `os`, `device`, `is_mobile`), `access_tokens` (one per sign in, `token`, `ip`, `user_agent`, `last_used_at`, `device_token_id`), `device_tokens` (`device_id`, `token`, for mobile apps), `user_connected_accounts` (`type` in `google_sign_in`, `apple_sign_in`, `external_id`, `email`), `user_groups`, `user_actions_log` (`action`, `params` JSON, kept 12 months), `user_stats` (`key`, `value` per user, written by other modules), `user_source_accesses` (`user_id`, `source`, `last_accessed_at`).

Events emitted by the users module and consumed by other modules: Hermes `user-registered`, `email-changed`, `user-confirmed`, `login-attempt`, `address-changed`, `new-address`. The `login-attempt` payload carries a `browser_id` read from a cookie of that name (`crm-application-module/src/Models/Authenticator/BaseAuthenticator.php`, lines 86 to 95). Inferred: that cookie is the Beam `browser_id` when CRM and site share a cookie domain. It is forwarded in the message and never stored in a CRM table.

GDPR deletion is anonymisation, not removal. `BasicUserDataProvider` overwrites `email` with `GDPR_removal@<timestamp>`, sets names and other personal columns to `GDPR Removal`, sets `active = 0` and `deleted_at`, and deletes login attempts, tokens, groups, stats and public meta (`crm-users-module/src/DataProviders/BasicUserDataProvider.php`, lines 92 to 116). `uuid`, `source`, `registration_channel` and `created_at` are left untouched. `POST /api/v1/users/list` never returns anonymised users. The churn module's `JOIN users` does not filter `deleted_at`, so anonymised users still appear in its rows (inferred from the two code paths).

#### Subscriptions

`subscriptions` is created by `crm-subscriptions-module/src/migrations/20180831074642_subscriptions_module_init_migration.php` (lines 91 to 121). Columns: `id`, `user_id` (`int(11) NOT NULL`, FK to `users.id`), `subscription_type_id` (FK), `is_recurrent`, `is_paid` (nullable boolean added by `20200430113327_add_column_is_paid_to_subscriptions.php` and backfilled from `type`), `start_time`, `end_time`, `type` (FK to `subscription_type_names.type`; seeded `regular`, `free`, `donation`, `gift`, `prepaid`, `upgrade`, `special`), `length` (integer days since `20200518073127_fixing_subscription_length_type.php`), `created_at`, `updated_at`, `next_subscription_id` (self FK), `internal_status` (`unknown`, `before_start`, `active`, `after_end`, a cache refreshed by `subscriptions:change_status`), `note`, `address_id`.

Active is always computed as `start_time <= now AND end_time > now` (`SubscriptionsRepository.php`, lines 386 to 393). Cancellation is modelled by shortening `end_time`, possibly to `start_time`, with a note and the meta key `expired_by_admin`; there is no status column for it. Renewal is a chain through `next_subscription_id`, set when a new subscription starts exactly at the previous `end_time`. Upgrades are rows in `subscription_upgrades` (`base_subscription_id`, `upgraded_subscription_id`, `type` in `paid_recurrent`, `free_recurrent`, `paid_extend`, `short`, `trial`) plus a new subscription with `type = 'upgrade'`. Gaps longer than 28 days are precomputed into `subscription_gaps` (2026).

`subscription_types` holds `code` (UNIQUE), `price` (decimal 10,2), `length` (days), `extension_method_id`, `length_method_id`, `next_subscription_type_id`, `trial_periods`, `default`, `no_subscription`, `limit_per_user`, `recurrent_charge_before` and legacy access flags. Content access is a property of the type: `subscription_type_content_access` links types to `content_access` rows (seeded `web`, `print`, `mobile`). `subscription_type_tags` (2023) holds free text tags with a unique pair index. Nothing in the CRM defines a `trial` tag; the churn module's reliance on `tag = 'trial'` is a publisher convention (inferred). The system level trial mechanism is `subscription_types.trial_periods` with `next_subscription_type_id`, and the upgrades module's `TrialUpgrade`.

Events: League `NewSubscriptionEvent`, `SubscriptionUpdatedEvent`, `SubscriptionStartsEvent`, `SubscriptionEndsEvent`, `AfterLastSubscriptionEndedEvent`; Hermes `new-subscription`, `update-subscription`, `subscription-starts`, `subscription-ends`. The time driven start and end events fire from the cron command `subscriptions:change_status`.

There is no `subscriber` boolean in the CRM. The nearest source for Beam's `user.subscriber` is the per access token user data blob, which carries the user's subscriptions with `end_time > now` as `id`, `start_time`, `end_time`, `code`, `is_recurrent`, `types` (`crm-subscriptions-module/src/DataProviders/SubscriptionsUserDataProvider.php`, lines 36 to 63). It is refreshed on new and updated subscription events but not on the time driven `subscription-ends` event (inferred staleness).

#### Payments

`payments` is created by `crm-payments-module/src/migrations/20190418061253_payments_module_init_migration.php` (lines 28 to 66). Columns: `id`, `variable_symbol` (`varchar(255) NOT NULL`, indexed, not unique), `amount` (decimal 10,2), `additional_amount`, `additional_type`, `payment_gateway_id` (FK), `subscription_id` (FK, nullable), `subscription_type_id` (FK, nullable), `user_id` (`int(11) NOT NULL`, FK to `users.id`), `status` (`varchar(255) NOT NULL DEFAULT 'form'`), `created_at`, `updated_at`, `error_message`, `referer` (varchar 2000), `paid_at`, `note`, `ip`, `user_agent`, `subscription_start_at`, `subscription_end_at`, `address_id`, `recurrent_charge`, plus `sales_funnel_id`, `upgrade_type`, `invoice_id`, `invoice_number_id`, `payment_country_id` and `payment_country_resolution_reason` added by other modules and later migrations.

`PaymentStatusEnum` has exactly eight cases: `form`, `paid`, `fail`, `timeout`, `refund`, `imported`, `prepaid`, `authorized` (`crm-payments-module/src/Models/Payment/PaymentStatusEnum.php`, lines 11 to 18). The literals `family` and `upgrade` that the churn module uses as payment statuses (`RunChurnPipelineCommand.php`, line 858) do not exist in any cloned module. Inferred: `family` comes from a family module that was not cloned, and `upgrade` is legacy or installation specific.

`variable_symbol` is a random 10 digit numeric string generated per payment and reserved in `variable_symbols` (`crm-payments-module/src/Repositories/VariableSymbolRepository.php`, lines 13 to 45). It is the bank transfer reference, the PayPal invoice field, and the value the upgrades module sends as `transaction_id` in its commerce shaped events. This confirms the earlier inference that Beam's `conversions.transaction_id` is the CRM variable symbol, with the caveat that a copied payment can reuse one.

There is no currency column on `payments`, `payment_items` or `recurrent_payments`. Currency is one application config, default `EUR` (`crm-application-module/src/Seeders/ConfigsSeeder.php`, line 80). Only `invoice_items.currency` stores a currency string.

`payment_items` (`payment_id`, `type`, `subscription_type_id`, `product_id`, `postal_fee_id`, `name`, `count`, `amount`, `amount_without_vat`, `vat`) carries the line items. Type literals defined in code are `subscription_type` (default), `donation`, `authorization`, `gift`, `product`, `postal_fee`.

`recurrent_payments` (init migration lines 110 to 144) is one row per future charge: `cid` (legacy card token), `payment_method_id`, `chain_id` and `cycle` (added by `20250902124449_add_chain_tracking_columns_to_recurrent_payments.php`), `charge_at`, `expires_at` (the card expiry fetched from the gateway, not a subscription expiry), `payment_id`, `parent_payment_id`, `retries`, `status` and `approval` (gateway results), `user_id` (FK), `subscription_type_id`, `next_subscription_type_id`, `state`, `note`, `custom_amount`. `RecurrentPaymentStateEnum` has exactly seven cases: `user_stop`, `admin_stop`, `active`, `pending`, `charged`, `charge_failed`, `system_stop`. `cycle` starts at 1 and increments per successful renewal; rows that predate the 2025 backfill command have NULL.

Every payment stores `ip` and `user_agent`, overwritten with `GDPR removal` on anonymisation. `payment_meta` carries `rtm_campaign`, `rtm_content`, `rtm_source`, `rtm_medium`, `rtm_variant` (renamed from `utm_*` by `20201209124720_rename_utm_to_rtm_in_payments_meta.php`), `newsletters_subscribe`, gateway transaction references and refund details.

Events: Hermes `new-payment`, `payment-status-change` (with `payment_id`, `sales_funnel_id`, `send_email`), `recurrent-payment-created`, `recurrent-payment-state-changed`, `recurrent-payment-renewed`. On `paid` or `prepaid`, `PaymentStatusChangeHandler` creates the subscription and writes `payments.subscription_id`.

#### Segments and scenarios

A CRM segment is a SQL template stored in `segments.query_string` with the placeholders `%fields%`, `%table%`, `%where%`, `%group_by%` and optional `%segment.<code>%` (`crm-segment-module/src/Models/SegmentQuery.php`, lines 84 to 124). `segments.table_name` is `users`, `subscriptions` or `payments`. `segments.version` 2 rows are built from a JSON criteria tree in `segments.criteria`. There is no table of segment members. `segments_values` stores counts over time, refreshed by `segment:actualize_counts` on a per segment periodicity (default 6 hours). Membership checks run the segment SQL per call.

The endpoints Mailer and Campaign call are in `crm-segment-module/src/SegmentModule.php` (lines 77 to 95): `GET /api/v1/user-segments/list` returns only `users` based segments; `GET /api/v1/user-segments/users?code=` streams `{id, email}` pairs with the id cast to a string from one unpaged SQL run; `GET /api/v1/user-segments/check` accepts `resolver_type` `id` or `email`. The string id on this endpoint is why Beam and Campaign treat `user_id` as a string.

Scenarios are automation graphs (`scenarios`, `scenarios_triggers`, `scenarios_elements`, edges, `scenarios_jobs`, `scenarios_job_history`). Triggers are Hermes messages mapped to public event codes: `user_registered`, `user_confirmed`, `new_subscription`, `subscription_starts`, `subscription_ends`, `subscription_upgraded`, `new_payment`, `payment_change_status`, `recurrent_payment_renewed`, `recurrent_payment_state_changed`, `new_invoice`, `address_changed`, `new_address`, `new_order`, `order_status_change`, plus before events (`subscription_ends`, `before_recurrent_payment_charge`, `before_recurrent_payment_expires`, `after_last_subscription_ended`) and repeating segment triggers. Actions are `email` (through Mailer), `push_notification`, `banner` (emits `BannerEvent`; no listener exists in the clones), `segment`, `condition`, `wait`, `goal`, `generic`, `ab_test`. Scenarios never subscribe to Beam events.

#### Sales funnels and shop

`sales_funnels` (`id`, `url_key` UNIQUE, Twig `body`, validity window, `segment_id`, counters) is the CRM checkout. A submit creates the payment, sets `payments.sales_funnel_id`, and emits a Hermes message of type `sales-funnel` with `type: 'payment'`, `user_id`, `sales_funnel_id`, `payment_id` merged with the output of `TrackerDataProviderInterface` providers (`crm-salesfunnel-module/src/Presenters/SalesFunnelFrontendPresenter.php`, lines 453 to 498). The shop emits the same message with `sales_funnel_id: 'shop'`, and the upgrades module emits it with `transaction_id`, `product_ids` and `revenue`, which are the Beam `CommercePayment` field names. Funnel statistics are counted per day, type (`show`, `form`, `no_access`, `error`, `ok`) and device type in `sales_funnels_stats`.

#### Mailer integration

`crm-remp-mailer-module` has no tables. Its repositories are HTTP wrappers around Mailer's API. The CRM calls Mailer's `users/user-registered`, `users/email-changed`, `users/delete`, `users/subscribe`, `users/un-subscribe`, `users/bulk-subscribe`, `users/is-subscribed`, `users/is-unsubscribed`, `users/user-preferences`, `users/logs`, `users/logs-count-per-status`, `mailers/send-email`, `mailers/mail-types`, `mailers/templates` and `mailers/jobs` (`crm-remp-mailer-module/src/Models/Api/Client.php`, lines 19 to 37). Every call identifies the person by `user_id` (integer) and clear text `email`; `users.uuid` is never sent. Newsletter consent is therefore held only in Mailer, and the CRM caches the subscribed list codes in the Redis user data document under the provider `remp_mailer_subscriptions`.

The CRM endpoints that REMP calls (section 1.6) all exist: `POST /api/v1/users/list` (API token; `user_ids`, `page` of 1000, `include_deactivated`, `with_uuid`; never returns anonymised users), `GET /api/v1/users/touch` (refreshes the Redis user data), `POST /api/v1/users/set-email-validated` v1 and v2 (write `users.email_validated_at`), `POST /api/v1/users/login`, `GET /api/v1/user/info`, `GET /api/v1/token/check`, and the three `user-segments` endpoints.

#### Time zones and audit

Every raw SQL migration sets `time_zone = '+00:00'` for its own session only. All datetime columns are MySQL `datetime` without a zone, and `Repository::processDateFields` converts every value to `date_default_timezone_get()` before writing (`crm-application-module/src/Models/Database/DateFieldsProcessorTrait.php`, lines 7 to 16). The skeleton sets `date.timezone: Europe/Bratislava` (`crm-skeleton/app/config/config.neon`, line 68). Inferred: CRM datetimes are wall clock values in the publisher's PHP zone, and an exporter has to attach that zone. API responses carry an explicit offset.

Every repository write is mirrored into `audit_logs` (`operation`, `user_id` of the acting admin, `table_name`, `signature`, `data` JSON with `from` and `to`), created by `crm-application-module/src/migrations/20180831084242_add_audit_logs.php`.

#### What the CRM does not contain

- No Beam tracker client, no `/track/commerce` call, no Journal client and no `remplib` reference in any cloned module. The only Beam facing hooks are the three `TrackerDataProviderInterface` extension points (`sales_funnel.dataprovider.tracker`, `products.dataprovider.tracker`, `upgrades.dataprovider.tracker`) and the Hermes `sales-funnel` message, for which no handler exists in the clones. Inferred: the bridge to the Beam Tracker is a publisher specific module.
- No `browser_id`, session id or device id column on any table. `device_tokens.device_id` is a mobile app identifier used for login.
- No newsletter consent table. Consent like timestamps are `users.confirmed_at`, `users.email_validated_at` (written by Mailer) and the `user_meta` key `gdpr = confirm_payment` set on the payment return page.
- No currency column on payment rows.
- No demographic columns (age, gender, education, income).

## 2. What the churn prediction module needs

### 2.1 Pipeline overview

The module (`churn/`) builds the feature tables for, and serves predictions from, an XGBoost classifier in BigQuery ML that estimates the probability that a subscription will not be renewed within `churn-days` (28 by default). It registers two commands, `churn:run_pipeline` and `churn:download_churn_predictions` (`churn/src/ChurnPredictionModule.php`, lines 12 to 16). The package requires PHP 8.3 and `google/cloud-bigquery` and imports CRM classes from `Crm\ApplicationModule`, `Crm\SubscriptionsModule`, `Crm\UsersModule` and `Crm\PaymentsModule` without declaring them in `composer.json`. Inferred: it is meant to be installed inside a running REMP CRM.

| BigQuery object | Kind | Created by | Write semantics |
|---|---|---|---|
| `churn_training_subscription_features` | table | load job with schema autodetect (`churn/src/Commands/RunChurnPipelineCommand.php`, lines 978 to 981) | Truncated with `--replace-training-data`, otherwise merged from a staging table by `(subscription_id, days_to_expiry)` (line 1015) |
| `churn_training_subscription_features_staging` | table | load job | Replaced on every non replace training run |
| `churn_scoring_subscription_features` | table | load job | Always truncated (line 214) |
| `churn_engagement_features` | table | `CREATE TABLE IF NOT EXISTS` in `churn/src/Commands/bigquery/engagement_features.sql`, lines 28 to 65 | Rows for today's `(user_id, observed_date)` pairs deleted and recomputed. Partitioned by `observed_date`, clustered by `user_id`. |
| `churn_training_all_features`, `churn_scoring_all_features` | views | manually, SQL in `churn/README.md`, lines 98 to 119 | Left join of subscription features to engagement features on `user_id` and `DATE(observed_at)`; the training view adds a `split` column by `FARM_FINGERPRINT(user_id)` (70 percent train, 20 eval, 10 test) |
| `churn_xgboost` | model | manually, SQL in `churn/README.md`, lines 131 to 154 | `BOOSTED_TREE_CLASSIFIER`, label `churned`, all columns except `user_id`, `subscription_id`, `observed_at`, `subscription_end_time`, `export_date`, `content_access_codes`, `split` and the derived `data_split` column (used as `DATA_SPLIT_COL`) are features. The model itself is created by a manual SQL step; the README notes the hyperparameters were tuned on one publisher's data (line 156) |
| `churn_predictions` | table | `churn/src/Commands/bigquery/churn_predict.sql`, lines 13 to 25 | Today's partition deleted then inserted. Partitioned by `prediction_date`, clustered by `subscription_id`. One row per scored subscription per day. |
| `pageviews`, `pageviews_time_spent` | tables | not by this module | Read only |

The merge script (`churn/src/Models/BigQuery/StagingMergeSqlBuilder.php`, lines 18 to 40) creates the target from the staging table if missing, deletes matching keys, and inserts by named column list. It cannot add columns to an existing target. Inferred: a new feature column requires a `--replace-training-data` rebuild or a manual `ALTER TABLE`.

### 2.2 Subscription features and the CRM tables they read

The subscription feature rows (the "analytical base table") are built in PHP from CRM MySQL. The tables below are the ones the SQL in `RunChurnPipelineCommand.php` and `churn/src/Models/ChurnExport/TierMaxPriceSnapshotBuilder.php` reads. Their definitions were checked against the CRM module repositories (section 1.8).

| CRM table | Columns used | Where in the churn module |
|---|---|---|
| `subscriptions` | `id`, `user_id`, `subscription_type_id`, `is_paid`, `is_recurrent`, `length`, `start_time`, `end_time`, `created_at` | lines 509 to 538, 648 to 655, 806 to 828 |
| `users` | `id`, `created_at`, `source`, `registration_channel` | lines 660, 669 to 671 |
| `subscription_types` | `id`, `length`, `price`, `created_at` | line 686; `TierMaxPriceSnapshotBuilder.php` lines 90 to 96 |
| `subscription_type_content_access`, `content_access` | `subscription_type_id`, `content_access_id`; `id`, `name` | lines 561 to 566, 681, 687 to 688 |
| `subscription_type_tags` | `subscription_type_id`, `tag` (value `trial`) | lines 817, 824 |
| `subscription_upgrades` | `base_subscription_id`, `created_at` (the table also has `upgraded_subscription_id` and `type`) | lines 819 to 823 |
| `payments` | `id`, `user_id`, `subscription_id`, `amount`, `status`, `created_at`, `payment_gateway_id` | lines 677, 689 to 691, 853 to 859, 877 to 886 |
| `payment_gateways` | `id`, `code` | lines 678, 692 |
| `payment_items` | `payment_id`, `type` (`donation` or `product`; the CRM also defines `subscription_type`, `authorization`, `gift`, `postal_fee`), `amount` | lines 879 to 883 |
| `recurrent_payments` | `user_id`, `created_at`, `updated_at`, `expires_at`, `charge_at`, `state`, `cycle` | lines 907 to 913, 933 to 938 |

Payment status literals used are `paid`, `prepaid`, `authorized`, `family`, `upgrade` and `fail`. The CRM's `PaymentStatusEnum` defines `form`, `paid`, `fail`, `timeout`, `refund`, `imported`, `prepaid` and `authorized` (`crm-payments-module/src/Models/Payment/PaymentStatusEnum.php`, lines 11 to 18); `family` and `upgrade` are not among them and do not appear in any cloned module. Inferred: they come from a module that was not cloned or from a specific installation. Recurrent payment states used are `user_stop` (a literal at line 911) and, through the CRM enum `RecurrentPaymentStateEnum` (`ChurnFeatureCalculator.php`, lines 187 to 191), `UserStop`, `AdminStop` and `SystemStop`. The string values `admin_stop` and `system_stop` are confirmed by the CRM enum (`crm-payments-module/src/Models/RecurrentPayment/RecurrentPaymentStateEnum.php`, lines 11 to 17). Two CRM facts matter for these features: `recurrent_payments.expires_at` is the card expiry fetched from the gateway, and `cycle` exists only since 2025 and is NULL on older rows unless the backfill command was run.

Columns written per row, in order (`RunChurnPipelineCommand.php`, lines 659 to 682 and 742 to 755):

| Group | Columns |
|---|---|
| Keys | `user_id`, `subscription_id`, `days_to_expiry`, `export_date`, `observed_at`, `subscription_end_time` |
| Main query | `days_after_registration`, `source`, `registration_channel`, `subscription_is_recurrent`, `subscription_length`, `length_family` (`monthly`, `quarterly`, `half_year`, `yearly`, `two_year`, `other`), `payment_amount`, `payment_gateway`, one `content_access_<name>` 0 or 1 flag per content access row, `content_access_codes`, and in training mode `churned` |
| Tenure and coverage | `longest_continuous_subscription_tenure`, `days_subscribed_total`, `days_since_first_subscription`, `subscribed_days_ratio_since_first_subscription`, `unique_days_subscribed_30`, `unique_days_subscribed_90` |
| Subscription history | `total_amount`, `average_amount`, `total_subscription_count`, `paid_subs`, `free_subs`, `total_days_paid_sub`, `total_days_free_sub`, `days_between_first_and_last_paid_sub`, `avg_subscription_length_days`, `avg_gap_length_days`, `max_gap`, `subscription_gaps_total`, `upgraded_subs` |
| Previous subscription | `is_recurrent_previous`, `is_recurrent_changed` |
| Payments | `payment_failures_12m`, `payment_retries_30d`, `days_since_last_payment_failure`, `payment_successes_12m`, `payment_success_ratio`, `donation_amount_total`, `donation_amount_12m`, `purchases_total`, `purchases_12m`, `purchases_revenue_total`, `purchases_revenue_12m`, `avg_purchase_value` |
| Cancellations | `cancellations_total`, `cancellations_365d`, `days_since_last_cancellation` |
| Active recurring payment | `card_expires_in_days`, `active_recurring_stopped`, `renewal_cycle`, `is_first_renewal`, `card_expires_before_renewal`, `days_card_valid_after_renewal` |
| Data provider columns | any columns from implementations of `ChurnFeatureDataProviderInterface` registered under `churn_prediction.dataprovider.features` |
| Tier price | `payment_pct_of_tier_max_price`, `payment_pct_of_tier_median_price` |
| Lifecycle | `subscriber_segment` in `trial`, `post_trial`, `new`, `reactivated`, `core` |

Most calculator functions in `churn/src/Models/ChurnExport/ChurnFeatureCalculator.php` take `observed_at` and skip rows after it (payments, cancellations, active recurring payment, tenure, days subscribed, subscription history). `computePreviousSubscriptionFeatures` and `computeSubscriberSegment` (lines 522 and 578) take the current subscription id instead and only look at subscriptions that started before it. `computeTierPriceFeature` (line 235) reads `observed_at` from the row to pick the month's price snapshot. Inferred: this as-of discipline is what keeps training rows free of leakage; the test file header says the same (`ChurnFeatureCalculatorTest.php`, lines 10 to 14). The `content_access_<name>` columns are generated from `ContentAccessRepository::all()`, so the schema differs per installation.

### 2.3 Engagement features and the Beam columns they read

`engagement_features.sql` computes one row per `(user_id, observed_date)` for the rows exported today. Every pageview CTE joins `pageviews pv ON pv.user_id = CAST(obs.user_id AS STRING)` within a date window and `pv.token IN UNNEST(active_tokens)`. The tokens are Beam property tokens passed through `setActiveTokens()` in the CRM configuration (`churn/README.md`, lines 51 to 55).

| Feature | Window (days before `observed_date`) | Definition | Beam columns |
|---|---|---|---|
| `unique_pageviews_30`, `_90` | 30 to 1, 90 to 1 | `COUNT(DISTINCT remp_pageview_id)` | `remp_pageview_id` |
| `unique_pages_seen_30`, `_90` | same | `COUNT(DISTINCT canonical_url)` | `canonical_url` |
| `unique_days_seen_30`, `_90` | same | `COUNT(DISTINCT DATE(time))` | `time` |
| `user_last_seen_days_30`, `_90` | same | days from `observed_date - 1` to `MAX(time)` | `time` |
| `unique_articles_seen_30`, `_90` | same | distinct `article_id` where `content_type = 'article'` | `content_type`, `article_id` |
| `sum_total_timespent_30`, `_90` | same | `SUM(timespent)` from `pageviews_time_spent` | `timespent` |
| `unique_articles_seen_30d_vs_prev_30d_change`, `unique_days_seen_30d_vs_prev_30d_change` | 30 to 1 minus 60 to 31 | difference of the two windows | as above |
| `distinct_authors_30d`, `_90d` | 30, 90 | distinct `author_id` on article pageviews | `author_id` |
| `distinct_devices_30d` | 30 | distinct `derived_ua_device` excluding NULL and `Unknown` | `derived_ua_device` |
| `distinct_tags_30d`, `_90d` | 30, 90 | distinct values of `SPLIT(tags, ',')` | `tags` |
| `top_tag_share_30d`, `_90d` | 30, 90 | top tag mentions divided by all tag mentions | `tags` |

The Beam columns map back to the Tracker payload as follows.

| BigQuery column | Tracker payload field |
|---|---|
| `user_id` | `user.id` |
| `time` | `system.time` (the browser clock) |
| `token` | `system.property_token` |
| `remp_pageview_id` | `user.remp_pageview_id` |
| `canonical_url` | `user.canonical_url` |
| `article_id`, `author_id`, `content_type` | `article.id`, `article.author_id`, `article.content_type` |
| `tags` | `article.tags`, comma joined by the Tracker (`track.go`, line 343) |
| `derived_ua_device` | parsed from `user.user_agent` |
| `timespent` | cumulative seconds from the timespent pings |

The engagement SQL is a template, not fixed code. PHP substitutes `{{ACTIVE_TOKENS}}` (line 8), `{{TABLE_NAME}}` (lines 19, 75 and 90) and `{{DATASET}}` before running it. The file header says it must not be run directly (line 5). Its `CREATE TABLE IF NOT EXISTS` has to be kept in sync with the final `SELECT` by hand (line 26). `setEngagementFeaturesSqlPath()` points the pipeline at a publisher's own copy, and the README section 8 tells installers to extend it that way. Inferred: the feature set above is therefore per installation.

Fields present in the Elasticsearch `pageviews` index but not used by the churn SQL: `browser_id`, `subscriber`, `subscription_ids`, `signed_in`, `remp_session_id`, `rtm_*`, `derived_referer_medium`, `locked`, `url`. The `pageviews_progress` index (`page_progress`, `article_progress`) is not read at all. Only pageviews that carry a `user_id` count.

### 2.4 Observation points and label

| Item | Value | Source |
|---|---|---|
| Offsets (`days_to_expiry` in training) | `1,3,7,14,21,28,42,60,90` days before `end_time` | `RunChurnPipelineCommand.php`, line 39 |
| `churn-days` | 28 | lines 122 to 127 |
| Training window | 365 by default, 33 in the cron example | lines 134 to 139; README line 221 |
| Eligible for training | `is_paid = 1`, `end_time` between `now - window` and `now - churn_days`, `length >= 28`, duration over one hour | lines 529 to 539 |
| Eligible for scoring | `is_paid = 1`, active now, `end_time` within `max offset` days, `length >= 28`, and no later paid subscription for the user | lines 508 to 527 |
| Training rows | one per eligible subscription per offset, `observed_at = end_time - offset`, dropped when the user's next paid subscription had already started by `observed_at`, when the offset exceeds the subscription `length`, or when `observed_at` is less than one hour after `start_time` | lines 607 to 627, 649 to 653 |
| Scoring rows | one per eligible subscription, `observed_at = now`, `days_to_expiry = DATEDIFF(end_time, now)` | lines 584 to 602 |
| Label `churned` | 1 when no paid subscription of the user covers the instant `end_time + churn_days` | lines 630 to 639 |

The prediction script runs `ML.PREDICT` over `churn_scoring_all_features`, keeps `user_id`, `subscription_id`, `length_family`, `days_to_expiry` and `subscription_end_time` as pass through columns, and writes `churn_probability` as the probability of label 1 (`churn_predict.sql`, lines 30 to 46).

`churn:download_churn_predictions` pulls the newest row per subscription among rows whose `prediction_date` is today or within the previous three days (`churn/src/Commands/DownloadChurnPredictionsCommand.php`, lines 55 to 63). It skips rows whose user or subscription no longer exists in the CRM. It upserts the CRM table `churn_predictions` by `subscription_id`, so that table holds only the latest score per subscription (`ChurnPredictionsRepository::upsert`, lines 19 to 48) while BigQuery keeps one row per subscription per day.

| CRM table | Created by | Columns |
|---|---|---|
| `churn_predictions` | `churn/src/migrations/20260723205508_add_churn_predictions_table.php` | `user_id` and `subscription_id` (integers, foreign keys to `users` and `subscriptions`), `churn_probability`, `days_to_expiry`, `model_name`, `scored_at`, `created_at`, `updated_at` |

The README also documents the evaluation step: `ML.EVALUATE` on the `test` split restricted to `days_to_expiry = 28`, and `ML.FEATURE_IMPORTANCE` for feature ranking (`churn/README.md`, lines 163 to 184).

### 2.5 How often it runs

The README cron (`churn/README.md`, lines 216 to 228):

| Time | Command |
|---|---|
| 03:05 | `beam-bigquery-export.sh`, exports yesterday's pageviews to BigQuery (separate project) |
| 04:00 | `churn:run_pipeline --mode=training --training-window-days=33` under `flock` |
| 05:30 | `churn:run_pipeline --mode=scoring --model=churn_xgboost` under `flock` |
| 06:30 | `churn:download_churn_predictions` |

Everything is day grained. `export_date` is `date('Y-m-d')` at run time (line 190) and is how the engagement script finds "this run's rows". Engagement rows are keyed by `DATE(observed_at)`. Predictions are partitioned by `prediction_date`. All scoring rows of one run share `observed_at = now`. Model retraining is manual (README line 234). The README says a missed training run is caught up for up to five days (line 233). Inferred: that matches the arithmetic, because the 33 day window minus the 28 day churn horizon leaves five days of overlap.

The engagement windows end at `observed_date - 1 day`. Inferred: the 05:30 scoring run therefore depends on the 03:05 export of yesterday having completed. If the export is late or fails, the engagement features silently under count, because the join simply finds fewer rows and no freshness check exists.

Options that change what the pipeline runs on (`RunChurnPipelineCommand.php`):

| Option | Effect | Lines |
|---|---|---|
| `--mode` | `training` (default) or `scoring` | 151 to 156 |
| `--now-date` | the reference instant, default the current datetime; scoring uses it as `observed_at` | 128 to 133, 189, 596 |
| `--offsets` | the observation offsets, default `1,3,7,14,21,28,42,60,90` | 39, 140 to 145 |
| `--churn-days` | the churn horizon, default 28 | 122 to 127 |
| `--training-window-days` | default 365, must exceed `--churn-days` | 134 to 139, 216 to 220 |
| `--replace-training-data` | truncate the training table, rejected in scoring mode | 146 to 150, 209 to 214 |
| `--skip-engagement-refresh` | skip the BigQuery engagement script | 157 to 161 |
| `--model`, `--skip-predict` | the model to score with, or skip prediction | 162 to 171 |

### 2.6 Identity assumptions

The CRM `users.id` and `subscriptions.id` are `int(11) AUTO_INCREMENT` columns (`crm-users-module/src/migrations/20180831074542_users_module_init_migration.php`, line 16; `crm-subscriptions-module/src/migrations/20180831074642_subscriptions_module_init_migration.php`, line 92). The evidence inside the module is the docblock on `computeFeatures` (`churn/src/DataProviders/ChurnFeatureDataProviderInterface.php`, line 31: `user_id: int, subscription_id: int`), the `INT64` columns of the BigQuery tables, and the foreign keys in the `churn_predictions` migration.

The module joins Beam to the CRM with `pv.user_id = CAST(obs.user_id AS STRING)`. Inferred: it assumes the publisher's front end sends the CRM `users.id` as Beam's `user_id`. `browser_id` is never read. Pageviews without a `user_id` contribute nothing. There is no identity stitching anywhere in the module. The join keys are `(user_id, DATE(observed_at))` for engagement, `(subscription_id, days_to_expiry)` for subscription features, `(prediction_date, subscription_id)` for predictions and `subscription_id` for the CRM table.

Inferred: `DATEDIFF` and `DATE_SUB` run in the CRM database timezone while BigQuery applies `DATE()` to a TIMESTAMP in UTC, so observations near midnight could fall on different calendar days on the two sides. The code does not address this.

## 3. The ADC schema

### 3.1 What it is

ADC is one JSON-LD file, `adc/schema/adc.schema.jsonld` (281 lines, version 1.0.0, released 2026-10-01), plus eight taxonomies under `adc/taxonomies/` and a validator at `adc/scripts/validate.mjs`. JSON-LD is JSON with an `@context` block that maps each field name to a term in a web vocabulary. The README calls ADC "an open data model for newsroom audience data" (`adc/README.md`, line 3). It covers people, the objects they interact with, and the actions that connect the two. The stated goal is that "different newsrooms and different tools can produce and consume" the data "without bespoke integration work". The repository contains no application code. The model was extracted unchanged from the Schema Mapping CLI, and property descriptions are "what an LLM-based mapper is shown" (README line 126).

The `@context` (lines 5 to 32) maps ADC names onto schema.org terms, with one Activity Streams term for `referrer`. Names not listed resolve through `@vocab` to the schema.org term of the same name.

| ADC names | schema.org term |
|---|---|
| `userID`, `personID`, `objectID`, `person`, `object` | `identifier` |
| `primaryEmail`, `additionalEmails` | `email` |
| `tags`, `subject` | `keywords` |
| `consentDate`, `lastDonationDate` | `dateCreated` |
| `donorStatus`, `consentType`, `type` | `category` |
| `engagementScore` | `ratingValue` |
| `dataSource` | `isBasedOn` |
| `title` | `name` |
| `subType` | `additionalType` |
| `published` | `datePublished` |
| `responses` | `about` |
| `userAgent` | `instrument` |
| `timeSpent` | `duration` |
| `completionRate` | `value` |
| `referrer` | `activitystream:origin` |

The `entities` block defines exactly three entities. Each property uses a JSON Schema like vocabulary (`type`, `description`, `required: true`, `items`, `properties`, `minimum`, `maximum`, `pattern`, `format`) plus the ADC extension `enumFromTaxonomy`. The README warns that the definitions "must not be fed to a JSON Schema validator" (line 121).

### 3.2 person

`@type` `Person`, `idProp` `userID` (lines 34 to 165).

| Property | Type | Required | Constraint |
|---|---|---|---|
| `userID` | string | yes | "Global user ID" |
| `givenName`, `familyName` | string | | |
| `primaryEmail` | string | | supplied "if the user has authenticated" |
| `additionalEmails` | array of string | | "for the purposes of matching" |
| `engagementScore` | number | | 1 to 5 |
| `tags` | array of string | | "could also represent MailChimp lists" |
| `dataSource` | string | yes | "Where this record originated" |
| `consentDate` | string | | RFC 3339 by description only |
| `consentType` | string | | taxonomy `ConsentType-v1` |
| `demographics.ageGroup`, `.gender`, `.educationLevel`, `.incomeBracket` | string | | taxonomies |
| `location.postalCode`, `.addressCountry`, `.addressLocality` | string | | |
| `behavior.donorStatus` | string | | taxonomy `DonorStatus-v1` |
| `behavior.lastDonationDate` | string | | RFC 3339 by description only |
| `interests.topics`, `.commentedOn` | array of string | | |

### 3.3 object

`@type` `Object`, `idProp` `objectID` (lines 166 to 224).

| Property | Type | Required | Constraint |
|---|---|---|---|
| `objectID` | string | yes | pattern `^(https?:\/\/)?([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}(:\d+)?(\/[^\s]*)?$`, "most commonly will be a URI" |
| `title` | string | | |
| `type` | string | | taxonomy `ObjectType-v1` |
| `subType` | string | | free text "for analysis rather than parsing" |
| `published` | string | yes | "Date-time when the item was created" |
| `subject` | array of string | | tags or subjects |
| `responses[]` | array of `{question, answer}` | | survey answers |
| `dataSource` | string | yes | |

Inferred: the `objectID` pattern requires a dotted host name, so a bare hash does not match it even though the description says the id "could potentially be a hashed uuid". The description also says to "leave it as null" when unknown, while the property is required and `nullable` is unset.

### 3.4 action

`@type` `Action`, no `idProp` (lines 225 to 279). The README calls it "the edge of the graph".

| Property | Type | Required | Constraint |
|---|---|---|---|
| `actionType` | string | | taxonomy `ActionType-v1` |
| `person` | string | yes | "the globally unique id of the person performing the action" |
| `object` | string | yes | same URL pattern as `objectID` |
| `referrer` | string | | URI of the page that sent the user |
| `published` | string | yes | `format: date-time`, the only `format` in the schema |
| `timeSpent` | string | | ISO 8601 duration by description only |
| `completionRate` | number | | 0 to 1 |
| `dataSource` | string | yes | |
| `deviceContext.userAgent` | string | | |

An action has no id, sequence number, ingestion time or version. Inferred: two identical action records are indistinguishable at the schema level.

### 3.5 Taxonomies

Each taxonomy is a JSON array of `{notation, value}`. `value` appears in data; `notation` is a stable upper case code. No file carries definitions beyond the names.

| Taxonomy | Values |
|---|---|
| `ActionType-v1` (11) | `newsletter_subscribe`, `newsletter_unsubscribe`, `newsletter_click`, `newsletter_open`, `web_read`, `app_read`, `form_submit`, `survey_complete`, `comment_post`, `donation_made`, `irl_event_attend` |
| `ObjectType-v1` (4) | `article`, `newsletter`, `survey`, `survey_response` |
| `ConsentType-v1` (3) | `implicit`, `explicit`, `imported` |
| `DonorStatus-v1` (4) | `no`, `minor`, `major`, `lapsed` |
| `AgeGroup-v1` (4) | `underage`, `youth`, `adult`, `senior` |
| `Gender-v1` (4) | `male`, `female`, `non-binary`, `other/unspecified` |
| `EducationLevel-v1` (6) | `no_formal_education`, `primary_education`, `secondary_education`, `bachelors_degree`, `masters_degree`, `doctorate_degree` |
| `IncomeBracket-v1` (7) | `very_low`, `low`, `lower_middle`, `middle`, `upper_middle`, `high`, `very_high` |

The README table lists the fifth education value as `mains_degree` (line 156); the taxonomy file has `masters_degree`. The README says `value` is what appears in data (line 148) and that README tables must match the schema exactly (`adc/CONTRIBUTING.md`, line 37), so the file governs and the README entry is a typo.

### 3.6 Validation and versioning

`validate.mjs` validates the schema file and the taxonomy files, not data records. There is no function that takes a person, object or action and checks it. `format: date-time` is accepted as a key and never evaluated. `pattern` is only compiled. `enumFromTaxonomy` is only resolved to a file. Running `node scripts/validate.mjs` on the shipped schema reports 3 entities, 51 properties, 8 taxonomy references, 43 taxonomy entries and 15 warnings. An IRI is the web identifier a JSON-LD term expands to. The warnings are all listed as known issues in the README:

- Two IRI collisions on one entity: `primaryEmail` and `additionalEmails` both map to `schema:email`; `action.person` and `action.object` both map to `schema:identifier`.
- Eleven property names that fall through `@vocab` to schema.org IRIs that do not exist.
- The `object` entity's `@type` `Object`, which is not a schema.org class.
- The `@context` term `personID`, declared but unused.

Versioning follows SemVer for the standard as a whole. The version must agree in the schema `version` field, the `$id` URL, `package.json` and the git tag. Adding an optional property or a taxonomy value is a minor change. Removing or renaming a property or taxonomy value is a major change. For a taxonomy value this is done by introducing a new file such as `Gender-v2` and pointing the schema at it. There is no version field on a data record.

There are no example records anywhere in the repository.

### 3.7 How ADC compares with REMP

| Aspect | REMP | ADC |
|---|---|---|
| Shape | Relational state (subscriptions, consents, campaigns) in several MySQL databases plus a flat event log in Elasticsearch | Three entity types: person facets, object facets, and a flat action log |
| Subject identity | `user_id` string or integer, `browser_id`, `remp_session_id`, `remp_pageview_id` | One required `userID`, plus emails for matching; no identifier for an anonymous subject |
| Object identity | `articles.external_id` (CMS id, the upsert matching key) and `article_id` tag; `articles.url` is stored and accepted as an alternative lookup but is not the matching key | `objectID`, normally a URL |
| Event identity | `remp_pageview_id`, `remp_event_id`, `remp_commerce_id`, `transaction_id` | none |
| Event vocabulary | `pageviews` with `load`, `timespent`, `progress`; `commerce` with `checkout`, `payment`, `purchase`, `refund`; free `category`/`action` pairs such as `banner/show`, `mail/sent`, `mail-type/subscribe` | 11 fixed `actionType` values |
| Time | Client supplied `system.time` on pageview, commerce, event and entity payloads (impressions fall back to server time); aggregates in 20 minute buckets, compressed to daily after 90 days | One `published` timestamp per action; no ingestion time |
| Tenant | `token` field on pageview, commerce and event messages (not on entity or impression messages) | `dataSource` free string on every record |
| Consent | Per newsletter list and variant with rtm attribution on the subscription row; the list carries a `locked` flag (`mail_types.locked`) | One `consentType` and `consentDate` per person |
| Money | `revenue.amount` and `currency` on commerce events, `conversions.amount` | `donorStatus` with a prose $100 threshold, `donation_made` action |
| Versioning | None in the message format; renames handled by dual reads (`utm_*` or `rtm_*`) | SemVer on the standard, nothing per record |

Inferred: ADC is a snapshot and export model for mapping heterogeneous newsroom exports into one shape, while REMP is an operational system with a live event stream. The two overlap on reading actions and newsletter actions, and diverge on subscriptions, payments, anonymous traffic and campaign exposure.

## 4. Field by field mapping from REMP to ADC

The mappings below name the REMP source and the ADC target. Where a transformation would be needed, it is spelled out. Nothing in this repository performs any of these mappings; this section is a design proposal grounded in the fields that exist.

### 4.1 person

| ADC property | REMP source | Notes |
|---|---|---|
| `userID` | CRM `users.id` (inferred), as carried in Mailer `mail_user_subscriptions.user_id` (integer) and Beam `user_id` (string) | Inferred: to be global, the id needs a publisher namespace, for example `<dataSource>:<id>`; ADC does not say how to form one. Anonymous Beam `browser_id` has no home here, see 4.4. |
| `givenName`, `familyName` | CRM `users.first_name`, `users.last_name` (also `public_name`, and names on `addresses`) | Not in Beam, Mailer or Campaign. Mailer's `IUser` providers return only `id` and `email`. |
| `primaryEmail` | CRM `users.email` (UNIQUE); Mailer `mail_user_subscriptions.user_email`, `mail_logs.email` | Clear text in REMP. ADC has no hashing rule. |
| `additionalEmails` | CRM `user_connected_accounts.email` (Google or Apple sign in) | Mailer's `email-changed` call carries the old address but neither system keeps it. |
| `engagementScore` | none directly. Candidates: Beam `views_per_user_mv.total_views_last_30_days`, author or section segment membership, `churn_engagement_features` columns | ADC requires 1 to 5 with no definition. Any mapping is a publisher convention. Inferred: `churn_probability` is a different quantity (a 0 to 1 probability, not a 1 to 5 score), so mapping it here would change its meaning. |
| `tags` | Mailer `mail_types.code` of lists where `subscribed = 1`; Beam `segments.code` for segments the user is in (`GET /segments/{segment_code}/users`, `beam-go/cmd/segments/design/resources.go`, line 151) | ADC's description names "MailChimp lists", which matches Mailer lists. Segment membership would need a prefix to avoid collision. |
| `dataSource` | Beam `token` (property uuid), or a per publisher constant | Required. |
| `consentDate` | Mailer `mail_user_subscriptions.created_at` or `updated_at` of a subscribed row; CRM `users.confirmed_at` (account confirmation) or `users.email_validated_at` (deliverability, written by Mailer) | Mailer has one row per list; ADC has one date per person. A reducer (earliest or latest) is a convention. The two CRM timestamps are not newsletter consent. |
| `consentType` | `mail_types.auto_subscribe` on registration maps to `implicit`; a row created through `users/subscribe` maps to `explicit`; rows created by `mail:sync-user-subscriptions` map to `imported` | Inferred mapping. Mailer does not record how a row was created, so this can only be derived at write time, not from the stored row. |
| `demographics.*` | not in REMP (no age, gender, education or income column in any CRM table) | No source. |
| `location.addressCountry` | Campaign resolves country from the request IP at showtime with MaxMind but never stores it. Beam stores `ip` only when a server side caller sends `user.ip_address` (`track.go`, line 363); the browser library does not send it | Could be derived per event for server side callers only, not per person. |
| `location.postalCode`, `.addressLocality`, `.addressCountry` | CRM `addresses.zip`, `addresses.city`, `addresses.country_id` to `countries.iso_code`, per address type (`invoice`, `print`, `shop`, ...) with `is_default` | A person can have several addresses; the mapping has to pick one. |
| `behavior.donorStatus` | CRM `payment_items.type = 'donation'` sums (the churn module's `donation_amount_total`), `subscriptions.type = 'donation'`, and the `users.supporter` flag | The $100 threshold is prose; the CRM has no currency column, only a global `currency` config. `lapsed` has no definition. |
| `behavior.lastDonationDate` | CRM `payments.paid_at` of the latest payment with a `donation` item | |
| `interests.topics` | Beam `article_aggregated_views` joined to `article_tag` and `article_section`, or the churn `top_tag_share` input | Derived, not stored. |
| `interests.commentedOn` | not in REMP | No source. |

### 4.2 object

| ADC property | REMP source | Notes |
|---|---|---|
| `objectID` | Beam `articles.url` (or `canonical_url` from events) | ADC wants a URL. REMP keys articles by `external_id`; the URL is not unique in Beam and can change. Inferred: a stable mapping would need to carry `external_id` somewhere, and `subType` or a custom property are the only free slots. For newsletters, Mailer already serves a public preview page per template at `<mailer host>/mailer/preview/public/<public_code>` (`mailer-module/Presenters/PreviewPresenter.php`, `renderPublic`, routed by `Models/RouterFactory.php`), which can serve as the `objectID`. |
| `title` | `articles.title`; `mail_templates.subject` or `name` | |
| `type` | `articles.content_type = 'article'` maps to `article`; Mailer templates map to `newsletter` | Beam `content_type` values other than `article` have no ADC type. `survey` and `survey_response` have no REMP source. |
| `subType` | `articles.content_type`; `mail_types.code`; sections | Free text. |
| `published` | `articles.published_at`; `mail_logs.created_at` of the first send, or `mail_templates.created_at` | Required. |
| `subject` | `tags.name` via `article_tag`, `sections.name` via `article_section`, `tag_categories.name` | Authors have no ADC field; they could be appended to `subject` with a prefix, which is a convention. |
| `responses` | none | Campaign's newsletter rectangle banner posts an email address to an external endpoint, not a survey. |
| `dataSource` | `token` or publisher constant | Required. |

### 4.3 action

Each REMP event type maps to at most one `actionType`. The `person` field is the constraint in every row: ADC requires it, and REMP events carry a `user_id` only when the page injected one.

| REMP event | `actionType` | `person` | `object` | `published` | `timeSpent` | `completionRate` | `referrer` | `deviceContext.userAgent` |
|---|---|---|---|---|---|---|---|---|
| `pageviews` document (`action = load`) with `user_id` | `web_read` | `user_id` | `canonical_url` or `url` | `time` (client clock) | from the `pageviews_time_spent` document with the same `remp_pageview_id`, as `PT<n>S` | `article_progress` or `page_progress` from `pageviews_progress` | `referer` | `user_agent` |
| same without `user_id` | none | not expressible | | | | | | |
| `events` with `category = banner` | none | | | | | | | |
| `commerce` step `purchase` | none. `donation_made` fits only when the product is a donation | `user_id` | no object; `product_ids` are CRM ids, not URLs | `time` | | | | |
| `commerce` steps `checkout`, `payment`, `refund` | none | | | | | | | |
| Mailer `mail_logs.created_at` (send) | none | | | | | | | |
| Mailer `mail_logs.opened_at` | `newsletter_open` | `mail_logs.user_id` or email lookup | minted newsletter URL | `opened_at` (ESP event time, truncated to seconds) | | | | |
| Mailer `mail_logs.clicked_at` | `newsletter_click` | same | same | `clicked_at` | | | | |
| Mailer `mail_user_subscriptions` flip to `subscribed = 1`, or Hermes `user-subscribed`, or Beam `mail-type/subscribe` | `newsletter_subscribe` | `user_id` | minted URL for the `mail_types.code` list | `updated_at` or the Hermes `time` | | | | |
| same for unsubscribe | `newsletter_unsubscribe` | | | | | | | |
| Mailer `mail_logs.delivered_at`, `dropped_at`, `hard_bounced_at`, `spam_complained_at` | none | | | | | | | |
| Beam `conversions` row | none, unless treated as `donation_made` | `conversions.user_id` | `articles.url` of `article_id` | `paid_at` | | | | |
| `/track/entity` | none | no user fields on the document | | | | | | |
| `impressions` | none | no user id on the document | | | | | | |

#### A worked example

The ADC repository has no example records, so this one is constructed here from the mappings above. Values are synthetic. The comments name the REMP source.

```json
{
  "person": {
    "userID": "3d6e...-property-token:29953",
    "primaryEmail": "reader@example.org",
    "tags": ["daily_digest", "weekend"],
    "consentDate": "2026-03-02T08:15:00Z",
    "consentType": "explicit",
    "dataSource": "3d6e...-property-token"
  },
  "object": {
    "objectID": "https://example.org/world/12345-headline",
    "title": "Headline",
    "type": "article",
    "subType": "article",
    "published": "2026-04-01T06:00:00Z",
    "subject": ["world", "elections"],
    "dataSource": "3d6e...-property-token"
  },
  "action": {
    "actionType": "web_read",
    "person": "3d6e...-property-token:29953",
    "object": "https://example.org/world/12345-headline",
    "published": "2026-04-01T07:12:33Z",
    "timeSpent": "PT95S",
    "completionRate": 0.8,
    "referrer": "https://news.google.com/",
    "dataSource": "3d6e...-property-token",
    "deviceContext": { "userAgent": "Mozilla/5.0 ..." }
  }
}
```

Sources for each value:

- `userID` and `person`: the Beam `user_id` tag with the `token` prefix.
- `primaryEmail`: `mail_user_subscriptions.user_email`.
- `tags`: `mail_types.code` where `subscribed = 1`; `consentDate`: that row's `created_at`.
- `objectID` and `object`: `canonical_url`.
- `title` and `subject`: `articles.title`, `tags.name` and `sections.name`; object `published`: `articles.published_at`.
- action `published`: `system.time`; `timeSpent`: the `pageviews_time_spent` document with the same `remp_pageview_id`; `completionRate`: `article_progress`.
- `referrer`: `referer`; `userAgent`: `user_agent`; `dataSource`: `token`.

Only the `web_read` and the four `newsletter_*` action types have a REMP source. `app_read`, `form_submit`, `survey_complete`, `comment_post`, `donation_made` (except by convention for donation products) and `irl_event_attend` have none.

A `web_read` action built from Beam collapses three Elasticsearch documents (load, timespent, progress) that share a `remp_pageview_id`. Because timespent and progress are overwritten in place, a batch mapping can only read the final state. Inferred: a streaming mapping has to wait for the `unload: true` ping or emit updates. See section 6.

### 4.4 What ADC is missing relative to REMP

| Concept in REMP | ADC status | Evidence |
|---|---|---|
| Anonymous readers (`browser_id`, a UUID kept for 2 years in localStorage by default, or in a cookie when `storage: "cookie"` is configured) | Not expressible. `person.userID` and `action.person` are required. No device or cookie identifier exists. | `adc.schema.jsonld` lines 38 to 42, 233 to 237; grep for `cookie`, `anonym` finds nothing |
| Sessions (`remp_session_id`), pageview ids (`remp_pageview_id`), event ids (`remp_event_id`, `remp_commerce_id`) | No identifiers on `action` at all | README line 90, "`action` has no `idProp`" |
| Paid subscriptions (`subscriptions.start_time`, `end_time`, `length`, `is_paid`, `is_recurrent`, `subscription_ids` on events) | Absent. `newsletter_subscribe` is an event about a newsletter list, not a paid subscription state | grep for `subscription` finds no file |
| Payments (`commerce` events, `conversions`, `payments`, `recurrent_payments`, amounts, currency, `transaction_id`, `product_ids`, `payment_gateway`) | Absent. Only `donation_made` and `donorStatus` | grep for `payment`, `transaction`, `price`, `currency` finds nothing |
| Subscription tiers (`content_access`, `subscription_types.price`) | Absent | |
| Renewal, cancellation, expiry, churn | Absent. `lapsed` exists only as a donor status | |
| Consent per newsletter list and per variant, with `rtm_*` attribution and a `locked` flag | One `consentType` and `consentDate` per person, no channel, purpose, list or revocation | schema lines 80 to 88 |
| Delivery outcomes (`delivered_at`, `dropped_at`, `hard_bounced_at`, `spam_complained_at`) | Absent | |
| Article metadata: authors, sections, tag categories, `external_id`, `image_url`, `locked`, A/B `variants` | Only `title`, `subject`, `subType` | |
| Referrer medium and source (`derived_referer_medium`, `derived_referer_source`), campaign attribution (`rtm_source`, `rtm_medium`, `rtm_campaign`, `rtm_content`, `rtm_variant`) | Only `referrer` as a URI | grep for `utm`, `campaign` finds nothing |
| Campaign and banner exposure (`banner/show`, `banner/click`, `banner/close`, variants, control groups) | Absent. No impression or exposure action type | |
| Segments (`segments`, `segment_rules`, explicit membership) | Absent. `tags` and `interests.topics` are the nearest free text fields | |
| Tenant or property (`token`, `properties.uuid`) | Only `dataSource`, a free string | |
| Scroll depth and reading progress (`page_progress`, `article_progress`) | `completionRate` 0 to 1 can hold one of them | |
| Device facets (`derived_ua_device`, `derived_ua_os`, `derived_ua_browser`, window size, adblock) | Only `userAgent` free text | |
| IP or geo on events | Absent. `location` exists only on `person` | |
| Event ordering, late arrival, deduplication, ingestion time | Absent | |
| Schema version per record | Absent. Version lives in the schema file, `$id`, `package.json` and the git tag | |
| Record level validation | Absent. The validator checks the schema, not data | `validate.mjs` has no record checker |

### 4.5 What REMP is missing relative to ADC

| ADC concept | REMP status |
|---|---|
| Global person id across sources | None. No component validates the CRM id against the CRM (see 1.7). Beam and Campaign carry it as a string while Mailer stores it as an integer. |
| Names (`givenName`, `familyName`) | Stored only in the CRM (`users.first_name`, `users.last_name`, `addresses`). Beam, Mailer and Campaign hold none. |
| Demographics (age group, gender, education, income) | Not stored anywhere, including the CRM. |
| Location on the person | Stored only in the CRM `addresses` table (several per user, typed). Campaign resolves country per request and discards it. |
| Donor status and last donation date | Not as a status. The CRM has `users.supporter`, `subscriptions.type = 'donation'` and `payment_items.type = 'donation'`, from which the churn module derives donation totals. |
| Interests and commented topics | Not stored. Derivable from `article_aggregated_views` plus tags and sections for 30 days. |
| Consent type and consent date as a person attribute | Mailer has per list rows with timestamps but no record of how consent was obtained. The CRM has `confirmed_at` and `email_validated_at`, which are account and deliverability facts, not consent. |
| Surveys, survey responses, forms, comments, in person events | No tables or events. |
| App reads | The Tracker payload has no app or platform attribute. The Tracker derives `derived_ua_device`, `derived_ua_os` and `derived_ua_platform` from the user agent (`track.go`, lines 375 to 378), which distinguish device class and OS, not a native app. |
| A controlled action vocabulary | Beam `events` accept any `category` and `action` string. |
| A stable object URL | Beam keys articles by `external_id`; `url` is non unique and not used for joins. |
| Time spent as a per action duration | Beam has it as a mutable cumulative field per pageview, and MySQL only as aggregates: `article_timespents.sum` per article per window, and `article_aggregated_views.timespent` per article, user or browser, and day. |
| Completion rate | Beam has `page_progress` and `article_progress` only when progress tracking is enabled, which is off by default. |
| Record provenance (`dataSource`) | Beam has `token`; Mailer and Campaign have nothing comparable on their rows. |

## 5. Advantages and caveats of adopting ADC as a shared layer

### 5.1 Advantages

1. The reading and newsletter half of REMP already fits. Beam pageviews map to `web_read` with `timeSpent`, `completionRate`, `referrer` and `userAgent`, and Mailer opens, clicks, subscribes and unsubscribes map to the four `newsletter_*` action types. Inferred: pageviews are likely the largest share of events, but the repository holds no volume figures.
2. The model is small and has no tooling dependency. Three entities, 51 properties and 43 taxonomy values can be read from nine plain JSON files (one schema file and eight taxonomy files) by any language. The README gives Node and Python loaders and spreadsheet guidance.
3. The versioning policy is explicit. Additive changes are minor, breaking changes are major, taxonomy values are never renamed in place, and the version is pinned in the `$id` URL. A consumer can cite the exact schema it targets.
4. `dataSource` is required on every record. Beam's `token` can fill it directly, which gives a per property provenance for free.
5. The validator and CI protect the schema itself. Any proposed extension (for example a `subscription` entity or new action types) would pass through the same gate and the same review process (`adc/CONTRIBUTING.md`).
6. Person facets that REMP does not have (demographics, location, donor status, interests) are optional in ADC, so a REMP publisher can emit sparse person records without violating the schema.

### 5.2 Caveats

1. ADC cannot express anonymous traffic. Every browser pageview carries a `browser_id`, and `user_id` is present only when the page injected one. Beam itself keys segments, aggregates and conversion attribution by `browser_id`. Mapping to ADC drops this traffic or forces a convention such as putting `browser_id` into `userID`, which would contradict "Global user ID" and would create one person record per browser. Inferred: this is the single largest gap.
2. ADC has no subscriptions, payments or tiers. The churn module's entire subscription feature set (section 2.2) and Beam's `commerce` and `conversions` data have no target. A publisher adopting ADC as its only shared layer would lose the data the churn model depends on most.
3. ADC has no event identity or envelope. Without an action id, a sequence or an ingestion time, deduplication and late arrival handling are not possible at the schema level. REMP already has `remp_pageview_id`, `remp_event_id`, `remp_commerce_id` and `transaction_id` for exactly this.
4. ADC consent is a single person level pair. Mailer consent is per list, per variant, with the flag history overwritten on each flip and rtm attribution of the last change. Reducing that to one `consentType` and `consentDate` loses the per list state that drives sending.
5. The validator does not validate data. A record with a wrong taxonomy value, a malformed date or a missing required property passes through unnoticed, because no tool in the repository checks records. An adopter that wants record checks would have to write them; nothing in the repository does.
6. Identity is clear text email matching. `primaryEmail` and `additionalEmails` are described as the matching mechanism. REMP stores emails in the CRM (`users.email`, plus names, addresses, `ip` and `user_agent` on every payment) and in Mailer. Beam can also receive them through `/track/entity`: the Beam seeder defines an entity `user` with params `id` and `email` (`remp-master/Beam/database/seeders/EntitySeeder.php`, lines 18 to 36). Entity documents land in the `entities` index without `token`, `user_id` or `browser_id`, so such emails have no provenance. Nothing in ADC specifies hashing, salting or pseudonymisation. Sharing such records between organisations would need a privacy layer that ADC does not define.
7. The `objectID` pattern requires a domain shaped string (a dotted host name with a two letter or longer top level domain, with optional scheme, port and path), and it rejects the "hashed uuid" its own description mentions. REMP keys articles by CMS `external_id`, and Beam's `articles.url` is neither unique nor stable. Newsletters and lists have no URL at all. Minting URLs is a convention the adopter must own.
8. The 1.0.0 `@context` has known IRI collisions (`person` and `object` on `action` both map to `schema:identifier`), so JSON-LD processors cannot distinguish actor from object. The README lists these under "Known issues (to be addressed in 1.1)". Inferred: until then a linked data consumer cannot tell `person` from `object` on an action by IRI alone.
9. `engagementScore` has no definition and a minimum of 1, so "no engagement" cannot be expressed as 0. Churn probability is a different quantity and should stay out of this field.
10. The README has two places where it does not match the schema (`mains_degree`, and the paraphrased `answer` example), which matters because the README tables are the documented reference for spreadsheet users.

### 5.3 What adoption would look like in practice

Inferred, as a design position: ADC works as an export layer for the reading and newsletter graph of identified users, emitted daily from Beam Elasticsearch and Mailer MySQL. It does not work as REMP's internal event contract, and it does not carry what the churn model needs. A publisher that wants both would keep REMP's event stream and CRM tables as the system of record and derive ADC records from them. In parallel it would propose four additions to ADC through the documented process: a `subscription` entity, a payment or purchase action, an anonymous subject identifier, and an action id.

## 6. Notes for building streaming later

### 6.1 REMP already has a stream

Every tracked call is one Kafka message on `beam_events` (or one Pub/Sub message), except `/track/impressions`, which emits one message per impression block in the payload (`track.go`, lines 300 to 316). The message is an Influx line protocol point with the measurement name and a single `_json` field holding the flat document (`kafka_event_producer.go`, lines 56 to 66). Telegraf, the metrics agent that reads Kafka and writes Elasticsearch, consumes it with the consumer group `beam_consumers`. A consumer group is Kafka's way of letting several readers share a topic while each message goes to one of them. Inferred from Kafka semantics: a second consumer group can read the same topic without touching the Tracker or Telegraf. The measurement name is the only routing key; there is no message key, no partition key and no schema version field.

When Mailer is configured with `Tracker\Remp`, its `mail-type/subscribe`, `mail-type/subscribe-variant` and `mail-type/unsubscribe` events reach the same topic through the Tracker. `mail/sent` needs the `MailSentEventHandler` subscription, which is commented out in the example config. Mailer's delivery events (opens, clicks, bounces) never reach the topic: in webhook mode they become a `mailgun-event` message in Hermes, and in polling mode `mailgun:events` writes straight to `mail_logs`. Campaign's `banner/*` events reach the topic through the browser.

The CRM has its own stream that never reaches Kafka. Every subscription and payment change is emitted as a Hermes message on the CRM's Redis queues: `new-subscription`, `update-subscription`, `subscription-starts`, `subscription-ends`, `new-payment`, `payment-status-change`, `recurrent-payment-created`, `recurrent-payment-state-changed`, `recurrent-payment-renewed`, `user-registered`, `email-changed`, `user-confirmed`, `address-changed`, `new-address` (section 1.8). Scenarios consume them inside the CRM. The only CRM to Beam path is the `sales-funnel` Hermes message shaped like a Beam commerce payload, whose consumer is a publisher module outside the cloned repositories.

### 6.2 Which REMP events could become ADC actions in real time

| Topic message (measurement) | Condition | ADC action | What is missing at the moment the message arrives |
|---|---|---|---|
| `pageviews` | `user_id` present | `web_read` | `timeSpent` and `completionRate`, which arrive later as separate `pageviews_time_spent` and `pageviews_progress` messages keyed by `remp_pageview_id` |
| `pageviews_time_spent` | `user_id` present | update to the same `web_read` | ADC has no update semantics; the consumer must either emit the final value on `unload: true` or emit repeated actions and let the reader take the last |
| `pageviews_progress` | `user_id` present | update to `completionRate` | same |
| `events_v2` with `category = mail-type`, `action = subscribe` | always has `user_id` (Mailer sends it); requires `Tracker\Remp` to be configured | `newsletter_subscribe` | the object URL must be minted from `fields.mail_type` |
| `events_v2` with `category = mail-type`, `action = unsubscribe` | same | `newsletter_unsubscribe` | same |
| `events_v2` with `category = mail`, `action = sent` | same | none in ADC | |
| `events_v2` with `category = banner` | `user_id` present or not | none in ADC | |
| `commerce` steps | `user_id` present | none, except `donation_made` by convention | |
| `pageviews` without `user_id` | | not expressible | |

Mailer opens and clicks would have to be forwarded from the Hermes `mailgun-event` handler to the Tracker as `events_v2` to become visible on the topic. Nothing does that today. `TrackNewsletterArticlesHandler` shows the pattern (it posts `newsletter` events per article link) but is not registered in any shipped configuration.

Mailer subscription events are stamped with the time the Hermes message was emitted: `new DateTime()` at the subscription flip (`UserSubscriptionsRepository.php`, lines 417 and 432), and `TrackSubscribeUnsubscribeHandler` reuses that payload time. `mail/sent` is stamped with `time()` when `MailWorkerCommand.php` (line 294) dispatches `MailSentEvent` through the event dispatcher. Beam `pageviews` are stamped with the browser clock.

### 6.3 Identity resolution

The stream carries up to four subject keys on each message: `browser_id` (always, from the browser), `user_id` (when the page injected it), `remp_session_id` and `remp_pageview_id`. Mailer originated messages carry `user_id` only.

What exists today:

1. The Tracker records both ids when both are present (`track.go`, lines 481 to 489). The link exists per message, never as a table.
2. `AggregateConversionEvents` rebuilds a browser to user map per conversion by querying all `browser_id` values on events carrying that `user_id` in the 14 days before the conversion's `paid_at` (`DAYS_IN_PAST = 14`, `AggregateConversionEvents.php`, lines 23 and 73 to 81). It runs daily at 03:30 and per conversion through the queue.
3. `ProcessPageviewLoyalVisitors` groups by `user_id, browser_id` and maps browsers to users when both tags are present on the same day. It is a manual command: `BeamServiceProvider.php` (line 214) registers it, `Scheduler.php` does not run it, and nothing else calls it.
4. The Segments service, Campaign showtime and the churn module never link the two.

Inferred design for a stream:

1. Maintain a `browser_id` to `user_id` link table fed from every message that carries both. Beam already produces the raw material; the table is what is missing. Each link should carry the first and last time it was observed and the `token`, because a shared device can carry several users.
2. Decide the backfill rule explicitly. When a browser logs in, the events it produced anonymously before login can be re-attributed to the user. REMP never does this today, and the churn model never sees pre login activity. A streaming design must choose whether retroactive attribution is allowed, because it changes feature values after the fact.
3. Keep the ADC `person` requirement in mind. Only messages with a resolved `user_id` can become ADC actions. Everything else stays in a REMP native anonymous stream or waits in the link table.
4. The `user_id` type mismatch must be settled at the edge. Mailer sends the CRM integer as a string, the page sends whatever the publisher configured, and the Segments API accepts `@` and `.` in ids. A stream consumer should normalise to one canonical form and namespace it with the `token` or publisher.

### 6.4 Ordering

Kafka ordering is per partition. The Tracker sets no message key, and the Pub/Sub producer sets no `OrderingKey` (`pubsub_event_producer.go`, lines 48 to 50). Inferred: once `beam_events` has more than one partition, messages from one browser can be consumed out of order. Three REMP facts make ordering matter:

1. Timespent and progress are cumulative overwrites keyed by `remp_pageview_id`. Inferred: an out of order `timespent` ping would regress the stored value. A consumer should keep the maximum per `remp_pageview_id`, or key the topic by `remp_pageview_id` or `browser_id` at the producer.
2. Commerce steps form a funnel (`checkout`, `payment`, `purchase`, `refund`) linked by `commerce_session_id`. Inferred: the `purchase` message can arrive before `checkout` if they land on different partitions.
3. Mailer subscribe and unsubscribe events for one user and one list are state flips. Applying them out of order yields the wrong consent state. Mailer's own table overwrites with the latest write. Inferred: that is safe only because the writes come from one application.

Inferred: setting the Kafka message key to `browser_id` for browser originated events and to `user_id` for Mailer originated events would keep per subject order within a partition. The change is small but not one line. `Produce(ctx, table, time, data)` receives only the measurement, the time and the `_json` map, so the subject id would have to be added to the `EventProducer` interface and passed from `pushInternal`, which already holds the tags.

### 6.5 Late events

Three sources of lateness exist in REMP today:

1. The event time is the client clock (`system.time` parsed as RFC 3339). Skewed clocks move events across day boundaries. The churn SQL buckets by `DATE(pv.time)`, so a skewed clock changes which observation window a pageview falls into.
2. The last timespent and progress pings are sent on `beforeunload` over XMLHttpRequest. Inferred: they can be lost or arrive after the pageview's day has closed.
3. Mailgun events are polled with an intentional 60 second delay in the polling mode (`MailgunEventsCommand.php`, line 23). Inferred: opens and clicks can arrive hours or days after the send, because they depend on when the recipient acts.

Inferred: the batch pipeline hides this because it runs the next morning and Elasticsearch documents are overwritten in place. A stream consumer needs three things. A watermark per `token` or per subject (the event time below which it assumes no more events will arrive). An allowed lateness window. A rule for events that arrive after a feature window was emitted. ADC has no field for ingestion time or lateness, so those fields would have to travel in an envelope outside the ADC record.

### 6.6 Schema versioning

REMP has no schema registry. The only schemas are the goa design types in `beam-go/cmd/tracker/design/` (`user_types.go`, `resources.go`, `api.go`) and the Elasticsearch mappings, which are dynamic apart from the `subscriber` and `signed_in` booleans the Segments service pushes at start. When REMP renamed `utm_*` to `rtm_*`, it kept both for reads (`db_elastic.go`, lines 83 to 127) and renamed columns by migration in Beam and Mailer. The Kafka message carries no version.

ADC versions the standard as a whole and pins it in the `$id` URL, but a record does not say which version it follows.

Inferred requirements for a stream:

1. Put a schema version and the producing component in a message envelope, outside the ADC record, so a consumer can route by version. REMP messages could carry the Tracker build and the goa design version.
2. Treat the ADC taxonomies as versioned enums at the consumer. A new taxonomy value is a minor ADC release and must not break the consumer. A new taxonomy file (for example `ActionType-v2`) is a major release and needs a parallel consumer.
3. Register the REMP measurement names and their field sets somewhere machine readable. Today a consumer has to read Go code to learn that `pageviews_time_spent` has a `timespent` field and `pageviews_progress` has `page_progress` and `article_progress`.

### 6.7 Feeding the churn model from a stream instead of batch exports

The churn model is batch in four places. Each would need to change.

| Today | What a stream would need |
|---|---|
| `beam-bigquery-export` loads yesterday's `pageviews` and `pageviews_time_spent` into BigQuery at 03:05 | A consumer on `beam_events` that writes pageview, timespent and progress messages into BigQuery as they arrive. Because `pageviews_time_spent` is an overwrite in Elasticsearch, the stream version would hold many rows per `remp_pageview_id` and the engagement SQL would need `MAX(timespent)` per pageview instead of `SUM(timespent)` over rows. |
| `engagement_features.sql` computes 30 and 90 day windows once per day for the `(user_id, observed_date)` pairs exported today, with windows ending at `observed_date - 1 day` | Option A: keep the daily recompute and point the same SQL at streamed rows. Option B: maintain per user rolling aggregates updated per message. B must track distinct pageviews, days, articles, tags and authors over 30 and 90 days, plus timespent. The delta features (`30d vs prev 30d`) and `top_tag_share` need per tag counters. |
| Subscription features are exported by PHP from CRM MySQL once per day, with `observed_at = now` for scoring | Subscription state changes are not on the Kafka topic. The CRM already emits them as Hermes messages on its Redis queues (`new-subscription`, `subscription-starts`, `subscription-ends`, `payment-status-change`, `recurrent-payment-state-changed`, `recurrent-payment-renewed`, section 1.8), so a bridge from Hermes to Kafka would put subscription and payment changes on the stream without changing the CRM. Even then the subscription features need as-of history (tenure, gaps, payment counts), which the CRM holds as rows, not events. Inferred: the realistic first step is to keep the daily subscription export and stream only the engagement side. |
| `ML.PREDICT` runs once per day over `churn_scoring_all_features`, keyed by `(prediction_date, subscription_id)` | Scoring could run on a schedule shorter than a day over the same view, or per user when an engagement aggregate crosses a threshold. The BigQuery predictions table already supports many rows per subscription. The CRM `churn_predictions` table holds one row per subscription. `churn:download_churn_predictions` picks the newest row, so it would simply see a fresher one. |

Constraints that do not change with streaming:

1. The model only sees signed in pageviews joined by `user_id`. Streaming does not add anonymous traffic unless the identity link table from 6.3 is built and the engagement SQL is changed to use it.
2. `export_date` is the handle the engagement script uses to find today's rows. A streaming version needs a different handle, such as a run id, because `export_date` is the wall clock date and cannot distinguish two runs on one day. The `--now-date` option already lets the subscription export be rerun for a past instant, so a stream fed engagement table only needs the same `observed_at` handle.
3. Training rows depend on the full 90 day pageview history before each historical observation date still being present. A stream sink must retain at least that much. Because REMP has no automated Elasticsearch retention (the manual `service:elastic-data-retention` command exists but is not scheduled), the history available to a backfill today is whatever the operator kept. The training label still needs 28 days to elapse after `end_time`, so training stays batch by definition.
4. Feature values must stay as of `observed_at`. Retroactive identity attribution (6.3) would change historical features and must be versioned or excluded from training.
5. The timezone split between CRM MySQL dates and BigQuery `DATE()` on UTC timestamps (section 2.6) becomes more visible when windows are computed continuously. The stream should carry one canonical timezone.

## Appendix: sources consulted

| Area | Files |
|---|---|
| Beam MySQL | `beam-module/database/migrations/*.php` (78), `remp-master/Beam/database/migrations/2023_09_07_080805_create_article_meta_table.php`, `beam-module/database/schema/mysql-schema.sql`, `beam-module/src/Model/*.php`, `beam-module/src/Console/Commands/*.php`, `beam-module/src/Scheduler.php`, `beam-module/src/Http/Controllers/` |
| Beam events | `beam-go/cmd/tracker/design/*.go`, `beam-go/cmd/tracker/controller/*.go`, `beam-go/cmd/segments/design/*.go`, `beam-go/cmd/segments/main.go`, `beam-go/model/*.go`, `beam-module/resources/assets/js/remplib.js`, `remp-master/Package/remp/js/remplib.js`, `remp-master/Docker/telegraf/telegraf.conf`, `remp-master/Docker/elasticsearch/*`, `remp-master/docker-compose.yml`, `remp-master/Composer/remp-commons/src/journal/*.php` |
| Mailer | `mailer-module/migrations/*.php` (86), `mailer-module/Repositories/*.php`, `mailer-module/Hermes/*.php`, `mailer-module/Api/`, `mailer-module/Models/`, `mailer-module/config/*.neon`, `remp-master/Mailer/app/config/*.neon*` |
| Campaign | `campaign-module/database/migrations/*.php` (69), `campaign-module/database/schema/mysql-schema.sql`, `campaign-module/src/*.php`, `campaign-module/src/Http/Showtime/Showtime.php`, `campaign-module/public/showtime.php`, `campaign-module/resources/assets/js/remplib.js`, `campaign-module/src/Contracts/` |
| SSO and shared libraries | `remp-master/Sso/database/migrations/*.php` (3), `remp-master/Sso/app/`, `remp-master/Composer/laravel-sso/`, `remp-master/Composer/laravel-helpers/` |
| CRM (separate repositories) | `crm-skeleton/composer.json`; for each of `crm-users-module`, `crm-api-module`, `crm-admin-module`, `crm-subscriptions-module`, `crm-upgrades-module`, `crm-gifts-module`, `crm-print-module`, `crm-payments-module`, `crm-stripe-module`, `crm-invoices-module`, `crm-segment-module`, `crm-scenarios-module`, `crm-dashboard-module`, `crm-application-module`, `crm-remp-mailer-module`, `crm-onboarding-module`, `crm-issues-module`, `crm-products-module`, `crm-salesfunnel-module`: `src/migrations/*.php`, `src/Repositories/`, `src/Models/`, `src/Api/`, `src/Events/`, `src/Hermes/`, `src/DataProviders/`, `src/*Module.php`, `src/config/config.neon`, `README.md` |
| Churn module | `churn/README.md`, `churn/src/Commands/RunChurnPipelineCommand.php`, `churn/src/Commands/DownloadChurnPredictionsCommand.php`, `churn/src/Commands/bigquery/engagement_features.sql`, `churn/src/Commands/bigquery/churn_predict.sql`, `churn/src/Models/ChurnExport/*.php`, `churn/src/Models/BigQuery/StagingMergeSqlBuilder.php`, `churn/src/migrations/20260723205508_add_churn_predictions_table.php`, `churn/src/Tests/` |
| ADC | `adc/schema/adc.schema.jsonld`, `adc/taxonomies/*.json`, `adc/scripts/validate.mjs`, `adc/scripts/validate.test.mjs`, `adc/README.md`, `adc/CHANGELOG.md`, `adc/CONTRIBUTING.md`, `adc/.github/workflows/*.yml` |
