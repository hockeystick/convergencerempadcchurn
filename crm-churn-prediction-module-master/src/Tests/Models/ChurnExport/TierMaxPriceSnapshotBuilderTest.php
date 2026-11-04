<?php
declare(strict_types=1);

namespace Crm\ChurnPredictionModule\Tests\Models\ChurnExport;

use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\ChurnPredictionModule\Models\ChurnExport\TierMaxPriceSnapshotBuilder;
use Crm\SubscriptionsModule\Models\Builder\SubscriptionTypeBuilder;
use Crm\SubscriptionsModule\Repositories\ContentAccessRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionExtensionMethodsRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypeContentAccessRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypesMetaRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypesRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Crm\SubscriptionsModule\Seeders\ContentAccessSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionExtensionMethodsSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionLengthMethodSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionTypeNamesSeeder;
use Crm\UsersModule\Repositories\UsersRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;

class TierMaxPriceSnapshotBuilderTest extends DatabaseTestCase
{
    private TierMaxPriceSnapshotBuilder $builder;
    private SubscriptionsRepository $subscriptionsRepository;
    private SubscriptionTypesRepository $subscriptionTypesRepository;
    private SubscriptionTypeBuilder $subscriptionTypeBuilder;
    private UsersRepository $usersRepository;

    protected function requiredRepositories(): array
    {
        return [
            UsersRepository::class,
            SubscriptionsRepository::class,
            SubscriptionTypesRepository::class,
            SubscriptionTypeBuilder::class,
            SubscriptionTypesMetaRepository::class,
            SubscriptionExtensionMethodsRepository::class,
            ContentAccessRepository::class,
            SubscriptionTypeContentAccessRepository::class,
        ];
    }

