<?php

namespace Tests\Feature\Compliance;

use App\Models\Agency;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Compliance\PractitionerFfcRosterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Johan 2026-10-05 — who appears on the Inspection Pack staff roster is a Role Manager permission
 * (ppra_inspection_pack.roster) per role per agency, not a hardcoded role list.
 */
class PpraInspectionPackRosterPermissionTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Southern Cape Realty', 'slug' => 'southern-cape-realty']);
    }

    private function grant(string $role, ?Agency $agency = null): void
    {
        RolePermission::create([
            'role' => $role,
            'permission_key' => PractitionerFfcRosterService::ROSTER_PERMISSION,
            'scope' => null,
            'agency_id' => ($agency ?? $this->agency)->id,
        ]);
    }

    private function names(?Agency $agency = null): array
    {
        return app(PractitionerFfcRosterService::class)
            ->rosterFor(($agency ?? $this->agency)->id)->pluck('name')->sort()->values()->all();
    }

    private function user(string $name, string $role, array $extra = []): User
    {
        return User::factory()->create(array_merge([
            'name' => $name, 'role' => $role, 'agency_id' => $this->agency->id, 'is_active' => true,
        ], $extra));
    }

    public function test_only_roles_holding_the_permission_appear(): void
    {
        $this->grant('agent');
        $this->grant('branch_manager');
        $this->grant('admin');
        $this->user('Agnes Agent', 'agent');
        $this->user('Bob Manager', 'branch_manager');
        $this->user('Ann Admin', 'admin');
        $this->user('Angelique Office', 'office_admin');
        $this->user('Vera Viewer', 'viewer');

        $this->assertSame(['Agnes Agent', 'Ann Admin', 'Bob Manager'], $this->names());
    }

    public function test_ticking_and_unticking_a_role_adds_and_removes_its_users(): void
    {
        $this->grant('agent');
        $this->user('Agnes Agent', 'agent');
        $this->user('Angelique Office', 'office_admin');
        $this->assertSame(['Agnes Agent'], $this->names());

        $this->grant('office_admin');
        $this->assertSame(['Agnes Agent', 'Angelique Office'], $this->names());

        // Role Manager un-tick = soft delete of the grant row.
        RolePermission::where('role', 'office_admin')
            ->where('permission_key', PractitionerFfcRosterService::ROSTER_PERMISSION)->delete();
        $this->assertSame(['Agnes Agent'], $this->names());
    }

    public function test_inactive_and_deleted_users_stay_off_even_when_their_role_is_ticked(): void
    {
        $this->grant('agent');
        $this->user('Active Agent', 'agent');
        $this->user('Inactive Agent', 'agent', ['is_active' => false]);
        $this->user('Deleted Agent', 'agent')->delete();

        $this->assertSame(['Active Agent'], $this->names());
    }

    public function test_grants_and_users_are_isolated_per_agency(): void
    {
        $other = Agency::create(['name' => 'Cape Peninsula Properties', 'slug' => 'cape-peninsula']);
        $this->grant('office_admin', $other);          // other agency ticks office_admin
        $this->grant('agent');                          // this agency ticks agent only
        $this->user('Our Office', 'office_admin');
        $this->user('Our Agent', 'agent');
        User::factory()->create(['name' => 'Their Office', 'role' => 'office_admin', 'agency_id' => $other->id, 'is_active' => true]);

        $this->assertSame(['Our Agent'], $this->names());
        $this->assertSame(['Their Office'], $this->names($other));
    }

    public function test_no_grants_means_an_empty_roster(): void
    {
        $this->user('Agnes Agent', 'agent');
        $this->assertSame([], $this->names());
    }

    public function test_migration_grants_qualifying_roles_idempotently_and_rolls_back(): void
    {
        $roles = ['agent', 'branch_manager', 'admin', 'office_admin'];
        foreach ($roles as $r) {
            \App\Models\Role::create(['name' => $r, 'label' => ucfirst($r), 'agency_id' => $this->agency->id, 'is_owner' => false]);
        }
        $migration = require base_path('database/migrations/2026_10_05_200000_grant_ppra_inspection_pack_roster_permission.php');
        $count = fn () => RolePermission::where('agency_id', $this->agency->id)
            ->where('permission_key', PractitionerFfcRosterService::ROSTER_PERMISSION)->pluck('role')->sort()->values()->all();

        $migration->up();
        $migration->up(); // idempotent
        $this->assertSame(['admin', 'agent', 'branch_manager'], $count());

        $migration->down();
        $this->assertSame([], $count());
        $migration->up(); // re-up after rollback restores the trashed rows
        $this->assertSame(['admin', 'agent', 'branch_manager'], $count());
    }
}
