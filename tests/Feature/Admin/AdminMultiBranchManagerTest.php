<?php

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Admin Multi-Branch Manager — spec .ai/specs/admin-multi-branch-manager.md.
 * Covers the new connective logic: self-assignment save, the acting-as gate,
 * and the Deal::branchManager() resolution precedence.
 */
class AdminMultiBranchManagerTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgencyWithBranches(): array
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal']);
        $b1 = Branch::create(['agency_id' => $agency->id, 'name' => 'Margate']);
        $b2 = Branch::create(['agency_id' => $agency->id, 'name' => 'Scottburgh']);
        $admin = User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $b1->id,
            'role' => 'admin',
        ]);

        return [$agency, $b1, $b2, $admin];
    }

    public function test_admin_saves_managed_branches_with_single_default(): void
    {
        [$agency, $b1, $b2, $admin] = $this->makeAgencyWithBranches();

        $resp = $this->actingAs($admin)->patch(route('agent.portal.managed-branches.update'), [
            'managed_branches' => [$b1->id, $b2->id],
            'default_branch_id' => $b2->id,
        ]);

        $resp->assertSessionHasNoErrors();

        $this->assertDatabaseHas('user_managed_branches', [
            'user_id' => $admin->id, 'branch_id' => $b1->id, 'is_default' => 0,
        ]);
        $this->assertDatabaseHas('user_managed_branches', [
            'user_id' => $admin->id, 'branch_id' => $b2->id, 'is_default' => 1,
        ]);
        $this->assertSame(1, DB::table('user_managed_branches')
            ->where('user_id', $admin->id)->where('is_default', 1)->count(),
            'Exactly one default is allowed.');

        $this->assertTrue($admin->isManagerOfBranch($b2->id));
        $this->assertSame($b2->id, $admin->defaultManagedBranchId());
    }

    public function test_branch_from_another_agency_is_dropped(): void
    {
        [$agency, $b1, $b2, $admin] = $this->makeAgencyWithBranches();
        $otherAgency = Agency::create(['name' => 'Rival', 'slug' => 'rival']);
        $foreign = Branch::create(['agency_id' => $otherAgency->id, 'name' => 'Foreign']);

        $this->actingAs($admin)->patch(route('agent.portal.managed-branches.update'), [
            'managed_branches' => [$b1->id, $foreign->id],
            'default_branch_id' => $foreign->id,
        ])->assertSessionHasNoErrors();

        // Foreign branch never lands in the pivot; the valid one becomes default.
        $this->assertDatabaseMissing('user_managed_branches', [
            'user_id' => $admin->id, 'branch_id' => $foreign->id,
        ]);
        $this->assertSame($b1->id, $admin->defaultManagedBranchId());
    }

    public function test_acting_requires_managing_the_branch(): void
    {
        [$agency, $b1, $b2, $admin] = $this->makeAgencyWithBranches();

        // Not yet a manager of b1 → forbidden.
        $this->actingAs($admin)->post(route('branch.acting', $b1))->assertForbidden();

        // Become a manager, then it succeeds and writes the session context.
        DB::table('user_managed_branches')->insert([
            'user_id' => $admin->id, 'branch_id' => $b1->id, 'agency_id' => $agency->id,
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin)->post(route('branch.acting', $b1))->assertSessionHas('status');
        $this->assertSame($b1->id, (int) session('acting_branch_manager_id'));
        $this->assertSame($b1->id, $admin->actingBranchManagerId());
    }

    public function test_deal_branch_manager_prefers_captured_over_role(): void
    {
        [$agency, $b1, $b2, $admin] = $this->makeAgencyWithBranches();
        $this->actingAs($admin); // AgencyScope context

        $roleBm = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $b1->id, 'role' => 'branch_manager',
        ]);

        $deal = Deal::create([
            'agency_id' => $agency->id, 'branch_id' => $b1->id,
            'deal_no' => '9001', 'period' => '2026-06', 'deal_date' => '2026-06-01',
            'property_value' => 1000000, 'total_commission' => 50000,
        ]);

        // No captured manager → falls back to the branch_manager-role user.
        $this->assertSame($roleBm->id, $deal->branchManager()?->id);

        // Capture an acting manager → it now wins.
        $deal->managed_by_user_id = $admin->id;
        $deal->save();
        $this->assertSame($admin->id, $deal->fresh()->branchManager()?->id);
    }

    public function test_admin_edit_screen_assigns_and_clears_managed_branches(): void
    {
        // The roles table is seeded by a data migration; the schema snapshot
        // used in tests carries structure only, so seed the roles this test's
        // role-validation (Rule::in(Role::roleNames())) needs, then bust cache.
        foreach ([['agent', 'Agent'], ['admin', 'Administrator']] as [$n, $l]) {
            \App\Models\Role::firstOrCreate(['name' => $n], [
                'label' => $l, 'is_owner' => false, 'can_be_deleted' => true, 'sort_order' => 1,
            ]);
        }
        \App\Models\Role::clearCache();

        [$agency, $b1, $b2, $editor] = $this->makeAgencyWithBranches();
        $target = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $b1->id, 'role' => 'admin',
            'name' => 'Jane Doe', 'email' => 'jane@coastal.test', 'cell' => '0123456789',
        ]);

        $base = [
            'name' => 'Jane', 'surname' => 'Doe', 'email' => 'jane@coastal.test',
            'display_email' => '', 'cell' => '0123456789', 'role' => 'admin', 'branch_id' => $b1->id,
            'designation' => '',
        ];

        // Admin assigns two managed branches with b2 as the login default.
        $this->actingAs($editor)->put(route('admin.users.update', $target), $base + [
            'managed_branches' => [$b1->id, $b2->id],
            'default_branch_id' => $b2->id,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('user_managed_branches', [
            'user_id' => $target->id, 'branch_id' => $b2->id, 'is_default' => 1,
        ]);
        $this->assertTrue($target->isManagerOfBranch($b1->id));
        $this->assertSame($b2->id, $target->defaultManagedBranchId());

        // Demoting out of an admin role clears the managed-branch assignments.
        $this->actingAs($editor)->put(route('admin.users.update', $target), $base + [
            'role' => 'agent',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('user_managed_branches')->where('user_id', $target->id)->count());
    }

    public function test_user_edit_screen_renders_branches_managed(): void
    {
        [$agency, $b1, $b2, $editor] = $this->makeAgencyWithBranches();
        \App\Models\Role::firstOrCreate(['name' => 'admin'], [
            'label' => 'Administrator', 'is_owner' => false, 'can_be_deleted' => true, 'sort_order' => 1,
        ]);
        \App\Models\Role::clearCache();

        $this->actingAs($editor)->get(route('admin.users.edit', $editor))
            ->assertOk()
            ->assertSee('Branches Managed');
    }

    public function test_portal_and_sidebar_render_for_managing_admin(): void
    {
        [$agency, $b1, $b2, $admin] = $this->makeAgencyWithBranches();
        DB::table('user_managed_branches')->insert([
            'user_id' => $admin->id, 'branch_id' => $b1->id, 'agency_id' => $agency->id,
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // The portal page (profile panel) and the layout sidebar (acting
        // switcher) must both render without error and show the new controls.
        $this->actingAs($admin)->get(route('agent.portal'))
            ->assertOk()
            ->assertSee('Branches I Manage')
            ->assertSee('Act as branch manager');
    }
}
