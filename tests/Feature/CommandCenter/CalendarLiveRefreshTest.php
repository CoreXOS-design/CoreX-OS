<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventClassSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-164 Gate 7 — live-RAG loop. The client refetches on focus/visibility + a light
 * poll; the SERVER side that makes this meaningful is that the range/month-block
 * endpoints recompute RAG on EVERY call. This proves the data currency the loop
 * relies on: the demo moment (red → complete → green on focus) works because a
 * refetch returns the freshly-resolved colour, not a cached one (§15.7B).
 */
final class CalendarLiveRefreshTest extends TestCase
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
        CalendarEventClassSetting::create([
            'agency_id' => $this->agencyId, 'event_class' => 'portal_listing_expiry', 'is_active' => true,
            'event_nature' => 'actionable', 'occupies_time' => false,
            'green_days' => 30, 'amber_days' => 14, 'red_days' => 7,
            'green_visibility' => ['all'], 'amber_visibility' => ['all'], 'red_visibility' => ['all'],
            'green_notifications' => [], 'amber_notifications' => [], 'red_notifications' => [],
            'label' => 'Portal Listing Expiry',
        ]);
    }

    private function rangeRag(): ?string
    {
        $resp = $this->actingAs($this->user)->getJson(route('command-center.calendar.grid-range', [
            'start' => now()->subDays(1)->toDateString(),
            'end'   => now()->addDays(40)->toDateString(),
        ]));
        $resp->assertOk();
        foreach ($resp->json('deadlineGroups') as $groups) {
            foreach ($groups as $g) {
                if ($g['group'] === 'property') return $g['worst'];
            }
        }
        return null;
    }

    public function test_grid_range_recomputes_rag_on_every_call(): void
    {
        // Due in ~10 days → inside amber_days(14), outside red_days(7) → amber.
        $event = CalendarEvent::create([
            'user_id' => $this->user->id, 'created_by_id' => $this->user->id,
            'event_type' => 'property', 'category' => 'portal_listing_expiry', 'title' => 'Portal expiry',
            'event_date' => now()->addDays(10)->startOfDay(), 'all_day' => true, 'status' => 'pending',
            'branch_id' => $this->agencyId, 'agency_id' => $this->agencyId,
        ]);
        $this->assertSame('amber', $this->rangeRag(), 'first refetch resolves amber');

        // Simulate the deadline getting more urgent (moves inside red_days=7) — a live
        // refetch must reflect the NEW colour, proving no stale cache.
        $event->update(['event_date' => now()->addDays(3)->startOfDay()]);
        $this->assertSame('red', $this->rangeRag(), 'a later refetch reflects the recomputed RAG');
    }
}
