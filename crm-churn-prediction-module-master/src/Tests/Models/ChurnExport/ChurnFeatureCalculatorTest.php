<?php
declare(strict_types=1);

namespace Crm\ChurnPredictionModule\Tests\Models\ChurnExport;

use Crm\ChurnPredictionModule\Models\ChurnExport\ChurnFeatureCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for the churn ABT feature calculator. No DB — every method is a
 * pure function of its arguments, so these cover the leakage rules, time-window
 * edges, signed values and segment branching directly.
 */
class ChurnFeatureCalculatorTest extends TestCase
{
    private ChurnFeatureCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new ChurnFeatureCalculator();
    }

    private function dt(string $s): \DateTimeImmutable
    {
        return new \DateTimeImmutable($s);
    }

    /**
     * Builds a subscription history row (the shape fetchUserSubscriptionsHistory() returns).
     */
    private function sub(
        int $id,
        string $start,
        string $end,
        int $length = 30,
        int $isPaid = 1,
        int $isRecurrent = 0,
        ?string $created = null,
        ?string $upgrade = null,
        int $isTrial = 0,
    ): array {
        return [
            'id' => $id,
            'start_time' => $this->dt($start),
            'end_time' => $this->dt($end),
            'length' => $length,
            'is_paid' => $isPaid,
            'is_recurrent' => $isRecurrent,
            'sub_created_at' => $this->dt($created ?? $start),
            'upgrade_created_at' => $upgrade !== null ? $this->dt($upgrade) : null,
            'is_trial' => $isTrial,
        ];
    }

    /**
     * Builds a recurrent_payment row for computeActiveRecurringFeatures, defaulting the
     * fields a given test doesn't exercise (active card-on-file, no chain/expiry/charge).
     */
    private function rp(
        string $created,
        string $state = 'active',
        ?string $updated = null,
        ?string $expires = null,
        ?string $charge = null,
        ?int $cycle = null,
    ): array {
        return [
            'created_at' => $this->dt($created),
            'updated_at' => $this->dt($updated ?? $created),
            'expires_at' => $expires !== null ? $this->dt($expires) : null,
            'charge_at' => $this->dt($charge ?? $created),
            'state' => $state,
            'cycle' => $cycle,
        ];
    }

    // ---- computePaymentFeatures -------------------------------------------------

    public function testPaymentFeaturesWindowsAndTotals(): void
    {
        $observedAt = $this->dt('2026-06-01 00:00:00');
        $payments = [
            ['status' => 'fail', 'created_at' => $this->dt('2026-05-20'), 'donation_amount' => 0.0, 'product_amount' => 0.0, 'product_count' => 0],
            ['status' => 'fail', 'created_at' => $this->dt('2025-12-01'), 'donation_amount' => 0.0, 'product_amount' => 0.0, 'product_count' => 0],
            ['status' => 'fail', 'created_at' => $this->dt('2024-01-01'), 'donation_amount' => 0.0, 'product_amount' => 0.0, 'product_count' => 0],
            ['status' => 'paid', 'created_at' => $this->dt('2026-05-15'), 'donation_amount' => 5.0, 'product_amount' => 10.0, 'product_count' => 2],
            ['status' => 'paid', 'created_at' => $this->dt('2024-01-01'), 'donation_amount' => 3.0, 'product_amount' => 6.0, 'product_count' => 1],
            // future payment must be ignored entirely (no leakage)
            ['status' => 'paid', 'created_at' => $this->dt('2026-07-01'), 'donation_amount' => 99.0, 'product_amount' => 99.0, 'product_count' => 9],
        ];

        $f = $this->calc->computePaymentFeatures($payments, $observedAt);

        self::assertSame(2, $f['payment_failures_12m']);
        self::assertSame(1, $f['payment_retries_30d']);
        self::assertSame(12, $f['days_since_last_payment_failure']);
        self::assertSame(1, $f['payment_successes_12m']);
        self::assertEqualsWithDelta(1 / 3, $f['payment_success_ratio'], 1e-9);
        self::assertEqualsWithDelta(8.0, $f['donation_amount_total'], 1e-9);
        self::assertEqualsWithDelta(5.0, $f['donation_amount_12m'], 1e-9);
        self::assertSame(3, $f['purchases_total']);
        self::assertSame(2, $f['purchases_12m']);
        self::assertEqualsWithDelta(16.0, $f['purchases_revenue_total'], 1e-9);
        self::assertEqualsWithDelta(10.0, $f['purchases_revenue_12m'], 1e-9);
        self::assertEqualsWithDelta(16.0 / 3, $f['avg_purchase_value'], 1e-9);
    }

    public function testPaymentFeaturesEmptyYieldsNullsAndZeros(): void
    {
        $f = $this->calc->computePaymentFeatures([], $this->dt('2026-06-01'));

        self::assertSame(0, $f['payment_failures_12m']);
        self::assertNull($f['days_since_last_payment_failure']);
        self::assertNull($f['payment_success_ratio']);
        self::assertNull($f['avg_purchase_value']);
        self::assertSame(0, $f['purchases_total']);
    }

    // ---- computeCancellationFeatures --------------------------------------------

    public function testCancellationFeatures(): void
    {
        $observedAt = $this->dt('2026-06-01');
        $cancellationsDesc = [
            $this->dt('2026-07-01'), // future, ignored
            $this->dt('2026-05-01'), // within 365d, most recent observable
            $this->dt('2024-01-01'), // older than 365d
        ];

        $f = $this->calc->computeCancellationFeatures($cancellationsDesc, $observedAt);

        self::assertSame(2, $f['cancellations_total']);
        self::assertSame(1, $f['cancellations_365d']);
        self::assertSame(31, $f['days_since_last_cancellation']);
    }

    public function testCancellationFeaturesIndependentOfInputOrder(): void
    {
        // Same timestamps as above but shuffled: the method sorts internally, so the
        // latest observable cancellation (2026-05-01 → 31 days) is order-independent.
        $observedAt = $this->dt('2026-06-01');
        $cancellations = [
            $this->dt('2024-01-01'), // older than 365d
            $this->dt('2026-07-01'), // future, ignored
            $this->dt('2026-05-01'), // within 365d, most recent observable
        ];

        $f = $this->calc->computeCancellationFeatures($cancellations, $observedAt);

        self::assertSame(2, $f['cancellations_total']);
        self::assertSame(1, $f['cancellations_365d']);
        self::assertSame(31, $f['days_since_last_cancellation']);
    }

    public function testCancellationFeaturesEmpty(): void
    {
        $f = $this->calc->computeCancellationFeatures([], $this->dt('2026-06-01'));
        self::assertSame(0, $f['cancellations_total']);
        self::assertNull($f['days_since_last_cancellation']);
    }

    // ---- computeActiveRecurringFeatures: card_expires_in_days -------------------

    public function testCardExpirationSignedNegativeWhenAlreadyExpired(): void
    {
        $observedAt = $this->dt('2026-06-01');
        $rps = [$this->rp('2026-05-01', expires: '2026-05-15')];

        $f = $this->calc->computeActiveRecurringFeatures($rps, $observedAt);

        self::assertSame(-17, $f['card_expires_in_days']);
    }

    public function testCardExpirationPositiveWhenInFuture(): void
    {
        $observedAt = $this->dt('2026-06-01');
        $rps = [$this->rp('2026-05-01', expires: '2026-07-01')];

        $f = $this->calc->computeActiveRecurringFeatures($rps, $observedAt);

        self::assertSame(30, $f['card_expires_in_days']);
    }

    public function testCardExpirationPicksMostRecentCreatedAtOrBeforeObservedAt(): void
    {
        $observedAt = $this->dt('2026-06-01');
        // desc order, most-recent-first; the first row is created after observed_at and must be skipped
        $rps = [
            $this->rp('2026-07-01', expires: '2099-01-01'),
            $this->rp('2026-05-20', expires: '2026-08-01'),
            $this->rp('2026-01-01', expires: '2026-02-01'),
        ];

        $f = $this->calc->computeActiveRecurringFeatures($rps, $observedAt);

        self::assertSame(61, $f['card_expires_in_days']);
    }

    public function testCardExpirationNullWhenNoneOrMissingExpiry(): void
    {
        $observedAt = $this->dt('2026-06-01');
        self::assertNull($this->calc->computeActiveRecurringFeatures([], $observedAt)['card_expires_in_days']);

        $rps = [$this->rp('2026-05-01', expires: null)];
        self::assertNull($this->calc->computeActiveRecurringFeatures($rps, $observedAt)['card_expires_in_days']);
    }

    // ---- computeActiveRecurringFeatures -----------------------------------------

    public function testActiveRecurringFeaturesEmptyYieldsNulls(): void
    {
        $f = $this->calc->computeActiveRecurringFeatures([], $this->dt('2026-06-01'));

        self::assertNull($f['card_expires_in_days']);
        self::assertNull($f['active_recurring_stopped']);
        self::assertNull($f['renewal_cycle']);
        self::assertNull($f['is_first_renewal']);
        self::assertNull($f['card_expires_before_renewal']);
        self::assertNull($f['days_card_valid_after_renewal']);
    }

    public function testActiveRecurringPicksMostRecentCreatedAtOrBeforeObservedAt(): void
    {
        $observedAt = $this->dt('2026-06-01');
        // desc order; the first row is created after observed_at (and is a stop) — it must be skipped,
        // so neither its stopped-state nor its cycle leaks into the features.
        $rps = [
            $this->rp('2026-07-01', state: 'user_stop', updated: '2026-07-02', cycle: 9),
            $this->rp('2026-05-20', state: 'active', cycle: 3),
            $this->rp('2026-01-01', state: 'user_stop', updated: '2026-01-02', cycle: 1),
        ];

        $f = $this->calc->computeActiveRecurringFeatures($rps, $observedAt);

        self::assertSame(0, $f['active_recurring_stopped']);
        self::assertSame(3, $f['renewal_cycle']);
        self::assertSame(0, $f['is_first_renewal']);
    }

    #[DataProvider('stopStateProvider')]
    public function testActiveRecurringStopStates(string $state, int $expected): void
    {
        $observedAt = $this->dt('2026-06-01');
        $rps = [$this->rp('2026-05-01', state: $state, updated: '2026-05-15')];

        $f = $this->calc->computeActiveRecurringFeatures($rps, $observedAt);

        self::assertSame($expected, $f['active_recurring_stopped']);
    }

    public static function stopStateProvider(): array
    {
        return [
            'user_stop'   => ['user_stop', 1],
            'admin_stop'  => ['admin_stop', 1],
            'system_stop' => ['system_stop', 1],
            'charge_failed is transient, not a stop' => ['charge_failed', 0],
            'active'      => ['active', 0],
            'pending'     => ['pending', 0],
        ];
    }

    public function testActiveRecurringStopRecordedAfterObservedAtIsNotCounted(): void
    {
        $observedAt = $this->dt('2026-06-01');
        // recurring created before observed_at, but the user_stop was recorded after it → future, must read 0
        $rps = [$this->rp('2026-01-01', state: 'user_stop', updated: '2026-06-15')];

        self::assertSame(0, $this->calc->computeActiveRecurringFeatures($rps, $observedAt)['active_recurring_stopped']);
    }

    public function testActiveRecurringStopExactlyAtObservedAtCounts(): void
    {
        $observedAt = $this->dt('2026-06-01 00:00:00');
        $rps = [$this->rp('2026-05-01', state: 'user_stop', updated: '2026-06-01 00:00:00')];

        self::assertSame(1, $this->calc->computeActiveRecurringFeatures($rps, $observedAt)['active_recurring_stopped']);
    }

    #[DataProvider('renewalCycleProvider')]
    public function testActiveRecurringRenewalCycle(?int $cycle, ?int $expectedCycle, ?int $expectedFirst): void
    {
        $observedAt = $this->dt('2026-06-01');
        $rps = [$this->rp('2026-05-01', cycle: $cycle)];

        $f = $this->calc->computeActiveRecurringFeatures($rps, $observedAt);

        self::assertSame($expectedCycle, $f['renewal_cycle']);
        self::assertSame($expectedFirst, $f['is_first_renewal']);
    }

    public static function renewalCycleProvider(): array
    {
        return [
            'first renewal'        => [1, 1, 1],
            'second renewal'       => [2, 2, 0],
            'fifth renewal'        => [5, 5, 0],
            'untracked → null/null' => [null, null, null],
        ];
    }

    public function testActiveRecurringCardExpiresBeforeRenewal(): void
    {
        $observedAt = $this->dt('2026-06-01');
        // card dies 2026-07-01, next charge 2026-07-15 → expired 14 days before the renewal charge
        $rps = [$this->rp('2026-05-01', expires: '2026-07-01', charge: '2026-07-15')];

        $f = $this->calc->computeActiveRecurringFeatures($rps, $observedAt);

        self::assertSame(1, $f['card_expires_before_renewal']);
        self::assertSame(-14, $f['days_card_valid_after_renewal']);
    }

    public function testActiveRecurringCardValidThroughRenewal(): void
    {
        $observedAt = $this->dt('2026-06-01');
        // card valid to 2026-08-01, next charge 2026-07-15 → 17 days of validity left at charge time
        $rps = [$this->rp('2026-05-01', expires: '2026-08-01', charge: '2026-07-15')];

        $f = $this->calc->computeActiveRecurringFeatures($rps, $observedAt);

        self::assertSame(0, $f['card_expires_before_renewal']);
        self::assertSame(17, $f['days_card_valid_after_renewal']);
    }

    public function testActiveRecurringCardFeaturesNullWhenExpiryMissing(): void
    {
        $observedAt = $this->dt('2026-06-01');

        $noExpiry = [$this->rp('2026-05-01', expires: null, charge: '2026-07-15')];
        $f1 = $this->calc->computeActiveRecurringFeatures($noExpiry, $observedAt);
        self::assertNull($f1['card_expires_before_renewal']);
        self::assertNull($f1['days_card_valid_after_renewal']);
    }

    // ---- computeTierPriceFeature ------------------------------------------------

    public function testTierPriceFeaturePercentages(): void
    {
        $snapshots = ['2026-06' => ['web' => [30 => ['max' => 10.0, 'median' => 8.0]]]];
        $rowData = [
            'observed_at' => $this->dt('2026-06-15'),
            'content_access_codes' => 'web',
            'subscription_length' => 30,
            'payment_amount' => 5.0,
        ];

        $f = $this->calc->computeTierPriceFeature($rowData, $snapshots);

        self::assertEqualsWithDelta(0.5, $f['payment_pct_of_tier_max_price'], 1e-9);
        self::assertEqualsWithDelta(0.625, $f['payment_pct_of_tier_median_price'], 1e-9);
    }

    public function testTierPriceFeatureNullWhenNoTierMatch(): void
    {
        $snapshots = ['2026-06' => ['web' => [30 => ['max' => 10.0, 'median' => 8.0]]]];
        $rowData = [
            'observed_at' => $this->dt('2026-06-15'),
            'content_access_codes' => 'web',
            'subscription_length' => 99, // no such length tier
            'payment_amount' => 5.0,
        ];

        $f = $this->calc->computeTierPriceFeature($rowData, $snapshots);

        self::assertNull($f['payment_pct_of_tier_max_price']);
        self::assertNull($f['payment_pct_of_tier_median_price']);
    }

    public function testTierPriceFeatureNullWhenNoPaymentAmount(): void
    {
        $snapshots = ['2026-06' => ['web' => [30 => ['max' => 10.0, 'median' => 8.0]]]];
        $rowData = [
            'observed_at' => $this->dt('2026-06-15'),
            'content_access_codes' => 'web',
            'subscription_length' => 30,
            'payment_amount' => null,
        ];

        self::assertNull($this->calc->computeTierPriceFeature($rowData, $snapshots)['payment_pct_of_tier_max_price']);
    }

    public function testTierPriceFeatureNullWhenReferencePriceZero(): void
    {
        $snapshots = ['2026-06' => ['web' => [30 => ['max' => 0.0, 'median' => 0.0]]]];
        $rowData = [
            'observed_at' => $this->dt('2026-06-15'),
            'content_access_codes' => 'web',
            'subscription_length' => 30,
            'payment_amount' => 5.0,
        ];

        $f = $this->calc->computeTierPriceFeature($rowData, $snapshots);

        self::assertNull($f['payment_pct_of_tier_max_price']);
        self::assertNull($f['payment_pct_of_tier_median_price']);
    }

    // ---- computeLongestContinuousTenure -----------------------------------------

    public function testLongestContinuousTenureStitchesAcrossShortGaps(): void
    {
        // A and B are within the 28-day churn window of each other (9-day gap) → one streak.
        // C starts long after B → separate, shorter streak.
        $subs = [
            $this->sub(1, '2025-01-01', '2025-02-01'),
            $this->sub(2, '2025-02-10', '2025-03-10'),
            $this->sub(3, '2025-06-01', '2025-07-01'),
        ];

        $tenure = $this->calc->computeLongestContinuousTenure($subs, $this->dt('2025-08-01'), 28);

        self::assertSame(68, $tenure); // 2025-01-01 → 2025-03-10
    }

    public function testLongestContinuousTenureIndependentOfInputOrder(): void
    {
        $subs = [
            $this->sub(3, '2025-06-01', '2025-07-01'),
            $this->sub(1, '2025-01-01', '2025-02-01'),
            $this->sub(2, '2025-02-10', '2025-03-10'),
        ];

        $tenure = $this->calc->computeLongestContinuousTenure($subs, $this->dt('2025-08-01'), 28);

        self::assertSame(68, $tenure);
    }

    public function testLongestContinuousTenureZeroWhenNoPriorSubs(): void
    {
        $subs = [$this->sub(1, '2026-01-01', '2026-02-01')];
        // observed_at before every start → nothing observable
        self::assertSame(0, $this->calc->computeLongestContinuousTenure($subs, $this->dt('2025-01-01'), 28));
    }

    // ---- computeDaysSubscribedInWindow ------------------------------------------
    // observed 2025-06-15 → the 30-day window is 2025-05-16 … 2025-06-14

    public function testDaysSubscribedInWindowFullyCovered(): void
    {
        $subs = [$this->sub(1, '2025-05-01', '2025-07-01')];
        self::assertSame(30, $this->calc->computeDaysSubscribedInWindow($subs, $this->dt('2025-06-15 10:00'), 30));
        self::assertSame(45, $this->calc->computeDaysSubscribedInWindow($subs, $this->dt('2025-06-15 10:00'), 90));
    }

    public function testDaysSubscribedInWindowStartingInsideWindow(): void
    {
        $subs = [$this->sub(1, '2025-06-01 12:00', '2025-07-01')];
        self::assertSame(14, $this->calc->computeDaysSubscribedInWindow($subs, $this->dt('2025-06-15 10:00'), 30));
    }

    public function testDaysSubscribedInWindowOverlapsCountedOnce(): void
    {
        $subs = [
            $this->sub(1, '2025-05-20', '2025-06-05'),
            $this->sub(2, '2025-06-01', '2025-06-20'),
            $this->sub(3, '2025-05-25', '2025-05-30'),
        ];
        self::assertSame(26, $this->calc->computeDaysSubscribedInWindow($subs, $this->dt('2025-06-15 10:00'), 30));
    }

    public function testDaysSubscribedInWindowSkipsGaps(): void
    {
        // 05-16 … 05-20 (5 days) + 06-10 … 06-14 (5 days)
        $subs = [
            $this->sub(2, '2025-06-10', '2025-06-30'),
            $this->sub(1, '2025-05-01', '2025-05-20'),
        ];
        self::assertSame(10, $this->calc->computeDaysSubscribedInWindow($subs, $this->dt('2025-06-15 10:00'), 30));
    }

    public function testDaysSubscribedInWindowCountsUnpaidSubscriptions(): void
    {
        $subs = [$this->sub(1, '2025-06-01', '2025-07-01', isPaid: 0)];
        self::assertSame(14, $this->calc->computeDaysSubscribedInWindow($subs, $this->dt('2025-06-15 10:00'), 30));
    }

    public function testDaysSubscribedInWindowIgnoresDaysFromTheObservedDateOn(): void
    {
        $subs = [$this->sub(1, '2025-06-15 08:00', '2025-07-15')];
        self::assertSame(0, $this->calc->computeDaysSubscribedInWindow($subs, $this->dt('2025-06-15 10:00'), 30));
    }

    public function testDaysSubscribedInWindowIgnoresSubscriptionsEndedBeforeWindow(): void
    {
        $subs = [$this->sub(1, '2025-04-01', '2025-05-15')];
        self::assertSame(0, $this->calc->computeDaysSubscribedInWindow($subs, $this->dt('2025-06-15 10:00'), 30));
    }

    public function testDaysSubscribedInWindowCountsEndDateAsWholeDay(): void
    {
        // ends at midnight on the first window day, which still counts (same as the former BigQuery DATE() logic)
        $subs = [$this->sub(1, '2025-04-16', '2025-05-16 00:00')];
        self::assertSame(1, $this->calc->computeDaysSubscribedInWindow($subs, $this->dt('2025-06-15 10:00'), 30));
    }

    public function testDaysSubscribedInWindowWithoutSubscriptions(): void
    {
        self::assertSame(0, $this->calc->computeDaysSubscribedInWindow([], $this->dt('2025-06-15 10:00'), 30));
    }

    // ---- computeDaysSubscribedTotal ---------------------------------------------

    public function testDaysSubscribedTotalMergesOverlaps(): void
    {
        $subs = [
            $this->sub(1, '2025-01-01', '2025-02-01'),
            $this->sub(2, '2025-01-15', '2025-03-01'), // overlaps #1 → merged 01-01..03-01 = 59
            $this->sub(3, '2025-04-01', '2025-04-11'), // separate = 10
        ];

        self::assertSame(69, $this->calc->computeDaysSubscribedTotal($subs, $this->dt('2025-12-01')));
    }

    public function testDaysSubscribedTotalClampsToObservedAt(): void
    {
        $subs = [$this->sub(1, '2025-01-01', '2026-01-01')];
        self::assertSame(30, $this->calc->computeDaysSubscribedTotal($subs, $this->dt('2025-01-31')));
    }

    // ---- computeDaysSinceFirstSubscription --------------------------------------

    public function testDaysSinceFirstSubscription(): void
    {
        $subs = [
            $this->sub(2, '2025-03-01', '2025-04-01'),
            $this->sub(1, '2025-01-01', '2025-02-01'),
        ];
        self::assertSame(31, $this->calc->computeDaysSinceFirstSubscription($subs, $this->dt('2025-02-01')));
    }

    // ---- computeSubscriptionHistoryFeatures -------------------------------------

    public function testSubscriptionHistoryFeatures(): void
    {
        $observedAt = $this->dt('2026-06-01');
        $subs = [
            $this->sub(1, '2025-01-01', '2025-02-01', length: 31, isPaid: 1, created: '2025-01-01'),
            $this->sub(2, '2025-06-01', '2025-07-01', length: 30, isPaid: 1, created: '2025-06-01', upgrade: '2025-06-15'),
            $this->sub(3, '2025-08-01', '2025-08-15', length: 14, isPaid: 0, created: '2025-08-01'),
        ];
        $subscriptionPayments = [
            1 => [
                ['created_at' => $this->dt('2025-01-05'), 'amount' => 10.0],
                ['created_at' => $this->dt('2026-07-01'), 'amount' => 99.0], // future → excluded
            ],
            2 => [['created_at' => $this->dt('2025-06-05'), 'amount' => 20.0]],
        ];

        $f = $this->calc->computeSubscriptionHistoryFeatures($subs, $subscriptionPayments, $observedAt);

        self::assertEqualsWithDelta(30.0, $f['total_amount'], 1e-9);
        self::assertEqualsWithDelta(10.0, $f['average_amount'], 1e-9);
        self::assertSame(3, $f['total_subscription_count']);
        self::assertSame(2, $f['paid_subs']);
        self::assertSame(1, $f['free_subs']);
        self::assertSame(61, $f['total_days_paid_sub']);
        self::assertSame(14, $f['total_days_free_sub']);
        self::assertSame(151, $f['days_between_first_and_last_paid_sub']);
        self::assertEqualsWithDelta(25.0, $f['avg_subscription_length_days'], 1e-9);
        self::assertEqualsWithDelta(75.5, $f['avg_gap_length_days'], 1e-9);
        self::assertSame(120, $f['max_gap']);
        self::assertSame(2, $f['subscription_gaps_total']);
        self::assertSame(1, $f['upgraded_subs']);
    }

    public function testSubscriptionHistoryFeaturesUpgradeAfterObservedAtNotCounted(): void
    {
        $observedAt = $this->dt('2026-06-01');
        $subs = [$this->sub(1, '2025-01-01', '2025-02-01', upgrade: '2026-07-01')];

        $f = $this->calc->computeSubscriptionHistoryFeatures($subs, [], $observedAt);

        self::assertSame(0, $f['upgraded_subs']);
    }

    // ---- computePreviousSubscriptionFeatures ------------------------------------

    public function testPreviousSubscriptionFeaturesDetectsRecurrentChange(): void
    {
        $subs = [
            $this->sub(1, '2025-01-01', '2025-02-01', isPaid: 1, isRecurrent: 0),
            $this->sub(2, '2025-06-01', '2025-07-01', isPaid: 1, isRecurrent: 1),
        ];

        $f = $this->calc->computePreviousSubscriptionFeatures($subs, 2);

        self::assertSame(0, $f['is_recurrent_previous']);
        self::assertSame(1, $f['is_recurrent_changed']);
    }

    public function testPreviousSubscriptionFeaturesNullWhenNoPrior(): void
    {
        $subs = [$this->sub(1, '2025-06-01', '2025-07-01', isRecurrent: 1)];
        $f = $this->calc->computePreviousSubscriptionFeatures($subs, 1);

        self::assertNull($f['is_recurrent_previous']);
        self::assertNull($f['is_recurrent_changed']);
    }

    // ---- computeSubscriberSegment -----------------------------------------------

    public function testSegmentTrial(): void
    {
        $subs = [$this->sub(1, '2025-06-01', '2025-07-01', isTrial: 1)];
        self::assertSame('trial', $this->calc->computeSubscriberSegment($subs, 1));
    }

    /**
     * Back-to-back subscriptions of $days days each, ids from $firstId, starting at $start.
     *
     * @return list<array<string, mixed>>
     */
    private function subChain(int $firstId, string $start, int $count, int $days = 30, int $isPaid = 1): array
    {
        $subs = [];
        $from = new \DateTimeImmutable($start);
        for ($i = 0; $i < $count; $i++) {
            $to = $from->modify("+{$days} days");
            $subs[] = $this->sub($firstId + $i, $from->format('Y-m-d'), $to->format('Y-m-d'), length: $days, isPaid: $isPaid);
            $from = $to;
        }
        return $subs;
    }

    public function testSegmentPostTrial(): void
    {
        $subs = [
            $this->sub(10, '2025-03-01', '2025-03-15', isPaid: 1, isTrial: 1),
            $this->sub(1, '2025-06-01', '2025-07-01', isPaid: 1),
        ];
        self::assertSame('post_trial', $this->calc->computeSubscriberSegment($subs, 1));
    }

    public function testSegmentUnpaidTrialIsNotFollowedAsPostTrial(): void
    {
        $subs = [
            $this->sub(10, '2025-03-01', '2025-03-15', isPaid: 0, isTrial: 1),
            $this->sub(1, '2025-06-01', '2025-07-01', isPaid: 1),
        ];
        self::assertSame('new', $this->calc->computeSubscriberSegment($subs, 1));
    }

    public function testSegmentPostTrialUpToSixthPaidSubscriptionAfterTrial(): void
    {
        // trial + 5 paid subscriptions before the current one → current is the 6th after the trial
        $subs = [
            $this->sub(10, '2025-01-01', '2025-01-08', length: 7, isPaid: 1, isTrial: 1),
            ...$this->subChain(20, '2025-01-08', 5),
            $this->sub(1, '2025-06-07', '2025-07-07', isPaid: 1),
        ];
        self::assertSame('post_trial', $this->calc->computeSubscriberSegment($subs, 1));
    }

    public function testSegmentSeventhPaidSubscriptionAfterTrialIsNotPostTrial(): void
    {
        $subs = [
            $this->sub(10, '2025-01-01', '2025-01-08', length: 7, isPaid: 1, isTrial: 1),
            ...$this->subChain(20, '2025-01-08', 6),
            $this->sub(1, '2025-07-07', '2025-08-06', isPaid: 1),
        ];
        self::assertSame('core', $this->calc->computeSubscriberSegment($subs, 1));
    }

    public function testSegmentNewWithPriorPaidWithinYear(): void
    {
        $subs = [
            $this->sub(2, '2025-01-01', '2025-02-01', isPaid: 1),
            $this->sub(1, '2025-06-01', '2025-07-01', isPaid: 1),
        ];
        self::assertSame('new', $this->calc->computeSubscriberSegment($subs, 1));
    }

    public function testSegmentNewWhenNoPriorPaid(): void
    {
        $subs = [$this->sub(1, '2025-06-01', '2025-07-01', isPaid: 1)];
        self::assertSame('new', $this->calc->computeSubscriberSegment($subs, 1));
    }

    public function testSegmentUnpaidSubscriptionsCountInNewRank(): void
    {
        // 6 earlier unpaid subscriptions → the current one is the 7th, too late for "new"
        $subs = [
            ...$this->subChain(20, '2025-01-01', 6, isPaid: 0),
            $this->sub(1, '2025-06-30', '2025-07-30', isPaid: 1),
        ];
        self::assertSame('core', $this->calc->computeSubscriberSegment($subs, 1));
    }

    public function testSegmentReactivated(): void
    {
        // prior relationship older than a year (so not "new"), with a lapse right before current
        $subs = [
            $this->sub(2, '2024-01-01', '2025-01-01', length: 366, isPaid: 1),
            $this->sub(1, '2025-06-01', '2025-07-01', isPaid: 1),
        ];
        self::assertSame('reactivated', $this->calc->computeSubscriberSegment($subs, 1));
    }

    public function testSegmentReactivatedAfterGapEndedWithin360Days(): void
    {
        // the gap ended ~6 months before the current subscription, which follows without a gap
        $subs = [
            $this->sub(3, '2022-01-01', '2024-01-01', length: 730, isPaid: 1),
            $this->sub(2, '2024-06-01', '2024-12-01', length: 183, isPaid: 1),
            $this->sub(1, '2024-12-01', '2025-01-01', isPaid: 1),
        ];
        self::assertSame('reactivated', $this->calc->computeSubscriberSegment($subs, 1));
    }

    public function testSegmentReactivatedAfterGapLongerThanYear(): void
    {
        $subs = [
            $this->sub(2, '2020-01-01', '2021-01-01', length: 366, isPaid: 1),
            $this->sub(1, '2023-01-01', '2023-02-01', isPaid: 1),
        ];
        self::assertSame('reactivated', $this->calc->computeSubscriberSegment($subs, 1));
    }

    public function testSegmentGapEndedMoreThan360DaysAgoIsCore(): void
    {
        $subs = [
            $this->sub(3, '2020-01-01', '2021-01-01', length: 366, isPaid: 1),
            $this->sub(2, '2022-01-01', '2023-06-01', length: 516, isPaid: 1),
            $this->sub(1, '2023-06-01', '2023-07-01', isPaid: 1),
        ];
        self::assertSame('core', $this->calc->computeSubscriberSegment($subs, 1));
    }

    public function testSegmentGapsIgnoreSubscriptionsShorterThanThreeDays(): void
    {
        // the 2-day subscription would split the 40-day lapse into two 19-day ones; it is skipped
        $subs = [
            $this->sub(4, '2020-01-01', '2023-01-01', length: 1096, isPaid: 1),
            $this->sub(3, '2023-01-20', '2023-01-22', length: 2, isPaid: 0),
            $this->sub(2, '2023-02-10', '2023-06-01', length: 111, isPaid: 1),
            $this->sub(1, '2023-06-01', '2023-07-01', isPaid: 1),
        ];
        self::assertSame('reactivated', $this->calc->computeSubscriberSegment($subs, 1));
    }

    public function testSegmentCore(): void
    {
        // long-standing relationship without a gap → core
        $subs = [
            $this->sub(2, '2024-01-01', '2025-06-01', length: 517, isPaid: 1),
            $this->sub(1, '2025-06-01', '2025-07-01', isPaid: 1),
        ];
        self::assertSame('core', $this->calc->computeSubscriberSegment($subs, 1));
    }

    public function testSegmentCoreWhenCurrentSubMissing(): void
    {
        $subs = [$this->sub(1, '2025-06-01', '2025-07-01', isPaid: 1)];
        self::assertSame('core', $this->calc->computeSubscriberSegment($subs, 999));
    }
}
