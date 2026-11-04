<?php

namespace Crm\ChurnPredictionModule\Models\ChurnExport;

use Crm\PaymentsModule\Models\Payment\PaymentStatusEnum;
use Crm\PaymentsModule\Models\RecurrentPayment\RecurrentPaymentStateEnum;

/**
 * @phpstan-type SubscriptionRow array{id: int, start_time: \DateTimeInterface, end_time: \DateTimeInterface, length: int, is_paid: int, is_recurrent: int, sub_created_at: \DateTimeInterface, upgrade_created_at: \DateTimeInterface|null, is_trial: int}
 * @phpstan-type UserPayment array{status: string, created_at: \DateTimeInterface, donation_amount: float, product_amount: float, product_count: int}
 * @phpstan-type SubscriptionPayment array{created_at: \DateTimeInterface, amount: float}
 * @phpstan-type RecurrentPayment array{created_at: \DateTimeInterface, updated_at: \DateTimeInterface, expires_at: \DateTimeInterface|null, charge_at: \DateTimeInterface, state: string, cycle: int|null}
 */
class ChurnFeatureCalculator
{
    private const PAID_PAYMENT_STATUSES = [
        PaymentStatusEnum::Paid->value,
        PaymentStatusEnum::Prepaid->value,
        PaymentStatusEnum::Authorized->value,
    ];

    /**
     * @param list<UserPayment> $userPayments All payments for one user (any order).
     *        Each row: status (e.g. 'fail'|'paid'|'prepaid'), created_at, donation_amount,
     *        product_amount, product_count. Rows with created_at > $observedAt are skipped.
     * @param \DateTimeInterface $observedAt Observation time the features are computed as-of.
     */
    public function computePaymentFeatures(array $userPayments, \DateTimeInterface $observedAt): array
    {
        $twelveMonthsAgo = \DateTimeImmutable::createFromInterface($observedAt)->modify('-365 days');
        $thirtyDaysAgo   = \DateTimeImmutable::createFromInterface($observedAt)->modify('-30 days');

        $failures12m = 0;
        $failures30d = 0;
        $lastFailureAt = null;
        $successes12m = 0;
        $donationTotal = 0.0;
        $donation12m = 0.0;
        $purchasesTotal = 0;
        $purchases12m = 0;
        $purchaseRevenueTotal = 0.0;
        $purchaseRevenue12m = 0.0;

        foreach ($userPayments as $p) {
            $createdAt = $p['created_at'];
            if ($createdAt > $observedAt) {
                continue;
            }
            $in12m = $createdAt > $twelveMonthsAgo;
            $in30d = $createdAt > $thirtyDaysAgo;

            if ($p['status'] === 'fail') {
                if ($in12m) {
                    $failures12m++;
                }
                if ($in30d) {
                    $failures30d++;
                }
                if ($lastFailureAt === null || $createdAt > $lastFailureAt) {
                    $lastFailureAt = $createdAt;
                }
            } elseif (in_array($p['status'], self::PAID_PAYMENT_STATUSES, true)) {
                if ($in12m) {
                    $successes12m++;
                }
                $donationTotal += $p['donation_amount'];
                if ($in12m) {
                    $donation12m += $p['donation_amount'];
                }
                $purchasesTotal += $p['product_count'];
                if ($in12m) {
                    $purchases12m += $p['product_count'];
                }
                $purchaseRevenueTotal += $p['product_amount'];
                if ($in12m) {
                    $purchaseRevenue12m += $p['product_amount'];
                }
            }
        }

        $denom = $failures12m + $successes12m;

        return [
            'payment_failures_12m' => $failures12m,
            'payment_retries_30d' => $failures30d,
            'days_since_last_payment_failure' => $lastFailureAt !== null ? $this->daysDifference($lastFailureAt, $observedAt) : null,
            'payment_successes_12m' => $successes12m,
            'payment_success_ratio' => $denom > 0 ? $successes12m / $denom : null,
            'donation_amount_total' => $donationTotal,
            'donation_amount_12m' => $donation12m,
            'purchases_total' => $purchasesTotal,
            'purchases_12m' => $purchases12m,
            'purchases_revenue_total' => $purchaseRevenueTotal,
            'purchases_revenue_12m' => $purchaseRevenue12m,
            'avg_purchase_value' => $purchasesTotal > 0 ? $purchaseRevenueTotal / $purchasesTotal : null,
        ];
    }

