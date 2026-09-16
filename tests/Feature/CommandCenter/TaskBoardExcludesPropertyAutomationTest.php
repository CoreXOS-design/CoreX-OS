<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\User;
use App\Services\CommandCenter\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Task board is for tasks. Auto-generated property housekeeping — the
 * per-listing document-chase chores and the daily idle "Property needs
 * attention" prompts — is property-health signal, not agent to-dos, and
 * must not appear on the board, in its header counts, or in its Archived
 * view. Deal automation (FICA / bond chores) is genuine work and stays.
 */
final class TaskBoardExcludesPropertyAutomationTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;
    private User $agent;
    private int $propertyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Branch 1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent', 'is_active' => 1,
        ]);
        $this->propertyId = (int) DB::table('properties')->insertGetId([
            'external_id' => (string) Str::uuid(), 'title' => '12 Beach Road, Ballito',
            'agency_id' => $this->agencyId, 'agent_id' => $this->agent->id, 'branch_id' => $this->branchId,
            'status' => 'active', 'street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Ballito',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_board_and_header_counts_hide_property_housekeeping_but_keep_real_and_deal_tasks(): void
    {
        $this->task('Call the seller back', 'custom', null, null);
        $this->task('Upload signed mandate — 12 Beach Road, Ballito', 'document_upload', 'automation_rule', $this->propertyId);
        $this->task('URGENT: Property needs attention — no activity for 40 days — 12 Beach Road, Ballito', 'review', 'automation_rule', $this->propertyId);
        $this->task('Complete FICA for Jane Smith — 12 Beach Road, Ballito', 'compliance', 'automation_rule', $this->propertyId, dealId: 7);
        $this->task('Follow up new buyer', 'follow_up', 'automation_rule', null);

        $service = new TaskService();
        $titles  = collect($service->getTasksByStatus($this->agent))->flatten(1)->pluck('title')->all();

        $this->assertContains('Call the seller back', $titles);
        $this->assertContains('Complete FICA for Jane Smith — 12 Beach Road, Ballito', $titles, 'deal automation is real work');
        $this->assertContains('Follow up new buyer', $titles, 'non-property automation stays');
        $this->assertNotContains('Upload signed mandate — 12 Beach Road, Ballito', $titles);
        $this->assertNotContains('URGENT: Property needs attention — no activity for 40 days — 12 Beach Road, Ballito', $titles);

        // Header counts describe the cards shown — every fixture is a day overdue.
        $board = $service->getBoardSummary($this->agent);
        $this->assertSame(3, $board['open']);
        $this->assertSame(3, $board['overdue']);

        // The dashboard/mobile summary must agree with the board — it also
        // excludes property housekeeping, so no screen ever shows an agent an
        // inflated count the board itself doesn't back up (AT-419 follow-up).
        $this->assertSame(3, $service->getSummary($this->agent)['open']);
    }

    public function test_task_board_page_renders_without_the_housekeeping_rows(): void
    {
        $this->task('Call the seller back', 'custom', null, null);
        $this->task('Upload owner ID copy — 12 Beach Road, Ballito', 'document_upload', 'automation_rule', $this->propertyId);

        $this->actingAs($this->agent)
            ->get(route('command-center.tasks'))
            ->assertOk()
            ->assertSee('Call the seller back')
            ->assertDontSee('Upload owner ID copy');
    }

    public function test_board_has_no_at_risk_strip_and_always_renders_all_four_columns(): void
    {
        // One overdue task would have lit the old "At Risk" strip; three columns are empty.
        $this->task('Call the seller back', 'custom', null, null);

        $this->actingAs($this->agent)
            ->get(route('command-center.tasks'))
            ->assertOk()
            ->assertDontSee('At Risk')
            ->assertSee('data-kanban-column="todo"', false)
            ->assertSee('data-kanban-column="in_progress"', false)
            ->assertSee('data-kanban-column="awaiting"', false)
            ->assertSee('data-kanban-column="done"', false)
            ->assertDontSee('emptyColumnHidden')
            // Column fold state is never remembered: Done must load open every visit.
            ->assertSee("colCollapsed: { todo: false, in_progress: false, awaiting: false, done: false }", false)
            ->assertDontSee("readJSON(LS.col");
    }

    public function test_archived_view_hides_archived_property_housekeeping(): void
    {
        $this->task('Old real task', 'custom', null, null, deletedAt: now());
        $this->task('Upload proof of ownership — 12 Beach Road, Ballito', 'document_upload', 'automation_rule', $this->propertyId, deletedAt: now());

        $this->actingAs($this->agent)
            ->get(route('command-center.tasks.archived'))
            ->assertOk()
            ->assertSee('Old real task')
            ->assertDontSee('Upload proof of ownership');
    }

    // ── helpers ──

    private function task(
        string $title,
        string $type,
        ?string $sourceType,
        ?int $propertyId,
        ?int $dealId = null,
        $deletedAt = null,
    ): void {
        DB::table('command_tasks')->insert([
            'title' => $title, 'task_type' => $type, 'status' => 'todo', 'priority' => 'high',
            'assigned_to' => $this->agent->id, 'due_date' => now()->subDay(),
            'property_id' => $propertyId, 'deal_id' => $dealId, 'source_type' => $sourceType,
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deletedAt,
        ]);
    }
}
