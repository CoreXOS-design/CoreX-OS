<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\User;
use App\Services\CommandCenter\CommandCentreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Today page — Day timeline layout (2026-09-13).
 *
 * The board draws today's appointments on an hour grid, so the schedule card's
 * items must carry the fields the grid needs (end time, all-day flag, date,
 * colour) and the page must render the three regions (timeline, rail, strip).
 * Spec: .ai/specs/spec-command-center.md → "Today page — Day timeline layout".
 */
final class TodayDayTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_items_carry_the_timeline_fields(): void
    {
        [$agencyId, $branchId, $user] = $this->seedBasics();

        $start = now()->setTime(11, 30);
        CalendarEvent::create([
            'user_id' => $user->id, 'event_type' => 'manual', 'category' => 'viewing',
            'title' => 'Valuation at Bendigo Road', 'event_date' => $start,
            'end_date' => $start->copy()->addMinutes(90), 'all_day' => false,
            'status' => 'pending', 'colour' => '#0ea5e9',
            'agency_id' => $agencyId, 'branch_id' => $branchId,
        ]);
        CalendarEvent::create([
            'user_id' => $user->id, 'event_type' => 'manual', 'category' => 'meeting',
            'title' => 'Show day', 'event_date' => now()->startOfDay(),
            'all_day' => true, 'status' => 'pending',
            'agency_id' => $agencyId, 'branch_id' => $branchId,
        ]);

        Cache::forget("command_centre_{$user->id}_{$agencyId}");
        $cards = app(CommandCentreService::class)->assembleForUser($user->fresh());
        $schedule = collect($cards)->firstWhere('card_id', 'today_appointments');

        $this->assertNotNull($schedule, 'schedule card is always present');
        $items = collect($schedule['items']);

        $timed = $items->firstWhere('title', 'Valuation at Bendigo Road');
        $this->assertNotNull($timed);
        $this->assertSame('11:30', $timed['time']);
        $this->assertSame('13:00', $timed['end_time']);
        $this->assertFalse($timed['all_day']);
        $this->assertSame(now()->toDateString(), $timed['date']);
        $this->assertSame('Today', $timed['date_label']);
        $this->assertSame('#0ea5e9', $timed['colour']);

        $allDay = $items->firstWhere('title', 'Show day');
        $this->assertNotNull($allDay);
        $this->assertTrue($allDay['all_day']);
        $this->assertNull($allDay['end_time']);
        $this->assertNotSame('', $allDay['colour'], 'an event without its own colour still resolves one');
    }

    public function test_today_page_renders_the_day_board(): void
    {
        [$agencyId, $branchId, $user] = $this->seedBasics();
        CalendarEvent::create([
            'user_id' => $user->id, 'event_type' => 'manual', 'category' => 'viewing',
            'title' => 'Buyer viewing Sea Breeze', 'event_date' => now()->setTime(14, 0),
            'end_date' => now()->setTime(15, 0), 'all_day' => false, 'status' => 'pending',
            'agency_id' => $agencyId, 'branch_id' => $branchId,
        ]);
        Cache::forget("command_centre_{$user->id}_{$agencyId}");

        $resp = $this->actingAs($user->fresh())->get(route('command-center.today'));

        $resp->assertOk();
        // The three regions of the day board + the anchors the guided tour relies on.
        $resp->assertSee('data-tour="cc-today-board"', false);
        $resp->assertSee('data-tour="cc-today-timeline"', false);
        $resp->assertSee('data-tour="cc-today-rail"', false);
        $resp->assertSee('data-tour="cc-today-strip"', false);
        $resp->assertSee('data-tour="cc-today-refresh"', false);
        // The appointment reaches the page (rendered client-side from the card JSON).
        $resp->assertSee('Buyer viewing Sea Breeze');
        $resp->assertSee('"end_time":"15:00"', false);
    }

    /** @return array{0:int,1:int,2:User} */
    private function seedBasics(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $agencyId, 'name' => 'Branch 1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $branchId, 'role' => 'agent',
        ]);

        return [$agencyId, $branchId, $user];
    }
}
