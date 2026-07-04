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

    private function event(string $type, string $category, Carbon $date, bool $allDay, array $extra = []): CalendarEvent
    {
        return CalendarEvent::create(array_merge([
            'user_id' => $this->user->id, 'created_by_id' => $this->user->id,
            'event_type' => $type, 'category' => $category, 'title' => Str::headline($category),
            'event_date' => $date, 'all_day' => $allDay, 'status' => 'pending',
            'branch_id' => $this->agencyId, 'agency_id' => $this->agencyId,
        ], $extra));
    }

    private function property(): \App\Models\Property
    {
        return \App\Models\Property::withoutEvents(fn () => \App\Models\Property::withoutGlobalScopes()->create([
            'external_id' => 'T-' . Str::random(8), 'title' => '8 Marine Drive', 'address' => '8 Marine Drive, Shelly Beach',
            'suburb' => 'Shelly Beach', 'erf_number' => '1234',
            'agent_id' => $this->user->id, 'branch_id' => $this->agencyId, 'agency_id' => $this->agencyId,
        ]));
    }

    public function test_a_day_of_deadlines_collapses_to_one_chip_and_the_appointment_stays(): void
    {
        // Mid-month day, ~10 days out → deadlines resolve amber (inside amber_days=14, outside red_days=7).
        $day = now()->startOfMonth()->addDays(14);
        $this->event('viewing', 'viewing', $day->copy()->setTime(10, 0), false);          // the real appointment
        $prop = $this->property();
        foreach (range(1, 6) as $i) {
            // 6 deadlines, each sourced at a real Property → Gate 2 resolves a new-tab deep link.
            $this->event('property', 'portal_listing_expiry', $day->copy()->startOfDay(), true, [
                'source_type' => \App\Models\Property::class, 'source_id' => $prop->id,
            ]);
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

        // Gate 2 — each group carries drill-down items with a resolved deep link.
        $this->assertCount(6, $groups[0]['items'], 'popover items enriched per deadline');
        $item = $groups[0]['items'][0];
        $this->assertArrayHasKey('title', $item);
        $this->assertSame('amber', $item['rag']);
        $this->assertStringContainsString('/properties/', (string) $item['url'], 'Property-sourced deadline resolves a deep link');

        // The page renders the aggregate chip + the Gate 2 popover items as new-tab links.
        $resp->assertSee('Listings', false);
        $resp->assertSee('target="_blank"', false);
    }
}
