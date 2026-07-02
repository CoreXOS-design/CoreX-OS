<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\User;
use App\Services\CommandCenter\Calendar\CalendarThresholdResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per-event "requires feedback" = the event_nature axis (actionable/informational)
 * exposed on the create/edit form. Effective nature = per-event metadata override
 * ?? agency-configurable class default. Informational events NEVER go red/overdue
 * and never ask for feedback.
 */
final class EventNatureFeedbackTest extends TestCase
{
    use RefreshDatabase;

    /** Effective nature = class default, and a per-event metadata override wins both ways. */
    public function test_effective_nature_default_and_override(): void
    {
        [$agencyId, $user] = $this->seedAgencyUser();
        $this->classSetting('viewing', 'actionable');
        $this->classSetting('private', 'informational');

        $viewing = $this->makeEvent($agencyId, $user->id, 'viewing', null);
        $private = $this->makeEvent($agencyId, $user->id, 'private', null);
        $this->assertSame('actionable', $viewing->effectiveEventNature());
        $this->assertSame('informational', $private->effectiveEventNature());
        $this->assertFalse($viewing->isInformational());
        $this->assertTrue($private->isInformational());

        // Per-event override flips both.
        $viewingOverride = $this->makeEvent($agencyId, $user->id, 'viewing', ['event_nature' => 'informational']);
        $privateOverride = $this->makeEvent($agencyId, $user->id, 'private', ['event_nature' => 'actionable']);
        $this->assertTrue($viewingOverride->isInformational(), 'viewing overridden to informational');
        $this->assertFalse($privateOverride->isInformational(), 'private overridden to actionable');
    }

    /** Colour: actionable past = red; informational past = neutral (never red). Override honoured. */
    public function test_informational_never_red_actionable_can(): void
    {
        [$agencyId, $user] = $this->seedAgencyUser();
        $this->classSetting('viewing', 'actionable');
        $this->classSetting('private', 'informational');
        $resolver = app(CalendarThresholdResolver::class);
        $yesterday = now()->subDay();

        $this->assertSame('red', $resolver->resolveForEvent(
            $this->makeEvent($agencyId, $user->id, 'viewing', null, $yesterday)));
        $this->assertSame('neutral', $resolver->resolveForEvent(
            $this->makeEvent($agencyId, $user->id, 'private', null, $yesterday)));

        // Overrides
        $this->assertSame('red', $resolver->resolveForEvent(
            $this->makeEvent($agencyId, $user->id, 'private', ['event_nature' => 'actionable'], $yesterday)));
        $this->assertSame('neutral', $resolver->resolveForEvent(
            $this->makeEvent($agencyId, $user->id, 'viewing', ['event_nature' => 'informational'], $yesterday)));
    }

    /** Overdue marker (command-center:reminders) sweeps actionable only; informational stays pending. */
    public function test_overdue_marker_skips_informational(): void
    {
        [$agencyId, $user] = $this->seedAgencyUser();
        $this->classSetting('viewing', 'actionable');
        $this->classSetting('private', 'informational');
        $yesterday = now()->subDay();

        $viewing        = $this->makeEvent($agencyId, $user->id, 'viewing', null, $yesterday);
        $private        = $this->makeEvent($agencyId, $user->id, 'private', null, $yesterday);
        $privateAsAppt  = $this->makeEvent($agencyId, $user->id, 'private', ['event_nature' => 'actionable'], $yesterday);
        $viewingAsBlock = $this->makeEvent($agencyId, $user->id, 'viewing', ['event_nature' => 'informational'], $yesterday);

        $this->artisan('command-center:reminders')->assertExitCode(0);

        $this->assertSame('overdue', $viewing->fresh()->status, 'actionable viewing → overdue');
        $this->assertSame('pending', $private->fresh()->status, 'informational private → NEVER overdue');
        $this->assertSame('overdue', $privateAsAppt->fresh()->status, 'private overridden actionable → overdue');
        $this->assertSame('pending', $viewingAsBlock->fresh()->status, 'viewing overridden informational → not overdue');
    }

    /** store() persists the choice in metadata; show() exposes is_actionable + event_nature; update() flips it. */
    public function test_store_show_update_roundtrip(): void
    {
        [$agencyId, $user] = $this->seedAgencyUser();
        $this->classSetting('private', 'informational');

        // Create a private block but choose "Requires feedback" (override to actionable).
        $this->actingAs($user)->post(route('command-center.calendar.store'), [
            'title'        => 'Client block',
            'category'     => 'private',
            'event_date'   => now()->addDay()->format('Y-m-d\TH:i'),
            'event_nature' => 'actionable',
        ])->assertRedirect();

        $ev = CalendarEvent::where('title', 'Client block')->firstOrFail();
        $this->assertSame('actionable', $ev->metadata['event_nature'] ?? null, 'override persisted to metadata');

        $this->actingAs($user)->getJson(route('command-center.calendar.show', $ev))
            ->assertOk()->assertJson(['is_actionable' => true, 'event_nature' => 'actionable']);

        // Update back to "No feedback needed".
        $this->actingAs($user)->putJson(route('command-center.calendar.update', $ev), [
            'title' => 'Client block', 'event_nature' => 'informational',
        ])->assertOk();
        $this->assertSame('informational', $ev->fresh()->metadata['event_nature'] ?? null, 'update persisted');

        $this->actingAs($user)->getJson(route('command-center.calendar.show', $ev))
            ->assertOk()->assertJson(['is_actionable' => false, 'event_nature' => 'informational']);
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

    private function classSetting(string $class, string $nature): void
    {
        DB::table('calendar_event_class_settings')->insert([
            'agency_id' => null, 'event_class' => $class, 'label' => Str::headline($class),
            'is_active' => true, 'event_nature' => $nature, 'actor_role' => 'both', 'occupies_time' => true,
            'green_days' => 30, 'amber_days' => 14, 'red_days' => 3, 'show_days' => 60,
            'green_visibility' => json_encode(['all']), 'amber_visibility' => json_encode(['all']), 'red_visibility' => json_encode(['all']),
            'green_notifications' => json_encode([]), 'amber_notifications' => json_encode([]), 'red_notifications' => json_encode([]),
            'daily_digest_enabled' => false, 'daily_digest_roles' => json_encode([]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeEvent(int $agencyId, int $userId, string $category, ?array $metadata, $eventDate = null): CalendarEvent
    {
        $start = $eventDate ?? now()->addDay()->setTime(10, 0);
        $id = DB::table('calendar_events')->insertGetId([
            'user_id' => $userId, 'created_by_id' => $userId, 'event_type' => 'manual', 'category' => $category,
            'title' => Str::headline($category).' '.Str::random(4),
            'event_date' => $start, 'end_date' => (clone $start)->addHour(),
            'all_day' => false, 'priority' => 'normal', 'status' => 'pending', 'source_type' => 'manual',
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'metadata' => $metadata !== null ? json_encode($metadata) : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return CalendarEvent::withoutGlobalScopes()->findOrFail($id);
    }
}
