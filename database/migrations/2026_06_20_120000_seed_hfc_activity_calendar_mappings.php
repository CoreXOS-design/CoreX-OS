<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Module 6 (M6.2) — seed HFC's day-one calendar_class → activity_definition
 * mappings so the M6.3 observer has something to react to as soon as it lands.
 *
 * Idempotent: per mapping we check whether a row already exists for the
 * (agency_id, event_class, activity_definition_id) triple — including
 * soft-deleted rows — and skip it if so. That way:
 *   - first run on production / demo: inserts HFC's mappings
 *   - re-runs: noop (no duplicates, no override of any subsequent agency edit)
 *   - agency deleted a mapping later: stays deleted (we don't resurrect)
 *
 * Per Johan: value_per_event seeded from the activity_definition's current
 * weight as the agency's editable starting default. No invented numbers.
 *
 * property_showday is intentionally NOT seeded: no 1:1 activity_definition
 * exists for it in HFC's 41-row list. Flagged for Johan to either create an
 * agency-scoped "Show Day / Open House" definition or reuse "Presentation"
 * — decision deferred, mapping skipped here.
 */
return new class extends Migration {
    public function up(): void
    {
        $agencyId = (int) (DB::table('agencies')->where('slug', 'hfc-coastal')->value('id') ?? 0);
        if ($agencyId === 0) {
            // No HFC agency present (e.g. fresh test DB). Nothing to seed.
            return;
        }

        $now = now();

        // Resolve activity_definition ids by name to avoid hardcoding row ids.
        // Skip a mapping silently if its target definition is missing on this
        // database — keeps the migration safe across demo / test / prod where
        // the seed inventory may differ.
        $defs = DB::table('activity_definitions')
            ->whereIn('name', ['Take out Buyers', 'Appointments', 'Presentation'])
            ->get(['id', 'name', 'weight'])
            ->keyBy('name');

        $mappings = [
            // event_class           => [definition name,    requires_feedback, is_active]
            'viewing'                => ['Take out Buyers',  true,  true],
            'property_evaluation'    => ['Appointments',     true,  true],
            'listing_presentation'   => ['Presentation',     true,  true],
            // meeting: high noise risk per spec — opt-in, agency activates.
            'meeting'                => ['Appointments',     false, false],
        ];

        foreach ($mappings as $eventClass => [$defName, $requiresFeedback, $isActive]) {
            $def = $defs->get($defName);
            if (!$def) {
                // Target definition missing — skip rather than fail.
                continue;
            }

            $alreadyExists = DB::table('activity_definition_calendar_classes')
                ->where('agency_id', $agencyId)
                ->where('event_class', $eventClass)
                ->where('activity_definition_id', (int) $def->id)
                ->exists(); // includes soft-deleted rows on purpose
            if ($alreadyExists) {
                continue;
            }

            DB::table('activity_definition_calendar_classes')->insert([
                'agency_id'               => $agencyId,
                'event_class'             => $eventClass,
                'activity_definition_id'  => (int) $def->id,
                'value_per_event'         => (int) round((float) $def->weight),
                'requires_feedback'       => $requiresFeedback,
                'auto_revoke_after_hours' => 24,
                'daily_cap'               => null,
                'back_date_limit_hours'   => 48,
                'is_active'               => $isActive,
                'created_by'              => null,
                'updated_by'              => null,
                'created_at'              => $now,
                'updated_at'              => $now,
            ]);
        }
    }

    public function down(): void
    {
        $agencyId = (int) (DB::table('agencies')->where('slug', 'hfc-coastal')->value('id') ?? 0);
        if ($agencyId === 0) {
            return;
        }

        // Reverse only the rows this seed could have inserted — leave any
        // agency-edited / agency-created mappings intact.
        DB::table('activity_definition_calendar_classes')
            ->where('agency_id', $agencyId)
            ->whereIn('event_class', ['viewing', 'property_evaluation', 'listing_presentation', 'meeting'])
            ->whereNull('deleted_at')
            ->delete();
    }
};
