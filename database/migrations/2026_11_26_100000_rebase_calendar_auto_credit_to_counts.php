<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agency Tracker re-home step 3 (agency-tracker-rehome.md §5.6 defect 1, §14) — the points
 * rule is "points = count × the agency's weight", and `daily_activity_entries.value` is a COUNT.
 *
 * Calendar auto-credit mappings were seeded with `value_per_event` = the activity's WEIGHT
 * (a points amount), so each calendar event stored value = 200 and was then multiplied by the
 * weight of 200 again: 40,000 points for one evaluation.
 *
 * This migration moves the rule to its correct shape FROM THE CUT-OVER ON (Johan, 20 Oct 2026,
 * answer 6: "fix from the cut-over date, past months keep their old totals"):
 *
 *   1. Calendar mappings whose count equals the activity's weight (the seeded copy) become 1
 *      per event. A mapping an agency set to anything else is left alone.
 *   2. Calendar entries still PROVISIONAL (not yet confirmed, so not part of anyone's total)
 *      and carrying that same seeded points figure are re-based to a count of 1, so they do
 *      not confirm later at the wrong size.
 *
 * It NEVER touches a confirmed, overridden or revoked entry: past months keep exactly the totals
 * they had. Whether the already-confirmed calendar credit should be corrected too is Johan's
 * decision, reported separately with per-agent figures.
 *
 * Idempotent: a second run finds nothing equal to the weight and changes nothing.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('activity_definition_calendar_classes')
            || ! Schema::hasTable('daily_activity_entries')
            || ! Schema::hasTable('activity_definitions')) {
            return;
        }

        DB::transaction(function () {
            // 1. Provisional calendar entries first (needs the weight comparison, independent of the mapping).
            DB::table('daily_activity_entries as e')
                ->join('activity_definitions as d', 'd.id', '=', 'e.activity_definition_id')
                ->where('e.source', 'auto_calendar')
                ->where('e.point_state', 'provisional')
                ->where('e.value', '>', 1)
                ->whereRaw('e.`value` = ROUND(d.`weight`)')
                ->update(['e.value' => 1, 'e.updated_at' => now()]);

            // 2. Calendar mappings that carry the activity's weight as their "count per event".
            DB::table('activity_definition_calendar_classes as m')
                ->join('activity_definitions as d', 'd.id', '=', 'm.activity_definition_id')
                ->where('m.trigger_kind', 'calendar')
                ->where('m.value_per_event', '>', 1)
                ->whereRaw('m.`value_per_event` = ROUND(d.`weight`)')
                ->update(['m.value_per_event' => 1, 'm.updated_at' => now()]);
        });
    }

    public function down(): void
    {
        // Not reversible: the old figure was the activity's weight, which can be re-derived but
        // restoring it would reintroduce the double-weighting defect. Intentionally a no-op.
    }
};
