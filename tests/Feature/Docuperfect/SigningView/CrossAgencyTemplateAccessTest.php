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
        // Data-scope grant (distinct from the can-do grants above) -- without this,
        // PermissionService::getDataScope('templates') resolves to NULL and
        // Template::scopeVisibleTo() shows nothing at all, for anyone. 'all' matches
        // the real product's admin-role default (agency-wide template visibility).
        RolePermission::updateOrCreate(
            ['role' => 'admin', 'permission_key' => 'templates.view', 'agency_id' => $agency->id],
            ['scope' => 'all']
        );
        PermissionService::clearCache();

        $admin = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true,
        ]);

        return compact('agency', 'branch', 'admin');
    }

    private function makeBranchTemplate(Branch $branch, string $name): Template
    {
        // agency_id stamped to match the branch, same as every real creation path
        // does post-2026-08-24 -- an unset agency_id here was a stale fixture shape
        // that happened to still pass under a NULL data-scope; it stopped matching
        // once a real 'templates.view' scope grant ('all') was added to
        // makeAgencyWithAdmin(), which resolves 'all'-scope visibility by agency_id.
        $template = Template::create([
            'name' => $name, 'template_type' => 'sales', 'page_count' => 1,
            'fields_json' => [], 'is_global' => false, 'agency_id' => $branch->agency_id,
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

    // ── 2026-08-24 mismatch fix: zero branches falls back to agency_id match ──
    // (scopeVisibleTo() already listed these; assertAccessibleBy() 404'd them —
    // the exact shape that stranded #52/#53/#55 and every PDF-upload/.docx-import
    // template, since neither creation path linked a branch.)

    public function test_assert_accessible_by_allows_a_branchless_template_matching_the_callers_agency(): void
    {
        $own = $this->makeAgencyWithAdmin('Own');
        $branchless = Template::create([
            'name' => 'Branchless own-agency template', 'template_type' => 'sales', 'page_count' => 1,
            'fields_json' => [], 'is_global' => false, 'agency_id' => $own['agency']->id,
        ]);

        $this->assertSame(0, $branchless->branches()->count());
        $branchless->assertAccessibleBy($own['admin']);
        $this->assertTrue(true);
    }

    public function test_assert_accessible_by_still_blocks_a_branchless_template_on_a_foreign_agency(): void
    {
        $own = $this->makeAgencyWithAdmin('Own');
        $foreign = $this->makeAgencyWithAdmin('Foreign');
        $branchless = Template::create([
            'name' => 'Branchless foreign-agency template', 'template_type' => 'sales', 'page_count' => 1,
            'fields_json' => [], 'is_global' => false, 'agency_id' => $foreign['agency']->id,
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $branchless->assertAccessibleBy($own['admin']);
    }

    public function test_assert_accessible_by_still_blocks_an_orphan_template_with_no_agency_at_all(): void
    {
        $own = $this->makeAgencyWithAdmin('Own');
        $orphan = Template::create([
            'name' => 'Orphan template', 'template_type' => 'sales', 'page_count' => 1,
            'fields_json' => [], 'is_global' => false, 'agency_id' => null,
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $orphan->assertAccessibleBy($own['admin']);
    }

    public function test_assert_accessible_by_still_blocks_a_branch_scoped_template_even_with_a_matching_agency_id(): void
    {
        // The direction that must NOT break: once a template HAS branches
        // assigned, that is an explicit narrowing -- the agency_id fallback only
        // applies to the zero-branches case. A branch on a foreign agency still
        // blocks even if agency_id happens to be set (mismatched, or stale).
        $own = $this->makeAgencyWithAdmin('Own');
        $foreign = $this->makeAgencyWithAdmin('Foreign');
        $scoped = Template::create([
            'name' => 'Foreign-branch, own-agency_id template', 'template_type' => 'sales', 'page_count' => 1,
            'fields_json' => [], 'is_global' => false, 'agency_id' => $own['agency']->id,
        ]);
        $scoped->branches()->attach($foreign['branch']->id);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $scoped->assertAccessibleBy($own['admin']);
    }

    // ── 2026-08-24 THE WHOLE CONTRACT: agency-wide, never platform-wide ──
    // An agency-wide (zero-branches) template must be completely invisible and
    // unreachable to a foreign agency across every path -- list, open, archive,
    // copy -- and completely reachable to every branch of its OWN agency across
    // the same paths. Over-restricting (blocking a same-agency, different-branch
    // user) would be its own bug -- agency-wide is the point.

    public function test_agency_wide_template_is_fully_unreachable_to_a_foreign_agency(): void
    {
        PermissionService::forceProductionPosture();
        $own = $this->makeAgencyWithAdmin('Own');
        $foreign = $this->makeAgencyWithAdmin('Foreign');

        $template = Template::create([
            'name' => 'Foreign Agency-Wide Mandate', 'template_type' => 'sales', 'page_count' => 1,
            'fields_json' => [], 'is_global' => false, 'agency_id' => $foreign['agency']->id,
        ]);
        $this->assertSame(0, $template->branches()->count(), 'agency-wide means zero branches, not is_global');

        // LIST — must not appear at all.
        $this->actingAs($own['admin'])
            ->get(route('docuperfect.templates.index'))
            ->assertOk()
            ->assertDontSee('Foreign Agency-Wide Mandate');

        // OPEN — must 404.
        $this->actingAs($own['admin'])
            ->get(route('docuperfect.templates.edit', ['id' => $template->id]))
            ->assertNotFound();

        // ARCHIVE — must 404, and must not archive it.
        $this->actingAs($own['admin'])
            ->postJson(route('docuperfect.templates.archive', ['id' => $template->id]))
            ->assertNotFound();
        $this->assertNull($template->fresh()->archived_at);

        // COPY — must 404, and must not create a copy.
        $this->actingAs($own['admin'])
            ->postJson(route('docuperfect.templates.copy', ['id' => $template->id]))
            ->assertNotFound();
        $this->assertDatabaseMissing('docuperfect_templates', ['name' => 'Foreign Agency-Wide Mandate (Copy)']);
    }

    public function test_agency_wide_template_is_fully_reachable_to_a_different_branch_of_its_own_agency(): void
    {
        PermissionService::forceProductionPosture();
        $own = $this->makeAgencyWithAdmin('Own');

        // A second branch, and a second admin on THAT branch -- same agency, never
        // linked to the template via any branch pivot row.
        $otherBranch = Branch::create(['agency_id' => $own['agency']->id, 'name' => 'Own — Other Branch']);
        $otherBranchAdmin = User::factory()->create([
            'agency_id' => $own['agency']->id, 'branch_id' => $otherBranch->id, 'role' => 'admin', 'is_active' => true,
        ]);

        $template = Template::create([
            'name' => 'Own Agency-Wide Mandate', 'template_type' => 'sales', 'page_count' => 1,
            'fields_json' => [], 'is_global' => false, 'agency_id' => $own['agency']->id,
        ]);
        $this->assertSame(0, $template->branches()->count());

        // LIST — must appear, even though this admin is on a different branch.
        $this->actingAs($otherBranchAdmin)
            ->get(route('docuperfect.templates.index'))
            ->assertOk()
            ->assertSee('Own Agency-Wide Mandate');

        // OPEN — must succeed.
        $this->actingAs($otherBranchAdmin)
            ->get(route('docuperfect.templates.edit', ['id' => $template->id]))
            ->assertOk();

        // ARCHIVE — must succeed.
        $this->actingAs($otherBranchAdmin)
            ->postJson(route('docuperfect.templates.archive', ['id' => $template->id]))
            ->assertRedirect();
        $this->assertNotNull($template->fresh()->archived_at);
        $template->update(['archived_at' => null]); // reset for the next assertion

        // COPY — must succeed.
        $this->actingAs($otherBranchAdmin)
            ->postJson(route('docuperfect.templates.copy', ['id' => $template->id]))
            ->assertRedirect();
        $this->assertDatabaseHas('docuperfect_templates', ['name' => 'Own Agency-Wide Mandate (Copy)']);
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

        // agency_id stamped to match, same as every real creation path does
        // post-2026-08-24 -- see the note on makeBranchTemplate() above.
        $foreignTemplate = Template::create([
            'name' => 'Foreign matching template', 'template_type' => 'sales', 'page_count' => 1,
            'fields_json' => [], 'is_global' => false, 'document_type_id' => $docType->id,
            'agency_id' => $foreign['agency']->id,
        ]);
        $foreignTemplate->branches()->attach($foreign['branch']->id);

        $ownTemplate = Template::create([
            'name' => 'Own matching template', 'template_type' => 'sales', 'page_count' => 1,
            'fields_json' => [], 'is_global' => false, 'document_type_id' => $docType->id,
            'agency_id' => $own['agency']->id,
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
