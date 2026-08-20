<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\AgencyAccessRequest;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cross-agency isolation audit 2026-08-20 (hygiene finding):
 * AgencyAccessRequestController::authorize() hardcoded `role === 'admin'`
 * instead of consulting the registered agency.authorize_external_access
 * permission key -- so revoking that permission from the 'admin' role via
 * Role Manager had no effect on this endpoint. Not a leak (nothing became
 * more permissive), but the config an admin sets in Role Manager silently
 * didn't apply. Fixed by checking hasPermission() instead of the role name.
 */
final class AgencyAccessRequestAuthorizePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_without_the_permission_cannot_authorize_a_request(): void
    {
        $agency = Agency::create(['name' => 'Own', 'slug' => 'own-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'HQ']);
        Role::create(['name' => 'admin', 'label' => 'Administrator', 'agency_id' => $agency->id]);
        // Grant a DIFFERENT permission (so grantsExist() is true and the
        // unseeded-table allow-all fallback doesn't mask this assertion) but
        // deliberately not agency.authorize_external_access.
        RolePermission::updateOrCreate(
            ['role' => 'admin', 'permission_key' => 'manage_targets', 'agency_id' => $agency->id],
            []
        );
        PermissionService::clearCache();
        PermissionService::forceProductionPosture();

        $admin = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true,
        ]);

        $accessRequest = AgencyAccessRequest::create([
            'target_agency_id' => $agency->id,
            'requester_user_id' => $admin->id,
            'requester_role' => 'super_admin',
            'status' => AgencyAccessRequest::STATUS_PENDING,
            'expires_at' => now()->addMinutes(5),
        ]);
        $accessRequest->targetedAdmins()->attach($admin->id);

        $this->actingAs($admin)
            ->postJson(route('api.v1.agency-access.authorize', ['request' => $accessRequest->id]), [
                'decision' => 'approve',
            ])
            ->assertForbidden();
    }

    public function test_an_admin_with_the_permission_granted_can_authorize_a_request(): void
    {
        $agency = Agency::create(['name' => 'Own', 'slug' => 'own-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'HQ']);
        Role::create(['name' => 'admin', 'label' => 'Administrator', 'agency_id' => $agency->id]);
        RolePermission::updateOrCreate(
            ['role' => 'admin', 'permission_key' => 'agency.authorize_external_access', 'agency_id' => $agency->id],
            []
        );
        PermissionService::clearCache();
        PermissionService::forceProductionPosture();

        $admin = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true,
        ]);

        $accessRequest = AgencyAccessRequest::create([
            'target_agency_id' => $agency->id,
            'requester_user_id' => $admin->id,
            'requester_role' => 'super_admin',
            'status' => AgencyAccessRequest::STATUS_PENDING,
            'expires_at' => now()->addMinutes(5),
        ]);
        $accessRequest->targetedAdmins()->attach($admin->id);

        $this->actingAs($admin)
            ->postJson(route('api.v1.agency-access.authorize', ['request' => $accessRequest->id]), [
                'decision' => 'approve',
            ])
            ->assertOk();

        $this->assertSame(AgencyAccessRequest::STATUS_APPROVED, $accessRequest->fresh()->status);
    }
}