    /**
     * @param list<\DateTimeInterface> $userCancellationTimes Recurrent-payment cancellation
     *        timestamps (state = 'user_stop') for one user (any order).
     * @param \DateTimeInterface       $observedAt                  Observation time the features are computed as-of.
     */
    public function computeCancellationFeatures(array $userCancellationTimes, \DateTimeInterface $observedAt): array
    {
        $oneYearAgo = \DateTimeImmutable::createFromInterface($observedAt)->modify('-365 days');

        // Sort most-recent-first
        usort($userCancellationTimes, fn($a, $b) => $b <=> $a);

        $total = 0;
        $count365d = 0;
        $lastCancellation = null;

        foreach ($userCancellationTimes as $cancellationTime) {
            if ($cancellationTime > $observedAt) {
                continue;
            }
            $total++;
            if ($cancellationTime > $oneYearAgo) {
                $count365d++;
            }
            if ($lastCancellation === null) {
                $lastCancellation = $cancellationTime;
            }
        }

        return [
            'cancellations_total' => $total,
            'cancellations_365d' => $count365d,
            'days_since_last_cancellation' => $lastCancellation !== null ? $this->daysDifference($lastCancellation, $observedAt) : null,
        ];
    }

    /**
     * Features derived from the user's recurrent_payment at observed_at — the most recently created
     * row with created_at ≤ observed_at.
     *
     *   card_expires_in_days — days from observed_at to the card's expiry, signed (negative = already expired
     *     N days ago, itself a strong churn signal). NULL when there is no card/expiry on file.
     *
     *   active_recurring_stopped — 1 if that recurring is in any terminal stop state
     *     (user_stop / admin_stop / system_stop) AND the stop was recorded (updated_at) on or before
     *     observed_at. charge_failed is excluded (transient — still retrying).
     *
     *   renewal_cycle / is_first_renewal — the recurring's position in its payment chain (the `cycle`
     *     column).
     *
     *   card_expires_before_renewal / days_card_valid_after_renewal — whether the card on file expires
     *     before the scheduled next charge (charge_at) → the auto-renewal will fail = involuntary churn.
     *     days_card_valid_after_renewal is signed: negative = card dies that many days before the charge.
     *
     * @param list<RecurrentPayment> $userRecurrentPaymentsDesc The user's recurrent_payments,
     *        most-recent-first by created_at. The first row with created_at ≤ $observedAt is the active one.
     * @param \DateTimeInterface $observedAt Observation time the features are computed as-of.
     */
    public function computeActiveRecurringFeatures(array $userRecurrentPaymentsDesc, \DateTimeInterface $observedAt): array
    {
        $nullFeatures = [
            'card_expires_in_days' => null,
            'active_recurring_stopped' => null,
            'renewal_cycle' => null,
            'is_first_renewal' => null,
            'card_expires_before_renewal' => null,
            'days_card_valid_after_renewal' => null,
        ];

        $latest = null;
        foreach ($userRecurrentPaymentsDesc as $rp) {
            if ($rp['created_at'] <= $observedAt) {
                $latest = $rp;
                break;
            }
        }
        if ($latest === null) {
            return $nullFeatures;
        }

        $cardExpiresInDays = null;
        if ($latest['expires_at'] !== null) {
            $cardExpiresInDays = $this->daysDifference($observedAt, $latest['expires_at']);
            if ($latest['expires_at'] < $observedAt) {
                $cardExpiresInDays = -$cardExpiresInDays;
            }
        }

        $stoppedStates = [
            RecurrentPaymentStateEnum::UserStop->value,
            RecurrentPaymentStateEnum::AdminStop->value,
            RecurrentPaymentStateEnum::SystemStop->value,
        ];
        $stoppedByObservedAt = (in_array($latest['state'], $stoppedStates, true)
            && $latest['updated_at'] !== null
            && $latest['updated_at'] <= $observedAt) ? 1 : 0;

        $cycle = $latest['cycle'];
        $isFirstRenewal = $cycle !== null ? ($cycle <= 1 ? 1 : 0) : null;

        $cardExpiresBeforeRenewal = null;
        $daysCardValidAfterRenewal = null;
        if ($latest['expires_at'] !== null) {
            $cardExpiresBeforeRenewal = $latest['expires_at'] < $latest['charge_at'] ? 1 : 0;
            $days = $this->daysDifference($latest['charge_at'], $latest['expires_at']);
            if ($latest['expires_at'] < $latest['charge_at']) {
                $days = -$days;
            }
            $daysCardValidAfterRenewal = $days;
        }

        return [
            'card_expires_in_days' => $cardExpiresInDays,
            'active_recurring_stopped' => $stoppedByObservedAt,
            'renewal_cycle' => $cycle,
            'is_first_renewal' => $isFirstRenewal,
            'card_expires_before_renewal' => $cardExpiresBeforeRenewal,
            'days_card_valid_after_renewal' => $daysCardValidAfterRenewal,
        ];
    }

