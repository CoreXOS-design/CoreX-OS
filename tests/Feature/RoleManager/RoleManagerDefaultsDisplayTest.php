<?php

declare(strict_types=1);

namespace Tests\Feature\RoleManager;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\CoreXPermission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-401 — Role Manager must show the access that is IN FORCE.
 *
 * Before: a role with no grant row for a `<module>.view` key showed "None" even where the code applies
 * a default ("own" / "all"), and a role holding a `.view` row with no stored scope showed "All" while
 * the runtime reads NULL — and saving the matrix with no changes then WROTE that "all".
 *
 * The matrix state is built in JavaScript, so the contract is tested on both sides of it:
 *   - the server hands the page the explicit scopes ($scopeGranted) and the code's defaults
 *     ($scopeDefaults); effective = explicit ?? default — and that effective value is compared with
 *     what PermissionService really answers at runtime for a user of that role;
 *   - the page posts, for a role whose scope is only DEFAULT, the unchanged grant bit and
 *     scopes[key]=default; the save must leave every stored row exactly as it was.
 *
 * Seeds the permission system for real (see RoleManagerFunctionalTest) — an empty grants table is
 * allow-all in the suite and would make every runtime assertion vacuous.
 */
final class RoleManagerDefaultsDisplayTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedPermissionSystem();

        $this->agency = Agency::create(['name' => 'Coastal ' . uniqid(), 'slug' => 'coastal-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $mk = fn (string $role) => User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => $role, 'is_active' => true,
        ]);
        $this->admin = $mk('admin');
        $this->agent = $mk('agent');

        $this->assertGreaterThan(0, RolePermission::where('agency_id', $this->agency->id)->count(), 'agency must be provisioned its own grants');
    }

    protected function tearDown(): void
    {
        Role::clearCache();
        PermissionService::clearCache();
        parent::tearDown();
    }

    private function seedPermissionSystem(): void
    {
        $now = now();
        $roles = [
            ['name' => 'super_admin',    'label' => 'System Owner',   'is_owner' => 1, 'can_be_deleted' => 0, 'sort_order' => 1],
            ['name' => 'admin',          'label' => 'Administrator',  'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 2],
            ['name' => 'branch_manager', 'label' => 'Branch Manager', 'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 3],
            ['name' => 'agent',          'label' => 'Agent',          'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 4],
            ['name' => 'viewer',         'label' => 'Viewer',         'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 5],
            ['name' => 'office_admin',   'label' => 'Office Staff',   'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 6],
        ];
        foreach ($roles as &$r) {
            $r['agency_id'] = null;
            $r['created_at'] = $now;
            $r['updated_at'] = $now;
        }
        DB::table('roles')->insert($roles);
        Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);
        Role::clearCache();
        PermissionService::clearCache();
    }

    /** Remove the agent role's row for $key in this agency (soft delete, like Role Manager does). */
    private function revoke(string $key): void
    {
        RolePermission::where('agency_id', $this->agency->id)->where('role', 'agent')->where('permission_key', $key)->delete();
        PermissionService::clearCache();
    }

    /** Set the agent role's row for $key in this agency (restoring a trashed one). */
    private function grant(string $key, ?string $scope): void
    {
        RolePermission::withTrashed()->updateOrCreate(
            ['agency_id' => $this->agency->id, 'role' => 'agent', 'permission_key' => $key],
            ['scope' => $scope, 'deleted_at' => null],
        );
        PermissionService::clearCache();
    }

    /** @return array<string,mixed> the page's view data */
    private function page(): array
    {
        PermissionService::clearCache();
        $response = $this->actingAs($this->admin)->get(route('corex.role-manager'))->assertOk();

        return [
            'granted'  => $response->viewData('scopeGranted')->toArray(),
            'defaults' => $response->viewData('scopeDefaults')->toArray(),
            'html'     => $response->getContent(),
        ];
    }

    /** What the matrix shows for (viewKey, agent): the explicit scope, else the code's default; null = None. */
    private function shown(array $page, string $viewKey): ?string
    {
        $explicit = $page['granted'][$viewKey]['agent'] ?? null;

        return $explicit ?? ($page['defaults'][$viewKey]['scope'] ?? null);
    }

    public function test_a_defaulted_permission_shows_the_scope_the_runtime_really_applies(): void
    {
        // Wrapper-backed modules: their fallback in PermissionService IS the runtime default.
        $cases = [
            'deeds_capture.view'           => fn () => PermissionService::deedsCaptureScope($this->agent),
            'contact_rental_history.view'  => fn () => PermissionService::contactRentalHistoryScope($this->agent),
            'command_center.tasks.view'    => fn () => PermissionService::taskScope($this->agent),
            'command_center.calendar.view' => fn () => PermissionService::calendarScope($this->agent),
            'market_intelligence.view'     => fn () => PermissionService::marketIntelligenceScope($this->agent),
            'outreach_canvassing.view'     => fn () => PermissionService::outreachCanvassingScope($this->agent),
            'dr2_unfiled_emails.view'      => fn () => PermissionService::dr2UnfiledEmailsScope($this->agent),
        ];

        foreach (array_keys($cases) as $key) {
            $this->revoke($key);
        }

        $page = $this->page();

        foreach ($cases as $key => $runtime) {
            $this->assertNull($page['granted'][$key]['agent'] ?? null, "{$key}: nothing stored for the agent role");
            $this->assertSame($runtime(), $this->shown($page, $key), "{$key}: the matrix must show what the runtime applies");
        }

        // The ticket's confirmed case: the runtime default is agency-wide, and the matrix must not say None.
        $this->assertSame('all', $this->shown($page, 'contact_rental_history.view'));
    }

    public function test_an_explicit_grant_shows_as_itself_and_matches_the_runtime(): void
    {
        $this->grant('fica.view', 'branch');

        $page = $this->page();

        $this->assertSame('branch', $this->shown($page, 'fica.view'));
        $this->assertSame('branch', PermissionService::getDataScope($this->agent, 'fica'));
    }

    public function test_a_denied_permission_shows_none_and_the_runtime_applies_no_scope(): void
    {
        $this->revoke('deals.view');

        $page = $this->page();

        $this->assertNull($this->shown($page, 'deals.view'), 'deals.view with no row: the code refuses (None)');
        $this->assertNull(PermissionService::getDataScope($this->agent, 'deals'));
    }

    public function test_a_view_row_with_no_stored_scope_shows_the_default_not_all(): void
    {
        // Granted (the gate passes) but the scope is NULL — runtime reads null → the module's own default.
        $this->grant('deeds_capture.view', null);

        $page = $this->page();

        $this->assertNull($page['granted']['deeds_capture.view']['agent'] ?? null);
        $this->assertSame('own', $this->shown($page, 'deeds_capture.view'));
        $this->assertSame('own', PermissionService::deedsCaptureScope($this->agent));
    }

    public function test_saving_the_matrix_without_changes_changes_nothing(): void
    {
        // Give the role every shape: a NULL-scope view row, an explicit scope, a revoked key (no row).
        $this->grant('deeds_capture.view', null);
        $this->grant('fica.view', 'branch');
        $this->revoke('contact_rental_history.view');

        $before = $this->activeRows();
        $scopeBefore = PermissionService::deedsCaptureScope($this->agent);

        $page = $this->page();

        // Build the payload exactly as the page's hidden inputs would (role-manager.blade.php).
        $payload = ['role' => 'agent', 'permissions' => [], 'scopes' => []];
        $granted = RolePermission::where('agency_id', $this->agency->id)->where('role', 'agent')->pluck('permission_key')->flip();
        foreach (CoreXPermission::all() as $perm) {
            $isViewAction = $perm->type === 'action' && str_ends_with($perm->key, '.view');
            if (! $isViewAction) {
                $payload['permissions'][$perm->key] = $granted->has($perm->key) ? '1' : '0';
                continue;
            }
            $explicit = $page['granted'][$perm->key]['agent'] ?? null;
            if ($explicit !== null) {
                $payload['permissions'][$perm->key] = '1';
                $payload['scopes'][$perm->key] = $explicit;
            } else {
                // Only the default is on show → unchanged grant bit, scope=default.
                $payload['permissions'][$perm->key] = $granted->has($perm->key) ? '1' : '0';
                $payload['scopes'][$perm->key] = 'default';
            }
        }

        $this->actingAs($this->admin)->postJson(route('corex.role-manager.save'), $payload)->assertOk();

        $this->assertSame($before, $this->activeRows(), 'a save with nothing changed must leave every grant row exactly as it was');
        $this->assertSame($scopeBefore, PermissionService::deedsCaptureScope($this->agent), 'nobody\'s actual access changed');
        $this->assertNull(
            RolePermission::where('agency_id', $this->agency->id)->where('role', 'agent')->where('permission_key', 'deeds_capture.view')->value('scope'),
            'the NULL-scope row stays NULL (it used to be rewritten as "all")'
        );
        $this->assertFalse(
            RolePermission::where('agency_id', $this->agency->id)->where('role', 'agent')->where('permission_key', 'contact_rental_history.view')->exists(),
            'a revoked key stays revoked — the default on screen is never saved as a grant'
        );
    }

    public function test_choosing_a_scope_still_saves_it(): void
    {
        $this->revoke('contact_rental_history.view');

        $payload = ['role' => 'agent', 'permissions' => ['contact_rental_history.view' => '1'], 'scopes' => ['contact_rental_history.view' => 'branch']];
        $this->actingAs($this->admin)->postJson(route('corex.role-manager.save'), $payload)->assertOk();

        $this->assertSame('branch', PermissionService::getDataScope($this->agent, 'contact_rental_history'));
    }

    public function test_every_wrapper_fallback_comes_from_the_defaults_map(): void
    {
        foreach (['command_center.calendar', 'command_center.tasks', 'contact_rental_history', 'market_intelligence', 'outreach_canvassing', 'deeds_capture', 'dr2_unfiled_emails'] as $module) {
            $this->revoke($module . '.view');
        }

        $this->assertSame(PermissionService::ungrantedScopeDefault('command_center.calendar')['scope'], PermissionService::calendarScope($this->agent));
        $this->assertSame(PermissionService::ungrantedScopeDefault('command_center.tasks')['scope'], PermissionService::taskScope($this->agent));
        $this->assertSame(PermissionService::ungrantedScopeDefault('contact_rental_history')['scope'], PermissionService::contactRentalHistoryScope($this->agent));
        $this->assertSame(PermissionService::ungrantedScopeDefault('market_intelligence')['scope'], PermissionService::marketIntelligenceScope($this->agent));
        $this->assertSame(PermissionService::ungrantedScopeDefault('outreach_canvassing')['scope'], PermissionService::outreachCanvassingScope($this->agent));
        $this->assertSame(PermissionService::ungrantedScopeDefault('deeds_capture')['scope'], PermissionService::deedsCaptureScope($this->agent));
        $this->assertSame(PermissionService::ungrantedScopeDefault('dr2_unfiled_emails')['scope'], PermissionService::dr2UnfiledEmailsScope($this->agent));
    }

    public function test_model_level_own_fallbacks_match_the_map(): void
    {
        $this->revoke('fica.view');
        $this->assertSame('own', \App\Models\FicaSubmission::ficaScopeFor($this->agent));
        $this->assertSame('own', PermissionService::ungrantedScopeDefault('fica')['scope']);
    }

    public function test_a_key_nothing_reads_the_breadth_of_is_marked_as_plain_on_off(): void
    {
        $d = PermissionService::ungrantedScopeDefault('users');

        $this->assertFalse($d['read']);
        $this->assertStringContainsString('on/off', $d['note']);
    }

    public function test_the_page_marks_defaults_and_explains_access_type_view_keys(): void
    {
        $page = $this->page();

        $this->assertStringContainsString('defaultLine', $page['html']);
        $this->assertStringContainsString('scopeIsDefault', $page['html']);
        // rental_applications.view is an ACCESS-type key (no scope picker): the note says what applies.
        $this->assertStringContainsString('Default breadth when no scope is stored', $page['html']);
    }

    /** @return array<string,?string> active grant rows of the agent role in this agency: key => scope */
    private function activeRows(): array
    {
        return RolePermission::where('agency_id', $this->agency->id)->where('role', 'agent')
            ->orderBy('permission_key')->pluck('scope', 'permission_key')->all();
    }
}
