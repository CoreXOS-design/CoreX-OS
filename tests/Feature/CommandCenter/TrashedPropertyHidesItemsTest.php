<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Mail\CommandCenter\TaskDueDigest;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CommandTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Soft-deleting a property must take its derived Command Center work items
 * (document tasks, "needs attention" prompts, viewings) off Today / Tasks /
 * Calendar / reminders. LivePropertyScope is the read-time guard; it is
 * self-healing — restoring the property brings the items back. Standalone
 * items (no property_id) are never affected.
 */
final class TrashedPropertyHidesItemsTest extends TestCase
{
    use RefreshDatabase;

    public function test_trashed_property_items_disappear_and_return_on_restore(): void
    {
        [$agencyId, $branchId, $user] = $this->seedBasics();
        $propId = $this->makeProperty($agencyId, $branchId, $user->id);

        $linkedTask  = $this->makeTask($agencyId, $branchId, $user->id, 'Upload signed mandate', $propId);
        $linkedEvent = $this->makeEvent($agencyId, $branchId, $user->id, 'Viewing', $propId);
        $standalone  = $this->makeTask($agencyId, $branchId, $user->id, 'Call back lead', null);

        // While the property is live, everything is visible.
        $this->assertNotNull(CommandTask::find($linkedTask));
        $this->assertNotNull(CalendarEvent::find($linkedEvent));
        $this->assertNotNull(CommandTask::find($standalone));

        // Soft-delete the property.
        DB::table('properties')->where('id', $propId)->update(['deleted_at' => now()]);

        // The property's task & event vanish from every model query; the
        // standalone task (no property) stays.
        $this->assertNull(CommandTask::find($linkedTask), 'trashed-property task should be hidden');
        $this->assertNull(CalendarEvent::find($linkedEvent), 'trashed-property event should be hidden');
        $this->assertNotNull(CommandTask::find($standalone), 'standalone task must remain');

        // The rows are NOT destroyed — escape hatch still sees them.
        $this->assertNotNull(
            CommandTask::withoutGlobalScope(\App\Models\Scopes\LivePropertyScope::class)->find($linkedTask)
        );

        // Restore the property → items reappear (self-healing, no backfill).
        DB::table('properties')->where('id', $propId)->update(['deleted_at' => null]);

        $this->assertNotNull(CommandTask::find($linkedTask), 'task returns when property restored');
        $this->assertNotNull(CalendarEvent::find($linkedEvent), 'event returns when property restored');
    }

    public function test_no_reminder_email_for_trashed_property_task(): void
    {
        Mail::fake();

        [$agencyId, $branchId, $user] = $this->seedBasics();
        $propId = $this->makeProperty($agencyId, $branchId, $user->id);
        $this->makeDueTask($agencyId, $branchId, $user->id, 'Upload owner ID copy', $propId);

        DB::table('properties')->where('id', $propId)->update(['deleted_at' => now()]);

        $this->artisan('command-center:reminders')->assertSuccessful();

        // The task is tied to a gone property — no digest, no per-task notice.
        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $user->id)->count());
    }

    // ── Helpers ──────────────────────────────────────────────────────────

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

    private function makeProperty(int $agencyId, int $branchId, int $agentId): int
    {
        return (int) DB::table('properties')->insertGetId([
            'external_id' => (string) Str::uuid(),
            'title'       => 'Test Property',
            'agent_id'    => $agentId,
            'branch_id'   => $branchId,
            'agency_id'   => $agencyId,
            'created_at'  => now(), 'updated_at' => now(),
        ]);
    }

    private function makeTask(int $agencyId, int $branchId, int $userId, string $title, ?int $propertyId): int
    {
        return (int) DB::table('command_tasks')->insertGetId([
            'title' => $title, 'task_type' => 'custom', 'status' => 'todo',
            'priority' => 'normal', 'assigned_to' => $userId,
            'property_id' => $propertyId, 'due_date' => now()->addDay(),
            'agency_id' => $agencyId, 'branch_id' => $branchId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeDueTask(int $agencyId, int $branchId, int $userId, string $title, ?int $propertyId): int
    {
        return (int) DB::table('command_tasks')->insertGetId([
            'title' => $title, 'task_type' => 'custom', 'status' => 'todo',
            'priority' => 'normal', 'assigned_to' => $userId, 'send_reminder' => true,
            'property_id' => $propertyId, 'due_date' => now()->addHours(2),
            'agency_id' => $agencyId, 'branch_id' => $branchId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeEvent(int $agencyId, int $branchId, int $userId, string $title, ?int $propertyId): int
    {
        return (int) DB::table('calendar_events')->insertGetId([
            'user_id' => $userId, 'event_type' => 'manual', 'category' => 'meeting',
            'title' => $title, 'event_date' => now()->addDay(),
            'all_day' => false, 'priority' => 'normal', 'status' => 'pending',
            'property_id' => $propertyId,
            'agency_id' => $agencyId, 'branch_id' => $branchId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
