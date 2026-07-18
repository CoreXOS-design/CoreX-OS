<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill the `deal_branches` originator pivot for pre-existing (legacy) deals.
 *
 * The legacy `Deal` model isolates by the `deal_branches` pivot (DealBranchScope),
 * and only auto-attaches the originator row via a `created` model hook — so deals
 * that existed BEFORE that hook shipped have an EMPTY pivot. Under Split Branches
 * ON, an empty pivot makes a deal visible ONLY to `branches.view_all` holders, so
 * a plain agent loses sight of their own historical deals.
 *
 * This attaches one `originator` pivot row per legacy deal, sourced from the
 * deal's OWN `branch_id` (no guessing). Idempotent — skips any deal that already
 * has a matching pivot row — and uses the query builder so it bypasses SoftDeletes
 * and the global DealBranchScope. Deals with a NULL branch_id are left untouched
 * (nothing to attribute); a `view_all` holder still sees them, exactly as today.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Only meaningful where both tables and the source column exist.
        if (! Schema::hasTable('deals') || ! Schema::hasTable('deal_branches')
            || ! Schema::hasColumn('deals', 'branch_id')) {
            return;
        }

        $hasTimestamps = Schema::hasColumn('deal_branches', 'created_at');
        $hasRole       = Schema::hasColumn('deal_branches', 'role');

        DB::table('deals')
            ->whereNotNull('branch_id')
            ->orderBy('id')
            ->chunkById(500, function ($deals) use ($hasTimestamps, $hasRole) {
                $now  = now();
                $rows = [];

                foreach ($deals as $deal) {
                    $already = DB::table('deal_branches')
                        ->where('deal_id', $deal->id)
                        ->where('branch_id', $deal->branch_id)
                        ->exists();
                    if ($already) {
                        continue;
                    }

                    $row = ['deal_id' => $deal->id, 'branch_id' => $deal->branch_id];
                    if ($hasRole) {
                        $row['role'] = 'originator';
                    }
                    if ($hasTimestamps) {
                        $row['created_at'] = $now;
                        $row['updated_at'] = $now;
                    }
                    $rows[] = $row;
                }

                if ($rows) {
                    DB::table('deal_branches')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        // Non-reversible data backfill (we cannot tell a backfilled originator row
        // from an organically-created one). No-op down.
    }
};
