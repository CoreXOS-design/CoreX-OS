<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cross-agency isolation audit 2026-08-20 (hygiene finding): ViewAsController::
 * update() validated branch_id as a bare nullable integer, no ownership check.
 * Not a data leak -- BranchScope independently re-derives the caller's own real
 * agency_id before ever applying a branch filter -- but a confusing UX bug (an
 * admin picking a foreign branch_id silently sees an empty view with no error).
 * Fixed by scoping the exists rule to the caller's own agency.
 */
final class ViewAsBranchScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_cannot_set_view_as_branch_id_belonging_to_another_agency(): void
    {
        $agency = Agency::create(['name' => 'Own', 'slug' => 'own-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Own HQ']);
        Role::create(['name' => 'super_admin', 'label' => 'System Owner', 'is_owner' => true]);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $agency->id]);

        $foreignAgency = Agency::create(['name' => 'Foreign', 'slug' => 'foreign-' . uniqid()]);
        $foreignBranch = Branch::create(['agency_id' => $foreignAgency->id, 'name' => 'Foreign HQ']);

        $owner = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'super_admin']);
        session(['active_agency_id' => $agency->id]);

        $response = $this->actingAs($owner)->post(route('admin.viewas.update'), [
            'role' => 'agent',
            'branch_id' => $foreignBranch->id,
        ]);

        $response->assertSessionHasErrors('branch_id');
    }

    public function test_owner_can_still_set_view_as_branch_id_for_their_own_branch(): void
    {
        $agency = Agency::create(['name' => 'Own', 'slug' => 'own-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Own HQ']);
        Role::create(['name' => 'super_admin', 'label' => 'System Owner', 'is_owner' => true]);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $agency->id]);

        $owner = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'super_admin']);
        session(['active_agency_id' => $agency->id]);

        $response = $this->actingAs($owner)->post(route('admin.viewas.update'), [
            'role' => 'agent',
            'branch_id' => $branch->id,
        ]);

        $response->assertSessionDoesntHaveErrors('branch_id');
        $this->assertSame($branch->id, session('view_as_branch_id'));
    }
}