    /**
     * Computes two ABT features comparing the user's payment to the price distribution
     * of their (content_access_codes, length) tier at observed_at:
     *
     *   - `payment_pct_of_tier_max_price` — vs the premium variant in the tier.
     *     ~1.0 means "on the premium plan", lower means "on a cheaper variant or promo".
     *   - `payment_pct_of_tier_median_price` — vs the weighted median (typical user).
     *     ~1.0 means "paying what most users pay", <1.0 means "below typical".
     *
     * @param array{observed_at?: \DateTimeInterface, content_access_codes?: string|null, subscription_length?: int|numeric-string|null, payment_amount?: float|numeric-string|null} $rowData
     *   The ABT row being assembled. Only these four keys are read; observed_at selects the
     *   monthly snapshot, (content_access_codes, subscription_length) identify the tier.
     * @param array<string, array<string, array<int, array{max: float, median: float}>>> $tierPriceByMonth
     *   Keyed as [yyyy-mm][content_access_codes][length] => ['max' => …, 'median' => …].
     */
    public function computeTierPriceFeature(array $rowData, array $tierPriceByMonth): array
    {
        $nullFeatures = [
            'payment_pct_of_tier_max_price' => null,
            'payment_pct_of_tier_median_price' => null,
        ];

        $observedAt = $rowData['observed_at'] ?? null;
        $tierKey = $rowData['content_access_codes'] ?? null;
        $length = isset($rowData['subscription_length']) ? (int) $rowData['subscription_length'] : 0;
        $paymentAmount = ($rowData['payment_amount'] ?? null) !== null ? (float) $rowData['payment_amount'] : null;

        if (!$observedAt instanceof \DateTimeInterface || $tierKey === null || $paymentAmount === null) {
            return $nullFeatures;
        }

        $monthKey = $observedAt->format('Y-m');
        $stats = $tierPriceByMonth[$monthKey][$tierKey][$length] ?? null;
        if ($stats === null) {
            return $nullFeatures;
        }

        return [
            'payment_pct_of_tier_max_price'    => $stats['max']    > 0 ? $paymentAmount / $stats['max']    : null,
            'payment_pct_of_tier_median_price' => $stats['median'] > 0 ? $paymentAmount / $stats['median'] : null,
        ];
    }

    /**
     * Longest unbroken run of subscription coverage, in days, treating gaps of up to
     * $churnDays as still "continuous".
     *
     * @param list<SubscriptionRow> $subscriptionRows All of the user's subscriptions (any order).
     * @param \DateTimeInterface $observedAt Observation time the features are computed as-of.
     * @param int $churnDays Gap tolerance: two subscriptions are part of the same streak
     *        if the later one starts within $churnDays of the earlier one's end.
     */
    public function computeLongestContinuousTenure(array $subscriptionRows, \DateTimeInterface $observedAt, int $churnDays): int
    {
        $rows = [];
        foreach ($subscriptionRows as $row) {
            if ($row['start_time'] >= $observedAt) {
                continue;
            }
            $realEndTime = min($row['end_time'], $observedAt);
            $gapEndCandidate = \DateTimeImmutable::createFromInterface($row['end_time'])->modify("+{$churnDays} days");
            $gapEndTime = min($gapEndCandidate, $observedAt);
            $rows[] = [
                'start_time' => $row['start_time'],
                'real_end_time' => $realEndTime,
                'gap_end_time' => $gapEndTime,
            ];
        }

        if (empty($rows)) {
            return 0;
        }

        usort($rows, fn($a, $b) => $a['start_time'] <=> $b['start_time']);

        $longest = 0;
        $streakStart = $rows[0]['start_time'];
        $streakRealEnd = $rows[0]['real_end_time'];
        $streakGapEnd = $rows[0]['gap_end_time'];

        foreach ($rows as $row) {
            if ($row['start_time'] > $streakGapEnd) {
                $longest = max($longest, $this->daysDifference($streakStart, $streakRealEnd));
                $streakStart = $row['start_time'];
                $streakRealEnd = $row['real_end_time'];
                $streakGapEnd = $row['gap_end_time'];
            } else {
                $streakRealEnd = $streakRealEnd > $row['real_end_time'] ? $streakRealEnd : $row['real_end_time'];
                $streakGapEnd = $streakGapEnd > $row['gap_end_time'] ? $streakGapEnd : $row['gap_end_time'];
            }
        }
        return max($longest, $this->daysDifference($streakStart, $streakRealEnd));
    }

