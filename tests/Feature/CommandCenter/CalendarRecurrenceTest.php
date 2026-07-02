<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\User;
use App\Services\CommandCenter\Calendar\RecurrenceEditService;
use App\Services\CommandCenter\Calendar\RecurrenceExpander;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Recurring calendar events — materialise-on-view expansion + edit/delete scope.
 *
 * Covers the acceptance criteria for the recurrence build:
 *   - a weekly series lands on the right day each week across the range
 *   - COUNT and UNTIL bound the series correctly
 *   - edit "this occurrence" creates an exception without breaking the series
 *   - edit "all" updates the series; edit "future" splits it
 *   - delete "this" tombstones one occurrence (no hard delete); delete "all" stops it
 *   - occurrences inherit event_nature (informational never actionable) + category
 *     (so the per-occurrence conflict sweep and overdue logic behave per occurrence)
 */
final class CalendarRecurrenceTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->agencyId, $this->user] = $this->seedAgencyUser();
        $this->classSetting('viewing', 'actionable');
        $this->classSetting('private', 'informational');
    }

    public function test_weekly_series_lands_on_the_same_weekday_each_week(): void
    {
        $monday = Carbon::parse('2026-07-06 09:00'); // a Monday
        $this->makeRecurring('Weekly standup', 'viewing', 'FREQ=WEEKLY;INTERVAL=1', $monday);

        $dates = $this->occurrenceDates('Weekly standup',
            Carbon::parse('2026-07-01'), Carbon::parse('2026-08-31'));

        $this->assertContains('2026-07-06', $dates);
        $this->assertContains('2026-07-13', $dates);
        $this->assertContains('2026-07-20', $dates);
        $this->assertContains('2026-07-27', $dates);
        $this->assertContains('2026-08-03', $dates);
        foreach ($dates as $d) {
            $this->assertSame('Monday', Carbon::parse($d)->englishDayOfWeek, "$d is not a Monday");
        }
    }

    public function test_count_bounds_the_series(): void
    {
        $this->makeRecurring('Thrice', 'viewing', 'FREQ=WEEKLY;INTERVAL=1;COUNT=3',
            Carbon::parse('2026-07-06 09:00'));

        $dates = $this->occurrenceDates('Thrice',
            Carbon::parse('2026-07-01'), Carbon::parse('2026-09-30'));

        $this->assertSame(['2026-07-06', '2026-07-13', '2026-07-20'], $dates);
    }

    public function test_until_bounds_the_series(): void
    {
        $this->makeRecurring('Until', 'viewing', 'FREQ=WEEKLY;INTERVAL=1;UNTIL=20260720',
            Carbon::parse('2026-07-06 09:00'));

        $dates = $this->occurrenceDates('Until',
            Carbon::parse('2026-07-01'), Carbon::parse('2026-09-30'));

        $this->assertSame(['2026-07-06', '2026-07-13', '2026-07-20'], $dates);
    }

    public function test_edit_this_occurrence_creates_exception_and_leaves_series_intact(): void
    {
        $parent = $this->makeRecurring('Weekly standup', 'viewing', 'FREQ=WEEKLY;INTERVAL=1',
            Carbon::parse('2026-07-06 09:00'));

        app(RecurrenceEditService::class)->editOccurrence($parent, '2026-07-13', [
            'title'      => 'Standup (moved)',
            'event_date' => '2026-07-13 11:00:00',
        ], $this->user);

        $titlesByDate = $this->titlesByDate(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));

        // The edited date now shows the exception; the neighbours are untouched.
        $this->assertSame('Standup (moved)', $titlesByDate['2026-07-13'] ?? null);
        $this->assertSame('Weekly standup', $titlesByDate['2026-07-06'] ?? null);
        $this->assertSame('Weekly standup', $titlesByDate['2026-07-20'] ?? null);

        // Exactly one exception child row, series parent still recurring.
        $this->assertSame(1, CalendarEvent::withoutGlobalScopes()
            ->where('parent_event_id', $parent->id)->count());
        $this->assertTrue((bool) $parent->fresh()->is_recurring);
    }

    public function test_edit_all_updates_the_whole_series(): void
    {
        $parent = $this->makeRecurring('Weekly standup', 'viewing', 'FREQ=WEEKLY;INTERVAL=1',
            Carbon::parse('2026-07-06 09:00'));

        app(RecurrenceEditService::class)->editAll($parent, ['title' => 'Team standup']);

        $titles = array_values(array_unique(array_values(
            $this->titlesByDate(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'))
        )));
        $this->assertSame(['Team standup'], $titles);
    }

    public function test_edit_future_splits_the_series(): void
    {
        $parent = $this->makeRecurring('Weekly standup', 'viewing', 'FREQ=WEEKLY;INTERVAL=1',
            Carbon::parse('2026-07-06 09:00'));

        app(RecurrenceEditService::class)->editFuture($parent, '2026-07-20', [
            'title' => 'New series',
        ], $this->user);

        $titlesByDate = $this->titlesByDate(Carbon::parse('2026-07-01'), Carbon::parse('2026-08-10'));

        // Before the split: original series.
        $this->assertSame('Weekly standup', $titlesByDate['2026-07-06'] ?? null);
        $this->assertSame('Weekly standup', $titlesByDate['2026-07-13'] ?? null);
        // From the split forward: the new series.
        $this->assertSame('New series', $titlesByDate['2026-07-20'] ?? null);
        $this->assertSame('New series', $titlesByDate['2026-07-27'] ?? null);
    }

    public function test_delete_this_occurrence_tombstones_without_hard_delete(): void
    {
        $parent = $this->makeRecurring('Weekly standup', 'viewing', 'FREQ=WEEKLY;INTERVAL=1',
            Carbon::parse('2026-07-06 09:00'));

        app(RecurrenceEditService::class)->deleteOccurrence($parent, '2026-07-13', $this->user);

        $dates = $this->occurrenceDates('Weekly standup',
            Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));

        $this->assertNotContains('2026-07-13', $dates);
        $this->assertContains('2026-07-06', $dates);
        $this->assertContains('2026-07-20', $dates);

        // Tombstone is a dismissed child row — nothing hard-deleted.
        $child = CalendarEvent::withoutGlobalScopes()->where('parent_event_id', $parent->id)->first();
        $this->assertNotNull($child);
        $this->assertSame('dismissed', $child->status);
    }

    public function test_delete_all_soft_deletes_the_parent_and_stops_expansion(): void
    {
        $parent = $this->makeRecurring('Weekly standup', 'viewing', 'FREQ=WEEKLY;INTERVAL=1',
            Carbon::parse('2026-07-06 09:00'));

        app(RecurrenceEditService::class)->deleteAll($parent);

        $dates = $this->occurrenceDates('Weekly standup',
            Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));
        $this->assertSame([], $dates);

        // Soft delete, not hard delete.
        $this->assertNotNull(CalendarEvent::withoutGlobalScopes()->withTrashed()->find($parent->id)->deleted_at);
    }

    public function test_informational_recurring_occurrence_is_never_actionable(): void
    {
        $parent = $this->makeRecurring('Focus block', 'private', 'FREQ=DAILY;INTERVAL=1',
            Carbon::parse('2026-07-06 09:00'), 'informational');

        $occurrences = app(RecurrenceExpander::class)
            ->expand($parent, Carbon::parse('2026-07-06'), Carbon::parse('2026-07-10'));

        $this->assertGreaterThan(0, $occurrences->count());
        foreach ($occurrences as $occ) {
            $this->assertTrue($occ->isInformational(), 'informational nature must be inherited');
            $this->assertSame('informational', $occ->effectiveEventNature());
        }
    }

    public function test_occurrences_get_distinct_ids_and_inherit_category_for_conflict_detection(): void
    {
        $parent = $this->makeRecurring('Viewing', 'viewing', 'FREQ=WEEKLY;INTERVAL=1',
            Carbon::parse('2026-07-06 09:00'));

        $occurrences = app(RecurrenceExpander::class)
            ->expand($parent, Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));

        $ids = $occurrences->pluck('id')->all();
        $this->assertSame(count($ids), count(array_unique($ids)), 'occurrence ids must be unique');
        foreach ($occurrences as $occ) {
            // The conflict sweep keys off category (occupies_time class) + time,
            // both inherited from the parent — so conflicts resolve per occurrence.
            $this->assertSame('viewing', $occ->category);
            $this->assertNotNull($occ->end_date);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Materialise a recurring PARENT and return the reloaded model. */
    private function makeRecurring(string $title, string $category, string $rule, Carbon $start, ?string $nature = null): CalendarEvent
    {
        $id = (int) DB::table('calendar_events')->insertGetId([
            'user_id'         => $this->user->id,
            'event_type'      => 'manual',
            'category'        => $category,
            'title'           => $title,
            'event_date'      => $start->toDateTimeString(),
            'end_date'        => $start->copy()->addHour()->toDateTimeString(),
            'all_day'         => false,
            'priority'        => 'normal',
            'status'          => 'pending',
            'source_type'     => 'manual',
            'is_recurring'    => true,
            'recurrence_rule' => $rule,
            'metadata'        => $nature ? json_encode(['event_nature' => $nature]) : null,
            'agency_id'       => $this->agencyId,
            'branch_id'       => $this->agencyId,
            'created_at'      => now(), 'updated_at' => now(),
        ]);

        return CalendarEvent::withoutGlobalScopes()->findOrFail($id);
    }

    /** Sorted unique occurrence dates for a title, via the real feed endpoint. */
    private function occurrenceDates(string $title, Carbon $start, Carbon $end): array
    {
        $rows = $this->feed($start, $end);
        $dates = collect($rows)
            ->where('title', $title)
            ->map(fn ($r) => Carbon::parse($r['start'])->toDateString())
            ->unique()->sort()->values()->all();
        return $dates;
    }

    /** Map of date => title for the range (last title wins if two share a day). */
    private function titlesByDate(Carbon $start, Carbon $end): array
    {
        $out = [];
        foreach ($this->feed($start, $end) as $r) {
            $out[Carbon::parse($r['start'])->toDateString()] = $r['title'];
        }
        return $out;
    }

    private function feed(Carbon $start, Carbon $end): array
    {
        return $this->actingAs($this->user)
            ->getJson(route('command-center.calendar.events', [
                'start' => $start->toDateString(),
                'end'   => $end->toDateString(),
            ]))
            ->assertOk()
            ->json();
    }

    private function seedAgencyUser(): array
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
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);

        return [$agencyId, $user];
    }

    private function classSetting(string $class, string $nature): void
    {
        DB::table('calendar_event_class_settings')->insert([
            'agency_id'    => null,
            'event_class'  => $class,
            'label'        => Str::headline($class),
            'is_active'    => true,
            'event_nature' => $nature,
            // Generous windows so occurrences aren't dropped by the colour resolver.
            'green_days'   => 365,
            'amber_days'   => 30,
            'red_days'     => 7,
            'show_days'    => 365,
            'green_visibility'    => json_encode(['all']),
            'amber_visibility'    => json_encode(['all']),
            'red_visibility'      => json_encode(['all']),
            'green_notifications' => json_encode([]),
            'amber_notifications' => json_encode([]),
            'red_notifications'   => json_encode([]),
            'daily_digest_enabled' => false,
            'daily_digest_roles'  => json_encode([]),
            'created_at'   => now(), 'updated_at' => now(),
        ]);
    }
}
