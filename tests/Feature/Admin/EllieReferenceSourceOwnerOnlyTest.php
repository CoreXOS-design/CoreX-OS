<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cross-agency isolation audit 2026-08-20 (hygiene finding): the route
 * comment already said "super_admin only" but the middleware enforced only
 * permission:manage_reference_sources -- nothing stopped that permission key
 * from being granted to a non-owner agency-admin role, which would let that
 * agency's admin edit what's meant to be a single global, cross-agency
 * allowlist every other agency's Ellie also searches. Fixed by adding
 * owner_only to the route group.
 */
final class EllieReferenceSourceOwnerOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_non_owner_admin_is_refused_even_with_the_permission_granted(): void
    {
        PermissionService::forceProductionPosture();

        $agency = Agency::create(['name' => 'Own', 'slug' => 'own-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'HQ']);
        Role::create(['name' => 'admin', 'label' => 'Administrator', 'agency_id' => $agency->id]);
        RolePermission::updateOrCreate(
            ['role' => 'admin', 'permission_key' => 'manage_reference_sources', 'agency_id' => $agency->id],
            []
        );
        PermissionService::clearCache();

        $admin = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true,
        ]);

        // getJson (not get) so a 403 renders as a JSON error response instead
        // of the HTML error view — the test env has no built Vite manifest,
        // unrelated to the authorization behaviour under test.
        $this->actingAs($admin)
            ->getJson(route('admin.ellie.reference-sources.index'))
            ->assertForbidden();
    }

    public function test_a_real_system_owner_can_still_reach_the_page(): void
    {
        $this->withoutVite();

        $ownerRole = Role::create(['name' => 'super_admin', 'label' => 'System Owner']);
        $ownerRole->is_owner = true;
        $ownerRole->save();
        Role::clearCache();
        $owner = User::factory()->create(['agency_id' => null, 'branch_id' => null, 'role' => 'super_admin']);

        $this->actingAs($owner)
            ->get(route('admin.ellie.reference-sources.index'))
            ->assertOk();
    }
}
