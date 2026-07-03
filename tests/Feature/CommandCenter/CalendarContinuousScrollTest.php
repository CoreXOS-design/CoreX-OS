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

    public function test_month_view_renders_the_continuous_container_and_initial_block(): void
    {
        $resp = $this->actingAs($this->user)->get(route('command-center.calendar', ['view' => 'month']));
        $resp->assertOk();
        $resp->assertSee('continuousMonth()', false);
        $resp->assertSee('cal-month-block', false);
        $resp->assertSee('data-month=', false);
        // In-page Today anchor replaces pagination (§15.3).
        $resp->assertSee('calendar:today', false);
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
}
