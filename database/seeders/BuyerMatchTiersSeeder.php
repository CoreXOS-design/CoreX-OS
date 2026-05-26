<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Agency;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds default buyer-match thresholds (80 / 50 / 0) for every agency.
 * Idempotent — safe to re-run.
 */
final class BuyerMatchTiersSeeder extends Seeder
{
    public function run(): void
    {
        Agency::query()->whereNull('deleted_at')->each(function (Agency $agency) {
            DB::table('buyer_match_tiers')->updateOrInsert(
                ['agency_id' => $agency->id],
                [
                    'strong_min_score'   => 80,
                    'mid_min_score'      => 50,
                    'weak_min_score'     => 0,
                    'strong_label'       => 'Strong',
                    'mid_label'          => 'Mid',
                    'weak_label'         => 'Weak',
                    'show_weak_in_badge' => true,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]
            );
        });
    }
}
