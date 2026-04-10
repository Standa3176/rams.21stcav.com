<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * QuoteWerksSchema — development tool to inspect the live QuoteWerks DB schema.
 *
 * Usage: php artisan quotewerks:schema
 *        php artisan quotewerks:schema --table=DocumentHeaders
 *        php artisan quotewerks:schema --sample=5
 *
 * Purpose: Verify actual column names before implementing QuoteWerksRepository.
 * Column names in research are [ASSUMED] — this command reveals ground truth.
 *
 * NOT intended for production use. Development/migration tool only.
 */
class QuoteWerksSchema extends Command
{
    protected $signature   = 'quotewerks:schema
                              {--table= : Show columns and sample rows for a specific table}
                              {--sample=3 : Number of sample rows to display}';
    protected $description = 'Inspect QuoteWerks database schema (development tool)';

    /**
     * Tables of interest for the import pipeline.
     * DocumentHeaders = quote header, DocumentItems = line items,
     * DocumentItemGroups = room/zone grouping (D-06, D-07).
     */
    private const TARGET_TABLES = [
        'DocumentHeaders',
        'DocumentItems',
        'DocumentItemGroups',
    ];

    public function handle(): int
    {
        try {
            $conn = DB::connection('quotewerks');
            $conn->getPdo(); // force connection
        } catch (\Throwable $e) {
            $this->error('Cannot connect to QuoteWerks: ' . $e->getMessage());
            $this->line('Run quotewerks:ping for diagnostics.');
            return self::FAILURE;
        }

        $specificTable = $this->option('table');
        $sampleRows    = (int) $this->option('sample');

        if ($specificTable) {
            $this->inspectTable($specificTable, $sampleRows);
            return self::SUCCESS;
        }

        // ── List all tables ──
        $this->info('QuoteWerks database tables:');
        $tables = DB::connection('quotewerks')->select(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
        );

        foreach ($tables as $t) {
            $name   = $t->TABLE_NAME ?? $t->table_name;
            $marker = in_array($name, self::TARGET_TABLES, true) ? ' <- import target' : '';
            $this->line("  {$name}{$marker}");
        }

        $this->line('');
        $this->info('Inspecting import target tables:');

        foreach (self::TARGET_TABLES as $table) {
            $this->line('');
            $this->inspectTable($table, $sampleRows);
        }

        return self::SUCCESS;
    }

    /**
     * Inspect a single table: show columns then optional sample rows.
     *
     * @param  string  $table      Table name to inspect
     * @param  int     $sampleRows Number of sample rows to display (0 = skip)
     */
    private function inspectTable(string $table, int $sampleRows): void
    {
        $conn = DB::connection('quotewerks');

        $this->info("── {$table} ──");

        // ── Columns ──
        try {
            $columns = $conn->select(
                'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_NAME = ?
                 ORDER BY ORDINAL_POSITION',
                [$table]
            );

            if (empty($columns)) {
                $this->warn("  Table '{$table}' not found or no columns.");
                return;
            }

            $headers = ['Column', 'Type', 'Nullable', 'MaxLen'];
            $rows    = [];
            foreach ($columns as $col) {
                $rows[] = [
                    $col->COLUMN_NAME              ?? $col->column_name,
                    $col->DATA_TYPE                ?? $col->data_type,
                    $col->IS_NULLABLE              ?? $col->is_nullable,
                    $col->CHARACTER_MAXIMUM_LENGTH ?? $col->character_maximum_length ?? '-',
                ];
            }
            $this->table($headers, $rows);
        } catch (\Throwable $e) {
            $this->warn('  Could not fetch columns: ' . $e->getMessage());
            return;
        }

        // ── Sample rows ──
        if ($sampleRows > 0) {
            try {
                $sample = $conn->select("SELECT TOP {$sampleRows} * FROM [{$table}]");
                if (! empty($sample)) {
                    $this->line("  Sample ({$sampleRows} rows):");
                    foreach ($sample as $i => $row) {
                        $this->line('  Row ' . ($i + 1) . ':');
                        foreach ((array) $row as $k => $v) {
                            $display = is_string($v) ? mb_substr($v, 0, 80) : $v;
                            $this->line("    {$k}: {$display}");
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->warn('  Could not fetch sample rows: ' . $e->getMessage());
            }
        }
    }
}
