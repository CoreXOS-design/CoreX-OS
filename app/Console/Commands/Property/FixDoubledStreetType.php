<?php

declare(strict_types=1);

namespace App\Console\Commands\Property;

use App\Support\Address\StreetTypes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * AT-520: "13 Newport Road Road" -> "13 Newport Road" on the stored street name (31 files on QA1, 31 Oct). Property files and their address history;
 * every changed row is journalled (table, id, old, new). New saves are normalised by the model, so this is a one-off clean-up. Dry run by default.
 */
final class FixDoubledStreetType extends Command
{
    protected $signature = 'property-identity:fix-doubled-street-type
        {--dry-run : Count and write nothing (the default)}
        {--apply : Write the corrected street names}
        {--journal-dir= : Where the row journal is written (default: storage/app/reports)}';

    protected $description = 'Collapse a doubled trailing street-type word in stored street names (row journal)';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing', 'qa'])) {
            $this->error('This only runs on QA / local / testing (APP_ENV is "' . app()->environment() . '").');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $dir = (string) ($this->option('journal-dir') ?: storage_path('app/reports'));
        File::ensureDirectoryExists($dir);
        $fh = $apply ? fopen($dir . '/fix-doubled-street-type-journal-' . now()->format('Ymd-His') . '.jsonl', 'ab') : null;
        $total = 0;
        foreach (['tracked_properties', 'tracked_property_addresses'] as $table) {
            $n = 0;
            DB::table($table)->whereNotNull('street_name')->orderBy('id')->chunkById(500, function ($rows) use ($table, $apply, $fh, &$n) {
                foreach ($rows as $r) {
                    $fixed = StreetTypes::collapseDoubled($r->street_name);
                    if ($fixed === $r->street_name) {
                        continue;
                    }
                    $n++;
                    if ($apply) {
                        fwrite($fh, json_encode(['table' => $table, 'id' => (int) $r->id, 'column' => 'street_name', 'old' => $r->street_name, 'new' => $fixed], JSON_UNESCAPED_UNICODE) . "\n");
                        DB::table($table)->where('id', $r->id)->update(['street_name' => $fixed]);
                    }
                }
            });
            $this->line("{$table}: {$n} street names " . ($apply ? 'corrected' : 'would be corrected'));
            $total += $n;
        }
        if ($fh) {
            fclose($fh);
        }
        $this->info($apply ? "Applied: {$total} rows (journalled)." : "Dry run: {$total} rows would change. Nothing was written.");

        return self::SUCCESS;
    }
}
