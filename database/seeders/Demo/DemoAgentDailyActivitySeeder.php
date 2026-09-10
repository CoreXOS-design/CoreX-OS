<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Facades\DB;

/**
 * Webinar day 2026-09-03 — orphan screen (cc6's audit: nobody's slice all
 * night). agent.daily / agent.daily.summary are confirmed real, working
 * code (rendered cleanly for a real agent, proper zero-state, no errors) —
 * a pure demo-data gap, not a half-built feature.
 *
 * Root cause (confirmed by investigation before building): the Activity
 * Points engine needs THREE things demo never had —
 *   1. Visible (agent-facing, is_enabled=true) activity_definitions. The
 *      real 41-row HFC catalogue has no seeder anywhere in the codebase —
 *      it reads as hand-configured production content that was never
 *      captured as reproducible reference data, for any environment.
 *      Authors a smaller, plausible ~11-row set here (new content).
 *   2. Calendar-class -> definition mappings. The real seeder
 *      (ActivityCalendarMappingSeeder) exists but is hardcoded to
 *      `where('slug', 'hfc-coastal')` — demo's agency slug is
 *      'corex-demo-realty', so it silently no-ops. NOT modifying that
 *      seeder (production file, hardcoded intentionally for HFC's real
 *      deploy) — this seeder replicates its exact mapping logic
 *      (viewing/property_evaluation/listing_presentation/meeting) for
 *      agency 1 directly, same pattern as the rest of tonight's demo-side
 *      fixes.
 *   3. Actual daily_activity_entries + a monthly target. Derived from
 *      REAL calendar_events already on demo this month — not fabricated
 *      counts — so the auto-credit story is genuine: an agent's viewing
 *      appointments this month become their activity points this month.
 *
 * ActivityInstantActionsSeeder (the separate hidden "[Auto]" instant-
 * action catalogue) is wired into DemoDataSeeder directly alongside this
 * one — same gap class, different seeder, no reason to duplicate it here.
 *
 * Idempotent throughout: definitions matched on name, mappings matched on
 * (agency_id, event_class, activity_definition_id) including soft-deleted
 * (never resurrects an admin removal), entries upserted on the real
 * (activity_definition_id, user_id, activity_date) unique key, targets
 * upserted on (period, user_id).
 */
final class DemoAgentDailyActivitySeeder
{
    private const DEFINITIONS = [
        ['name' => 'Take out Buyers', 'weight' => 3, 'sort_order' => 10],
        ['name' => 'Appointments', 'weight' => 2, 'sort_order' => 20],
        ['name' => 'Presentation', 'weight' => 5, 'sort_order' => 30],
        ['name' => 'Listing Taken', 'weight' => 8, 'sort_order' => 40],
        ['name' => 'Offer Submitted', 'weight' => 6, 'sort_order' => 50],
        ['name' => 'FICA Collected', 'weight' => 2, 'sort_order' => 60],
        ['name' => 'Cold Call Session', 'weight' => 1, 'sort_order' => 70],
        ['name' => 'Social Media Post', 'weight' => 1, 'sort_order' => 80],
        ['name' => 'Door-to-Door Canvassing', 'weight' => 2, 'sort_order' => 90],
        ['name' => 'Seller Follow-up Call', 'weight' => 1, 'sort_order' => 100],
        ['name' => 'Referral Received', 'weight' => 4, 'sort_order' => 110],
    ];

    /** event_class => [definition name, requires_feedback, is_active]. Mirrors ActivityCalendarMappingSeeder's real HFC mapping. */
    private const CALENDAR_MAPPINGS = [
        'viewing' => ['Take out Buyers', true, true],
        'property_evaluation' => ['Appointments', true, true],
        'listing_presentation' => ['Presentation', true, true],
        'meeting' => ['Appointments', false, false],
    ];

    private const MARKER = '[DEMO-TODAY]';

    public function run(int $agencyId): array
    {
        $defIds = $this->seedDefinitions($agencyId);
        $mappingsCreated = $this->seedCalendarMappings($agencyId, $defIds);
        $targetsCreated = $this->seedTargets($agencyId);
        $todayEvents = $this->seedTodayCalendarEvents($agencyId);
        $entriesCreated = $this->seedEntriesFromRealCalendar($agencyId, $defIds);

        return [
            'definitions' => count($defIds),
            'mappings' => $mappingsCreated,
            'targets' => $targetsCreated,
            'todayEvents' => $todayEvents,
            'entries' => $entriesCreated,
        ];
    }

