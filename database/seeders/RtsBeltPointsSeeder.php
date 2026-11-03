<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Contracts\SyncableReferenceSeeder;
use App\Services\RoadToSold\BeltPoints;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Road to Sold v4 belt points (road-to-sold.md 23.16): every agency gets its own definition + mapping per belt credit, at the spec default.
 * Idempotent; never changes an amount an agency edited and never brings back a credit an agency removed. A new agency created between
 * deploys is provisioned the first time it earns or opens the Points tab (BeltPoints::ensureAgency), so it is never left without.
 */
class RtsBeltPointsSeeder extends Seeder implements SyncableReferenceSeeder
{
    public function run(): void
    {
        $points = app(BeltPoints::class);
        foreach (DB::table('agencies')->whereNull('deleted_at')->pluck('id') as $agencyId) {
            $points->ensureAgency((int) $agencyId);
        }
    }
}
