<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventClassSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-164 Gate 5 — continuous-scroll month view. The month renders as a continuous
 * vertical scroll of month blocks (Outlook-web); pagination is gone; adjacent
 * months lazy-load through the /calendar/month-block endpoint (the SAME partial),
 * and a JSON range endpoint feeds the live loop (§15.3/§15.11).
 */
final class CalendarContinuousScrollTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'HFC ' . Str::random(6), 'slug' => 'hfc-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->user = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'super_admin', 'is_active' => true,
        ]);

        $this->classSetting('viewing', true);
        $this->classSetting('portal_listing_expiry', false);
    }

    private function classSetting(string $class, bool $occupiesTime): void
    {
        CalendarEventClassSetting::create([
            'agency_id' => $this->agencyId, 'event_class' => $class, 'is_active' => true,
            'event_nature' => 'actionable', 'occupies_time' => $occupiesTime,
            'green_days' => 30, 'amber_days' => 14, 'red_days' => 7,
            'green_visibility' => ['all'], 'amber_visibility' => ['all'], 'red_visibility' => ['all'],
            'green_notifications' => [], 'amber_notifications' => [], 'red_notifications' => [],
            'label' => Str::headline($class),
        ]);
    }

    private function event(string $type, string $category, Carbon $date, bool $allDay): CalendarEvent
    {
        return CalendarEvent::create([
            'user_id' => $this->user->id, 'created_by_id' => $this->user->id,
            'event_type' => $type, 'category' => $category, 'title' => Str::headline($category),
            'event_date' => $date, 'all_day' => $allDay, 'status' => 'pending',
            'branch_id' => $this->agencyId, 'agency_id' => $this->agencyId,
        ]);
    }

    public function test_month_view_renders_the_continuous_week_stream(): void
    {
        // AT-164 single week-stream — the month view is now ONE continuous stream of
        // week rows (each week exactly once; no month-block splitter, no duplicated
        // boundary weeks). Windows are addressed by WEEK.
        $resp = $this->actingAs($this->user)->get(route('command-center.calendar', ['view' => 'month']));
        $resp->assertOk();
        $resp->assertSee('continuousMonth()', false);
        $resp->assertSee('cal-week-row', false);
        $resp->assertSee('data-week=', false);
        // The old month-block splitter must NOT be in the month view any more.
        $resp->assertDontSee('cal-month-block', false);
        // In-page Today anchor replaces pagination (§15.3).
        $resp->assertSee('calendar:today', false);

        // Every rendered week appears exactly once (no duplicated boundary weeks).
        preg_match_all('/data-week="(\d{4}-\d{2}-\d{2})"/', $resp->getContent(), $m);
        $this->assertNotEmpty($m[1], 'week rows rendered');
        $this->assertSame(count($m[1]), count(array_unique($m[1])), 'no week is rendered twice');
    }

    public function test_week_rows_endpoint_renders_the_same_partial_with_interactions(): void
    {
        $monday = now()->startOfWeek(Carbon::MONDAY);
        $this->event('viewing', 'viewing', $monday->copy()->addDays(2)->setTime(9, 0), false);

        $resp = $this->actingAs($this->user)->get(
            route('command-center.calendar.week-rows', ['start' => $monday->toDateString(), 'count' => 4])
        );
        $resp->assertOk();
        $resp->assertSee('data-week="' . $monday->toDateString() . '"', false);
        $resp->assertSee('cal-week-row', false);
        // Interaction parity — chips still open the in-page slide-over + carry a layer.
        $resp->assertSee('openEventPanel', false);
        $resp->assertSee('data-layer=', false);

        preg_match_all('/data-week="(\d{4}-\d{2}-\d{2})"/', $resp->getContent(), $m);
        $this->assertSame(count($m[1]), count(array_unique($m[1])), 'endpoint weeks are unique');
    }

    public function test_month_block_endpoint_renders_the_same_partial_with_interactions(): void
    {
        $day = now()->startOfMonth()->addDays(10);
        $this->event('viewing', 'viewing', $day->copy()->setTime(9, 0), false);

        $ym = sprintf('%04d-%02d', now()->year, now()->month);
        $resp = $this->actingAs($this->user)->get(
            route('command-center.calendar.month-block', ['year' => now()->year, 'month' => now()->month])
        );
        $resp->assertOk();
        $resp->assertSee('data-month="' . $ym . '"', false);
        $resp->assertSee('cal-month-label', false);
        // Interaction parity — chips still open the in-page slide-over.
        $resp->assertSee('openEventPanel', false);
    }

    public function test_month_block_rejects_an_out_of_range_month(): void
    {
        $this->actingAs($this->user)
            ->get(route('command-center.calendar.month-block', ['year' => now()->year, 'month' => 13]))
            ->assertStatus(422);
    }

    public function test_grid_range_returns_aggregated_json(): void
    {
        $day = now()->startOfMonth()->addDays(12);
        $this->event('viewing', 'viewing', $day->copy()->setTime(10, 0), false);
        foreach (range(1, 4) as $i) {
            $this->event('property', 'portal_listing_expiry', $day->copy()->startOfDay(), true);
        }

        $resp = $this->actingAs($this->user)->getJson(route('command-center.calendar.grid-range', [
            'start' => now()->startOfMonth()->toDateString(),
            'end'   => now()->endOfMonth()->toDateString(),
        ]));
        $resp->assertOk()->assertJsonStructure(['byDate', 'deadlineGroups', 'start', 'end']);

        $dateStr = $day->toDateString();
        // Appointment species in byDate; the 4 deadlines aggregate to one group.
        $this->assertArrayHasKey($dateStr, $resp->json('byDate'));
        $groups = $resp->json('deadlineGroups')[$dateStr] ?? [];
        $this->assertCount(1, $groups);
        $this->assertSame(4, $groups[0]['count']);
    }

    public function test_grid_range_caps_a_runaway_window(): void
    {
        // Ask for 5 years; the endpoint clamps to the agency expansion limit (default 400 days).
        $resp = $this->actingAs($this->user)->getJson(route('command-center.calendar.grid-range', [
            'start' => '2026-01-01',
            'end'   => '2031-01-01',
        ]));
        $resp->assertOk();
        $start = Carbon::parse($resp->json('start'));
        $end   = Carbon::parse($resp->json('end'));
        $this->assertLessThanOrEqual(401, $start->diffInDays($end), 'window is capped, not honoured to 5 years');
    }

    // ── AT-384 — the stream opens on TODAY ──────────────────────────────────────
    // The continuous month used to anchor on the Monday of the month's FIRST week.
    // For any month whose 1st is not a Monday that week belongs to the PREVIOUS
    // month, so a user opening the calendar on 27 Aug 2026 landed on the week of
    // Mon 27 Jul with the sticky label reading "July". Anchor precedence is now:
    // explicit ?date= → today's week (default) → the month's first week.

    public function test_month_view_opens_on_todays_week_not_the_months_first_week(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 09:00:00'));   // Thu; 1 Aug 2026 is a Sat

        $resp = $this->actingAs($this->user)->get(route('command-center.calendar', ['view' => 'month']));

        $resp->assertOk();
        $resp->assertSee("const anchorWeek = '2026-08-24'", false);   // Monday of today's week
        $resp->assertDontSee("const anchorWeek = '2026-07-27'", false); // the old July landing
        $resp->assertSee('data-week="2026-08-24"', false);             // and that week is preloaded
    }

    public function test_month_view_honours_an_explicit_date_over_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 09:00:00'));

        $resp = $this->actingAs($this->user)->get(route('command-center.calendar', [
            'view' => 'month', 'date' => '2026-08-05',
        ]));

        $resp->assertOk();
        $resp->assertSee("const anchorWeek = '2026-08-03'", false);   // Monday of the asked-for week
    }

    public function test_month_view_opens_on_the_first_week_of_another_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 09:00:00'));

        $resp = $this->actingAs($this->user)->get(route('command-center.calendar', [
            'view' => 'month', 'year' => 2026, 'month' => 10,
        ]));

        $resp->assertOk();
        $resp->assertSee("const anchorWeek = '2026-09-28'", false);   // Monday of 1 Oct 2026's week
    }
}
