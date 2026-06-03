<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * HFC's calendar_class → activity_definition mappings for the Module 6
 * Activity Points engine.
 *
 * REFERENCE seeder — runs on every deploy via DatabaseSeeder + scripts/
 * deploy.sh REF_SEEDERS. Mirrors the rows the one-time migration
 * 2026_06_20_120000_seed_hfc_activity_calendar_mappings created, so a
 * fresh DB / DB reload (e.g. staging copied from live) populates the
 * mappings even when the migration is already marked "ran" and would
 * therefore be skipped by `migrate --force`. This is the same
 * fix-the-class pattern applied to CalendarEventClassSeeder +
 * BuyerMatchTiersSeeder + AgencyFeedbackOptionsSeeder after the
 * 2026-06-02 db:seed incident: reference / seed data lives in
 * idempotent seeders, not one-time migrations.
 *
 * Idempotency contract — re-running NEVER:
 *   - duplicates: existence check is on (agency_id, event_class,
 *     activity_definition_id) and includes soft-deleted rows on
 *     purpose, so once a row exists in any state it is left alone;
 *   - resurrects admin-removed mappings: if an admin soft-deleted a
 *     mapping via the M6.2 admin UI, the soft-deleted row blocks the
 *     existence check and the seeder skips, preserving intent;
 *   - overwrites admin edits: value_per_event / is_active / cap fields
 *     are NEVER updated on existing rows — only set on first insert
 *     from the activity_definition's current weight as an editable
 *     starting default (Johan's decision: "do not invent numbers").
 *
 * Out-of-scope by design:
 *   - property_showday: no 1:1 activity_definition exists in HFC's
 *     41-row list. Either reuse "Presentation" or create an agency-
 *     scoped "Show Day / Open House" definition — open question on
 *     Johan's plate; mapping is intentionally NOT seeded.
 *   - meeting: high-noise event class — seeded as is_active=FALSE so
 *     the agency opts in deliberately.
 */
final class ActivityCalendarMappingSeeder extends Seeder
{
    public function run(): void
    {
        $agencyId = (int) (DB::table('agencies')->where('slug', 'hfc-coastal')->value('id') ?? 0);
        if ($agencyId === 0) {
            // HFC not present (fresh test DB / non-HFC environment). Nothing
            // to seed — caller is responsible for setting up the agency
            // first. Silent no-op so the seeder is safe to call universally.
            return;
        }

        // Resolve definition ids by name so a definition reorder / id-bump
        // doesn't break the seeder. If a definition is missing on this DB
        // (e.g. a custom HFC list), skip that specific mapping silently.
        $defs = DB::table('activity_definitions')
            ->whereIn('name', ['Take out Buyers', 'Appointments', 'Presentation'])
            ->get(['id', 'name', 'weight'])
            ->keyBy('name');

        $mappings = [
            // event_class           => [definition name,    requires_feedback, is_active]
            'viewing'                => ['Take out Buyers',  true,  true],
            'property_evaluation'    => ['Appointments',     true,  true],
            'listing_presentation'   => ['Presentation',     true,  true],
            // meeting: opt-in (high noise risk — agency activates explicitly).
            'meeting'                => ['Appointments',     false, false],
        ];

        $now = now();

        foreach ($mappings as $eventClass => [$defName, $requiresFeedback, $isActive]) {
            $def = $defs->get($defName);
            if (! $def) {
                continue;
            }

            $alreadyExists = DB::table('activity_definition_calendar_classes')
                ->where('agency_id', $agencyId)
                ->where('event_class', $eventClass)
                ->where('activity_definition_id', (int) $def->id)
                ->exists(); // includes soft-deleted on purpose — see docblock
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
}
