<?php
declare(strict_types=1);

namespace Crm\ChurnPredictionModule\Models\BigQuery;

/**
 * Builds the BigQuery script that upserts a staging table into its target table.
 *
 * Columns are matched by name. A staging column missing in the target
 * makes the insert fail.
 */
class StagingMergeSqlBuilder
{
    /**
     * @param list<string> $columns staging table column names
     * @param list<string> $keyColumns columns identifying a row; target rows matching staging rows are replaced
     */
    public function build(
        string $dataset,
        string $targetTable,
        string $stagingTable,
        array $columns,
        array $keyColumns,
    ): string {
        $target = $this->quoteTable($dataset, $targetTable);
        $staging = $this->quoteTable($dataset, $stagingTable);

        $keyConditions = array_map(
            fn(string $column) => 's.' . $this->quoteColumn($column) . ' = t.' . $this->quoteColumn($column),
            $keyColumns,
        );
        $columnList = implode(', ', array_map(fn(string $column) => $this->quoteColumn($column), $columns));

        return "BEGIN\n"
            . "CREATE TABLE IF NOT EXISTS {$target} AS SELECT * FROM {$staging} WHERE FALSE;\n\n"
            . "DELETE FROM {$target} t\nWHERE EXISTS (\n    SELECT 1 FROM {$staging} s\n    WHERE "
            . implode("\n      AND ", $keyConditions) . "\n);\n\n"
            . "INSERT INTO {$target} ({$columnList})\nSELECT {$columnList}\nFROM {$staging};\n"
            . "END;";
    }

    private function quoteColumn(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("Invalid column name '{$name}'.");
        }
        return "`{$name}`";
    }

    private function quoteTable(string $dataset, string $table): string
    {
        return "`{$dataset}.{$table}`";
    }
}
