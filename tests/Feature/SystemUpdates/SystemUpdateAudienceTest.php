<?php

declare(strict_types=1);

namespace Tests\Feature\SystemUpdates;

use App\Models\Role;
use App\Models\RolePermission;
use App\Models\SystemUpdate;
use App\Models\User;
use App\Services\PermissionService;

/**
 * Audience targeting — spec §6.
 *
 * The bug this whole design exists to prevent: resolving "admin" from a hardcoded
 * role-name list. `roles` is per-agency and agency-editable, so a name list fails
 * SILENTLY on any agency that renamed its roles — the admin-only update reaches
 * nobody and nothing errors. test_capability_resolution_survives_a_renamed_role is
 * the guard.
 */
final class SystemUpdateAudienceTest extends SystemUpdateTestCase
{
    public function test_everyone_audience_reaches_a_plain_agent(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $this->publish(['audience' => SystemUpdate::AUDIENCE_ALL]);

        $this->assertCount(1, $this->service()->pendingFor($this->agent));
    }

    public function test_admins_only_never_reaches_a_plain_agent(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $this->publish(['audience' => SystemUpdate::AUDIENCE_ADMINS]);

        $this->assertFalse($this->service()->userIsAdminAudience($this->agent));
        $this->assertCount(0, $this->service()->pendingFor($this->agent));
    }

    public function test_admins_only_reaches_a_user_who_can_see_the_admin_section(): void
    {
        $this->joinedAt($this->admin, now()->subMonth());
        $this->publish(['audience' => SystemUpdate::AUDIENCE_ADMINS]);

        $this->assertTrue($this->service()->userIsAdminAudience($this->admin));
        $this->assertCount(1, $this->service()->pendingFor($this->admin));
    }

    public function test_admins_only_reaches_a_system_owner(): void
    {
        $this->joinedAt($this->owner, now()->subMonth());
        $this->publish(['audience' => SystemUpdate::AUDIENCE_ADMINS]);

        $this->assertTrue($this->service()->userIsAdminAudience($this->owner));
        $this->assertCount(1, $this->service()->pendingFor($this->owner));
    }

    /** Spec §6.2 — audience resolves at DISPLAY time, not publish time. */
    public function test_an_agent_promoted_after_publish_then_sees_the_admin_only_update(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $this->publish(['audience' => SystemUpdate::AUDIENCE_ADMINS]);

        $this->assertCount(0, $this->service()->pendingFor($this->agent));

        $this->agent->update(['role' => 'admin']);
        PermissionService::clearCache();
        Role::clearCache();

        $this->assertCount(1, $this->service()->pendingFor($this->agent->refresh()),
            'promotion after publish must grant visibility');
    }

    /**
     * The failure a hardcoded role-name list would produce, made impossible.
     *
     * An agency that calls its admin role "Principal" must still receive admin-only
     * updates — the capability is what matters, not the word.
     */
    public function test_capability_resolution_survives_a_renamed_role(): void
    {
        Role::create(['name' => 'principal', 'label' => 'Principal', 'agency_id' => $this->agency->id]);
        RolePermission::create([
            'role'           => 'principal',
            'permission_key' => (string) config('system-updates.admin_permission'),
            'agency_id'      => $this->agency->id,
        ]);
        PermissionService::clearCache();
        Role::clearCache();

        $principal = User::factory()->create([
            'agency_id' => $this->agency->id, 'role' => 'principal', 'is_active' => true,
        ]);
        $this->joinedAt($principal, now()->subMonth());

        $this->publish(['audience' => SystemUpdate::AUDIENCE_ADMINS]);

        $this->assertTrue(
            $this->service()->userIsAdminAudience($principal),
            'a renamed admin role must still resolve as the admin audience'
        );
        $this->assertCount(1, $this->service()->pendingFor($principal));
    }

    public function test_the_adoption_denominator_respects_the_audience(): void
    {
        $everyone = $this->publish(['audience' => SystemUpdate::AUDIENCE_ALL]);
        $adminsOnly = $this->publish(['audience' => SystemUpdate::AUDIENCE_ADMINS]);

        $allUsers = User::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->count();

        $this->assertSame($allUsers, $this->service()->audienceUserCount($everyone));
        $this->assertSame(2, $this->service()->audienceUserCount($adminsOnly), 'owner + admin only');
    }
}
