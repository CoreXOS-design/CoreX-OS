<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Agency Tracker re-home step 4 - the DATA move (agency-tracker-rehome.md 4.5, 5.7).
 *
 *  1. worksheets (personal / business budget + planning figures)      -> rts_budgets, level agent
 *  2. monthly_target_goals.branch_budget (branch / company rows)      -> rts_budgets, level branch / agency
 *  3. listing_targets.target_listings                                 -> targets.listings_target (never over a non-zero number)
 *  4. The stray R200,000 branch-less budget row for 2026-09 on QA1 (monthly_target_goals id 31; Johan, 20 Oct 2026,
 *     answer 16: "It was Southbroom's; the branch is closed. Archive, never hard-delete") is archived BY ID, with
 *     its agency / branch / period / amount re-checked first so a different row with that id on another database
 *     is never touched. It is not copied.
 *
 * Nothing is deleted. The old tables keep every row they had (the one stray row gets deleted_at set); the
 * "want" figure stays on worksheets and is simply not carried (ruling A drops it).
 *
 * Only budgets above zero are carried (a row of zeros says nothing). Idempotent: a (who, month) that already
 * has a row in rts_budgets - live or archived - is left alone, so a second run changes nothing.
 * Counts and sums are verified before / after in .ai/audits/2026-10-21-budgets-targets-move.md.
 */
return new class extends Migration {
    private const STRAY_ID = 31;

    public function up(): void
    {
        if (! Schema::hasTable('rts_budgets')) {
            return;
        }

        DB::transaction(function () {
            $stats = ['worksheets' => 0, 'worksheets_dupes' => 0, 'branch' => 0, 'agency' => 0, 'stray' => 0, 'listing_filled' => 0, 'listing_created' => 0, 'listing_skipped' => 0];
            $now = now();

            // 1. Worksheets -> agent budgets. The table has no unique key on (user, period): the newest row wins.
            if (Schema::hasTable('worksheets')) {
                $rows = DB::table('worksheets as w')->join('users as u', 'u.id', '=', 'w.user_id')
                    ->whereNull('w.deleted_at')
                    ->whereRaw("w.period REGEXP '^[0-9]{4}-[0-9]{2}$'")
                    ->where(fn ($q) => $q->where('w.personal_net_target', '>', 0)->orWhere('w.business_net_target', '>', 0))
                    ->orderBy('w.id')
                    ->get(['w.*', 'u.branch_id as user_branch_id']);
                $newest = [];
                foreach ($rows as $r) {
                    $key = $r->user_id . '|' . $r->period;
                    if (isset($newest[$key])) {
                        $stats['worksheets_dupes']++;
                    }
                    $newest[$key] = $r; // later id overwrites earlier
                }
                foreach ($newest as $r) {
                    $scopeKey = 'user:' . (int) $r->user_id;
                    $exists = DB::table('rts_budgets')->where('agency_id', $r->agency_id)->where('track', 'sales')
                        ->where('scope_key', $scopeKey)->where('period', $r->period)->exists();
                    if ($exists) {
                        continue;
                    }
                    DB::table('rts_budgets')->insert([
                        'agency_id' => $r->agency_id, 'branch_id' => $r->branch_id ?? $r->user_branch_id, 'user_id' => $r->user_id,
                        'track' => 'sales', 'level' => 'agent', 'scope_key' => $scopeKey, 'period' => $r->period,
                        'personal_budget' => $r->personal_net_target, 'business_budget' => $r->business_net_target, 'requirement' => 0,
                        'avg_sale_price' => $r->avg_sale_price, 'commission_percent' => $r->commission_percent,
                        'paye_percent' => $r->paye_percent, 'agent_split_percent' => $r->agent_split_percent,
                        'correctly_priced_percent' => $r->correctly_priced_percent,
                        'legacy_source' => 'worksheets', 'legacy_id' => $r->id, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $stats['worksheets']++;
                }
            }

            // 2. Monthly goals -> branch / agency requirements; archive the one named stray row.
            if (Schema::hasTable('monthly_target_goals')) {
                $goals = DB::table('monthly_target_goals')->whereNull('deleted_at')->whereNull('user_id')->where('branch_budget', '>', 0)
                    ->whereRaw("period REGEXP '^[0-9]{4}-[0-9]{2}$'")->orderBy('id')->get();
                foreach ($goals as $g) {
                    $isStray = (int) $g->id === self::STRAY_ID && (int) $g->agency_id === 1 && (int) ($g->branch_id ?? -1) === 0
                        && $g->period === '2026-09' && (float) $g->branch_budget === 200000.0;
                    if ($isStray) {
                        DB::table('monthly_target_goals')->where('id', $g->id)->update(['deleted_at' => $now, 'updated_at' => $now]);
                        $stats['stray']++;
                        Log::info('rts budgets move: archived stray branch-less budget row', ['monthly_target_goals_id' => $g->id, 'amount' => $g->branch_budget]);
                        continue;
                    }
                    $isBranch = $g->branch_id !== null;
                    $scopeKey = $isBranch ? 'branch:' . (int) $g->branch_id : 'agency';
                    $exists = DB::table('rts_budgets')->where('agency_id', $g->agency_id)->where('track', 'sales')
                        ->where('scope_key', $scopeKey)->where('period', $g->period)->exists();
                    if ($exists) {
                        continue;
                    }
                    DB::table('rts_budgets')->insert([
                        'agency_id' => $g->agency_id, 'branch_id' => $isBranch ? $g->branch_id : null, 'user_id' => null,
                        'track' => 'sales', 'level' => $isBranch ? 'branch' : 'agency', 'scope_key' => $scopeKey, 'period' => $g->period,
                        'personal_budget' => 0, 'business_budget' => 0, 'requirement' => $g->branch_budget,
                        'legacy_source' => 'monthly_target_goals', 'legacy_id' => $g->id, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $stats[$isBranch ? 'branch' : 'agency']++;
                }
            }

            // 3. Listing targets -> the listings number on the monthly targets. A number already there is never replaced.
            if (Schema::hasTable('listing_targets') && Schema::hasTable('targets')) {
                $lts = DB::table('listing_targets as l')->join('users as u', 'u.id', '=', 'l.user_id')
                    ->whereNull('l.deleted_at')->where('l.target_listings', '>', 0)
                    ->get(['l.user_id', 'l.agency_id', 'l.period', 'l.target_listings', 'u.branch_id']);
                foreach ($lts as $l) {
                    $t = DB::table('targets')->where('period', $l->period)->where('user_id', $l->user_id)->first();
                    if (! $t) {
                        DB::table('targets')->insert([
                            'period' => $l->period, 'user_id' => $l->user_id, 'agency_id' => $l->agency_id, 'branch_id' => $l->branch_id,
                            'listings_target' => $l->target_listings, 'deals_target' => 0, 'value_target' => 0, 'points_target' => 0,
                            'created_at' => $now, 'updated_at' => $now,
                        ]);
                        $stats['listing_created']++;
                    } elseif ($t->deleted_at === null && (int) $t->listings_target === 0) {
                        DB::table('targets')->where('id', $t->id)->update(['listings_target' => $l->target_listings, 'updated_at' => $now]);
                        $stats['listing_filled']++;
                    } else {
                        $stats['listing_skipped']++;
                    }
                }
            }

            Log::info('rts budgets move done', $stats);
        });
    }

    public function down(): void
    {
        // Not reversible on purpose: the old tables were left intact, so there is nothing to put back.
    }
};