    protected function requiredSeeders(): array
    {
        return [
            SubscriptionExtensionMethodsSeeder::class,
            SubscriptionLengthMethodSeeder::class,
            SubscriptionTypeNamesSeeder::class,
            ContentAccessSeeder::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = $this->inject(TierMaxPriceSnapshotBuilder::class);
        $this->subscriptionsRepository = $this->getRepository(SubscriptionsRepository::class);
        $this->subscriptionTypesRepository = $this->getRepository(SubscriptionTypesRepository::class);
        $this->subscriptionTypeBuilder = $this->getRepository(SubscriptionTypeBuilder::class);
        $this->usersRepository = $this->getRepository(UsersRepository::class);
    }

    /**
     * Two subscription_types in the same tier (same content_access combo + same length),
     * one regular-priced and one promo-priced, both with active subscriptions.
     * The snapshot's max_price for that tier should pick the regular price.
     */
    public function testTakesMaxPriceWhenTwoTypesShareTier(): void
    {
        $user = $this->createTestUser('max-tier@example.com');

        $regular = $this->createSubscriptionType(
            code: 'regular_web_30d',
            price: 7.0,
            length: 30,
            accessOptions: ['web'],
            createdAt: DateTime::from('2025-01-15 10:00:00'),
        );
        $promo = $this->createSubscriptionType(
            code: 'promo_web_30d',
            price: 1.0,
            length: 30,
            accessOptions: ['web'],
            createdAt: DateTime::from('2025-01-15 10:00:00'),
        );

        // Both types have an active subscription covering June 2025.
        $this->subscriptionsRepository->add($regular, false, true, $user, SubscriptionsRepository::TYPE_PREPAID, DateTime::from('2025-06-01'), DateTime::from('2025-06-30'));
        $this->subscriptionsRepository->add($promo, false, true, $user, SubscriptionsRepository::TYPE_PREPAID, DateTime::from('2025-06-01'), DateTime::from('2025-06-30'));

        $snapshots = $this->builder->build(
            new \DateTimeImmutable('2025-06-01'),
            new \DateTimeImmutable('2025-06-01'),
        );

        $this->assertArrayHasKey('2025-06', $snapshots);
        $this->assertArrayHasKey('web', $snapshots['2025-06']);
        $this->assertArrayHasKey(30, $snapshots['2025-06']['web']);
        $this->assertSame(7.0, $snapshots['2025-06']['web'][30]['max']);
    }

    /**
     * Single-type tier: weighted median should equal max (and equal the only price).
     */
    public function testMedianEqualsMaxWhenTierHasSingleType(): void
    {
        $user = $this->createTestUser('single-type@example.com');

        $only = $this->createSubscriptionType('only_web_30d', 5.0, 30, ['web'], DateTime::from('2025-01-15'));
        $this->subscriptionsRepository->add($only, false, true, $user, SubscriptionsRepository::TYPE_PREPAID, DateTime::from('2025-06-01'), DateTime::from('2025-06-30'));

        $snapshots = $this->builder->build(
            new \DateTimeImmutable('2025-06-01'),
            new \DateTimeImmutable('2025-06-01'),
        );

        $this->assertSame(5.0, $snapshots['2025-06']['web'][30]['max']);
        $this->assertSame(5.0, $snapshots['2025-06']['web'][30]['median']);
    }

    /**
     * Two types in the same tier — one premium with 1 subscriber, one cheap with many.
     * Weighted median should pick the cheap type's price (where most users actually sit),
     * not the premium type's price (which a plain median or MAX would).
     */
    public function testWeightedMedianFollowsSubscriptionCount(): void
    {
        $premiumUser = $this->createTestUser('premium@example.com');

        $premium = $this->createSubscriptionType('premium_web_30d', 8.0, 30, ['web'], DateTime::from('2025-01-15'));
        $cheap   = $this->createSubscriptionType('cheap_web_30d', 4.0, 30, ['web'], DateTime::from('2025-01-15'));

        // Only one user on the premium type.
        $this->subscriptionsRepository->add($premium, false, true, $premiumUser, SubscriptionsRepository::TYPE_PREPAID, DateTime::from('2025-06-01'), DateTime::from('2025-06-30'));

        // Five separate users on the cheap type.
        for ($i = 0; $i < 5; $i++) {
            $cheapUser = $this->createTestUser("cheap-{$i}@example.com");
            $this->subscriptionsRepository->add($cheap, false, true, $cheapUser, SubscriptionsRepository::TYPE_PREPAID, DateTime::from('2025-06-01'), DateTime::from('2025-06-30'));
        }

        $snapshots = $this->builder->build(
            new \DateTimeImmutable('2025-06-01'),
            new \DateTimeImmutable('2025-06-01'),
        );

        $this->assertSame(8.0, $snapshots['2025-06']['web'][30]['max']);
        // Cumulative subs sorted by price: cheap=5 (cum 5), premium=1 (cum 6). Total=6.
        // First price where cum_count * 2 >= total_count is 4.0 (5*2 >= 6).
        $this->assertSame(4.0, $snapshots['2025-06']['web'][30]['median']);
    }

    /**
     * Three subscription_types in three distinct tiers (different content_access combos
     * for the same length). Each tier should appear as its own key, with content_access
     * names alphabetically concatenated.
     */
    public function testGroupsByContentAccessCombo(): void
    {
        $user = $this->createTestUser('combo@example.com');

        $web      = $this->createSubscriptionType('web_30d', 5.0, 30, ['web'], DateTime::from('2025-01-15'));
        $print    = $this->createSubscriptionType('print_30d', 8.0, 30, ['print'], DateTime::from('2025-01-15'));
        $webPrint = $this->createSubscriptionType('web_print_30d', 12.0, 30, ['web', 'print'], DateTime::from('2025-01-15'));

        foreach ([$web, $print, $webPrint] as $type) {
            $this->subscriptionsRepository->add($type, false, true, $user, SubscriptionsRepository::TYPE_PREPAID, DateTime::from('2025-06-01'), DateTime::from('2025-06-30'));
        }

        $snapshots = $this->builder->build(
            new \DateTimeImmutable('2025-06-01'),
            new \DateTimeImmutable('2025-06-01'),
        );

        $this->assertArrayHasKey('web', $snapshots['2025-06']);
        $this->assertArrayHasKey('print', $snapshots['2025-06']);
        // Alphabetically sorted: 'print' < 'web', so the combined key is 'print,web'.
        $this->assertArrayHasKey('print,web', $snapshots['2025-06']);

        $this->assertSame(5.0, $snapshots['2025-06']['web'][30]['max']);
        $this->assertSame(8.0, $snapshots['2025-06']['print'][30]['max']);
        $this->assertSame(12.0, $snapshots['2025-06']['print,web'][30]['max']);
    }

    /**
     * A subscription_type with no content_access mapping should still appear in the
     * snapshot, under the empty-string tier key, via the LEFT JOIN.
     */
    public function testHandlesTypeWithoutContentAccess(): void
    {
        $user = $this->createTestUser('no-access@example.com');

        $noAccess = $this->createSubscriptionType('no_access_30d', 3.0, 30, [], DateTime::from('2025-01-15'));
        $this->subscriptionsRepository->add($noAccess, false, true, $user, SubscriptionsRepository::TYPE_PREPAID, DateTime::from('2025-06-01'), DateTime::from('2025-06-30'));

        $snapshots = $this->builder->build(
            new \DateTimeImmutable('2025-06-01'),
            new \DateTimeImmutable('2025-06-01'),
        );

        $this->assertArrayHasKey('', $snapshots['2025-06']);
        $this->assertSame(3.0, $snapshots['2025-06'][''][30]['max']);
    }

    /**
     * A subscription_type created mid-range should be absent from snapshots taken
     * before its created_at and present in snapshots taken after.
     */
    public function testRespectsCreatedAtFilter(): void
    {
        $user = $this->createTestUser('created-at@example.com');

        // "Older" type exists at the start of the range.
        $older = $this->createSubscriptionType('web_old_30d', 5.0, 30, ['web'], DateTime::from('2024-12-15'));
        $this->subscriptionsRepository->add($older, false, true, $user, SubscriptionsRepository::TYPE_PREPAID, DateTime::from('2025-01-01'), DateTime::from('2025-06-30'));

        // "Newer" type created mid-March 2025 in the same tier with a higher price.
        $newer = $this->createSubscriptionType('web_new_30d', 10.0, 30, ['web'], DateTime::from('2025-03-15'));
        $this->subscriptionsRepository->add($newer, false, true, $user, SubscriptionsRepository::TYPE_PREPAID, DateTime::from('2025-03-16'), DateTime::from('2025-06-30'));

        $snapshots = $this->builder->build(
            new \DateTimeImmutable('2025-01-01'),
            new \DateTimeImmutable('2025-04-01'),
        );

        // Jan/Feb/Mar snapshots are taken at the 1st of each month — newer type
        // (created 2025-03-15) hasn't been created yet by any of those anchors.
        $this->assertSame(5.0, $snapshots['2025-01']['web'][30]['max']);
        $this->assertSame(5.0, $snapshots['2025-02']['web'][30]['max']);
        $this->assertSame(5.0, $snapshots['2025-03']['web'][30]['max']);
        // April snapshot at 2025-04-01 sees the newer type and its higher price.
        $this->assertSame(10.0, $snapshots['2025-04']['web'][30]['max']);
    }

    /**
     * Active filter: a subscription_type with only long-expired subscriptions
     * (end_time more than 365 days before asOf) should be excluded.
     */
    public function testExcludesTypeWithSubscriptionLongExpired(): void
    {
        $user = $this->createTestUser('long-expired@example.com');

        $expired = $this->createSubscriptionType('web_expired_30d', 5.0, 30, ['web'], DateTime::from('2020-01-15'));
        // Subscription ended in 2020, far outside the 365d active window for asOf 2025-06-01.
        $this->subscriptionsRepository->add($expired, false, true, $user, SubscriptionsRepository::TYPE_PREPAID, DateTime::from('2020-02-01'), DateTime::from('2020-03-01'));

        $snapshots = $this->builder->build(
            new \DateTimeImmutable('2025-06-01'),
            new \DateTimeImmutable('2025-06-01'),
        );

        // Tier should not appear since no type with that tier passes the active filter.
        $this->assertArrayNotHasKey('web', $snapshots['2025-06'] ?? []);
    }

    /**
     * Active filter: a subscription_type whose only subscription starts AFTER asOf
     * should also be excluded.
     */
    public function testExcludesTypeWithSubscriptionStartingAfterAsOf(): void
    {
        $user = $this->createTestUser('future-start@example.com');

        $future = $this->createSubscriptionType('web_future_30d', 5.0, 30, ['web'], DateTime::from('2025-01-15'));
        $this->subscriptionsRepository->add(
            $future,
            false,
            true,
            $user,
            SubscriptionsRepository::TYPE_PREPAID,
            DateTime::from('2025-06-01 00:00:01'),
            DateTime::from('2025-07-31'),
        );

        $snapshots = $this->builder->build(
            new \DateTimeImmutable('2025-06-01'),
            new \DateTimeImmutable('2025-06-01'),
        );

        $this->assertArrayNotHasKey('web', $snapshots['2025-06'] ?? []);
    }

    /**
     * Reversed date range (start after end) should produce an empty result cleanly,
     * no errors and no spurious month keys.
     */
    public function testReturnsEmptyForReversedDateRange(): void
    {
        $snapshots = $this->builder->build(
            new \DateTimeImmutable('2025-06-01'),
            new \DateTimeImmutable('2025-05-01'),
        );

        $this->assertSame([], $snapshots);
    }

    /**
     * Date range spanning a year boundary should produce correctly-formatted month keys
     * with no off-by-one across the year change.
     */
    public function testHandlesYearBoundary(): void
    {
        $snapshots = $this->builder->build(
            new \DateTimeImmutable('2024-12-15'),
            new \DateTimeImmutable('2025-01-15'),
        );

        $this->assertSame(['2024-12', '2025-01'], array_keys($snapshots));
    }

    /**
     * Creates a subscription_type via SubscriptionTypeBuilder, then back-dates its
     * created_at since the builder hard-codes NOW() at save time.
     *
     * @param list<string> $accessOptions content_access names (must be in ContentAccessSeeder set)
     */
    private function createSubscriptionType(
        string $code,
        float $price,
        int $length,
        array $accessOptions,
        \DateTimeInterface $createdAt,
    ): ActiveRow {
        $builder = $this->subscriptionTypeBuilder->createNew()
            ->setName($code)
            ->setUserLabel($code)
            ->setCode($code)
            ->setPrice($price)
            ->setLength($length)
            ->setActive(true);
        if ($accessOptions !== []) {
            $builder->setContentAccessOption(...$accessOptions);
        }
        $type = $builder->save();

        $this->subscriptionTypesRepository->update($type, ['created_at' => $createdAt]);
        return $type;
    }

    private function createTestUser(string $email): ActiveRow
    {
        return $this->usersRepository->add($email, 'test-password-not-used');
    }
}