    /**
     * @param list<SubscriptionRow> $subscriptionRows All of the user's subscriptions (any order).
     *        Rows starting at or after $observedAt are ignored.
     * @param \DateTimeInterface $observedAt Observation time the features are computed as-of.
     */
    public function computeDaysSinceFirstSubscription(array $subscriptionRows, \DateTimeInterface $observedAt): int
    {
        $firstStart = null;
        foreach ($subscriptionRows as $row) {
            if ($row['start_time'] >= $observedAt) {
                continue;
            }
            if ($firstStart === null || $row['start_time'] < $firstStart) {
                $firstStart = $row['start_time'];
            }
        }
        return $firstStart === null ? 0 : $this->daysDifference($firstStart, $observedAt);
    }

    /**
     * Total distinct days covered by subscriptions (overlapping intervals merged, not double-counted).
     *
     * @param list<SubscriptionRow> $subscriptionRows All of the user's subscriptions (any order).
     *        Rows starting at or after $observedAt are ignored; end_time is clamped to $observedAt.
     * @param \DateTimeInterface $observedAt Observation time the features are computed as-of.
     */
    public function computeDaysSubscribedTotal(array $subscriptionRows, \DateTimeInterface $observedAt): int
    {
        $intervals = [];
        foreach ($subscriptionRows as $row) {
            if ($row['start_time'] >= $observedAt) {
                continue;
            }
            $end = min($row['end_time'], $observedAt);
            $intervals[] = [$row['start_time'], $end];
        }

        if (empty($intervals)) {
            return 0;
        }

        usort($intervals, fn($a, $b) => $a[0] <=> $b[0]);

        $totalDays = 0;
        [$currentStart, $currentEnd] = $intervals[0];
        for ($i = 1, $n = count($intervals); $i < $n; $i++) {
            [$start, $end] = $intervals[$i];
            if ($start <= $currentEnd) {
                if ($end > $currentEnd) {
                    $currentEnd = $end;
                }
            } else {
                $totalDays += $this->daysDifference($currentStart, $currentEnd);
                $currentStart = $start;
                $currentEnd = $end;
            }
        }
        $totalDays += $this->daysDifference($currentStart, $currentEnd);

        return $totalDays;
    }

    /**
     * Distinct days in the $windowDays-day window before the observation
     *
     * @param list<SubscriptionRow> $subscriptionRows All of the user's subscriptions (any order).
     * @param \DateTimeInterface $observedAt Observation time the features are computed as-of.
     */
    public function computeDaysSubscribedInWindow(array $subscriptionRows, \DateTimeInterface $observedAt, int $windowDays): int
    {
        $windowLast = \DateTimeImmutable::createFromInterface($observedAt)
            ->setTime(0, 0)
            ->modify('-1 day');
        $windowFirst = $windowLast
            ->modify('-' . ($windowDays - 1) . ' days');

        $intervals = [];
        foreach ($subscriptionRows as $row) {
            $first = max(\DateTimeImmutable::createFromInterface($row['start_time'])->setTime(0, 0), $windowFirst);
            $last = min(\DateTimeImmutable::createFromInterface($row['end_time'])->setTime(0, 0), $windowLast);
            if ($first <= $last) {
                $intervals[] = [$first, $last];
            }
        }

        usort($intervals, fn($a, $b) => $a[0] <=> $b[0]);

        $coveredDays = 0;
        $countedUntil = null;
        foreach ($intervals as [$first, $last]) {
            if ($countedUntil !== null && $first <= $countedUntil) {
                $first = $countedUntil->modify('+1 day');
            }
            if ($first <= $last) {
                $coveredDays += $this->daysDifference($first, $last) + 1;
                $countedUntil = $last;
            }
        }

        return $coveredDays;
    }

