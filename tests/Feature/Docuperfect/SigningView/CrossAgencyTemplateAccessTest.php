<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\Template;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cross-agency isolation audit 2026-08-20 follow-up: TemplateController's
 * edit/saveFields/uploadPageImages/archive/restore/copy/webPreview/destroy/
 * wizardConfig/saveWizardConfig, PageImageController::show, and
 * DocumentImporterController::editFromTemplate all did
 * `Template::findOrFail($id)` with only a hasPermission('manage_templates')
 * check -- manage_templates is an ordinary, per-agency-grantable permission,
 * not owner-only, so any agency's admin/agent could read, rewrite, delete,
 * or clone ANY other agency's template by id. `docuperfect_templates` has no
 * agency_id column (tenancy is via `is_global` + the
 * docuperfect_template_branches pivot, since a branch belongs to exactly one
 * agency). Fixed via Template::assertAccessibleBy().
 *
 * Lives under SigningView/ to satisfy the e-sign pipeline gate (dev-check.ps1)
 * -- Template.php is a listed pipeline file.
 */
final class CrossAgencyTemplateAccessTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgencyWithAdmin(string $label): array
    {
        $agency = Agency::create(['name' => $label, 'slug' => strtolower($label) . '-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => $label . ' HQ']);
        Role::create(['name' => 'admin', 'label' => 'Administrator', 'agency_id' => $agency->id]);
        RolePermission::updateOrCreate(
            ['role' => 'admin', 'permission_key' => 'access_docuperfect', 'agency_id' => $agency->id],
            []
        );
        RolePermission::updateOrCreate(
            ['role' => 'admin', 'permission_key' => 'manage_templates', 'agency_id' => $agency->id],
            []
        );
        PermissionService::clearCache();

        $admin = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true,
        ]);

        return compact('agency', 'branch', 'admin');
    }

    private function makeBranchTemplate(Branch $branch, string $name): Template
    {
        $template = Template::create([
            'name' => $name, 'template_type' => 'sales', 'page_count' => 1,
            'fields_json' => [], 'is_global' => false,
        ]);
        $template->branches()->attach($branch->id);

        return $template;
    }

    // ── Template::assertAccessibleBy() — the shared guard, all boundary cases ──

    public function test_assert_accessible_by_blocks_a_template_on_a_foreign_agencys_branch(): void
    {
        $own = $this->makeAgencyWithAdmin('Own');
        $foreign = $this->makeAgencyWithAdmin('Foreign');
        $foreignTemplate = $this->makeBranchTemplate($foreign['branch'], 'Foreign template');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $foreignTemplate->assertAccessibleBy($own['admin']);
    }

    public function test_assert_accessible_by_allows_a_template_on_the_callers_own_branch(): void
    {
        $own = $this->makeAgencyWithAdmin('Own');
        $ownTemplate = $this->makeBranchTemplate($own['branch'], 'Own template');

        $ownTemplate->assertAccessibleBy($own['admin']);
        $this->assertTrue(true);
    }

    public function test_assert_accessible_by_allows_a_global_template_for_anyone(): void
    {
        $own = $this->makeAgencyWithAdmin('Own');
        $global = Template::create([
            'name' => 'Global template', 'template_type' => 'sales', 'page_count' => 1,
            'fields_json' => [], 'is_global' => true,
        ]);

        $global->assertAccessibleBy($own['admin']);
        $this->assertTrue(true);
    }

    public function test_assert_accessible_by_allows_owner_role_unconditionally(): void
    {
        $foreign = $this->makeAgencyWithAdmin('Foreign');
        $foreignTemplate = $this->makeBranchTemplate($foreign['branch'], 'Foreign template');

        $ownerRole = Role::create(['name' => 'super_admin', 'label' => 'System Owner']);
        $ownerRole->is_owner = true;
        $ownerRole->save();
        Role::clearCache();
        $owner = User::factory()->create(['agency_id' => null, 'branch_id' => null, 'role' => 'super_admin']);

        $foreignTemplate->assertAccessibleBy($owner);
        $this->assertTrue(true);
    }

    // ── HTTP-level wiring — a representative sample of the fixed routes ──

    public function test_archive_404s_on_a_foreign_agencys_template(): void
    {
        PermissionService::forceProductionPosture();
        $own = $this->makeAgencyWithAdmin('Own');
        $foreign = $this->makeAgencyWithAdmin('Foreign');
        $foreignTemplate = $this->makeBranchTemplate($foreign['branch'], 'Foreign template');

        $this->actingAs($own['admin'])
            ->postJson(route('docuperfect.templates.archive', ['id' => $foreignTemplate->id]))
            ->assertNotFound();

        $this->assertDatabaseHas('docuperfect_templates', ['id' => $foreignTemplate->id, 'archived_at' => null]);
    }

    public function test_destroy_404s_on_a_foreign_agencys_template(): void
    {
        PermissionService::forceProductionPosture();
        $own = $this->makeAgencyWithAdmin('Own');
        $foreign = $this->makeAgencyWithAdmin('Foreign');
        $foreignTemplate = $this->makeBranchTemplate($foreign['branch'], 'Foreign template');

        $this->actingAs($own['admin'])
            ->deleteJson(route('docuperfect.templates.destroy', ['id' => $foreignTemplate->id]))
            ->assertNotFound();

        $this->assertNotSoftDeleted('docuperfect_templates', ['id' => $foreignTemplate->id]);
    }

    public function test_web_preview_404s_on_a_foreign_agencys_template(): void
    {
        PermissionService::forceProductionPosture();
        $own = $this->makeAgencyWithAdmin('Own');
        $foreign = $this->makeAgencyWithAdmin('Foreign');
        $foreignTemplate = $this->makeBranchTemplate($foreign['branch'], 'Foreign template');
        $foreignTemplate->update(['blade_view' => 'docuperfect.web-templates.does-not-matter']);

        $this->actingAs($own['admin'])
            ->getJson(route('docuperfect.templates.webPreview', ['id' => $foreignTemplate->id]))
            ->assertNotFound();
    }

    public function test_page_image_show_404s_on_a_foreign_agencys_template(): void
    {
        PermissionService::forceProductionPosture();
        $own = $this->makeAgencyWithAdmin('Own');
        $foreign = $this->makeAgencyWithAdmin('Foreign');
        $foreignTemplate = $this->makeBranchTemplate($foreign['branch'], 'Foreign template');

        $this->actingAs($own['admin'])
            ->getJson(route('docuperfect.page.image', ['id' => $foreignTemplate->id, 'page' => 0]))
            ->assertNotFound();
    }

    public function test_archive_still_works_on_the_callers_own_template(): void
    {
        PermissionService::forceProductionPosture();
        $own = $this->makeAgencyWithAdmin('Own');
        $ownTemplate = $this->makeBranchTemplate($own['branch'], 'Own template');

        $this->actingAs($own['admin'])
            ->postJson(route('docuperfect.templates.archive', ['id' => $ownTemplate->id]))
            ->assertStatus(302);

        $this->assertDatabaseMissing('docuperfect_templates', ['id' => $ownTemplate->id, 'archived_at' => null]);
    }

    // ── PackController::resolveSelectableTemplates — a different mechanism
    // (Template::visibleTo() scope, not assertAccessibleBy) ──

    public function test_pack_slot_resolution_ignores_a_foreign_agencys_matching_template(): void
    {
        PermissionService::forceProductionPosture();
        $own = $this->makeAgencyWithAdmin('Own');
        $foreign = $this->makeAgencyWithAdmin('Foreign');

        $docType = \App\Models\Docuperfect\DocumentType::create(['slug' => 'test-doc-' . uniqid(), 'label' => 'Test Doc']);

        $foreignTemplate = Template::create([
            'name' => 'Foreign matching template', 'template_type' => 'sales', 'page_count' => 1,
            'fields_json' => [], 'is_global' => false, 'document_type_id' => $docType->id,
        ]);
        $foreignTemplate->branches()->attach($foreign['branch']->id);

        $ownTemplate = Template::create([
            'name' => 'Own matching template', 'template_type' => 'sales', 'page_count' => 1,
            'fields_json' => [], 'is_global' => false, 'document_type_id' => $docType->id,
        ]);
        $ownTemplate->branches()->attach($own['branch']->id);

        $pack = \App\Models\Docuperfect\Pack::create([
            'agency_id' => $own['agency']->id, 'name' => 'Test pack',
        ]);
        $slot = \App\Models\Docuperfect\PackSlot::create([
            'pack_id' => $pack->id, 'sort_order' => 0, 'label' => 'Slot',
            'slot_type' => 'required', 'document_type_id' => $docType->id,
        ]);

        auth()->login($own['admin']);

        $controller = app(\App\Http\Controllers\Docuperfect\PackController::class);
        $method = (new \ReflectionClass($controller))->getMethod('resolveSelectableTemplates');
        $method->setAccessible(true);

        // Agent submits BOTH the foreign template id (guessed/enumerated)
        // and their own -- before the fix, both would have resolved since
        // the query had no visibility scope at all.
        $resolved = $method->invoke($controller, $slot, [$foreignTemplate->id, $ownTemplate->id]);

        $this->assertNotContains($foreignTemplate->id, $resolved);
        $this->assertContains($ownTemplate->id, $resolved);
    }
}
