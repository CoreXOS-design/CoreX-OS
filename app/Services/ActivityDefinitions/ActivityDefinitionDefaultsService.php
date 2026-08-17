<?php

namespace App\Services\ActivityDefinitions;

use Illuminate\Support\Facades\DB;

/**
 * Seeds an agency's own copy of the daily-activity points definitions.
 *
 * Root cause fixed alongside this service: TargetController's
 * activityDefinitions()/activityDefinitionsSave() used to read AND write the
 * single shared `scope='system'` row set directly — every agency's admin was
 * editing (and adding to) the exact same global list, so one agency's changes
 * silently became every other agency's points config. There was no per-agency
 * copy at all.
 *
 * Fix: each agency now gets its OWN `scope='agency'` rows, seeded once from
 * whatever the shared `scope='system'` rows hold at seed time (today, that is
 * effectively Home Finders Coastal's tuned values — a reasonable starting
 * point, not a live link). The agency can freely edit/add from there without
 * touching any other agency. The original `scope='system'` rows are left in
 * place, permanently inert as far as the per-agency screen is concerned —
 * they exist only as the template new agencies seed from.
 */
class ActivityDefinitionDefaultsService
{
    /**
     * Seed this agency's own activity_definitions from the current system
     * template, UNLESS it already has any agency-scoped rows of its own
     * (including disabled ones — an agency that deliberately disabled
     * everything keeps that choice and is not re-seeded). Idempotent; safe
     * to call on every agency creation / settings-page load.
     */
    public function ensureDefaults(int $agencyId): void
    {
        $hasAny = DB::table('activity_definitions')
            ->where('agency_id', $agencyId)
            ->where('scope', 'agency')
            ->exists();

        if ($hasAny) {
            return;
        }

        $template = DB::table('activity_definitions')
            ->where('scope', 'system')
            ->whereNull('agency_id')
            ->get(['name', 'weight', 'sort_order', 'scoring_mode', 'is_enabled']);

        $now = now();
        foreach ($template as $def) {
            DB::table('activity_definitions')->insert([
                'name'         => $def->name,
                'weight'       => $def->weight,
                'sort_order'   => $def->sort_order,
                'scope'        => 'agency',
                'agency_id'    => $agencyId,
                'branch_id'    => null,
                'scoring_mode' => $def->scoring_mode,
                'is_enabled'   => $def->is_enabled,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }
    }
}
