<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\SuggestedActionThresholds;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds Build E suggested-action thresholds for every existing agency with
 * the §7.2 defaults. Idempotent — safe to re-run.
 *
 * Mirrors BuyerMatchTiersSeeder in pattern. Uses updateOrInsert so existing
 * customised rows are not overwritten on a partial re-seed of fresh agencies.
 */
final class SuggestedActionThresholdsSeeder extends Seeder
{
    public function run(): void
    {
        Agency::query()->whereNull('deleted_at')->each(function (Agency $agency): void {
            $defaults = SuggestedActionThresholds::defaultsFor((int) $agency->id);
            // Drop agency_id from the values payload — it's the match key.
            unset($defaults['agency_id']);

            DB::table('suggested_action_thresholds')->updateOrInsert(
                ['agency_id' => $agency->id],
                array_merge($defaults, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        });
    }
}
