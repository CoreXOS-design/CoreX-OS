<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time repair: fix double-encoded extracted_json / override_json
 * in presentation_links and presentation_uploads tables.
 *
 * Safe for dev DB only. Idempotent — running twice is harmless.
 */
class RepairDoubleEncodedJson extends Command
{
    protected $signature = 'presentations:repair-json';
    protected $description = 'Fix double-encoded JSON in presentation_links and presentation_uploads';

    public function handle(): int
    {
        $fixed = 0;

        $fixed += $this->repairTable('presentation_links', 'extracted_json');
        $fixed += $this->repairTable('presentation_links', 'override_json');
        $fixed += $this->repairTable('presentation_uploads', 'extraction_json');
        $fixed += $this->repairTable('presentation_uploads', 'override_json');

        $this->info("Done. Fixed {$fixed} row(s).");

        return self::SUCCESS;
    }

    private function repairTable(string $table, string $column): int
    {
        $rows = DB::table($table)
            ->whereNotNull($column)
            ->get(['id', $column]);

        $fixed = 0;

        foreach ($rows as $row) {
            $raw = $row->{$column};

            if (!is_string($raw)) {
                continue;
            }

            // Try to detect double-encoding: starts with quote or contains escaped braces
            $decoded = json_decode($raw, true);

            if (is_string($decoded)) {
                // Double-encoded — decode again
                $secondDecode = json_decode($decoded, true);
                if (is_array($secondDecode)) {
                    DB::table($table)
                        ->where('id', $row->id)
                        ->update([$column => json_encode($secondDecode)]);
                    $fixed++;
                    $this->line("  Fixed {$table}.{$column} id={$row->id} (double-encoded)");
                }
            }
        }

        return $fixed;
    }
}
