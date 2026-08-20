<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\TargetController;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Cross-agency isolation audit 2026-08-20, finding M2:
 * TargetController::activitySetup() built its branch-selector dropdown via a
 * raw DB::table('branches') query with no agency filter -- listing every
 * branch across every agency -- while the SIBLING check ~20 lines below
 * (which validates a submitted branch_id before activitySetupSave() writes)
 * correctly scoped to the caller's own agency. Fixed by switching to
 * Branch::query(), which is agency-scoped automatically (BelongsToAgency).
 *
 * Note: activitySetup()'s own GET route currently redirects to
 * admin.targets.activity.definitions before ever reaching this method (see
 * routes/web.php ~1336), so this method is presently unreachable over HTTP.
 * The bug is real and worth fixing regardless (routing is one edit away from
 * exposing it, and the identical pattern living 20 lines apart is exactly
 * the kind of drift this audit exists to catch) -- this test calls the
 * controller method directly since no live route exercises it.
 */
final class TargetControllerActivitySetupBranchListTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgencyAdminWithAllScope(string $label): array
    {
        $agency = Agency::create(['name' => $label, 'slug' => strtolower($label) . '-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => $label . ' HQ']);
        Role::create(['name' => 'admin', 'label' => 'Administrator', 'agency_id' => $agency->id]);
        RolePermission::updateOrCreate(
            ['role' => 'admin', 'permission_key' => 'manage_targets', 'agency_id' => $agency->id],
            []
        );
        RolePermission::updateOrCreate(
            ['role' => 'admin', 'permission_key' => 'targets.view', 'agency_id' => $agency->id],
            ['scope' => 'all']
        );
        PermissionService::clearCache();

        $admin = User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        return compact('agency', 'branch', 'admin');
    }

    public function test_branch_selector_only_lists_the_callers_own_agency_branches(): void
    {
        PermissionService::forceProductionPosture();

        $own = $this->makeAgencyAdminWithAllScope('Own');
        $foreign = $this->makeAgencyAdminWithAllScope('Foreign');

        $this->actingAs($own['admin']);

        $response = app(TargetController::class)->activitySetup(Request::create('/admin/targets/activity-setup', 'GET'));
        $data = $response->getData();

        $branchNames = collect($data['branches'])->pluck('name')->all();

        $this->assertContains($own['branch']->name, $branchNames);
        $this->assertNotContains($foreign['branch']->name, $branchNames);
    }
}
