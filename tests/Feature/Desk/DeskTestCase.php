<?php

declare(strict_types=1);

namespace Tests\Feature\Desk;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CoreX Desk (.ai/specs/corex-desk.md) - shared fixture: the default roles with their seeded grants, two agencies, an agent in each,
 * a CoreX owner (support) and an agency admin who is given desk.support through Role Manager.
 */
abstract class DeskTestCase extends TestCase
{
    use RefreshDatabase;

    protected Agency $agencyA;

    protected Agency $agencyB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['desk.expire_throttle_seconds' => 0]);   // (the lazy stale-session sweep is throttled in production; tests want it on every read)
        $now = now();
        foreach ([['super_admin', 'System Owner', 1], ['admin', 'Administrator', 0], ['branch_manager', 'Branch Manager', 0], ['agent', 'Agent', 0], ['viewer', 'Viewer', 0], ['office_admin', 'Office Staff', 0]] as $i => [$name, $label, $owner]) {
            DB::table('roles')->insertOrIgnore(['name' => $name, 'label' => $label, 'is_owner' => $owner, 'can_be_deleted' => $owner ? 0 : 1, 'sort_order' => $i + 1, 'agency_id' => null, 'created_at' => $now, 'updated_at' => $now]);
        }
        Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);
        Role::clearCache();
        PermissionService::clearCache();

        $this->agencyA = Agency::create(['name' => 'Agency A ' . uniqid(), 'slug' => 'a-' . uniqid()]);
        $this->agencyB = Agency::create(['name' => 'Agency B ' . uniqid(), 'slug' => 'b-' . uniqid()]);
        foreach ([$this->agencyA, $this->agencyB] as $agency) {
            Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        }
    }

    protected function tearDown(): void
    {
        Role::clearCache();
        PermissionService::clearCache();
        parent::tearDown();
    }

    /** The id of the take-over request currently open on a session (what the agent's Allow / Decline has to quote). */
    protected function rid(\App\Models\DeskSession $session): string
    {
        return (string) $session->fresh()->control_request_id;
    }

    protected function agentIn(Agency $agency, string $role = 'agent'): User
    {
        return User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => Branch::where('agency_id', $agency->id)->value('id'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    /** A CoreX owner: owner role, reaches every agency. */
    protected function owner(): User
    {
        return User::factory()->create(['agency_id' => null, 'role' => 'super_admin', 'is_active' => true]);
    }

    /** An agency admin who has been granted desk.support in Role Manager (admin does not get it by default). */
    protected function adminWithSupport(Agency $agency): User
    {
        RolePermission::firstOrCreate(['role' => 'admin', 'permission_key' => 'desk.support', 'agency_id' => $agency->id], ['scope' => 'all']);
        PermissionService::clearCache();

        return $this->agentIn($agency, 'admin');
    }
}