    /**
     * The demo calendar had ZERO events on the actual current date (its
     * relative-date window was computed when it last ran, and enough time
     * has passed that "today" fell outside it) — so the agent Daily view,
     * which is specifically about TODAY, had nothing to show even though
     * the month has some history. Archives-then-recreates 2-3 real
     * viewing/appointment events for TODAY for a couple of real agents,
     * correctly linked (calendar_event_links), so seedEntriesFromRealCalendar()
     * picks them up the same way it picks up any other real event.
     */
    private function seedTodayCalendarEvents(int $agencyId): int
    {
        DB::transaction(function () use ($agencyId) {
            $now = now();
            $eventIds = DB::table('calendar_events')
                ->where('agency_id', $agencyId)
                ->where('title', 'like', self::MARKER . '%')
                ->whereNull('deleted_at')
                ->pluck('id');
            if ($eventIds->isNotEmpty()) {
                DB::table('calendar_event_links')->whereIn('calendar_event_id', $eventIds)->delete();
                DB::table('calendar_events')->whereIn('id', $eventIds)
                    ->update(['deleted_at' => $now, 'updated_at' => $now]);
            }
        });

        $agents = DB::table('users')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->whereIn('role', ['agent', 'branch_manager'])->orderBy('id')->limit(3)->get(['id', 'branch_id']);
        $properties = DB::table('properties')->where('agency_id', $agencyId)->where('status', 'active')
            ->whereNull('deleted_at')->orderBy('id')->limit(3)->pluck('id');
        $contacts = DB::table('contacts')->where('agency_id', $agencyId)->where('is_buyer', true)
            ->whereNull('deleted_at')->orderBy('id')->limit(3)->pluck('id');

        if ($agents->isEmpty() || $properties->isEmpty() || $contacts->isEmpty()) {
            return 0;
        }

        $created = 0;
        foreach ($agents as $i => $agent) {
            $propertyId = $properties[$i % $properties->count()];
            $contactId = $contacts[$i % $contacts->count()];
            $eventDate = now()->setTime(9 + ($i * 2), 30);
            $property = DB::table('properties')->find($propertyId);

            $eventId = DB::table('calendar_events')->insertGetId([
                'event_type' => 'manual',
                'category' => 'viewing',
                'title' => self::MARKER . ' Viewing — ' . $property->address,
                'description' => null,
                'event_date' => $eventDate,
                'end_date' => $eventDate->copy()->addMinutes(30),
                'all_day' => false,
                'priority' => 'normal',
                'send_reminder' => true,
                'status' => $eventDate->isPast() ? 'completed' : 'scheduled',
                'source_type' => 'manual:demo',
                'user_id' => $agent->id,
                'property_id' => $propertyId,
                'contact_id' => $contactId,
                'agency_id' => $agencyId,
                'branch_id' => $agent->branch_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('calendar_event_links')->insert([
                [
                    'calendar_event_id' => $eventId, 'linkable_type' => 'App\\Models\\Property',
                    'linkable_id' => $propertyId, 'role' => 'subject_property', 'agency_id' => $agencyId,
                    'created_by_user_id' => $agent->id, 'created_at' => now(), 'updated_at' => now(),
                ],
                [
                    'calendar_event_id' => $eventId, 'linkable_type' => 'App\\Models\\Contact',
                    'linkable_id' => $contactId, 'role' => 'attendee', 'agency_id' => $agencyId,
                    'created_by_user_id' => $agent->id, 'created_at' => now(), 'updated_at' => now(),
                ],
            ]);
            $created++;
        }

        return $created;
    }

    private function seedDefinitions(int $agencyId): array
    {
        // Agent\DailyActivityController::index() hard-filters the manual-
        // capture picker on scope='system' (which per ActivityDefinition's
        // own validation means agency_id MUST be null — confirmed by
        // reading the model) — an agency-scoped definition never shows up
        // there regardless of is_enabled. This is what the real 41-row
        // HFC catalogue actually is: a global default list, not a per-
        // agency one, despite living in one agency's database historically.
        // Safe here: this demo database is fully isolated from every real
        // agency, so a "global" row here only ever affects demo.
        $ids = [];
        foreach (self::DEFINITIONS as $def) {
            $existing = DB::table('activity_definitions')
                ->whereNull('agency_id')
                ->where('scope', 'system')
                ->where('name', $def['name'])
                ->first(['id']);

            if ($existing) {
                $ids[$def['name']] = (int) $existing->id;
                continue;
            }

            $ids[$def['name']] = (int) DB::table('activity_definitions')->insertGetId([
                'name' => $def['name'],
                'scope' => 'system',
                'agency_id' => null,
                'branch_id' => null,
                'weight' => $def['weight'],
                'sort_order' => $def['sort_order'],
                'scoring_mode' => 'count',
                'is_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $ids;
    }

    private function seedCalendarMappings(int $agencyId, array $defIds): int
    {
        $created = 0;
        foreach (self::CALENDAR_MAPPINGS as $eventClass => [$defName, $requiresFeedback, $isActive]) {
            $defId = $defIds[$defName] ?? null;
            if (! $defId) {
                continue;
            }

            $exists = DB::table('activity_definition_calendar_classes')
                ->where('agency_id', $agencyId)
                ->where('event_class', $eventClass)
                ->where('activity_definition_id', $defId)
                ->exists();
            if ($exists) {
                continue;
            }

            $weight = DB::table('activity_definitions')->where('id', $defId)->value('weight');

            DB::table('activity_definition_calendar_classes')->insert([
                'agency_id' => $agencyId,
                'event_class' => $eventClass,
                'trigger_kind' => 'calendar',
                'slug' => null,
                'activity_definition_id' => $defId,
                'subject_type' => null,
                'value_per_event' => (int) round((float) $weight),
                'requires_feedback' => $requiresFeedback,
                'auto_revoke_after_hours' => 24,
                'daily_cap' => null,
                'back_date_limit_hours' => 48,
                'is_active' => $isActive,
                'created_by' => null,
                'updated_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $created++;
        }

        return $created;
    }

    private function seedTargets(int $agencyId): int
    {
        $period = now()->format('Y-m');
        $agents = DB::table('users')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->whereIn('role', ['agent', 'branch_manager'])->pluck('id');

        $created = 0;
        foreach ($agents as $i => $userId) {
            $exists = DB::table('targets')->where('period', $period)->where('user_id', $userId)
                ->whereNull('deleted_at')->exists();
            if ($exists) {
                continue;
            }

            DB::table('targets')->insert([
                'period' => $period,
                'user_id' => $userId,
                'agency_id' => $agencyId,
                'branch_id' => DB::table('users')->where('id', $userId)->value('branch_id'),
                'listings_target' => 3 + ($i % 3),
                'deals_target' => 2 + ($i % 2),
                'value_target' => 4_500_000 + ($i % 4) * 500_000,
                'points_target' => 40 + ($i % 5) * 5,
                'notes' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * Real calendar_events for this agency this month, category IN the
     * mapped classes, grouped per (definition, user, date) to respect the
     * table's own unique key — value = how many qualifying events that
     * agent had that day.
     */
    private function seedEntriesFromRealCalendar(int $agencyId, array $defIds): int
    {
        $monthStart = now()->startOfMonth();
        $period = now()->format('Y-m');

        $eventClassToDef = [
            'viewing' => $defIds['Take out Buyers'] ?? null,
            'property_evaluation' => $defIds['Appointments'] ?? null,
            'listing_presentation' => $defIds['Presentation'] ?? null,
        ];

        $created = 0;
        foreach ($eventClassToDef as $category => $defId) {
            if (! $defId) {
                continue;
            }

            $rows = DB::table('calendar_events')
                ->where('agency_id', $agencyId)
                ->where('category', $category)
                ->where('event_date', '>=', $monthStart)
                ->where('event_date', '<=', now())
                ->whereNotNull('user_id')
                ->whereNull('deleted_at')
                ->selectRaw('user_id, branch_id, DATE(event_date) as activity_date, COUNT(*) as cnt, MIN(id) as sample_event_id')
                ->groupBy('user_id', 'branch_id', DB::raw('DATE(event_date)'))
                ->get();

            foreach ($rows as $row) {
                $exists = DB::table('daily_activity_entries')
                    ->where('activity_definition_id', $defId)
                    ->where('user_id', $row->user_id)
                    ->where('activity_date', $row->activity_date)
                    ->exists();
                if ($exists) {
                    continue;
                }

                DB::table('daily_activity_entries')->insert([
                    'activity_date' => $row->activity_date,
                    'period' => $period,
                    'user_id' => $row->user_id,
                    'agency_id' => $agencyId,
                    'branch_id' => $row->branch_id,
                    'activity_definition_id' => $defId,
                    'value' => $row->cnt,
                    'point_state' => 'confirmed',
                    'source' => 'auto_calendar',
                    'calendar_event_id' => $row->sample_event_id,
                    'subject_type' => null,
                    'subject_id' => null,
                    'confirmed_at' => now(),
                    'created_by' => null,
                    'updated_by' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $created++;
            }
        }

        return $created;
    }
}
