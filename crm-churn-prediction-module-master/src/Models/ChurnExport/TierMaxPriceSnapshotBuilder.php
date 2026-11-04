<?php

namespace Crm\ChurnPredictionModule\Models\ChurnExport;

use Nette\Database\Explorer;

/**
 * For each month and combination of content-accesses and subscription length, builder computes maximum and median prices
 * of subscription types existing up to that point-in-time (approximated by the end of the month).
 *
 * This is useful for the churn model as it can differentiate subscription types based on discount percentage
 * compared to other similar subscriptions.
 *
 * Sample output (formated in JSON):
 *
  {
  "2026-07": {
    "print,web": {
      "28": {
        "max": 14.99,
        "median": 14.99
      },
      "365": {
        "max": 173.9,
        "median": 106
      },
    },
    "web": {
      "28": {
        "max": 5.99,
        "median": 5.99
      },
      "365": {
        "max": 65.9,
        "median": 65.9
      }
    }
  }
 */
readonly class TierMaxPriceSnapshotBuilder
{
    public function __construct(private Explorer $database)
    {
    }

    /**
     * Builds one snapshot per calendar month covering [$start, $end] inclusive
     * (by containing month).
     *
     * @return array<string, array<string, array<int, array{max: float, median: float}>>>
     *   Keyed as [yyyy-mm][content_access_codes][length] => ['max' => …, 'median' => …].
     */
    public function build(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $result = [];
        foreach ($this->generateMonthStarts($start, $end) as $monthStart) {
            $result[$monthStart->format('Y-m')] = $this->fetchTierPriceStats($monthStart);
        }
        return $result;
    }

    private function generateMonthStarts(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $months = [];
        $cursor = $start->modify('first day of this month')->setTime(0, 0, 0);
        $endMonth = $end->modify('first day of this month')->setTime(0, 0, 0);
        while ($cursor <= $endMonth) {
            $months[] = $cursor;
            $cursor = $cursor->modify('first day of next month');
        }
        return $months;
    }

    /**
     * @return array<string, array<int, array{max: float, median: float}>>
     *   indexed by [content_access_codes][length] => ['max' => …, 'median' => …]
     */
    private function fetchTierPriceStats(\DateTimeImmutable $asOf): array
    {
        $sql = <<<SQL
            WITH type_activity AS (
                SELECT subscription_type_id, COUNT(*) AS active_count
                FROM subscriptions
                WHERE start_time <= ?
                  AND end_time   >  DATE_SUB(?, INTERVAL 365 DAY)
                GROUP BY subscription_type_id
            ),
            types_with_access AS (
                SELECT
                    st.id, st.length, st.price, ta.active_count,
                    COALESCE(GROUP_CONCAT(ca.name ORDER BY ca.name SEPARATOR ','), '') AS content_access_codes
                FROM subscription_types st
                JOIN type_activity ta ON ta.subscription_type_id = st.id
                LEFT JOIN subscription_type_content_access stca ON stca.subscription_type_id = st.id
                LEFT JOIN content_access ca ON ca.id = stca.content_access_id
                WHERE st.created_at <= ?
                GROUP BY st.id, st.length, st.price, ta.active_count
            ),
            cumulative AS (
                SELECT
                    content_access_codes, length, price, active_count,
                    SUM(active_count) OVER (
                        PARTITION BY content_access_codes, length
                        ORDER BY price
                        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                    ) AS cumulative_count,
                    SUM(active_count) OVER (
                        PARTITION BY content_access_codes, length
                    ) AS total_count
                FROM types_with_access
            )
            SELECT
                content_access_codes,
                length,
                MAX(price) AS max_price,
                MIN(CASE WHEN cumulative_count * 2 >= total_count THEN price END) AS median_price
            FROM cumulative
            GROUP BY content_access_codes, length
        SQL;

        $result = [];
        foreach ($this->database->query($sql, $asOf, $asOf, $asOf) as $row) {
            $codes = (string) $row['content_access_codes'];
            $length = (int) $row['length'];
            $result[$codes][$length] = [
                'max' => (float) $row['max_price'],
                'median' => (float) $row['median_price'],
            ];
        }
        return $result;
    }
}
