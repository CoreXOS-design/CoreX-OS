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
 * AT-164 Gate 1 — species split + server-side deadline aggregation. A day with
 * many system-deadline rows (portal expiries) collapses to ONE aggregate chip per
 * group, coloured by the worst RAG, while a real appointment keeps its own chip.
 * This is the headline acceptance criterion (§15.11).
 */
final class CalendarDeadlineAggregationTest extends TestCase
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

        // Appointment class (occupies_time=true) + deadline class (occupies_time=false).
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

    public function test_a_day_of_deadlines_collapses_to_one_chip_and_the_appointment_stays(): void
    {
        // Mid-month day, ~10 days out → deadlines resolve amber (inside amber_days=14, outside red_days=7).
        $day = now()->startOfMonth()->addDays(14);
        $this->event('viewing', 'viewing', $day->copy()->setTime(10, 0), false);          // the real appointment
        foreach (range(1, 6) as $i) {
            $this->event('property', 'portal_listing_expiry', $day->copy()->startOfDay(), true); // 6 deadlines
        }

        $resp = $this->actingAs($this->user)->get(
            route('command-center.calendar', ['year' => $day->year, 'month' => $day->month, 'scope' => 'all'])
        );
        $resp->assertOk();

        $dateStr = $day->toDateString();
        $byDate = $resp->viewData('byDate');
        $deadlineGroups = $resp->viewData('deadlineGroups');

        // The grid cell shows ONLY the appointment (not 6 deadline bars).
        $this->assertArrayHasKey($dateStr, $byDate);
        $this->assertCount(1, $byDate[$dateStr], 'only the appointment renders as a cell chip');

        // The 6 deadlines collapse to ONE group chip (count 6, worst = amber).
        $this->assertArrayHasKey($dateStr, $deadlineGroups);
        $groups = $deadlineGroups[$dateStr];
        $this->assertCount(1, $groups, 'one aggregate chip, not six bars');
        $this->assertSame('property', $groups[0]['group']);
        $this->assertSame(6, $groups[0]['count']);
        $this->assertSame('amber', $groups[0]['worst']);

        // And it renders on the page as an aggregate chip.
        $resp->assertSee('6', false);
        $resp->assertSee('Listings', false);
    }
}
