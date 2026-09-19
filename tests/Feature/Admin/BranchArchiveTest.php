<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Events\Agent\AgentBranchAssigned;
use App\Events\Branch\BranchArchived;
use App\Events\Branch\BranchRestored;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Deal;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Branch archive & agent reassignment — spec .ai/specs/branch-archive-reassignment.md (AT-420).
 *
 * Business rule (Andre, 2026-09-15): deals carry on as loaded. A deal keeps
 * the branch it was loaded under; a moved agent's NEXT deal lands on the new
 * branch; the move is dated; an archived branch keeps its name everywhere and
 * can be restored without moving anyone back.
 *
 * Acceptance criteria covered: §10 items 1, 2, 3, 4, 5, 7, 8, 9, 10.
 */
final class BranchArchiveTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $kloof;      // the branch being archived
    private Branch $margate;    // the target
    private Branch $scottburgh; // a second active branch
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency     = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->kloof      = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Kloof', 'code' => 'KLF']);
        $this->margate    = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate', 'code' => 'MGT']);
        $this->scottburgh = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Scottburgh', 'code' => 'SCB']);

        Role::create(['name' => 'admin', 'label' => 'Administrator', 'agency_id' => $this->agency->id]);
        RolePermission::updateOrCreate(
            ['role' => 'admin', 'permission_key' => 'access_branch_assignments', 'agency_id' => $this->agency->id],
            []
        );
        // Company Settings + Agency edit pages (the other two places the wizard lives)
        RolePermission::updateOrCreate(
            ['role' => 'admin', 'permission_key' => 'manage_performance_settings', 'agency_id' => $this->agency->id],
            []
        );
        PermissionService::clearCache();

        $this->admin = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->margate->id,
            'role'      => 'admin',
            'is_active' => true,
        ]);
    }

    private function agentIn(Branch $branch, string $name, array $extra = []): User
    {
        return User::factory()->create(array_merge([
            'agency_id' => $this->agency->id,
            'branch_id' => $branch->id,
            'role'      => 'agent',
            'name'      => $name,
            'is_active' => true,
        ], $extra));
    }

    private function dealFor(User $agent, Branch $branch, string $date = '2026-08-12'): Deal
    {
        $deal = Deal::create([
            'agency_id'         => $this->agency->id,
            'branch_id'         => $branch->id,
            'deal_no'           => random_int(100000, 999999), // integer column
            'deal_date'         => $date,
            'period'            => substr($date, 0, 7),
            'property_value'    => 1850000,
            'total_commission'  => 115000, // inc VAT
            'accepted_status'   => 'P',
            'commission_status' => 'Not Paid',
            'listing_split_percent' => 50,
            'selling_split_percent' => 50,
        ]);
        $deal->agents()->attach($agent->id, ['side' => 'selling', 'agent_split_percent' => 100, 'agent_cut_percent' => 60]);
        $deal->agents()->attach($agent->id, ['side' => 'listing', 'agent_split_percent' => 100, 'agent_cut_percent' => 60]);

        return $deal->fresh();
    }

    private function archive(Branch $branch, array $reassignments = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->post(
            route('admin.branches.delete', $branch),
            ['reassignments' => $reassignments]
        );
    }

    // ── §10.1 all-or-nothing ───────────────────────────────────────────────

    public function test_archive_is_refused_when_any_agent_has_no_target(): void
    {
        $sipho  = $this->agentIn($this->kloof, 'Sipho Dlamini');
        $anneke = $this->agentIn($this->kloof, 'Anneke van der Merwe');

        $resp = $this->archive($this->kloof, [$sipho->id => $this->margate->id]); // Anneke left blank

        $resp->assertSessionHasErrors('branch');
        $this->assertNull($this->kloof->fresh()->deleted_at, 'Branch must NOT be archived');
        $this->assertSame($this->kloof->id, (int) $sipho->fresh()->branch_id, 'Nobody moves on a refused archive');
        $this->assertSame($this->kloof->id, (int) $anneke->fresh()->branch_id);
        $this->assertDatabaseCount('user_branch_history', 0);
    }

    public function test_archive_is_refused_with_no_reassignments_at_all(): void
    {
        $this->agentIn($this->kloof, 'Sipho Dlamini');

        $this->archive($this->kloof)->assertSessionHasErrors('branch');
        $this->assertNull($this->kloof->fresh()->deleted_at);
    }

    public function test_archive_is_refused_when_target_is_the_branch_being_archived(): void
    {
        $sipho = $this->agentIn($this->kloof, 'Sipho Dlamini');

        $this->archive($this->kloof, [$sipho->id => $this->kloof->id])->assertSessionHasErrors('branch');
        $this->assertNull($this->kloof->fresh()->deleted_at);
    }

    public function test_archive_is_refused_when_target_is_already_archived(): void
    {
        $sipho = $this->agentIn($this->kloof, 'Sipho Dlamini');
        $this->scottburgh->delete();

        $this->archive($this->kloof, [$sipho->id => $this->scottburgh->id])->assertSessionHasErrors('branch');
        $this->assertNull($this->kloof->fresh()->deleted_at);
        $this->assertSame($this->kloof->id, (int) $sipho->fresh()->branch_id);
    }

    public function test_archive_is_refused_when_target_is_in_another_agency(): void
    {
        $sipho = $this->agentIn($this->kloof, 'Sipho Dlamini');
        $rival = Agency::create(['name' => 'Rival', 'slug' => 'rival-' . uniqid()]);
        $foreign = Branch::create(['agency_id' => $rival->id, 'name' => 'Foreign']);

        $this->archive($this->kloof, [$sipho->id => $foreign->id])->assertSessionHasErrors('branch');
        $this->assertNull($this->kloof->fresh()->deleted_at);
    }

    public function test_archive_is_refused_when_it_is_the_only_active_branch(): void
    {
        $this->margate->delete();
        $this->scottburgh->delete();
        $sipho = $this->agentIn($this->kloof, 'Sipho Dlamini');

        $this->archive($this->kloof, [$sipho->id => $this->margate->id])->assertSessionHasErrors('branch');
        $this->assertNull($this->kloof->fresh()->deleted_at);
    }

    // ── §10.2 dated move + §10.9 one click ──────────────────────────────────

    public function test_archive_moves_every_agent_and_writes_one_dated_history_row_each(): void
    {
        Event::fake([AgentBranchAssigned::class, BranchArchived::class]);

        $sipho  = $this->agentIn($this->kloof, 'Sipho Dlamini');
        $anneke = $this->agentIn($this->kloof, 'Anneke van der Merwe', ['is_active' => false]); // inactive still moves
        $thandi = $this->agentIn($this->kloof, 'Thandi Ngcobo');

        $resp = $this->archive($this->kloof, [
            $sipho->id  => $this->margate->id,
            $anneke->id => $this->margate->id,
            $thandi->id => $this->scottburgh->id,
        ]);

        $resp->assertSessionHasNoErrors();
        $resp->assertSessionHas('success');

        $this->assertNotNull($this->kloof->fresh()->deleted_at, 'Branch is archived (soft-deleted)');
        $this->assertNotNull(Branch::withTrashed()->find($this->kloof->id), 'Never hard-deleted');

        $this->assertSame($this->margate->id, (int) $sipho->fresh()->branch_id);
        $this->assertSame($this->margate->id, (int) $anneke->fresh()->branch_id);
        $this->assertSame($this->scottburgh->id, (int) $thandi->fresh()->branch_id);

        foreach ([[$sipho, $this->margate], [$anneke, $this->margate], [$thandi, $this->scottburgh]] as [$agent, $target]) {
            $rows = DB::table('user_branch_history')->where('user_id', $agent->id)->get();
            $this->assertCount(1, $rows, "{$agent->name} has exactly one move row");
            $this->assertSame($this->kloof->id, (int) $rows[0]->from_branch_id);
            $this->assertSame($target->id, (int) $rows[0]->to_branch_id);
            $this->assertSame($this->admin->id, (int) $rows[0]->moved_by_user_id);
            $this->assertNotNull($rows[0]->moved_at);
        }

        Event::assertDispatchedTimes(AgentBranchAssigned::class, 3);
        Event::assertDispatched(AgentBranchAssigned::class, fn ($e) => $e->reason === 'branch_archived' && $e->fromBranchId === $this->kloof->id);
        Event::assertDispatched(BranchArchived::class, fn ($e) => $e->branch->id === $this->kloof->id && count($e->movedUserIds) === 3);
    }

    public function test_branch_with_nobody_on_it_archives_in_one_click(): void
    {
        $resp = $this->archive($this->scottburgh);

        $resp->assertSessionHasNoErrors();
        $this->assertNotNull($this->scottburgh->fresh()->deleted_at);
        $this->assertDatabaseCount('user_branch_history', 0);
    }

    public function test_legacy_pivot_only_user_must_also_be_moved(): void
    {
        // users.branch_id says Margate but the legacy 1:1 pivot still says Kloof
        $lerato = $this->agentIn($this->margate, 'Lerato Mokoena');
        DB::table('branch_assignments')->insert([
            'user_id' => $lerato->id, 'branch_id' => $this->kloof->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->archive($this->kloof)->assertSessionHasErrors('branch');

        $this->archive($this->kloof, [$lerato->id => $this->margate->id])->assertSessionHasNoErrors();
        $this->assertSame($this->margate->id, (int) DB::table('branch_assignments')->where('user_id', $lerato->id)->value('branch_id'));
    }

    // ── §10.3 + §10.4 deals carry on as loaded ──────────────────────────────

    public function test_deal_loaded_before_the_move_stays_with_the_archived_branch(): void
    {
        $sipho = $this->agentIn($this->kloof, 'Sipho Dlamini');
        $deal  = $this->dealFor($sipho, $this->kloof, '2026-08-12');

        $this->archive($this->kloof, [$sipho->id => $this->margate->id])->assertSessionHasNoErrors();

        $deal = $deal->fresh();
        $this->assertSame($this->kloof->id, (int) $deal->branch_id, 'Register untouched');

        // Branch-side roll-up follows the deal's stamp, not the agent's new home.
        $this->assertGreaterThan(0, $deal->branchCommission($this->kloof->id), 'Kloof still gets the money');
        $this->assertSame(0.0, $deal->branchCommission($this->margate->id), 'Margate gets none of the old deal');

        $kloofSummary   = Deal::statusSummaryForBranch($this->kloof->id, '2026-08');
        $margateSummary = Deal::statusSummaryForBranch($this->margate->id, '2026-08');
        $this->assertSame(1, $kloofSummary['pending_period']);
        $this->assertSame(0, $margateSummary['pending_period']);

        // The agent's own earnings are unchanged by the move.
        $this->assertGreaterThan(0, $deal->allocations()[$sipho->id] ?? 0);
    }

    public function test_deal_loaded_after_the_move_lands_on_the_new_branch_automatically(): void
    {
        $sipho = $this->agentIn($this->kloof, 'Sipho Dlamini');
        $this->archive($this->kloof, [$sipho->id => $this->margate->id])->assertSessionHasNoErrors();

        // Simulate the DealController default: a new deal takes the loader's effective branch.
        $effective = $sipho->fresh()->effectiveBranchId();
        $this->assertSame($this->margate->id, (int) $effective);

        $deal = $this->dealFor($sipho, Branch::find($effective), '2026-09-15');
        $this->assertSame($this->margate->id, (int) $deal->branch_id);
        $this->assertGreaterThan(0, $deal->branchCommission($this->margate->id));
        $this->assertSame(0.0, $deal->branchCommission($this->kloof->id));
    }

    public function test_legacy_deal_without_a_stamp_still_follows_the_agents_current_branch(): void
    {
        $sipho = $this->agentIn($this->kloof, 'Sipho Dlamini');
        $deal  = $this->dealFor($sipho, $this->kloof);
        DB::table('deals')->where('id', $deal->id)->update(['branch_id' => null]); // pre-stamp legacy row
        $deal = $deal->fresh();

        $this->assertGreaterThan(0, $deal->branchCommission($this->kloof->id));
        $this->assertSame(0.0, $deal->branchCommission($this->margate->id));
    }

    // ── §10.5 archived name never blank ─────────────────────────────────────

    public function test_archived_branch_keeps_its_name_on_records_and_is_marked_archived(): void
    {
        $sipho = $this->agentIn($this->kloof, 'Sipho Dlamini');
        $this->archive($this->kloof, [$sipho->id => $this->margate->id])->assertSessionHasNoErrors();

        $archived = Branch::withTrashed()->find($this->kloof->id);
        $this->assertSame('Kloof (archived)', $archived->display_name);
        $this->assertSame('Margate', $this->margate->fresh()->display_name);

        // A relation on a record stamped with the archived branch still resolves.
        $property = \App\Models\Property::create([
            'agency_id' => $this->agency->id, 'agent_id' => $sipho->id, 'branch_id' => $this->kloof->id,
            'external_id' => (string) \Illuminate\Support\Str::uuid(), 'title' => '12 Ocean View Drive',
            'suburb' => 'Kloof', 'property_type' => 'house', 'status' => 'sold', 'price' => 2400000,
        ]);
        $this->assertNotNull($property->fresh()->branch, 'Relation must not go blank');
        $this->assertSame('Kloof (archived)', $property->fresh()->branch->display_name);

        // Report lists carry it, grouped after the active ones; target lists never do.
        $report = Branch::listForReports();
        $this->assertSame(['Margate', 'Scottburgh', 'Kloof'], $report->pluck('name')->all());
        $this->assertFalse(Branch::selectable()->pluck('id')->contains($this->kloof->id));
    }

    // ── §10.7 multi-branch managers + view-as ───────────────────────────────

    public function test_manager_default_is_repointed_and_view_as_override_dies_with_the_branch(): void
    {
        $principal = $this->agentIn($this->kloof, 'Johan Reichel', ['role' => 'admin']);
        DB::table('user_managed_branches')->insert([
            ['user_id' => $principal->id, 'branch_id' => $this->kloof->id,   'agency_id' => $this->agency->id, 'is_default' => true,  'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $principal->id, 'branch_id' => $this->margate->id, 'agency_id' => $this->agency->id, 'is_default' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
        // A second manager whose HOME is Margate but who managed Kloof too.
        $coManager = $this->agentIn($this->margate, 'Elize Reichel', ['role' => 'admin']);
        DB::table('user_managed_branches')->insert([
            ['user_id' => $coManager->id, 'branch_id' => $this->kloof->id, 'agency_id' => $this->agency->id, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->archive($this->kloof, [$principal->id => $this->margate->id])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('user_managed_branches', ['branch_id' => $this->kloof->id]);
        $this->assertSame($this->margate->id, $principal->fresh()->defaultManagedBranchId(), 'Default re-pointed to the target they manage');
        $this->assertNull($coManager->fresh()->defaultManagedBranchId(), 'Nothing left to manage → no default');

        // A stale "viewing as Kloof" session falls back to the home branch.
        session(['view_as_branch_id' => $this->kloof->id]);
        $this->actingAs($principal->fresh());
        $this->assertSame($this->margate->id, (int) $principal->fresh()->effectiveBranchId());
    }

    // ── §10.8 restore ───────────────────────────────────────────────────────

    public function test_restore_reactivates_the_branch_and_moves_nobody(): void
    {
        Event::fake([BranchRestored::class]);
        $sipho = $this->agentIn($this->kloof, 'Sipho Dlamini');
        $deal  = $this->dealFor($sipho, $this->kloof);
        $this->archive($this->kloof, [$sipho->id => $this->margate->id])->assertSessionHasNoErrors();

        $resp = $this->actingAs($this->admin)->post(route('admin.branches.restore', $this->kloof->id));

        $resp->assertSessionHasNoErrors();
        $resp->assertSessionHas('success');
        $this->assertNull($this->kloof->fresh()->deleted_at);
        $this->assertSame('Kloof', $this->kloof->fresh()->display_name);
        $this->assertSame($this->margate->id, (int) $sipho->fresh()->branch_id, 'Restore moves nobody back');
        $this->assertSame($this->kloof->id, (int) $deal->fresh()->branch_id, 'History intact');
        Event::assertDispatched(BranchRestored::class);
    }

    public function test_restore_of_an_active_branch_is_not_found(): void
    {
        $this->actingAs($this->admin)->post(route('admin.branches.restore', $this->margate->id))->assertNotFound();
    }

    // ── §10.10 permission gate ──────────────────────────────────────────────

    public function test_archive_and_restore_require_the_branch_assignments_permission(): void
    {
        $agent = $this->agentIn($this->margate, 'Sipho Dlamini');

        $this->actingAs($agent)->post(route('admin.branches.delete', $this->scottburgh))->assertForbidden();
        $this->assertNull($this->scottburgh->fresh()->deleted_at);

        $this->scottburgh->delete();
        $this->actingAs($agent)->post(route('admin.branches.restore', $this->scottburgh->id))->assertForbidden();
        $this->assertNotNull($this->scottburgh->fresh()->deleted_at);
    }

    // ── Pages render with the wizard + archived panel ───────────────────────

    public function test_branch_assignments_page_renders_wizard_and_archived_panel(): void
    {
        $this->agentIn($this->kloof, 'Sipho Dlamini');
        $this->scottburgh->delete();

        $resp = $this->actingAs($this->admin)->get(route('admin.branch-assignments'));

        $resp->assertOk();
        $resp->assertSee('Archive Kloof?');
        $resp->assertSee('Sipho Dlamini');
        $resp->assertSee('Archived Branches');
        $resp->assertSee('Restore Scottburgh?');
        $resp->assertDontSee('This cannot be undone');
    }

    public function test_company_settings_and_agency_edit_pages_render_wizard_and_archived_panel(): void
    {
        $this->agentIn($this->kloof, 'Sipho Dlamini');
        $this->scottburgh->delete();

        $company = $this->actingAs($this->admin)->get(route('admin.company-settings'));
        $company->assertOk();
        $company->assertSee('Archive Kloof?');
        $company->assertSee('Restore Scottburgh?');
        $company->assertDontSee('This cannot be undone');

        $agencyEdit = $this->actingAs($this->admin)->get(route('agencies.edit', $this->agency));
        $agencyEdit->assertOk();
        $agencyEdit->assertSee('Archive Kloof?');
        $agencyEdit->assertSee('Restore Scottburgh?');
        $agencyEdit->assertDontSee('This cannot be undone');
    }
}
