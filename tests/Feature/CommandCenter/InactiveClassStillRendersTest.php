<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventClassSetting;
use App\Models\User;
use App\Services\CommandCenter\Calendar\CalendarThresholdResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression guard for the 2026-07-07 HFC calendar blackout.
 *
 * Root cause: all of agency 1's calendar_event_class_settings were toggled
 * is_active=0 in a batch. CalendarThresholdResolver returned NULL for an
 * inactive class, and CalendarController::applyFilters (+ the deck tiles) drop
 * every null-colour event — so the entire book vanished from every HFC calendar.
 *
 * The fix: an inactive or missing class config resolves to 'neutral' (visible,
 * no RAG urgency), never null. Deactivating a class stops NEW-event generation,
 * urgency, and notifications — it must never ERASE events already on the calendar.
 * Worst case is "no colour", never "no calendar".
 */
final class InactiveClassStillRendersTest extends TestCase
{
    use RefreshDatabase;

    /** A future actionable event under a normally-active class resolves to a RAG colour. */
    public function test_active_class_resolves_a_rag_colour(): void
    {
        [$agencyId, $user] = $this->seedAgencyUser();
        $this->globalClass('viewing', true);
        $resolver = app(CalendarThresholdResolver::class);

        $colour = $resolver->resolveForEvent(
            $this->makeEvent($agencyId, $user->id, 'viewing', now()->addDay())
        );
        $this->assertContains($colour, ['red', 'amber', 'green'], 'active class near-term event is RAG-coloured');
    }

    /** Deactivating the class must NOT hide existing events — they resolve to neutral, not null. */
    public function test_inactive_class_renders_neutral_not_hidden(): void
    {
        [$agencyId, $user] = $this->seedAgencyUser();
        $this->globalClass('viewing', false);   // class is INACTIVE
        $resolver = app(CalendarThresholdResolver::class);

        $colour = $resolver->resolveForEvent(
            $this->makeEvent($agencyId, $user->id, 'viewing', now()->addDay())
        );
        $this->assertSame('neutral', $colour, 'inactive class → neutral (visible), never null (hidden)');
        $this->assertNotNull($colour, 'a deactivated class must never erase an existing event');
    }

    /**
     * The actual incident shape: an INACTIVE agency override shadowing an ACTIVE
     * global. Before the fix this returned null (event erased); now it is neutral.
     */
    public function test_inactive_agency_override_shadowing_active_global_still_renders(): void
    {
        [$agencyId, $user] = $this->seedAgencyUser();
        $this->globalClass('viewing', true);            // active global
        $this->agencyClass($agencyId, 'viewing', false); // inactive agency override shadows it
        $resolver = app(CalendarThresholdResolver::class);

        $colour = $resolver->resolveForEvent(
            $this->makeEvent($agencyId, $user->id, 'viewing', now()->addDay())
        );
        $this->assertSame('neutral', $colour, 'inactive override must not erase the event — neutral, not null');
    }

    /** resolve() (used by ReconcileCalendarEvents) mirrors the contract: inactive → neutral. */
    public function test_resolve_direct_inactive_is_neutral(): void
    {
        [$agencyId, $user] = $this->seedAgencyUser();
        $this->globalClass('viewing', false);
        $resolver = app(CalendarThresholdResolver::class);

        $this->assertSame('neutral', $resolver->resolve($agencyId, 'viewing', now()->addDay()));
        // No date to place on the grid is the ONLY null case.
        $this->assertNull($resolver->resolve($agencyId, 'viewing', null));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function seedAgencyUser(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'T'.Str::random(5), 'slug' => 't-'.Str::random(6),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert(['id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'D', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin', 'is_active' => 1]);
        return [$agencyId, $user];
    }

    private function globalClass(string $class, bool $active): void
    {
        $this->insertClass(null, $class, $active);
    }

    private function agencyClass(int $agencyId, string $class, bool $active): void
    {
        $this->insertClass($agencyId, $class, $active);
    }

    private function insertClass(?int $agencyId, string $class, bool $active): void
    {
        DB::table('calendar_event_class_settings')->insert([
            'agency_id' => $agencyId, 'event_class' => $class, 'label' => Str::headline($class),
            'is_active' => $active, 'event_nature' => 'actionable', 'actor_role' => 'both', 'occupies_time' => true,
            'green_days' => 30, 'amber_days' => 14, 'red_days' => 3, 'show_days' => 60,
            'green_visibility' => json_encode(['all']), 'amber_visibility' => json_encode(['all']), 'red_visibility' => json_encode(['all']),
            'green_notifications' => json_encode([]), 'amber_notifications' => json_encode([]), 'red_notifications' => json_encode([]),
            'daily_digest_enabled' => false, 'daily_digest_roles' => json_encode([]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // The resolver memoizes forAgencyAndClass in-process; DB::insert fires no model
        // event, so flush the memo explicitly (production flushes on model save/delete).
        CalendarEventClassSetting::flushResolveCache();
    }

    private function makeEvent(int $agencyId, int $userId, string $category, $eventDate): CalendarEvent
    {
        $start = $eventDate ?? now()->addDay()->setTime(10, 0);
        $id = DB::table('calendar_events')->insertGetId([
            'user_id' => $userId, 'created_by_id' => $userId, 'event_type' => 'manual', 'category' => $category,
            'title' => Str::headline($category).' '.Str::random(4),
            'event_date' => $start, 'end_date' => (clone $start)->addHour(),
            'all_day' => false, 'priority' => 'normal', 'status' => 'pending', 'source_type' => 'manual',
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'metadata' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return CalendarEvent::withoutGlobalScopes()->findOrFail($id);
    }
}