    /**
     * Aggregate history features: totals, paid/free split, inter-subscription gaps, upgrades.
     *
     * @param list<SubscriptionRow> $subscriptionRows All of the user's subscriptions (any order).
     *        Rows starting at or after $observedAt are ignored.
     * @param array<int, list<SubscriptionPayment>> $subscriptionPayments Payments keyed by
     *        subscription id; each row: created_at, amount. Payments after $observedAt are not summed.
     * @param \DateTimeInterface $observedAt Observation time the features are computed as-of.
     */
    public function computeSubscriptionHistoryFeatures(
        array $subscriptionRows,
        array $subscriptionPayments,
        \DateTimeInterface $observedAt,
    ): array {
        $totalAmount = 0.0;
        $countSubs = 0;
        $paidSubs = 0;
        $freeSubs = 0;
        $totalDaysPaid = 0;
        $totalDaysFree = 0;
        $upgradedSubs = 0;

        $firstPaidCreatedAt = null;
        $lastPaidCreatedAt = null;

        $previousEnd = null;
        $realGapTotal = 0;
        $realGapCount = 0;
        $maxGap = null;

        foreach ($subscriptionRows as $row) {
            if ($row['start_time'] >= $observedAt) {
                continue;
            }

            $countSubs++;
            foreach ($subscriptionPayments[$row['id']] ?? [] as $payment) {
                if ($payment['created_at'] <= $observedAt) {
                    $totalAmount += $payment['amount'];
                }
            }

            if ($row['is_paid'] === 1) {
                $paidSubs++;
                $totalDaysPaid += $row['length'];
                if ($firstPaidCreatedAt === null || $row['sub_created_at'] < $firstPaidCreatedAt) {
                    $firstPaidCreatedAt = $row['sub_created_at'];
                }
                if ($lastPaidCreatedAt === null || $row['sub_created_at'] > $lastPaidCreatedAt) {
                    $lastPaidCreatedAt = $row['sub_created_at'];
                }
            } else {
                $freeSubs++;
                $totalDaysFree += $row['length'];
            }

            $isUpgraded = ($row['upgrade_created_at'] !== null
                && $row['upgrade_created_at'] <= $observedAt) ? 1 : 0;

            $upgradedSubs += $isUpgraded;

            if ($previousEnd !== null && $row['start_time'] > $previousEnd) {
                $gap = $this->daysDifference($previousEnd, $row['start_time']);
                $realGapTotal += $gap;
                $realGapCount++;
                if ($maxGap === null || $gap > $maxGap) {
                    $maxGap = $gap;
                }
            }
            $rowEnd = min($row['end_time'], $observedAt);
            if ($previousEnd === null || $rowEnd > $previousEnd) {
                $previousEnd = $rowEnd;
            }
        }

        $averageAmount = $countSubs > 0 ? $totalAmount / $countSubs : 0.0;
        $averageSubscriptionLengthDays = $countSubs > 0 ? ($totalDaysPaid + $totalDaysFree) / $countSubs : null;
        $averageGap = $realGapCount > 0 ? $realGapTotal / $realGapCount : null;
        $daysBetweenFirstAndLastPaidSub = ($firstPaidCreatedAt !== null && $lastPaidCreatedAt !== null)
            ? $this->daysDifference($firstPaidCreatedAt, $lastPaidCreatedAt)
            : 0;

        return [
            'total_amount' => $totalAmount,
            'average_amount' => $averageAmount,
            'total_subscription_count' => $countSubs,
            'paid_subs' => $paidSubs,
            'free_subs' => $freeSubs,
            'total_days_paid_sub' => $totalDaysPaid,
            'total_days_free_sub' => $totalDaysFree,
            'days_between_first_and_last_paid_sub' => $daysBetweenFirstAndLastPaidSub,
            'avg_subscription_length_days' => $averageSubscriptionLengthDays,
            'avg_gap_length_days' => $averageGap,
            'max_gap' => $maxGap,
            'subscription_gaps_total' => $realGapCount,
            'upgraded_subs' => $upgradedSubs,
        ];
    }

