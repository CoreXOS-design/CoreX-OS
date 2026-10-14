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
 * surface; (2) per Johan (14 Oct) every role carries EVERYTHING HFC's corresponding role holds, minus platform-owner-only and retired keys; (3) every default key is a defined
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

    /**
     * Johan, 14 Oct: "each gets everything - they decide in setup what they want and need." The default matrix
     * carries EVERYTHING HFC's corresponding role holds - these are the keys that were first held back as
     * manager / officer / HFC-specific. Agencies trim per role in Role Manager afterwards.
     */
    private const AGENT_ALSO_GETS = [
        'command_center.tasks.assign', 'command_center.automation.manage', 'command_center.health.view',
        'compliance.fica.approve', 'compliance.fica.review', 'approve_rmcp', 'approve_policy', 'compliance.whistleblow.configure',
        'contacts.delete', 'contacts.import', 'contacts.archive', 'contacts.reassign_agent', 'properties.archive', 'delete_properties',
        'manage_p24', 'p24.manage', 'finance.view', 'users.view', 'roles.view', 'settings.view',
        'agency_api.manage', 'testimonials.publish', 'marketing_suppressions.manage', 'client_app.create_login',
        'agency.authorize_external_access', 'tv_messages.create', 'targets.create', 'manage_targets', 'access_branch_assignments',
        'deeds_capture.access', 'create_deals', 'deals_v2.create', 'documents.edit',
        'access_listing_stock', 'access_import_listings', 'import_listings',
    ];

    /** Platform-owner-only keys + the three retired keys: never part of any non-admin default. */
    private const NEVER_IN_A_ROLE_DEFAULT = [
        'manage_agency_switching', 'reveal_mailbox_credential', 'reveal_backup_password', 'agency_setup.track',
        'manage_reference_sources', 'templates.transfer',
        'view_rentals', 'access_rental_signatures', 'ppra_employment_letters.sign_as_principal',
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

    public function test_a_new_agencys_agent_also_gets_the_keys_first_held_back_as_manager_or_hfc_specific(): void
    {
        $agency = $this->newAgency();
        $granted = $this->granted('agent', $agency->id);

        foreach (self::AGENT_ALSO_GETS as $key) {
            $this->assertContains($key, $granted, "{$key} is part of the agent default (HFC's agent holds it)");
        }
    }

    public function test_no_role_default_carries_a_platform_owner_only_or_retired_key(): void
    {
        $agency = $this->newAgency();

        foreach (['admin', 'branch_manager', 'agent', 'viewer', 'office_admin'] as $role) {
            $this->assertSame([], array_values(array_intersect(self::NEVER_IN_A_ROLE_DEFAULT, $this->granted($role, $agency->id))), "{$role}");
        }
    }

    public function test_office_admin_does_not_start_able_to_rewrite_roles_or_impersonate(): void
    {
        $agency = $this->newAgency();
        $granted = $this->granted('office_admin', $agency->id);

        // Held out pending Johan's word: they would let the first office admin make themselves admin on day one.
        foreach (['access_role_manager', 'edit_permissions', 'change_user_roles', 'roles.edit', 'impersonate_users'] as $key) {
            $this->assertNotContains($key, $granted, $key);
        }
        $this->assertContains('roles.view', $granted, 'role VIEWS are part of "everything"');
    }

    public function test_viewer_and_office_admin_carry_everything_hfcs_do(): void
    {
        $agency = $this->newAgency();

        foreach (['viewer' => ['contacts.create', 'compliance.fica.approve', 'finance.view', 'command_center.calendar.create'],
                  'office_admin' => ['contacts.edit', 'compliance.fica.send', 'users.view', 'command_center.tasks.create']] as $role => $keys) {
            $granted = $this->granted($role, $agency->id);
            foreach ($keys as $key) {
                $this->assertContains($key, $granted, "{$role} default carries {$key} (HFC's {$role} holds it)");
            }
        }
    }

    public function test_branch_manager_and_viewer_get_the_calendar_and_task_board(): void
    {
        $agency = $this->newAgency();

        $bm = $this->granted('branch_manager', $agency->id);
        $this->assertContains('command_center.tasks.assign', $bm);
        $this->assertContains('command_center.calendar.view', $bm);
        $this->assertContains('command_center.automation.manage', $bm);

        $viewer = $this->granted('viewer', $agency->id);
        $this->assertContains('command_center.calendar.view', $viewer);
        $this->assertContains('command_center.tasks.view', $viewer);
        $this->assertContains('command_center.calendar.create', $viewer, "HFC's viewer holds it - 'each gets everything'");
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
        RolePermission::create(['role' => 'agent', 'permission_key' => 'view_rentals', 'agency_id' => $agency->id]);
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
        $this->assertContains('view_rentals', $agent, 'an agency\'s extra grant is never removed');
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
