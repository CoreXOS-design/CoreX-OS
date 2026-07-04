<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarReminderLog;
use App\Models\User;
use App\Services\CommandCenter\CalendarReminderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AT-178 — the popup toast's due-reminders feed + dismiss/snooze actions.
 * Proves the endpoint is self-scoped, and that read + snooze behave (§10.8).
 */
final class EventReminderEndpointTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->agencyId, $this->user] = $this->seedAgencyUser();
    }

    public function test_due_feed_returns_only_the_authenticated_users_unread_reminders(): void
    {
        $now = Carbon::parse('2026-07-06 09:00:00');
        Carbon::setTestNow($now);

        $event = $this->event('Client viewing', $now->copy()->addMinutes(30));
        $mine   = $this->log($event, $this->user, $now->copy()->subMinute());
        $other  = $this->log($event, $this->makeUser(), $now->copy()->subMinute());

        Sanctum::actingAs($this->user);
        $data = $this->getJson(route('v1.command-center.reminders.due'))->assertOk()->json();

        $ids = collect($data['reminders'])->pluck('id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($other->id, $ids, 'another user\'s reminder must never leak');
        $this->assertSame($event->id, $data['reminders'][0]['event_id']);
        $this->assertStringContainsString('/command-center/calendar/' . $event->id, $data['reminders'][0]['view_url']);
    }

    public function test_read_dismisses_the_reminder_and_is_self_scoped(): void
    {
        $now = Carbon::parse('2026-07-06 09:00:00');
        Carbon::setTestNow($now);
        $event = $this->event('Viewing', $now->copy()->addMinutes(30));
        $mine  = $this->log($event, $this->user, $now->copy()->subMinute());
        $other = $this->log($event, $this->makeUser(), $now->copy()->subMinute());

        Sanctum::actingAs($this->user);

        // Cannot read someone else's reminder.
        $this->postJson(route('v1.command-center.reminders.read', ['log' => $other->id]))
            ->assertOk()->assertJson(['success' => false]);
        $this->assertNull($other->fresh()->read_at);

        // Reading own works; it then drops off the due feed.
        $this->postJson(route('v1.command-center.reminders.read', ['log' => $mine->id]))
            ->assertOk()->assertJson(['success' => true]);
        $this->assertNotNull($mine->fresh()->read_at);
        $this->assertCount(0, $this->getJson(route('v1.command-center.reminders.due'))->json('reminders'));
    }

    public function test_snooze_hides_the_reminder_then_it_resurfaces(): void
    {
        $now = Carbon::parse('2026-07-06 09:00:00');
        Carbon::setTestNow($now);
        $event = $this->event('Viewing', $now->copy()->addMinutes(30));
        $mine  = $this->log($event, $this->user, $now->copy()->subMinute());

        Sanctum::actingAs($this->user);
        $this->postJson(route('v1.command-center.reminders.snooze', ['log' => $mine->id]))
            ->assertOk()->assertJson(['success' => true, 'snooze_minutes' => CalendarReminderService::SNOOZE_MINUTES]);

        // Hidden immediately after snooze.
        $this->assertCount(0, $this->getJson(route('v1.command-center.reminders.due'))->json('reminders'));

        // Re-surfaces once the snooze window passes.
        Carbon::setTestNow($now->copy()->addMinutes(CalendarReminderService::SNOOZE_MINUTES + 1));
        $this->assertCount(1, $this->getJson(route('v1.command-center.reminders.due'))->json('reminders'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── helpers ──

    private function event(string $title, Carbon $start): CalendarEvent
    {
        $id = (int) DB::table('calendar_events')->insertGetId([
            'user_id' => $this->user->id, 'event_type' => 'manual', 'category' => 'viewing',
            'title' => $title, 'event_date' => $start->toDateTimeString(),
            'end_date' => $start->copy()->addHour()->toDateTimeString(),
            'all_day' => false, 'priority' => 'normal', 'status' => 'pending',
            'send_reminder' => true, 'source_type' => 'manual',
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return CalendarEvent::withoutGlobalScopes()->findOrFail($id);
    }

    private function log(CalendarEvent $event, User $user, Carbon $sentAt): CalendarReminderLog
    {
        return CalendarReminderLog::create([
            'calendar_event_id' => $event->id, 'agency_id' => $this->agencyId,
            'user_id' => $user->id, 'channel' => 'popup', 'offset_minutes' => 30,
            'occurrence_key' => 'single', 'sent_at' => $sentAt,
        ]);
    }

    private function makeUser(): User
    {
        return User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => 1,
        ]);
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
}