    /**
     * Compares the current subscription to the user's previous paid one (recurrence change).
     *
     * @param list<SubscriptionRow> $subscriptionRows All of the user's subscriptions (any order).
     * @param int $currentSubId id of the subscription this ABT row is anchored on; the
     *        "previous" sub is the latest paid one starting before it.
     */
    public function computePreviousSubscriptionFeatures(array $subscriptionRows, int $currentSubId): array
    {
        $current = null;
        foreach ($subscriptionRows as $row) {
            if ($row['id'] === $currentSubId) {
                $current = $row;
                break;
            }
        }

        $empty = [
            'is_recurrent_previous' => null,
            'is_recurrent_changed' => null,
        ];

        if ($current === null) {
            return $empty;
        }

        $previous = null;
        foreach ($subscriptionRows as $row) {
            if ($row['id'] === $currentSubId || $row['is_paid'] !== 1) {
                continue;
            }
            if ($row['start_time'] >= $current['start_time']) {
                continue;
            }
            if ($previous === null || $row['start_time'] > $previous['start_time']) {
                $previous = $row;
            }
        }

        if ($previous === null) {
            return $empty;
        }

        return [
            'is_recurrent_previous' => $previous['is_recurrent'],
            'is_recurrent_changed' => $previous['is_recurrent'] !== $current['is_recurrent'] ? 1 : 0,
        ];
    }

    /**
     * Classifies the subscriber at the current subscription into one of:
     * trial / post_trial / new / reactivated / core.
     *
     * Follows the reports-module lifecycle segments (TrialSubscriptionsReportSegment,
     * AfterTrialSubscriptionsReportSegment, InitialPhaseSubscriptionsReportSegment,
     * ReactivatedSubscriptionsReportSegment), without their company/shared precedence,
     * configured trial types, payment status and content access filters.
     *
     * @param list<SubscriptionRow> $subscriptionRows All of the user's subscriptions (any order);
     *        prior subs are those starting before the current one.
     * @param int $currentSubId id of the subscription this ABT row is anchored on. If it is not
     *        present in $subscriptionRows, 'core' is returned.
     */
    public function computeSubscriberSegment(array $subscriptionRows, int $currentSubId): string
    {
        $current = null;
        foreach ($subscriptionRows as $row) {
            if ($row['id'] === $currentSubId) {
                $current = $row;
                break;
            }
        }
        if ($current === null) {
            return 'core';
        }

        if ($current['is_trial'] === 1) {
            return 'trial';
        }

        usort($subscriptionRows, fn($a, $b) => $a['start_time'] <=> $b['start_time']);

        $priorPaid = [];
        $priorPaidTrials = [];
        $priorIncludedCount = 0;
        foreach ($subscriptionRows as $row) {
            if ($row['start_time'] >= $current['start_time']) {
                continue;
            }
            if ($row['is_paid'] === 1) {
                $priorPaid[] = $row;
                if ($row['is_trial'] === 1) {
                    $priorPaidTrials[] = $row;
                }
            }
            // reports rank only subscriptions lasting at least an hour, paid or not
            if ($row['end_time']->getTimestamp() - $row['start_time']->getTimestamp() >= 3600) {
                $priorIncludedCount++;
            }
        }

        // post_trial: 1 to 6 paid subscriptions after a paid trial, within 360 days of it
        if (!empty($priorPaidTrials)) {
            $mostRecentTrial = end($priorPaidTrials);
            $daysFromTrial = $this->daysDifference($mostRecentTrial['start_time'], $current['start_time']);
            $paidSinceTrial = 0;
            foreach ($priorPaid as $sub) {
                if ($sub['start_time'] >= $mostRecentTrial['start_time']) {
                    $paidSinceTrial++;
                }
            }
            if ($daysFromTrial <= 360 && $paidSinceTrial <= 6) {
                return 'post_trial';
            }
        }

        // new: at most 6 earlier subscriptions, within 360 days of the first paid one
        // (a trial first paid subscription is already covered by post_trial or fails the rank)
        $firstPaid = $priorPaid[0] ?? $current;
        if ($priorIncludedCount < 6
            && $this->daysDifference($firstPaid['start_time'], $current['start_time']) <= 360
        ) {
            return 'new';
        }

        // reactivated: a gap longer than 28 days ended within 360 days before the current subscription
        $previousEnd = null;
        foreach ($subscriptionRows as $row) {
            // reports build gaps from subscription types of at least 3 days
            if ($row['start_time'] > $current['start_time'] || $row['length'] < 3) {
                continue;
            }
            if ($previousEnd !== null
                && $row['start_time']->getTimestamp() - $previousEnd->getTimestamp() > 28 * 86400
                && $this->daysDifference($row['start_time'], $current['start_time']) <= 360
            ) {
                return 'reactivated';
            }
            if ($previousEnd === null || $row['end_time'] > $previousEnd) {
                $previousEnd = $row['end_time'];
            }
        }

        return 'core';
    }

    private function daysDifference(\DateTimeInterface $start, \DateTimeInterface $end): int
    {
        return (int) $start->diff($end)->format('%a');
    }
}
