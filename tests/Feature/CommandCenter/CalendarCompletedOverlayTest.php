<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Johan's calendar overlay bug: schedule a viewing (WITH a pack) and complete it, then schedule a
 * second viewing (WITHOUT a pack) in the same slot — the OLD completed appointment rendered OVER /
 * on top of the NEW event.
 *
 * Root cause (AT-335, commit 151b4c55): completed/dismissed tiles are pulled out of the overlap
 * lane-packing and rendered FULL-WIDTH, then appended LAST to the render array. Every tile carried
 * the SAME `z-index: 3`, so paint order = DOM order: the full-width completed tile, drawn last,
 * painted on top of the active tile beneath it. Pack/no-pack is only how the two overlapping viewings
 * get staged; the geometry bug is pure render layering.
 *
 * Fix: done tiles drop to `z-index: 2` so an active tile (3) always paints on top — completed events
 * stay visible (line-through, per CAL-8) but sit behind, never covering a live appointment.
 */
final class CalendarCompletedOverlayTest extends TestCase
{
    use RefreshDatabase;

    private function makeViewing(Agency $agency, User $user, Carbon $start, Carbon $end, string $status, string $title): CalendarEvent
    {
        return CalendarEvent::create([
            'event_type' => 'manual', 'category' => 'viewing', 'title' => $title,
            'event_date' => $start, 'end_date' => $end, 'all_day' => false,
            'status' => $status, 'priority' => 'normal',
            'source_type' => 'manual', 'user_id' => $user->id, 'created_by_id' => $user->id,
            'agency_id' => $agency->id, 'branch_id' => $user->branch_id,
        ]);
    }

    /** @return array{0:int} the z-index captured for the tile with $eventId. */
    private function zIndexFor(string $html, int $eventId): int
    {
        // The button renders: <button ... data-event-id="ID" ... style="z-index: N; ...">
        $this->assertMatchesRegularExpression('/data-event-id="' . $eventId . '"/', $html, "tile {$eventId} must be rendered");
        preg_match('/data-event-id="' . $eventId . '".*?z-index:\s*(\d+);/s', $html, $m);
        $this->assertNotEmpty($m, "z-index not found for tile {$eventId}");

        return (int) $m[1];
    }

    public function test_completed_viewing_paints_behind_a_new_overlapping_viewing(): void
    {
        $agency = Agency::create(['name' => 'Cal Co', 'slug' => 'cal-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $user   = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        $day   = Carbon::parse('2026-08-10');
        $start = $day->copy()->setTime(10, 0);
        $end   = $day->copy()->setTime(11, 0);

        // Viewing #1 (WITH pack) — already set + completed. Viewing #2 (WITHOUT pack) — new, same slot.
        $completed = $this->makeViewing($agency, $user, $start, $end, 'completed', 'Old viewing (with pack)');
        $pending   = $this->makeViewing($agency, $user, $start, $end, 'pending', 'New viewing (no pack)');

        $html = View::make('command-center.calendar.partials._day-column', [
            'date'   => $day,
            'events' => collect([$completed, $pending]),
        ])->render();

        // Both tiles still render — a completed appointment stays visible (CAL-8), it is not removed.
        $this->assertStringContainsString('data-event-id="' . $completed->id . '"', $html);
        $this->assertStringContainsString('data-event-id="' . $pending->id . '"', $html);

        // The fix: the new (active) viewing paints ON TOP; the old completed one sits BEHIND it.
        $this->assertSame(3, $this->zIndexFor($html, $pending->id), 'active viewing must be the top layer');
        $this->assertSame(2, $this->zIndexFor($html, $completed->id), 'completed viewing must sit behind the active one');
        $this->assertGreaterThan(
            $this->zIndexFor($html, $completed->id),
            $this->zIndexFor($html, $pending->id),
            'the new event must never be overlaid by the old completed one'
        );
    }

    public function test_two_active_viewings_share_the_top_layer(): void
    {
        // Regression guard: the z-index split is ONLY done-vs-active — two live events keep the
        // same layer (3) and are separated by lane-packing, unchanged by this fix.
        $agency = Agency::create(['name' => 'Cal Co', 'slug' => 'cal-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $user   = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        $day = Carbon::parse('2026-08-10');
        $a = $this->makeViewing($agency, $user, $day->copy()->setTime(10, 0), $day->copy()->setTime(11, 0), 'pending', 'A');
        $b = $this->makeViewing($agency, $user, $day->copy()->setTime(10, 30), $day->copy()->setTime(11, 30), 'pending', 'B');

        $html = View::make('command-center.calendar.partials._day-column', [
            'date'   => $day,
            'events' => collect([$a, $b]),
        ])->render();

        $this->assertSame(3, $this->zIndexFor($html, $a->id));
        $this->assertSame(3, $this->zIndexFor($html, $b->id));
    }
}
