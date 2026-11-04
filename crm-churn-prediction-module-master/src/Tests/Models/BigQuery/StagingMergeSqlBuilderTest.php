<?php
declare(strict_types=1);

namespace Crm\ChurnPredictionModule\Tests\Models\BigQuery;

use Crm\ChurnPredictionModule\Models\BigQuery\StagingMergeSqlBuilder;
use PHPUnit\Framework\TestCase;

class StagingMergeSqlBuilderTest extends TestCase
{
    private const COLUMNS = ['user_id', 'subscription_id', 'days_to_expiry', 'nps_score'];

    private StagingMergeSqlBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new StagingMergeSqlBuilder();
    }

    private function build(array $columns = self::COLUMNS): string
    {
        return $this->builder->build(
            dataset: 'ds',
            targetTable: 'abt',
            stagingTable: 'abt_staging',
            columns: $columns,
            keyColumns: ['subscription_id', 'days_to_expiry'],
        );
    }

    public function testCreatesTargetFromStagingWhenMissing(): void
    {
        self::assertStringStartsWith(
            "BEGIN\nCREATE TABLE IF NOT EXISTS `ds.abt` AS SELECT * FROM `ds.abt_staging` WHERE FALSE;",
            $this->build(),
        );
    }

    public function testInsertNamesColumns(): void
    {
        $sql = $this->build();

        $columns = '`user_id`, `subscription_id`, `days_to_expiry`, `nps_score`';
        self::assertStringContainsString("INSERT INTO `ds.abt` ({$columns})\nSELECT {$columns}\nFROM `ds.abt_staging`;", $sql);
        self::assertStringNotContainsString('ALTER TABLE', $sql);
    }

    public function testDeleteMatchesOnKeyColumns(): void
    {
        self::assertStringContainsString(
            "WHERE s.`subscription_id` = t.`subscription_id`\n      AND s.`days_to_expiry` = t.`days_to_expiry`",
            $this->build(),
        );
    }

    public function testRejectsInvalidColumnNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->build([...self::COLUMNS, 'bad`name']);
    }
}
