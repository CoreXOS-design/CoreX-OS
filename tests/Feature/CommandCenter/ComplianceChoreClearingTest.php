<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Events\Property\PropertyCompliancePassed;
use App\Listeners\Property\DismissComplianceClearedChores;
use App\Models\CommandCenter\CommandTask;
use App\Models\Property;
use App\Services\CommandCenter\AutoEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Properties marked compliant (P24 go-live imports especially) must not carry
 * the auto "Upload signed mandate / owner ID / proof of ownership" document
 * tasks or "needs attention" idle prompts — compliance is exactly what those
 * chase.
 */
final class ComplianceChoreClearingTest extends TestCase
{
    use RefreshDatabase;

    public function test_compliance_passed_clears_auto_chore_tasks_but_keeps_manual_and_done(): void
    {
        [$agencyId, $branchId, $agentId] = $this->seedBasics();
        $propId = $this->makeProperty($agencyId, $branchId, $agentId, compliant: true);

        $doc    = $this->makeTask($agencyId, $branchId, $agentId, $propId, 'document_upload', 'automation_rule', 'todo');
        $idle   = $this->makeTask($agencyId, $branchId, $agentId, $propId, 'review', 'automation_rule', 'todo');
        $manual = $this->makeTask($agencyId, $branchId, $agentId, $propId, 'document_upload', 'manual', 'todo');
        $done   = $this->makeTask($agencyId, $branchId, $agentId, $propId, 'document_upload', 'automation_rule', 'done');

        $property = Property::withoutGlobalScopes()->findOrFail($propId);
        (new DismissComplianceClearedChores())->handle(new PropertyCompliancePassed($property, $agentId));

        $this->assertSoftDeleted('command_tasks', ['id' => $doc]);
        $this->assertSoftDeleted('command_tasks', ['id' => $idle]);
        $this->assertNotSoftDeleted('command_tasks', ['id' => $manual]); // manual is sacred
        $this->assertNotSoftDeleted('command_tasks', ['id' => $done]);   // already resolved, untouched
    }

    public function test_dispatching_the_event_clears_tasks_via_the_registered_listener(): void
    {
        [$agencyId, $branchId, $agentId] = $this->seedBasics();
        $propId = $this->makeProperty($agencyId, $branchId, $agentId, compliant: true);
        $task   = $this->makeTask($agencyId, $branchId, $agentId, $propId, 'document_upload', 'automation_rule', 'todo');

        // Real dispatch through the app's event bus — proves AppServiceProvider
        // wired DismissComplianceClearedChores to PropertyCompliancePassed.
        $property = Property::withoutGlobalScopes()->findOrFail($propId);
        event(new PropertyCompliancePassed($property, $agentId));

        $this->assertSoftDeleted('command_tasks', ['id' => $task]);
    }

    public function test_already_compliant_property_gets_no_document_tasks_on_create(): void
    {
        [$agencyId, $branchId, $agentId] = $this->seedBasics();

        $compliant    = Property::withoutGlobalScopes()->findOrFail($this->makeProperty($agencyId, $branchId, $agentId, compliant: true));
        $nonCompliant = Property::withoutGlobalScopes()->findOrFail($this->makeProperty($agencyId, $branchId, $agentId, compliant: false));

        $svc = new AutoEventService();
        $svc->onPropertyCreated($compliant);
        $svc->onPropertyCreated($nonCompliant);

        $this->assertSame(0, CommandTask::withoutGlobalScopes()->where('property_id', $compliant->id)->count());
        $this->assertGreaterThan(0, CommandTask::withoutGlobalScopes()->where('property_id', $nonCompliant->id)->count());
    }

    public function test_idle_scan_skips_compliant_properties(): void
    {
        [$agencyId, $branchId, $agentId] = $this->seedBasics();
        // Idle (no activity) but compliant — must NOT be flagged.
        $compliantId = $this->makeProperty($agencyId, $branchId, $agentId, compliant: true);
        DB::table('properties')->where('id', $compliantId)->update(['last_activity_at' => now()->subDays(60)]);

        (new AutoEventService())->flagIdleProperties();

        $this->assertSame(0, CommandTask::withoutGlobalScopes()
            ->where('property_id', $compliantId)->where('task_type', 'review')->count());
    }

    public function test_backfill_command_clears_existing_redundant_tasks(): void
    {
        [$agencyId, $branchId, $agentId] = $this->seedBasics();
        $compliantId    = $this->makeProperty($agencyId, $branchId, $agentId, compliant: true);
        $nonCompliantId = $this->makeProperty($agencyId, $branchId, $agentId, compliant: false);

        $redundant = $this->makeTask($agencyId, $branchId, $agentId, $compliantId, 'document_upload', 'automation_rule', 'todo');
        $keep      = $this->makeTask($agencyId, $branchId, $agentId, $nonCompliantId, 'document_upload', 'automation_rule', 'todo');

        $this->artisan('command-center:clear-compliant-chores')->assertSuccessful();

        $this->assertSoftDeleted('command_tasks', ['id' => $redundant]);
        $this->assertNotSoftDeleted('command_tasks', ['id' => $keep]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:int,2:int} */
    private function seedBasics(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $agencyId, 'name' => 'Branch 1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agentId = \App\Models\User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $branchId, 'role' => 'agent',
        ])->id;

        return [$agencyId, $branchId, $agentId];
    }

    private function makeProperty(int $agencyId, int $branchId, int $agentId, bool $compliant): int
    {
        return (int) DB::table('properties')->insertGetId([
            'external_id' => (string) Str::uuid(), 'title' => 'Test Property',
            'agent_id' => $agentId, 'branch_id' => $branchId, 'agency_id' => $agencyId,
            'listing_type' => 'sale',
            'compliance_snapshot_at' => $compliant ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeTask(int $agencyId, int $branchId, int $userId, int $propertyId, string $type, string $source, string $status): int
    {
        return (int) DB::table('command_tasks')->insertGetId([
            'title' => ucfirst($type) . ' task', 'task_type' => $type, 'status' => $status,
            'priority' => 'normal', 'assigned_to' => $userId, 'source_type' => $source,
            'property_id' => $propertyId, 'due_date' => now()->subDay(),
            'agency_id' => $agencyId, 'branch_id' => $branchId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
