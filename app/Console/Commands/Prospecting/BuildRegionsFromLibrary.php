<?php

namespace App\Console\Commands\Prospecting;

use App\Services\Prospecting\RegionSuggestionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AT-242/AT-239 region backbone — pre-populate an agency's prospecting geography
 * (towns + town_suburbs + towns.region) from the curated region library
 * (database/seeders/data/sa_region_suggestions.php, via RegionSuggestionService).
 *
 * Johan-ruled taxonomy (2026-07-13, verified on P24): region = the P24 ALIAS
 * region where one exists (Hibiscus Coast), else the MUNICIPAL name grouping the
 * P24 towns beneath it (KwaDukuza, Umdoni). P24 payloads carry NO usable region
 * field (p24_listings.area is null; stored alias/city IDs are corrupt), so the
 * curated library is the seed source. Everything is agency-editable afterwards on
 * the Regions screen; the unmapped-suburb queue catches the long tail.
 *
 * Idempotent + resumable: towns keyed on (agency_id, slug), suburbs on
 * (agency_id, suburb_normalised) — a re-run updates in place and never
 * duplicates, and un-soft-deletes a previously removed row it re-seeds.
 * AT-203-safe: every write stamps agency_id explicitly (console has no agency
 * context).
 */
class BuildRegionsFromLibrary extends Command
{
    protected $signature = 'prospecting:build-regions-from-library
        {--agency= : agency_id to seed (default: every agency with prospecting_listings)}
        {--regions= : comma-separated library region keys (default: all)}
        {--dry-run : report only, write nothing}';

    protected $description = 'Seed towns + town_suburbs + region for an agency from the curated region library.';

    public function handle(RegionSuggestionService $lib): int
    {
        $dry = (bool) $this->option('dry-run');

        $agencyIds = $this->option('agency')
            ? [(int) $this->option('agency')]
            : DB::table('prospecting_listings')->distinct()->pluck('agency_id')->map(fn ($v) => (int) $v)->filter()->all();

        if (empty($agencyIds)) {
            $this->warn('No agencies to seed (pass --agency or ensure prospecting_listings exist).');
            return self::SUCCESS;
        }

        $allKeys = array_keys($lib->regions());
        $keys = $this->option('regions')
            ? array_values(array_intersect($allKeys, array_map('trim', explode(',', (string) $this->option('regions')))))
            : $allKeys;

        $now = now();
        foreach ($agencyIds as $agencyId) {
            $townCount = 0; $subCount = 0; $order = 0;
            foreach ($keys as $key) {
                $region = $lib->region($key);
                if (! $region) { continue; }
                foreach ($region['towns'] as $town) {
                    $order++;
                    $slug = Str::slug($town['name']);
                    $this->line(($dry ? '[dry] ' : '') . "  {$region['name']} → {$town['name']} ({$slug}) [" . count($town['suburbs']) . ' suburbs]');
                    if (! $dry) {
                        DB::table('towns')->updateOrInsert(
                            ['agency_id' => $agencyId, 'slug' => $slug],
                            ['name' => $town['name'], 'region' => $region['name'], 'display_order' => $order,
                             'deleted_at' => null, 'updated_at' => $now, 'created_at' => $now],
                        );
                    }
                    $townCount++;
                    $townId = $dry ? 0 : (int) DB::table('towns')->where('agency_id', $agencyId)->where('slug', $slug)->value('id');
                    foreach ($town['suburbs'] as $suburb) {
                        $norm = strtolower(trim($suburb));
                        if ($norm === '') { continue; }
                        if (! $dry) {
                            DB::table('town_suburbs')->updateOrInsert(
                                ['agency_id' => $agencyId, 'suburb_normalised' => $norm],
                                ['town_id' => $townId, 'suburb_name' => $suburb, 'deleted_at' => null,
                                 'updated_at' => $now, 'created_at' => $now],
                            );
                        }
                        $subCount++;
                    }
                }
            }
            $this->info(($dry ? '[dry-run] ' : '') . "agency {$agencyId}: {$townCount} towns, {$subCount} suburbs across " . count($keys) . ' regions.');
        }

        return self::SUCCESS;
    }
}
