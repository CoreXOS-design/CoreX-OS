<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\CdsDraft;
use App\Models\Docuperfect\ImportDraft;
use App\Models\Docuperfect\Template;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Docuperfect\DocumentTemplateGenerator;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 2026-08-24 — all three template-creation paths used to leave a new template
 * unreachable the instant it was created: is_global=false (or, for the CDS
 * builder, is_global=true but agency_id never stamped), zero branches linked.
 * Template::assertAccessibleBy() then 404'd it for anyone but an owner-role
 * user, even though Template::scopeVisibleTo() had already listed it.
 *
 * First fix attempt defaulted every path to is_global=true -- wrong, and
 * caught before it shipped: is_global bypasses AGENCY scoping entirely (not
 * just branch scoping -- see CrossAgencyTemplateAccessTest), so it would have
 * made every new HFC document visible to every future agency on the platform.
 *
 * Correct fix: agency_id always stamped to the creator's effective agency;
 * is_global stays false. Agency-wide reachability (every branch of the
 * creator's own agency) comes from Template::assertAccessibleBy()'s
 * zero-branches -> agency-match fallback, which never crosses agency lines.
 */
final class TemplateCreationDefaultsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{agency: Agency, branch: Branch, manager: User} */
    private function seedAgencyWithManager(string $label): array
    {
        $agency = Agency::create(['name' => $label, 'slug' => strtolower($label) . '-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => $label . ' HQ']);
        Role::create(['name' => 'template_manager', 'label' => 'Template Manager', 'agency_id' => $agency->id]);
        RolePermission::updateOrCreate(
            ['role' => 'template_manager', 'permission_key' => 'access_docuperfect', 'agency_id' => $agency->id],
            []
        );
        RolePermission::updateOrCreate(
            ['role' => 'template_manager', 'permission_key' => 'manage_templates', 'agency_id' => $agency->id],
            []
        );
        PermissionService::clearCache();

        $manager = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'template_manager', 'is_active' => true,
        ]);

        return compact('agency', 'branch', 'manager');
    }

    // ── Path 1: plain PDF upload (TemplateController::upload()) ──

    public function test_pdf_upload_creates_a_reachable_template(): void
    {
        ['agency' => $agency, 'manager' => $user] = $this->seedAgencyWithManager('Uploader');

        $this->actingAs($user)->post(route('docuperfect.templates.upload'), [
            'pdf' => \Illuminate\Http\UploadedFile::fake()->create('mandate.pdf', 10, 'application/pdf'),
            'name' => 'Uploaded Mandate',
        ])->assertRedirect();

        $template = Template::where('name', 'Uploaded Mandate')->firstOrFail();
        $this->assertFalse((bool) $template->is_global, 'a new upload must never default to platform-wide');
        $this->assertSame($agency->id, $template->agency_id, 'agency_id must be stamped to the creator\'s agency');

        // The point of the fix: assertAccessibleBy no longer 404s on it -- via the
        // agency_id fallback, NOT via is_global.
        $template->assertAccessibleBy($user);
        $this->assertTrue(true);
    }

    // ── Path 2: .docx/.pdf import (DocumentTemplateGenerator::generate()) ──

    public function test_docx_import_creates_a_reachable_template(): void
    {
        ['agency' => $agency, 'manager' => $user] = $this->seedAgencyWithManager('Importer');

        $draft = ImportDraft::create([
            'user_id' => $user->id,
            'filename' => 'test.docx',
            'html' => '<p>Body</p>',
            'fields_json' => json_encode([
                'tagged_html' => '<p>Signed: <span data-tag-id="t0">[1]</span></p>',
                'tags' => [['id' => 't0', 'number' => 1]],
                'mappings' => [],
            ]),
        ]);

        $template = app(DocumentTemplateGenerator::class)->generate(
            $draft,
            'Imported Test Document',
            $user->id,
            $user->effectiveAgencyId()
        );

        $this->assertFalse((bool) $template->is_global, 'a new import must never default to platform-wide');
        $this->assertSame($agency->id, $template->agency_id);

        $template->assertAccessibleBy($user);
        $this->assertTrue(true);
    }

    // ── Path 3: CDS-Builder (TemplateController::cdsGenerate()) ──

    public function test_cds_builder_generate_creates_a_fresh_template_agency_scoped_not_global(): void
    {
        ['agency' => $agency, 'manager' => $user] = $this->seedAgencyWithManager('CdsBuilder');

        $draft = CdsDraft::create([
            'user_id' => $user->id,
            'agency_id' => $agency->id,
            'template_name' => 'Fresh CDS Template',
            'cds_json' => ['sections' => []],
            'mappings' => [],
            'tags' => [],
            'tagged_html' => '<p>Body</p>',
            'settings' => [],
            'source_template_id' => null,
            'status' => 'draft',
        ]);

        $this->actingAs($user)->post('/docuperfect/templates/cds/generate', [
            'draft_id' => $draft->id,
            'template_name' => 'Fresh CDS Template',
            'is_esign' => 1,
            'party_mode' => 'shared',
        ])->assertRedirect();

        $template = Template::where('name', 'Fresh CDS Template')->firstOrFail();
        $this->assertFalse((bool) $template->is_global, 'this path previously hardcoded is_global=true unconditionally -- must not any more');
        $this->assertSame($agency->id, $template->agency_id, 'agency_id was previously never stamped on this path');
    }

    /**
     * The clobber this path used to have: EVERY re-save of an EXISTING template
     * (source_template_id set) forced is_global back to true, even one correctly
     * scoped to a single agency. Editing content must never touch scope.
     */
    public function test_cds_builder_generate_does_not_reassert_global_on_an_existing_templates_content_save(): void
    {
        ['agency' => $agency, 'manager' => $user] = $this->seedAgencyWithManager('CdsBuilderEdit');

        $template = Template::create([
            'name' => 'Existing CDS Template', 'render_type' => 'web', 'template_type' => 'cds',
            'fields_json' => [], 'is_global' => false, 'agency_id' => $agency->id, 'owner_id' => $user->id,
        ]);
        $draft = CdsDraft::create([
            'user_id' => $user->id,
            'agency_id' => $agency->id,
            'template_name' => $template->name,
            'cds_json' => ['sections' => []],
            'mappings' => [],
            'tags' => [],
            'tagged_html' => '<p>Edited body</p>',
            'settings' => [],
            'source_template_id' => $template->id,
            'status' => 'draft',
        ]);

        $this->actingAs($user)->post('/docuperfect/templates/cds/generate', [
            'draft_id' => $draft->id,
            'template_name' => $template->name,
            'is_esign' => 1,
            'party_mode' => 'shared',
        ])->assertRedirect();

        $fresh = $template->fresh();
        $this->assertFalse((bool) $fresh->is_global, 'a content re-save must not reassert platform-wide scope');
        $this->assertSame($agency->id, $fresh->agency_id);
    }

    // ── AT-390 — a CoreX (owner-role, no agency of its own) account creating or
    // editing a template on an agency's behalf via cdsGenerate(), driven by the
    // EXISTING agency switcher (session active_agency_id) rather than a second
    // selector. This is what closed template 96's original defect: cdsGenerate()
    // stamped agency_id from the creator's OWN effectiveAgencyId(), which for a
    // CoreX account is NULL. ──

    /** @return User an owner-role account with NO agency of its own. */
    private function seedOwnerUser(): User
    {
        $ownerRole = Role::create(['name' => 'super_admin_' . uniqid(), 'label' => 'System Owner']);
        $ownerRole->is_owner = true;
        $ownerRole->save();
        Role::clearCache();

        return User::factory()->create([
            'agency_id' => null, 'branch_id' => null, 'role' => $ownerRole->name, 'is_active' => true,
        ]);
    }

    public function test_owner_creating_a_template_with_no_agency_selected_is_refused(): void
    {
        $owner = $this->seedOwnerUser();
        $draft = CdsDraft::create([
            'user_id' => $owner->id, 'agency_id' => null, 'template_name' => 'Orphan Attempt',
            'cds_json' => ['sections' => []], 'mappings' => [], 'tags' => [], 'tagged_html' => '<p>Body</p>',
            'settings' => [], 'source_template_id' => null, 'status' => 'draft',
        ]);

        // No active_agency_id in session -- exactly Johan's shape before he
        // used the switcher.
        $this->actingAs($owner)->post('/docuperfect/templates/cds/generate', [
            'draft_id' => $draft->id, 'template_name' => 'Orphan Attempt', 'is_esign' => 1, 'party_mode' => 'shared',
        ])->assertSessionHasErrors('agency');

        $this->assertDatabaseMissing('docuperfect_templates', ['name' => 'Orphan Attempt']);
    }

    public function test_owner_creating_a_template_with_an_agency_selected_stamps_that_agency(): void
    {
        $owner = $this->seedOwnerUser();
        $target = Agency::create(['name' => 'Target Agency', 'slug' => 'target-agency-' . uniqid()]);
        $draft = CdsDraft::create([
            'user_id' => $owner->id, 'agency_id' => null, 'template_name' => 'On Behalf Of Target',
            'cds_json' => ['sections' => []], 'mappings' => [], 'tags' => [], 'tagged_html' => '<p>Body</p>',
            'settings' => [], 'source_template_id' => null, 'status' => 'draft',
        ]);

        // Drive the REAL flow: the agency-switch control surfaced on the
        // builder page, not a raw session poke -- this also backfills the
        // draft's own (BelongsToAgency-scoped) agency_id, which the switch
        // must do or the draft becomes unreachable to itself.
        $this->actingAs($owner)
            ->post(route('docuperfect.cds.switchAgency', ['draft' => $draft->id, 'agency' => $target->id]))
            ->assertRedirect();

        $this->actingAs($owner)
            ->post('/docuperfect/templates/cds/generate', [
                'draft_id' => $draft->id, 'template_name' => 'On Behalf Of Target', 'is_esign' => 1, 'party_mode' => 'shared',
            ])->assertRedirect();

        $template = Template::where('name', 'On Behalf Of Target')->firstOrFail();
        $this->assertSame($target->id, $template->agency_id);
        $this->assertFalse((bool) $template->is_global);
    }

    public function test_owner_editing_an_existing_template_reassigns_it_to_the_switched_agency(): void
    {
        // The exact repro for template 96: created (or previously stranded)
        // under the wrong agency; a CoreX user opens it, switches to the
        // correct agency, and saves -- through the interface, no data patch.
        $owner = $this->seedOwnerUser();
        $wrongAgency = Agency::create(['name' => 'Wrong Agency', 'slug' => 'wrong-agency-' . uniqid()]);
        $correctAgency = Agency::create(['name' => 'Correct Agency', 'slug' => 'correct-agency-' . uniqid()]);

        $template = Template::create([
            'name' => 'Stranded Template', 'render_type' => 'web', 'template_type' => 'cds',
            'fields_json' => [], 'is_global' => false, 'agency_id' => $wrongAgency->id, 'owner_id' => $owner->id,
        ]);
        $draft = CdsDraft::create([
            'user_id' => $owner->id, 'agency_id' => null, 'template_name' => $template->name,
            'cds_json' => ['sections' => []], 'mappings' => [], 'tags' => [], 'tagged_html' => '<p>Body</p>',
            'settings' => [], 'source_template_id' => $template->id, 'status' => 'draft',
        ]);

        $this->actingAs($owner)
            ->post(route('docuperfect.cds.switchAgency', ['draft' => $draft->id, 'agency' => $correctAgency->id]))
            ->assertRedirect();

        $this->actingAs($owner)
            ->post('/docuperfect/templates/cds/generate', [
                'draft_id' => $draft->id, 'template_name' => $template->name, 'is_esign' => 1, 'party_mode' => 'shared',
            ])->assertRedirect();

        $fresh = $template->fresh();
        $this->assertSame($correctAgency->id, $fresh->agency_id, 'the interface must be able to reassign a stranded template');
        $this->assertNotSame($wrongAgency->id, $fresh->agency_id);
    }

    // ── TemplateController::cdsSwitchAgencyContext() — the endpoint itself ──

    public function test_switch_agency_context_backfills_a_null_draft_agency_id(): void
    {
        $owner = $this->seedOwnerUser();
        $target = Agency::create(['name' => 'Backfill Target', 'slug' => 'backfill-target-' . uniqid()]);
        $draft = CdsDraft::create([
            'user_id' => $owner->id, 'agency_id' => null, 'template_name' => 'Backfill Draft',
            'cds_json' => ['sections' => []], 'mappings' => [], 'tags' => [], 'tagged_html' => '<p>Body</p>',
            'settings' => [], 'source_template_id' => null, 'status' => 'draft',
        ]);

        $this->actingAs($owner)
            ->post(route('docuperfect.cds.switchAgency', ['draft' => $draft->id, 'agency' => $target->id]))
            ->assertRedirect(route('docuperfect.cds.builder', $draft));

        $this->assertSame($target->id, $draft->fresh()->agency_id);
    }

    public function test_switch_agency_context_never_overwrites_an_already_set_draft_agency_id(): void
    {
        // Re-opening an EXISTING template's draft (agency_id already set from
        // a prior save) must not have that silently reassigned by a routine
        // context switch -- only cdsGenerate()'s explicit save path reassigns,
        // and only for the template, never speculatively on this draft.
        $owner = $this->seedOwnerUser();
        $original = Agency::create(['name' => 'Original', 'slug' => 'original-' . uniqid()]);
        $other = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $draft = CdsDraft::create([
            'user_id' => $owner->id, 'agency_id' => $original->id, 'template_name' => 'Already Scoped Draft',
            'cds_json' => ['sections' => []], 'mappings' => [], 'tags' => [], 'tagged_html' => '<p>Body</p>',
            'settings' => [], 'source_template_id' => null, 'status' => 'draft',
        ]);

        $this->actingAs($owner)
            ->post(route('docuperfect.cds.switchAgency', ['draft' => $draft->id, 'agency' => $other->id]))
            ->assertRedirect();

        $this->assertSame($original->id, $draft->fresh()->agency_id, 'an already-scoped draft\'s agency_id must not change on a routine context switch');
    }

    public function test_switch_agency_context_refuses_a_non_owner(): void
    {
        ['agency' => $agency, 'manager' => $user] = $this->seedAgencyWithManager('NonOwnerSwitchAttempt');
        $target = Agency::create(['name' => 'Non-owner Target', 'slug' => 'non-owner-target-' . uniqid()]);

        // Created WHILE authenticated as this user, matching real usage --
        // BelongsToAgency's creating() hook auto-stamps a non-owner's own
        // agency_id regardless of what's passed, so this is the draft an
        // ordinary user actually has (an ordinary user can never legitimately
        // end up with a null-agency draft -- that shape is owner-only).
        $this->actingAs($user);
        $draft = CdsDraft::create([
            'user_id' => $user->id, 'template_name' => 'Non-owner Draft',
            'cds_json' => ['sections' => []], 'mappings' => [], 'tags' => [], 'tagged_html' => '<p>Body</p>',
            'settings' => [], 'source_template_id' => null, 'status' => 'draft',
        ]);
        $this->assertSame($agency->id, $draft->agency_id, 'sanity check: an ordinary user\'s own draft is always agency-stamped, never null');

        // Whatever the exact HTTP status, this owner-only endpoint must not let
        // an ordinary agency user change agency context or touch this draft.
        $response = $this->actingAs($user)
            ->post(route('docuperfect.cds.switchAgency', ['draft' => $draft->id, 'agency' => $target->id]));
        $this->assertContains($response->getStatusCode(), [403, 404], 'must refuse, whether by 403 (owner check) or 404 (agency-scope invisibility)');

        $this->assertSame($agency->id, $draft->fresh()->agency_id, 'the switch must not have taken effect');
        $this->assertNull(session('active_agency_id'), 'the switch must not have taken effect');
    }

    public function test_ordinary_agency_user_editing_their_own_template_never_has_agency_id_touched(): void
    {
        // Regression guard: an ordinary agency user must never be able to
        // reassign a template out of their own agency -- not via a session
        // value, not by accident. This exercises cdsGenerate()'s update
        // branch for a NON-owner, which must behave exactly as before this
        // change (agency_id absent from the update payload entirely).
        ['agency' => $agency, 'manager' => $user] = $this->seedAgencyWithManager('OrdinaryEditor');
        $foreignAgency = Agency::create(['name' => 'Foreign Agency', 'slug' => 'foreign-agency-' . uniqid()]);

        $template = Template::create([
            'name' => 'Ordinary Template', 'render_type' => 'web', 'template_type' => 'cds',
            'fields_json' => [], 'is_global' => false, 'agency_id' => $agency->id, 'owner_id' => $user->id,
        ]);
        $draft = CdsDraft::create([
            'user_id' => $user->id, 'agency_id' => $agency->id, 'template_name' => $template->name,
            'cds_json' => ['sections' => []], 'mappings' => [], 'tags' => [], 'tagged_html' => '<p>Edited</p>',
            'settings' => [], 'source_template_id' => $template->id, 'status' => 'draft',
        ]);

        // Even if a foreign agency_id somehow ended up in this non-owner's
        // session (it never legitimately could -- userCanSwitchTo() blocks
        // it), the update branch must not consult it for a non-owner at all.
        $this->actingAs($user)->withSession(['active_agency_id' => $foreignAgency->id])
            ->post('/docuperfect/templates/cds/generate', [
                'draft_id' => $draft->id, 'template_name' => $template->name, 'is_esign' => 1, 'party_mode' => 'shared',
            ])->assertRedirect();

        $this->assertSame($agency->id, $template->fresh()->agency_id, 'an ordinary user\'s edit must never change agency_id');
    }

    // ── Edit-screen footgun guard (TemplateController::saveFields()) ──
    // Note: is_global can no longer be toggled through this endpoint at all (see the
    // "no request-input path" tests below) -- these two exercise the guard purely on
    // clearing branches to empty, independent of is_global.

    public function test_clearing_branches_to_empty_is_refused_when_it_would_strand_the_template(): void
    {
        ['agency' => $agency, 'branch' => $branch, 'manager' => $user] = $this->seedAgencyWithManager('NoFallback');
        $template = Template::create([
            'name' => 'No-agency template', 'template_type' => 'sales', 'page_count' => 1,
            'fields_json' => [], 'is_global' => false, 'agency_id' => null, 'owner_id' => $user->id,
        ]);
        // Currently reachable via its one branch -- agency_id is null, so clearing that
        // branch to zero would strand it. If it started unreachable, assertAccessibleBy()
        // would 404 before the guard is ever exercised.
        $template->branches()->attach($branch->id);

        $this->actingAs($user)->postJson(route('docuperfect.templates.saveFields', ['id' => $template->id]), [
            'allowed_branches' => [],
        ])->assertStatus(422);

        $this->assertSame(1, $template->fresh()->branches()->count(), 'the template must be left untouched, not silently stranded');
    }

    public function test_clearing_branches_to_empty_succeeds_when_agency_id_provides_a_fallback(): void
    {
        ['agency' => $agency, 'manager' => $user] = $this->seedAgencyWithManager('HasFallback');
        $template = Template::create([
            'name' => 'Has-agency template', 'template_type' => 'sales', 'page_count' => 1,
            'fields_json' => [], 'is_global' => false, 'agency_id' => $agency->id, 'owner_id' => $user->id,
        ]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'HasFallback HQ2']);
        $template->branches()->attach($branch->id);

        $this->actingAs($user)->postJson(route('docuperfect.templates.saveFields', ['id' => $template->id]), [
            'allowed_branches' => [],
        ])->assertOk();

        $fresh = $template->fresh();
        $this->assertSame(0, $fresh->branches()->count());
        $fresh->assertAccessibleBy($user); // agency_id fallback keeps it reachable
        $this->assertTrue(true);
    }

    // ── 2026-08-24 — is_global has NO request-input path any more, anywhere ──
    // Removing the UI checkbox alone would have been cosmetic: this endpoint accepted
    // a raw is_global key from any POST regardless of what the visible form sent. These
    // prove the server itself refuses it, not just that the control is hidden.

    public function test_saveFields_ignores_a_raw_is_global_true_in_the_request_body(): void
    {
        ['agency' => $agency, 'manager' => $user] = $this->seedAgencyWithManager('RawIsGlobalAttempt');
        $template = Template::create([
            'name' => 'Raw is_global attempt', 'template_type' => 'sales', 'page_count' => 1,
            'fields_json' => [], 'is_global' => false, 'agency_id' => $agency->id, 'owner_id' => $user->id,
        ]);

        $this->actingAs($user)->postJson(route('docuperfect.templates.saveFields', ['id' => $template->id]), [
            'name' => 'Raw is_global attempt', // exercise the normal, legitimate save path
            'is_global' => true,               // no UI sends this any more -- simulate one that still tries
        ])->assertOk();

        $this->assertFalse((bool) $template->fresh()->is_global, 'is_global must never be settable from request input, even without a UI control');
    }

    public function test_saveFields_does_not_detach_branches_on_a_raw_is_global_true(): void
    {
        // The old branch-sync logic branched on request is_global too (detach vs
        // sync) -- confirm that side channel is closed as well, not just the direct
        // column write.
        ['agency' => $agency, 'manager' => $user] = $this->seedAgencyWithManager('RawIsGlobalBranches');
        $template = Template::create([
            'name' => 'Raw is_global branches attempt', 'template_type' => 'sales', 'page_count' => 1,
            'fields_json' => [], 'is_global' => false, 'agency_id' => $agency->id, 'owner_id' => $user->id,
        ]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'RawIsGlobalBranches HQ2']);
        $template->branches()->attach($branch->id);

        $this->actingAs($user)->postJson(route('docuperfect.templates.saveFields', ['id' => $template->id]), [
            'is_global' => true,
            'allowed_branches' => [$branch->id], // submitted branches must still be respected
        ])->assertOk();

        $fresh = $template->fresh();
        $this->assertFalse((bool) $fresh->is_global);
        $this->assertSame(1, $fresh->branches()->count(), 'branches must be synced from allowed_branches, not detached because of a stray is_global key');
    }
}
