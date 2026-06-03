<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityPoints;

use App\Models\ActivityDefinition;
use App\Models\DailyActivityEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * M6.5 — locks the achievement-total scope as a behavioural contract.
 *
 * Every controller / service / calculator that produces an agent's
 * achievement total / target progress / BM-snapshot value MUST count
 * exactly the rows this test fixtures as "counts" and EXCLUDE exactly
 * the rows it fixtures as "doesn't count". Provisional points are
 * displayed elsewhere but never roll into the total — the anti-gaming
 * guard against ghost calendar appointments inflating a snapshot.
 *
 * The locked rule (Johan, M6.5):
 *   IN  point_state ∈ {confirmed, overridden}
 *   AND source     ∈ {manual, auto_calendar, auto_instant}
 *
 * If a future change to a controller/service silently widens the
 * counted set (e.g. begins counting provisional rows) this test
 * regresses immediately.
 */
final class AchievementTotalScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_included_in_achievement_total_scope_counts_only_locked_subset(): void
    {
        $agencyId = $this->makeAgency();
        $user = \App\Models\User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent',
        ]);
        $def = $this->seedSystemDefinition();

        // 9 rows covering every (state x source) combination that
        // matters for the achievement total. Activity dates vary so
        // any (user, date, definition) unique constraint is satisfied
        // — we exercise the state/source filter only.
        $fixtures = [
            // SHOULD count
            ['confirmed',   'manual',        true],
            ['confirmed',   'auto_calendar', true],
            ['confirmed',   'auto_instant',  true],
            ['overridden',  'manual',        true],
            ['overridden',  'auto_calendar', true],
            // SHOULD NOT count — state excluded
            ['provisional', 'manual',        false],
            ['provisional', 'auto_calendar', false],
            ['revoked',     'manual',        false],
            ['revoked',     'auto_instant',  false],
            // SHOULD NOT count — source excluded
            ['confirmed',   'auto_other',    false],
        ];

        foreach ($fixtures as $i => [$state, $source, $expectedCount]) {
            DailyActivityEntry::create([
                'activity_date'          => now()->subDays($i)->toDateString(),
                'period'                 => now()->subDays($i)->format('Y-m'),
                'user_id'                => $user->id,
                'agency_id'              => $agencyId,
                'branch_id'              => $agencyId,
                'activity_definition_id' => $def->id,
                'value'                  => 1,
                'point_state'            => $state,
                'source'                 => $source,
                'created_by'             => $user->id,
                'updated_by'             => $user->id,
            ]);
        }

        $expectedCounted = collect($fixtures)->filter(fn ($row) => $row[2])->count();

        // 1) Eloquent scope path
        $scopeCounted = DailyActivityEntry::query()
            ->where('user_id', $user->id)
            ->includedInAchievementTotal()
            ->count();
        $this->assertSame(
            $expectedCounted,
            $scopeCounted,
            'scopeIncludedInAchievementTotal() must count exactly the locked subset',
        );

        // 2) Inline-constant DB path — every raw DB::table query in
        //    the 11 total-computation sites uses this pattern.
        $inlineCounted = DB::table('daily_activity_entries')
            ->where('user_id', $user->id)
            ->whereIn('point_state', DailyActivityEntry::ACHIEVEMENT_TOTAL_STATES)
            ->whereIn('source',      DailyActivityEntry::ACHIEVEMENT_TOTAL_SOURCES)
            ->count();
        $this->assertSame(
            $expectedCounted,
            $inlineCounted,
            'ACHIEVEMENT_TOTAL_STATES + _SOURCES must match the Eloquent scope exactly',
        );
    }

    public function test_provisional_never_inflates_a_running_total(): void
    {
        $agencyId = $this->makeAgency();
        $user = \App\Models\User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent',
        ]);
        $def = $this->seedSystemDefinition();

        // Baseline: 1 confirmed manual row = 10 pts (weight=10, value=1)
        DailyActivityEntry::create([
            'activity_date'          => now()->toDateString(),
            'period'                 => now()->format('Y-m'),
            'user_id'                => $user->id,
            'agency_id'              => $agencyId,
            'branch_id'              => $agencyId,
            'activity_definition_id' => $def->id,
            'value'                  => 1,
            'point_state'            => 'confirmed',
            'source'                 => 'manual',
        ]);

        $expr = DB::raw('e.value * d.weight');
        $base = (int) DB::table('daily_activity_entries as e')
            ->join('activity_definitions as d', 'd.id', '=', 'e.activity_definition_id')
            ->where('e.user_id', $user->id)
            ->whereIn('e.point_state', DailyActivityEntry::ACHIEVEMENT_TOTAL_STATES)
            ->whereIn('e.source',      DailyActivityEntry::ACHIEVEMENT_TOTAL_SOURCES)
            ->sum($expr);
        $this->assertSame(10, $base);

        // Inject 3 ghost provisional rows — value=99 each, total
        // 99*10*3 = 2970 pts if they leaked into the total. Vary the
        // activity_date by one day each to dodge any (user, date,
        // definition) unique index on the table.
        for ($i = 1; $i <= 3; $i++) {
            DailyActivityEntry::create([
                'activity_date'          => now()->subDays($i)->toDateString(),
                'period'                 => now()->subDays($i)->format('Y-m'),
                'user_id'                => $user->id,
                'agency_id'              => $agencyId,
                'branch_id'              => $agencyId,
                'activity_definition_id' => $def->id,
                'value'                  => 99,
                'point_state'            => 'provisional',
                'source'                 => 'auto_calendar',
            ]);
        }

        $afterGhosts = (int) DB::table('daily_activity_entries as e')
            ->join('activity_definitions as d', 'd.id', '=', 'e.activity_definition_id')
            ->where('e.user_id', $user->id)
            ->whereIn('e.point_state', DailyActivityEntry::ACHIEVEMENT_TOTAL_STATES)
            ->whereIn('e.source',      DailyActivityEntry::ACHIEVEMENT_TOTAL_SOURCES)
            ->sum($expr);
        $this->assertSame(
            10,
            $afterGhosts,
            'Anti-gaming guard: 3 provisional rows of value 99 must NOT inflate the achievement total.',
        );
    }

    private function seedSystemDefinition(): ActivityDefinition
    {
        return ActivityDefinition::create([
            'name'       => 'M6.5 invariant ' . Str::random(6),
            'weight'     => 10,
            'sort_order' => 1,
            'scope'      => ActivityDefinition::SCOPE_SYSTEM,
            'is_enabled' => true,
        ]);
    }

    private function makeAgency(): int
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $agencyId;
    }
}
