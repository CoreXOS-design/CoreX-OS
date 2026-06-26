<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\User;
use App\Services\CommandCenter\CalendarEventService;
use App\Services\CommandCenter\TaskService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Role-based visibility scope for Calendar & Tasks (Command Center).
 *
 * role_permissions is unseeded in the test DB, so PermissionService falls back
 * to role-name defaults: agent → own, branch_manager → branch, admin/super →
 * all. That fallback is exactly what exercises CalendarEvent::scopeVisibleTo
 * and CommandTask::scopeVisibleTo here.
 */
final class VisibilityScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_sees_only_their_own_tasks_and_events(): void
    {
        [$agencyId, $b1] = $this->seedAgency();
        $agentA = $this->makeUser($agencyId, $b1, 'agent');
        $agentB = $this->makeUser($agencyId, $b1, 'agent');

        $this->makeTask($agencyId, $b1, $agentA->id, 'A task');
        $this->makeTask($agencyId, $b1, $agentB->id, 'B task');
        $this->makeEvent($agencyId, $b1, $agentA->id, 'A event');
        $this->makeEvent($agencyId, $b1, $agentB->id, 'B event');

        $this->assertSame('own', PermissionService::taskScope($agentA));

        $taskTitles = $this->taskTitles($agentA);
        $this->assertContains('A task', $taskTitles);
        $this->assertNotContains('B task', $taskTitles);

        $eventTitles = $this->eventTitles($agentA);
        $this->assertContains('A event', $eventTitles);
        $this->assertNotContains('B event', $eventTitles);
    }

    public function test_admin_scope_sees_whole_agency(): void
    {
        [$agencyId, $b1] = $this->seedAgency();
        $admin  = $this->makeUser($agencyId, $b1, 'super_admin');
        $agentB = $this->makeUser($agencyId, $b1, 'agent');

        $this->makeTask($agencyId, $b1, $admin->id, 'Admin task');
        $this->makeTask($agencyId, $b1, $agentB->id, 'Other task');
        $this->makeEvent($agencyId, $b1, $admin->id, 'Admin event');
        $this->makeEvent($agencyId, $b1, $agentB->id, 'Other event');

        $this->assertSame('all', PermissionService::taskScope($admin));

        $taskTitles = $this->taskTitles($admin);
        $this->assertContains('Admin task', $taskTitles);
        $this->assertContains('Other task', $taskTitles);

        $eventTitles = $this->eventTitles($admin);
        $this->assertContains('Admin event', $eventTitles);
        $this->assertContains('Other event', $eventTitles);
    }

    public function test_branch_manager_sees_branch_not_other_branch(): void
    {
        [$agencyId, $b1, $b2] = $this->seedAgency(twoBranches: true);
        $bm     = $this->makeUser($agencyId, $b1, 'branch_manager');
        $agentB = $this->makeUser($agencyId, $b2, 'agent');

        $this->makeTask($agencyId, $b1, $bm->id, 'Branch1 task');
        $this->makeTask($agencyId, $b2, $agentB->id, 'Branch2 task');
        $this->makeEvent($agencyId, $b1, $bm->id, 'Branch1 event');
        $this->makeEvent($agencyId, $b2, $agentB->id, 'Branch2 event');

        $this->assertSame('branch', PermissionService::taskScope($bm));

        $taskTitles = $this->taskTitles($bm);
        $this->assertContains('Branch1 task', $taskTitles);
        $this->assertNotContains('Branch2 task', $taskTitles);

        $eventTitles = $this->eventTitles($bm);
        $this->assertContains('Branch1 event', $eventTitles);
        $this->assertNotContains('Branch2 event', $eventTitles);
    }

    public function test_clamp_scope_never_widens_beyond_ceiling(): void
    {
        $this->assertSame('own', PermissionService::clampScope('all', 'own'));
        $this->assertSame('branch', PermissionService::clampScope('all', 'branch'));
        $this->assertSame('own', PermissionService::clampScope('own', 'all'));
        $this->assertSame('all', PermissionService::clampScope('all', 'all'));
        $this->assertSame('branch', PermissionService::clampScope(null, 'branch'));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:int,2:int} [agencyId, branch1Id, branch2Id] */
    private function seedAgency(bool $twoBranches = false): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $b1 = (int) DB::table('branches')->insertGetId([
            'agency_id' => $agencyId, 'name' => 'Branch 1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $b2 = $twoBranches ? (int) DB::table('branches')->insertGetId([
            'agency_id' => $agencyId, 'name' => 'Branch 2',
            'created_at' => now(), 'updated_at' => now(),
        ]) : $b1;

        return [$agencyId, $b1, $b2];
    }

    private function makeUser(int $agencyId, int $branchId, string $role): User
    {
        return User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $branchId, 'role' => $role,
        ]);
    }

    private function makeTask(int $agencyId, int $branchId, int $userId, string $title): void
    {
        DB::table('command_tasks')->insert([
            'title' => $title, 'task_type' => 'custom', 'status' => 'todo',
            'priority' => 'normal', 'assigned_to' => $userId,
            'due_date' => now()->addDay(),
            'agency_id' => $agencyId, 'branch_id' => $branchId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeEvent(int $agencyId, int $branchId, int $userId, string $title): void
    {
        DB::table('calendar_events')->insert([
            'user_id' => $userId, 'event_type' => 'manual', 'category' => 'meeting',
            'title' => $title, 'event_date' => now()->setTime(10, 0),
            'all_day' => false, 'priority' => 'normal', 'status' => 'pending',
            'agency_id' => $agencyId, 'branch_id' => $branchId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return string[] */
    private function taskTitles(User $user): array
    {
        $columns = (new TaskService())->getTasksByStatus($user);

        return collect($columns)->flatten(1)->pluck('title')->all();
    }

    /** @return string[] */
    private function eventTitles(User $user): array
    {
        $scope = PermissionService::calendarScope($user);

        // Bracket today with whole-day boundaries — whereBetween treats a bare
        // date as midnight, so use yesterday…tomorrow to include today's events.
        return (new CalendarEventService())
            ->getEventsForRange($user, now()->subDay()->toDateString(), now()->addDay()->toDateString(), [], $scope)
            ->pluck('title')->all();
    }
}
