<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-178 — the New/Edit event form's reminder fields: store persists per-event
 * offsets+channels, show() round-trips them for edit, update edits them, and the
 * "both channels off ⇒ send_reminder=false" guard holds (§10.9).
 */
final class EventReminderFormTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->agencyId, $this->user] = $this->seedAgencyUser();
        $this->classSetting('viewing');
        $this->actingAs($this->user);
    }

    public function test_store_persists_per_event_offsets_and_channels(): void
    {
        $this->post(route('command-center.calendar.store'), [
            'title'           => 'Client viewing at Ballito',
            'category'        => 'viewing',
            'event_date'      => '2026-07-10 14:00:00',
            'send_reminder'   => 1,
            'reminder_offset' => 30,
            'reminder_popup'  => 1,
            'reminder_email'  => 1,
        ])->assertRedirect();

        $event = CalendarEvent::withoutGlobalScopes()->where('title', 'Client viewing at Ballito')->firstOrFail();
        $this->assertTrue((bool) $event->send_reminder);
        $this->assertSame([30], $event->reminder_offsets);
        $this->assertSame(['popup', 'email'], $event->reminder_channels);
    }

    public function test_both_channels_off_disables_the_reminder(): void
    {
        $this->post(route('command-center.calendar.store'), [
            'title'           => 'No channel event',
            'category'        => 'viewing',
            'event_date'      => '2026-07-10 14:00:00',
            'send_reminder'   => 1,
            'reminder_offset' => 30,
            'reminder_popup'  => 0,
            'reminder_email'  => 0,
        ])->assertRedirect();

        $event = CalendarEvent::withoutGlobalScopes()->where('title', 'No channel event')->firstOrFail();
        $this->assertFalse((bool) $event->send_reminder, 'no channel selected ⇒ reminder disabled');
        $this->assertNull($event->reminder_channels);
    }

    public function test_show_returns_effective_reminder_config_for_edit_round_trip(): void
    {
        $event = $this->makeEvent('Round trip', [15], ['email']);

        $data = $this->getJson(route('command-center.calendar.show', ['calendarEvent' => $event->id]))
            ->assertOk()->json();

        $this->assertTrue($data['send_reminder']);
        $this->assertSame([15], $data['reminder_offsets']);
        $this->assertSame(['email'], $data['reminder_channels']);
    }

    public function test_update_edits_the_reminder_config(): void
    {
        $event = $this->makeEvent('Editable', [60], ['popup']);

        $this->put(route('command-center.calendar.update', ['calendarEvent' => $event->id]), [
            'title'           => 'Editable',
            'category'        => 'viewing',
            'event_date'      => $event->event_date->toDateTimeString(),
            'send_reminder'   => 1,
            'reminder_offset' => 10,
            'reminder_popup'  => 1,
            'reminder_email'  => 1,
        ])->assertRedirect();

        $event->refresh();
        $this->assertSame([10], $event->reminder_offsets);
        $this->assertSame(['popup', 'email'], $event->reminder_channels);
    }

    public function test_tampered_offset_falls_back_to_a_valid_option(): void
    {
        // 999 is not in the default agency option list → snaps to the 60 default.
        $this->post(route('command-center.calendar.store'), [
            'title'           => 'Tampered offset',
            'category'        => 'viewing',
            'event_date'      => '2026-07-10 14:00:00',
            'send_reminder'   => 1,
            'reminder_offset' => 999,
            'reminder_popup'  => 1,
            'reminder_email'  => 0,
        ])->assertRedirect();

        $event = CalendarEvent::withoutGlobalScopes()->where('title', 'Tampered offset')->firstOrFail();
        $this->assertSame([60], $event->reminder_offsets, 'out-of-list offset snaps to a valid option, never 500s');
    }

    // ── helpers ──

    private function makeEvent(string $title, array $offsets, array $channels): CalendarEvent
    {
        $id = (int) DB::table('calendar_events')->insertGetId([
            'user_id' => $this->user->id, 'event_type' => 'manual', 'category' => 'viewing',
            'title' => $title, 'event_date' => '2026-07-10 14:00:00', 'end_date' => '2026-07-10 15:00:00',
            'all_day' => false, 'priority' => 'normal', 'status' => 'pending',
            'send_reminder' => true, 'reminder_offsets' => json_encode($offsets),
            'reminder_channels' => json_encode($channels), 'source_type' => 'manual',
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return CalendarEvent::withoutGlobalScopes()->findOrFail($id);
    }

    private function seedAgencyUser(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin', 'is_active' => 1,
        ]);
        return [$agencyId, $user];
    }

    private function classSetting(string $class): void
    {
        DB::table('calendar_event_class_settings')->insert([
            'agency_id' => null, 'event_class' => $class, 'label' => Str::headline($class),
            'is_active' => true, 'event_nature' => 'actionable',
            'green_days' => 365, 'amber_days' => 30, 'red_days' => 7, 'show_days' => 365,
            'green_visibility' => json_encode(['all']), 'amber_visibility' => json_encode(['all']),
            'red_visibility' => json_encode(['all']), 'green_notifications' => json_encode([]),
            'amber_notifications' => json_encode([]), 'red_notifications' => json_encode([]),
            'daily_digest_enabled' => false, 'daily_digest_roles' => json_encode([]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
