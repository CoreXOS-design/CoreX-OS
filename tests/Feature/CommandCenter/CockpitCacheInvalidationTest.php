<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CommandTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Today cockpit (CommandCentreService::assembleForUser) caches per user for
 * 300s. Resolving an overdue task/event must drop it off Today immediately, so
 * every write to these models busts the owner's cockpit cache — otherwise
 * resolved items linger for up to 5 minutes, even through a hard refresh.
 */
final class CockpitCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolving_a_task_busts_the_assignees_cockpit_cache(): void
    {
        [$agencyId, $branchId, $user] = $this->seedBasics();
        $task = CommandTask::create([
            'title' => 'Upload signed mandate', 'task_type' => 'custom',
            'status' => 'todo', 'priority' => 'normal', 'assigned_to' => $user->id,
            'due_date' => now()->subDay(), 'agency_id' => $agencyId, 'branch_id' => $branchId,
        ]);

        Cache::put("command_centre_{$user->id}", ['stale'], 300);

        $task->update(['status' => 'done', 'completed_at' => now(), 'resolution' => 'completed']);

        $this->assertFalse(Cache::has("command_centre_{$user->id}"));
    }

    public function test_resolving_an_event_busts_the_owners_cockpit_cache(): void
    {
        [$agencyId, $branchId, $user] = $this->seedBasics();
        $event = CalendarEvent::create([
            'user_id' => $user->id, 'event_type' => 'manual', 'category' => 'meeting',
            'title' => 'Viewing', 'event_date' => now()->subDay(), 'status' => 'overdue',
            'agency_id' => $agencyId, 'branch_id' => $branchId,
        ]);

        Cache::put("command_centre_{$user->id}", ['stale'], 300);

        $event->update(['status' => 'completed', 'resolution' => 'completed']);

        $this->assertFalse(Cache::has("command_centre_{$user->id}"));
    }

    public function test_reassigning_a_task_busts_both_old_and_new_owner_caches(): void
    {
        [$agencyId, $branchId, $userA] = $this->seedBasics();
        $userB = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $branchId, 'role' => 'agent',
        ]);
        $task = CommandTask::create([
            'title' => 'Call back', 'task_type' => 'custom', 'status' => 'todo',
            'priority' => 'normal', 'assigned_to' => $userA->id,
            'due_date' => now()->addDay(), 'agency_id' => $agencyId, 'branch_id' => $branchId,
        ]);

        Cache::put("command_centre_{$userA->id}", ['stale'], 300);
        Cache::put("command_centre_{$userB->id}", ['stale'], 300);

        $task->update(['assigned_to' => $userB->id]);

        $this->assertFalse(Cache::has("command_centre_{$userA->id}"), 'previous assignee cache busted');
        $this->assertFalse(Cache::has("command_centre_{$userB->id}"), 'new assignee cache busted');
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
