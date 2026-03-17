<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SyncNamedFields extends Command
{
    protected $signature = 'docuperfect:sync-fields';

    protected $description = 'Sync named fields from database schema for contacts, properties, users, and deals';

    /**
     * Tables to scan and their source_type mapping.
     */
    private const SOURCE_MAP = [
        'contacts'   => 'contact',
        'properties' => 'property',
        'users'      => 'agent',
        'deals'      => 'deal',
    ];

    /**
     * System columns to skip — these are never useful as document fields.
     */
    private const SKIP_COLUMNS = [
        'id',
        'created_at',
        'updated_at',
        'deleted_at',
        'remember_token',
        'password',
        'email_verified_at',
        'api_token',
    ];

    /**
     * Composite/computed fields to ensure exist.
     * Format: source_type => [ source_column => label ]
     */
    private const COMPOSITE_FIELDS = [
        'contact' => [
            'first_name+last_name' => 'Full Name',
        ],
    ];

    public function handle(): int
    {
        $added = 0;
        $existed = 0;

        foreach (self::SOURCE_MAP as $table => $sourceType) {
            if (!Schema::hasTable($table)) {
                $this->warn("Table [{$table}] does not exist — skipping.");
                continue;
            }

            $columns = Schema::getColumnListing($table);
            $maxSort = (int) DB::table('docuperfect_named_fields')
                ->where('source_type', $sourceType)
                ->max('sort_order') ?? 0;

            foreach ($columns as $column) {
                if (in_array($column, self::SKIP_COLUMNS, true)) {
                    continue;
                }

                $exists = DB::table('docuperfect_named_fields')
                    ->where('source_type', $sourceType)
                    ->where('source_column', $column)
                    ->exists();

                if ($exists) {
                    $existed++;
                    continue;
                }

                $maxSort++;
                DB::table('docuperfect_named_fields')->insert([
                    'name'          => $this->humanLabel($column),
                    'field_type'    => $this->inferFieldType($table, $column),
                    'source_type'   => $sourceType,
                    'source_column' => $column,
                    'sort_order'    => $maxSort,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);

                $this->line("  + [{$sourceType}] {$column} → " . $this->humanLabel($column));
                $added++;
            }
        }

        // Ensure composite fields exist
        foreach (self::COMPOSITE_FIELDS as $sourceType => $fields) {
            foreach ($fields as $sourceColumn => $label) {
                $exists = DB::table('docuperfect_named_fields')
                    ->where('source_type', $sourceType)
                    ->where('source_column', $sourceColumn)
                    ->exists();

                if ($exists) {
                    $existed++;
                    continue;
                }

                $maxSort = (int) DB::table('docuperfect_named_fields')
                    ->where('source_type', $sourceType)
                    ->max('sort_order') ?? 0;

                DB::table('docuperfect_named_fields')->insert([
                    'name'          => $label,
                    'field_type'    => 'text',
                    'source_type'   => $sourceType,
                    'source_column' => $sourceColumn,
                    'sort_order'    => $maxSort + 1,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);

                $this->line("  + [{$sourceType}] {$sourceColumn} → {$label} (composite)");
                $added++;
            }
        }

        $this->info("Added {$added} new fields. {$existed} already existed.");

        return 0;
    }

    /**
     * Convert snake_case column name to Title Case label.
     */
    private function humanLabel(string $column): string
    {
        return Str::of($column)
            ->replace('_', ' ')
            ->title()
            ->toString();
    }

    /**
     * Infer field_type from the database column type.
     */
    private function inferFieldType(string $table, string $column): string
    {
        $type = Schema::getColumnType($table, $column);

        if (in_array($type, ['date', 'datetime', 'timestamp'], true)) {
            return 'date';
        }

        if (in_array($type, ['integer', 'bigint', 'smallint', 'decimal', 'float', 'double'], true)) {
            return 'number';
        }

        return 'text';
    }
}
