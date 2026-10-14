<?php

declare(strict_types=1);

namespace Tests\Feature\Permissions;

use App\Models\Agency;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Permissions\RoleDefaultsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Go-live blocker (14 Oct 2026, new-agency walk on QA1): a brand-new agency's agent got a 403 on
 * Calendar and Tasks and could not create/edit/archive a listing, because `role_defaults` in
 * config/corex-permissions.php — the ONE canonical default matrix a new agency is provisioned from
 * and every deploy merges into existing agencies — lacked the working keys HFC's agents only held
 * through hand-ticked grants.
 *
 * Guards: (1) a freshly provisioned agency's roles equal the default matrix and carry the working
 * surface; (2) the management keys are NOT in the agent default; (3) every default key is a defined
 * permission (an unknown key is silently skipped — that is how a default quietly goes missing);
 * (4) the deploy step adds missing keys to an EXISTING agency and never undoes an admin's un-tick
 * or touches a custom role.
 */
final class NewAgencyDefaultMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** What an agent needs to do the job: the Command Centre calendar + task board, own listings. */
    private const AGENT_WORKING_KEYS = [
        'command_center.view',
        'command_center.calendar.view', 'command_center.calendar.create', 'command_center.calendar.edit', 'command_center.calendar.delete',
        'command_center.tasks.view', 'command_center.tasks.create', 'command_center.tasks.edit', 'command_center.tasks.delete',
        'listings.create', 'listings.edit', 'listings.archive', 'rentals.archive',
        'rentals.view', 'rentals.create', 'rentals.edit',
    ];

    /** Management / compliance-officer / admin keys that must NOT come with the agent role. */
    private const AGENT_MUST_NOT_HAVE = [
        'command_center.tasks.assign', 'command_center.automation.manage', 'command_center.health.view',
        'compliance.fica.approve', 'compliance.fica.review', 'approve_rmcp', 'approve_policy',
        'contacts.delete', 'manage_p24', 'finance.view', 'users.view', 'roles.view', 'settings.view',
        'agency_api.manage', 'manage_activity_mappings',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        PermissionService::clearCache();
        Role::clearCache();
        foreach (['admin', 'branch_manager', 'agent', 'viewer', 'office_admin', 'assistant'] as $name) {
            Role::create(['name' => $name, 'label' => ucfirst($name), 'agency_id' => null]);
        }
        Role::clearCache();
    }

    private function newAgency(): Agency
    {
        return Agency::create(['name' => 'Cape ' . Str::random(5), 'slug' => 'cape-' . Str::random(6)]);
    }

    /** @return string[] */
    private function matrix(string $role): array
    {
        $cfg = config('corex-permissions');

        return array_values(array_unique(RoleDefaultsResolver::keysForDef(
            $cfg['role_defaults'][$role],
            array_column($cfg['permissions'], 'key')
        )));
    }

    /** @return string[] */
    private function granted(string $role, int $agencyId): array
    {
        return RolePermission::where('role', $role)->where('agency_id', $agencyId)->pluck('permission_key')->all();
    }

    public function test_a_freshly_provisioned_agency_gets_exactly_the_default_matrix_for_every_role(): void
    {
        $agency = $this->newAgency();

        foreach (['admin', 'branch_manager', 'agent', 'viewer'] as $role) {
            $this->assertEqualsCanonicalizing($this->matrix($role), $this->granted($role, $agency->id), "{$role} must equal the default matrix");
        }
        $this->assertSame([], $this->granted('assistant', $agency->id), 'assistant stays a zero-grant identity label');
    }

    public function test_the_new_agencys_agent_can_open_calendar_and_tasks_and_work_listings_and_rentals(): void
    {
        $agency = $this->newAgency();
        $agent = User::factory()->create(['agency_id' => $agency->id, 'role' => 'agent']);
        PermissionService::clearCache();

        foreach (self::AGENT_WORKING_KEYS as $key) {
            $this->assertTrue(PermissionService::userHasPermission($agent, $key), "new agency's agent must hold {$key}");
        }
    }

    public function test_management_keys_are_not_handed_to_a_new_agencys_agent(): void
    {
        $agency = $this->newAgency();
        $granted = $this->granted('agent', $agency->id);

        foreach (self::AGENT_MUST_NOT_HAVE as $key) {
            $this->assertNotContains($key, $granted, "{$key} is not an agent default");
        }
    }

    public function test_branch_manager_adds_assigning_tasks_and_viewer_gets_read_only_calendar(): void
    {
        $agency = $this->newAgency();

        $bm = $this->granted('branch_manager', $agency->id);
        $this->assertContains('command_center.tasks.assign', $bm);
        $this->assertContains('command_center.calendar.view', $bm);
        $this->assertNotContains('command_center.automation.manage', $bm, 'automation rules stay admin');

        $viewer = $this->granted('viewer', $agency->id);
        $this->assertContains('command_center.calendar.view', $viewer);
        $this->assertContains('command_center.tasks.view', $viewer);
        $this->assertNotContains('command_center.calendar.create', $viewer);
        $this->assertNotContains('command_center.tasks.create', $viewer);
    }

    public function test_every_default_key_is_a_defined_permission_so_none_is_silently_skipped(): void
    {
        $cfg = config('corex-permissions');
        $defined = array_flip(array_column($cfg['permissions'], 'key'));

        foreach (['branch_manager', 'agent', 'viewer', 'office_admin'] as $role) {
            foreach ($this->matrix($role) as $key) {
                $this->assertArrayHasKey($key, $defined, "{$role} default names a key the catalogue does not define: {$key}");
            }
        }
    }

    public function test_the_deploy_step_adds_missing_defaults_to_an_existing_agency_and_respects_its_choices(): void
    {
        $agency = $this->newAgency();

        // An existing agency provisioned before the defaults were completed: it lacks the working keys.
        RolePermission::where('role', 'agent')->where('agency_id', $agency->id)
            ->where('permission_key', 'like', 'command_center.%')->forceDelete();
        RolePermission::where('role', 'agent')->where('agency_id', $agency->id)
            ->whereIn('permission_key', ['listings.create', 'listings.edit', 'listings.archive'])->forceDelete();
        // Its admin deliberately un-ticked one default in Role Manager (a soft delete) and added a custom role.
        RolePermission::where('role', 'agent')->where('agency_id', $agency->id)->where('permission_key', 'rentals.archive')->delete();
        Role::create(['name' => 'intern', 'label' => 'Intern', 'agency_id' => $agency->id]);
        RolePermission::create(['role' => 'intern', 'permission_key' => 'listings.view', 'scope' => 'own', 'agency_id' => $agency->id]);
        // ...and added a grant that is not a default for the role.
        RolePermission::create(['role' => 'agent', 'permission_key' => 'finance.view', 'agency_id' => $agency->id]);
        PermissionService::clearCache();

        $exit = Artisan::call('corex:sync-permissions', ['--merge-defaults' => true]);
        $this->assertSame(0, $exit, Artisan::output());

        $agent = $this->granted('agent', $agency->id);
        foreach (self::AGENT_WORKING_KEYS as $key) {
            if ($key === 'rentals.archive') {
                continue;
            }
            $this->assertContains($key, $agent, "deploy sync must add {$key}");
        }
        $this->assertNotContains('rentals.archive', $agent, 'an admin\'s un-tick survives the deploy');
        $this->assertContains('finance.view', $agent, 'an agency\'s extra grant is never removed');
        $this->assertSame(['listings.view'], $this->granted('intern', $agency->id), 'a custom role is left alone');
    }

    public function test_the_reference_data_deploy_runs_the_permission_merge(): void
    {
        $exit = Artisan::call('deploy:sync-reference-data', ['--dry-run' => true]);
        $out = Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('corex:sync-permissions', $out);
        $this->assertStringContainsString('merge-defaults', $out);
    }
}
