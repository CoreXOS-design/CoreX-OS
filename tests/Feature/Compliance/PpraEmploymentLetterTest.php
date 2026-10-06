<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Compliance\PpraEmploymentLetter;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Compliance\PpraEmploymentLetterService;
use App\Services\Compliance\PractitionerFfcRosterService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PPRA FFC renewal — Confirmation of Employment letter.
 * .ai/specs/ppra-ffc-employment-letter.md
 *
 * Wet-ink flow (spec §20): create → download/print → sign on paper → upload the signed copy. role_permissions
 * unseeded → PermissionService fails open per EvaluationCertificateSignTest's documented convention — agent='own',
 * branch_manager='branch', admin='all' for scopeVisibleTo(). The wet-ink upload/replace/scoping tests are in
 * PpraEmploymentLetterWetInkTest.
 */
final class PpraEmploymentLetterTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        Mail::fake();
        $this->agency = Agency::create(['name' => 'Southern Cape Realty', 'slug' => 'southern-cape-realty', 'ppra_number' => 'F999999']);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Hermanus']);
    }

    private function user(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'agency_id'   => $this->agency->id,
            'branch_id'   => $this->branch->id,
            'role'        => 'agent',
            'designation' => 'Property Practitioner',
            'id_number'   => '9001015800088',
            'ffc_number'  => '1234567',
            'is_active'   => true,
        ], $attrs));
    }

    private function principal(array $attrs = []): User
    {
        return $this->user(array_merge([
            'designation'               => 'Principal',
            'is_principal_practitioner' => true,
        ], $attrs));
    }

    /**
     * Seeds an explicit grant set (the table is no longer "unseeded", so no fail-open): every letter key for
     * agent/branch_manager/admin/office_admin, with `ppra_employment_letters.receive` ticked ONLY for $receiveRoles
     * (the Role Manager setting under test). Scopes mirror scope_defaults: agent own, branch_manager branch, admin all.
     */
    private function seedLetterGrants(array $receiveRoles): void
    {
        $scopes = ['agent' => 'own', 'branch_manager' => 'branch', 'admin' => 'all', 'office_admin' => 'own'];
        foreach ($scopes as $role => $scope) {
            foreach (['access_my_portal', 'ppra_employment_letters.view', 'ppra_employment_letters.create'] as $key) {
                RolePermission::create(['role' => $role, 'permission_key' => $key, 'scope' => $scope, 'agency_id' => $this->agency->id]);
            }
        }
        foreach (['admin', 'branch_manager'] as $role) {
            RolePermission::create(['role' => $role, 'permission_key' => 'ppra_employment_letters.manage', 'scope' => null, 'agency_id' => $this->agency->id]);
        }
        foreach ($receiveRoles as $role) {
            RolePermission::create(['role' => $role, 'permission_key' => PractitionerFfcRosterService::LETTER_PERMISSION, 'scope' => null, 'agency_id' => $this->agency->id]);
        }
        PermissionService::clearCache();
    }

    // ── The wet-ink flow ─────────────────────────────────────────────────────

    public function test_create_and_download_the_letter_for_wet_ink_signing(): void
    {
        $agent = $this->user();
        $this->principal();

        $this->actingAs($agent)->post(route('ppra-employment-letters.store'))->assertRedirect();

        $letter = PpraEmploymentLetter::where('user_id', $agent->id)->firstOrFail();
        $this->assertSame(PpraEmploymentLetter::STATUS_AWAITING_SIGNED_COPY, $letter->status);
        $this->assertSame('Awaiting signed copy', PpraEmploymentLetter::statusLabel($letter->status));
        $this->assertSame($this->branch->id, $letter->branch_id);

        $this->actingAs($agent)
            ->get(route('ppra-employment-letters.download', $letter))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        // No electronic signing, no emails — the letter is printed and signed on paper.
        Mail::assertNothingSent();
        $this->assertNull($letter->agent_signed_at);
        $this->assertNull($letter->signed_pdf_path);
    }

    public function test_the_pin_sign_routes_are_gone(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('ppra-employment-letters.sign-as-agent'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('ppra-employment-letters.sign-as-principal'));
        $this->assertNull(collect(\Illuminate\Support\Facades\Artisan::all())->get('ppra-employment-letters:send-reminders'), 'the reminder job is retired');
        $this->assertNotContains('ppra_employment_letters.sign_as_principal', array_column(config('corex-permissions.permissions'), 'key'));
    }

    // ── Missing merge data ───────────────────────────────────────────────────

    public function test_missing_merge_data_blocks_letter_creation(): void
    {
        $agent = $this->user(['id_number' => null, 'ffc_number' => null]);
        $this->principal();

        $this->actingAs($agent)->post(route('ppra-employment-letters.store'))->assertRedirect();

        $this->assertDatabaseCount('ppra_employment_letters', 0);

        $missing = app(PpraEmploymentLetterService::class)->missingFieldsFor($agent, $this->agency);
        $this->assertNotEmpty($missing);
        $this->assertTrue(collect($missing)->contains(fn ($m) => str_contains($m['label'], 'ID number')));
        $this->assertTrue(collect($missing)->contains(fn ($m) => str_contains($m['label'], 'PPRA/FFC reference')));
    }

    // ── Zero / multiple principals ───────────────────────────────────────────

    public function test_zero_principals_blocks_letter_creation(): void
    {
        $agent = $this->user(); // no principal flagged anywhere in this agency

        $missing = app(PpraEmploymentLetterService::class)->missingFieldsFor($agent, $this->agency);
        $this->assertTrue(collect($missing)->contains(fn ($m) => str_contains($m['label'], 'no principal set')));

        $this->actingAs($agent)->post(route('ppra-employment-letters.store'))->assertRedirect();
        $this->assertDatabaseCount('ppra_employment_letters', 0);
    }

    public function test_multiple_principals_requires_an_explicit_choice(): void
    {
        $agent = $this->user();
        $p1 = $this->principal();
        $p2 = $this->principal();

        $resolved = app(PpraEmploymentLetterService::class)->resolvePrincipal($this->agency);
        $this->assertSame('multiple', $resolved['status']);
        $this->assertCount(2, $resolved['principals']);

        // No choice made — the service aborts with 422 (HttpException, not a
        // plain exception code, so assert via getStatusCode()).
        $this->actingAs($agent);
        try {
            app(PpraEmploymentLetterService::class)->create($agent, $agent, null);
            $this->fail('Expected a 422 HttpException for an un-chosen multiple-principal letter.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_multiple_principals_create_succeeds_with_a_valid_choice(): void
    {
        $agent = $this->user();
        $this->principal();
        $p2 = $this->principal();

        $letter = app(PpraEmploymentLetterService::class)->create($agent, $agent, $p2->id);
        $this->assertSame($p2->id, $letter->principal_user_id);
    }

    // ── Cancel / archive ─────────────────────────────────────────────────────

    public function test_agent_can_cancel_a_letter_with_no_signed_copy_but_not_one_with_a_copy_filed(): void
    {
        $agent = $this->user();
        $this->principal();

        $this->actingAs($agent)->post(route('ppra-employment-letters.store'));
        $letter = PpraEmploymentLetter::where('user_id', $agent->id)->firstOrFail();

        $this->actingAs($agent)->post(route('ppra-employment-letters.cancel', $letter))->assertRedirect();
        $this->assertSoftDeleted('ppra_employment_letters', ['id' => $letter->id]);

        // A letter with a signed copy filed cannot be cancelled this way.
        $letter2 = app(PpraEmploymentLetterService::class)->create($agent, $agent, null);
        $this->actingAs($agent)->post(route('ppra-employment-letters.upload', $letter2), [
            'signed_copy' => \Illuminate\Http\UploadedFile::fake()->create('signed.pdf', 20, 'application/pdf'),
        ]);
        $this->assertTrue($letter2->fresh()->isSignedCopyFiled());

        $this->actingAs($agent)->post(route('ppra-employment-letters.cancel', $letter2))->assertStatus(409);
    }

    // ── Agency isolation (the hard boundary) ─────────────────────────────────

    public function test_cross_agency_access_is_denied(): void
    {
        // Both agencies/users are created BEFORE acting as anyone — User
        // itself uses BelongsToAgency, so creating $otherUser WHILE still
        // acting as $agent would have its own agency_id force-stamped back
        // onto $agent's agency by the very auto-stamp this feature relies
        // on, which is correct trait behaviour but the wrong fixture order
        // for building a genuinely different tenant's user.
        $otherAgency = Agency::create(['name' => 'Cape Peninsula Properties', 'slug' => 'cape-peninsula']);
        $otherUser = User::factory()->create(['agency_id' => $otherAgency->id, 'role' => 'admin', 'is_active' => true]);

        $agent = $this->user();
        $this->principal();
        $this->actingAs($agent)->post(route('ppra-employment-letters.store'));
        $letter = PpraEmploymentLetter::where('user_id', $agent->id)->firstOrFail();

        $this->assertNotSame($otherAgency->id, $letter->agency_id);
        $this->assertSame($otherAgency->id, $otherUser->agency_id);

        $this->actingAs($otherUser)
            ->get(route('ppra-employment-letters.show', $letter))
            ->assertStatus(404);

        $this->actingAs($otherUser)
            ->get(route('admin.ppra-employment-letters.show', $letter->id))
            ->assertStatus(404);
    }

    // ── OWN / BRANCH / AGENCY scoping on the Admin register ──────────────────

    public function test_admin_register_scoping_by_role(): void
    {
        $branchB = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Stanford']);

        $agentA = $this->user(); // branch A (the setUp branch)
        $agentB = $this->user(['branch_id' => $branchB->id]);
        $this->principal();

        $this->actingAs($agentA)->post(route('ppra-employment-letters.store'));
        $letterA = PpraEmploymentLetter::where('user_id', $agentA->id)->firstOrFail();

        $this->actingAs($agentB)->post(route('ppra-employment-letters.store'));
        $letterB = PpraEmploymentLetter::where('user_id', $agentB->id)->firstOrFail();

        // agent scope ('own', unseeded-fallback) — sees only their own.
        $this->assertTrue(PpraEmploymentLetter::query()->visibleTo($agentA)->whereKey($letterA->id)->exists());
        $this->assertFalse(PpraEmploymentLetter::query()->visibleTo($agentA)->whereKey($letterB->id)->exists());

        // branch_manager scope ('branch', unseeded-fallback) — sees their own branch only.
        $bmBranchA = $this->user(['role' => 'branch_manager']);
        $this->assertTrue(PpraEmploymentLetter::query()->visibleTo($bmBranchA)->whereKey($letterA->id)->exists());
        $this->assertFalse(PpraEmploymentLetter::query()->visibleTo($bmBranchA)->whereKey($letterB->id)->exists());

        // admin scope ('all', unseeded-fallback) — sees every letter in the agency.
        $admin = $this->user(['role' => 'admin']);
        $this->assertTrue(PpraEmploymentLetter::query()->visibleTo($admin)->whereKey($letterA->id)->exists());
        $this->assertTrue(PpraEmploymentLetter::query()->visibleTo($admin)->whereKey($letterB->id)->exists());

        // Direct-URL-by-id on the Admin show/download route: out-of-branch 404s for the branch-A-scoped admin role.
        $this->actingAs($bmBranchA)
            ->get(route('admin.ppra-employment-letters.show', $letterB->id))
            ->assertStatus(404);
        $this->actingAs($admin)
            ->get(route('admin.ppra-employment-letters.show', $letterB->id))
            ->assertOk();
    }

    /**
     * 2026-10-06 — ONE rule for the whole Admin register: `ppra_employment_letters.manage`. A user without it
     * (an agent seeded view=own, or a branch_manager whose manage row was un-ticked in Role Manager — view=branch
     * still resolves) gets 403 on EVERY admin route and no sidebar link; before, the list opened on view+scope
     * while New letter needed manage, so the register offered a button that 403'd.
     */
    public function test_user_without_manage_gets_403_on_every_admin_route_and_no_sidebar_link(): void
    {
        $this->seedLetterGrants(['agent', 'branch_manager', 'admin']);
        RolePermission::where('role', 'branch_manager')->where('permission_key', 'ppra_employment_letters.manage')->delete();
        PermissionService::clearCache();

        $agent = $this->user();
        $this->principal();
        $this->actingAs($agent)->post(route('ppra-employment-letters.store'));
        $letter = PpraEmploymentLetter::where('user_id', $agent->id)->firstOrFail();

        $agentUser = $agent;
        $bmWithoutManage = $this->user(['role' => 'branch_manager']);
        foreach ([$agentUser, $bmWithoutManage] as $who) {
            $this->actingAs($who);
            $this->get(route('admin.ppra-employment-letters.index'))->assertStatus(403);
            $this->get(route('admin.ppra-employment-letters.create'))->assertStatus(403);
            $this->post(route('admin.ppra-employment-letters.store'), ['user_id' => $agent->id])->assertStatus(403);
            $this->get(route('admin.ppra-employment-letters.show', $letter->id))->assertStatus(403);
            $this->get(route('admin.ppra-employment-letters.download', $letter->id))->assertStatus(403);
            $this->post(route('admin.ppra-employment-letters.archive', $letter->id))->assertStatus(403);
            $this->post(route('admin.ppra-employment-letters.restore', $letter->id))->assertStatus(403);
            $this->assertFalse(PpraEmploymentLetter::userCanUseAdminRegister($who));
        }

        // Still allowed to use My Portal for their own letter.
        $this->assertNotNull(PpraEmploymentLetter::where('user_id', $agent->id)->first());
        $this->assertDatabaseCount('ppra_employment_letters', 1);
    }

    public function test_manager_sees_the_register_and_new_letter_and_can_create_and_the_sidebar_link_follows_the_same_rule(): void
    {
        $this->seedLetterGrants(['agent', 'branch_manager', 'admin']);
        $agent = $this->user();
        $this->principal();
        $admin = $this->user(['role' => 'admin']);

        $this->assertTrue(PpraEmploymentLetter::userCanUseAdminRegister($admin));

        $this->actingAs($admin)->get(route('admin.ppra-employment-letters.index'))
            ->assertOk()->assertSee(route('admin.ppra-employment-letters.create'), false);
        $this->actingAs($admin)->get(route('admin.ppra-employment-letters.create'))->assertOk();
        $this->actingAs($admin)->post(route('admin.ppra-employment-letters.store'), ['user_id' => $agent->id])->assertRedirect();
        $this->assertDatabaseCount('ppra_employment_letters', 1);

        // The sidebar renders the link for the manager (index page carries the layout) and not for the agent.
        $this->actingAs($admin)->get(route('admin.ppra-employment-letters.index'))
            ->assertSee(route('admin.ppra-employment-letters.index'), false);
        $this->actingAs($agent)->get(route('corex.dashboard'))
            ->assertDontSee(route('admin.ppra-employment-letters.index'), false);
    }

    /**
     * Johan's "Access denied" (2026-10-06): a browser posts form values as STRINGS, and validate() hands them back
     * unchanged; the store compared the raw string id to integer roster ids with in_array(strict) and 403'd every real
     * submit ("That agent is not in your scope"), and the multi-principal pick would have failed the service's strict
     * check next. Posts exactly as a browser does — string ids, two principals — and follows every redirect.
     */
    public function test_admin_start_letter_form_works_with_string_ids_from_a_real_browser_post_and_follows_redirects(): void
    {
        $this->seedLetterGrants(['agent', 'admin']);
        $agent = $this->user();
        $principalA = $this->principal();
        $this->principal();
        $admin = $this->user(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.ppra-employment-letters.create'))->assertOk();

        $response = $this->actingAs($admin)->followingRedirects()->post(
            route('admin.ppra-employment-letters.store'),
            ['user_id' => (string) $agent->id, 'principal_user_id' => (string) $principalA->id]
        );
        $response->assertOk()->assertSee('Letter started for ' . $agent->name);

        $letter = PpraEmploymentLetter::where('user_id', $agent->id)->firstOrFail();
        $this->assertSame($admin->id, $letter->created_by_user_id);
        $this->assertSame($principalA->id, $letter->principal_user_id);
        $this->actingAs($admin)->get(route('admin.ppra-employment-letters.show', $letter->id))->assertOk();
        $this->actingAs($admin)->get(route('admin.ppra-employment-letters.download', $letter->id))->assertStatus(200);
        $this->actingAs($admin)->post(route('admin.ppra-employment-letters.archive', $letter->id))->assertRedirect(route('admin.ppra-employment-letters.index'));
        $this->assertSoftDeleted('ppra_employment_letters', ['id' => $letter->id]);
    }

    /**
     * Switch User must give exactly the impersonated user's access. A View-As lens the admin set earlier lives in
     * the session, survives Auth::login(), and made Barbara (an agent) resolve to the lens role: the list opened
     * (view=branch) while create 403'd (no manage). ImpersonateController::start() now clears the lens.
     */
    public function test_switch_user_clears_the_admins_view_as_lens_so_the_target_gets_only_their_own_access(): void
    {
        $this->seedLetterGrants(['agent', 'branch_manager', 'admin']);
        RolePermission::where('role', 'branch_manager')->where('permission_key', 'ppra_employment_letters.manage')->delete();
        PermissionService::clearCache();

        // The lens belongs to a real owner (View-As is owner/impersonate-only, and an owner's real role bypasses it).
        Role::forceCreate(['name' => 'super_admin', 'label' => 'System Owner', 'is_owner' => true]); // is_owner is not mass-assignable
        Role::clearCache();
        $agent = $this->user();
        $admin = $this->user(['role' => 'super_admin']);

        $this->assertTrue($admin->isOwnerRole());
        $this->actingAs($admin)
            ->withSession(['view_as_role' => 'branch_manager', 'view_as_branch_id' => $this->branch->id])
            ->post(route("impersonate.start", $agent->id))
            ->assertRedirect();

        $this->assertNull(session('view_as_role'));
        $this->assertNull(session('view_as_branch_id'));
        $this->assertSame($agent->id, auth()->id());
        $this->assertSame('agent', auth()->user()->effectiveRole());

        $this->get(route('admin.ppra-employment-letters.index'))->assertStatus(403);
        $this->get(route('admin.ppra-employment-letters.create'))->assertStatus(403);
    }

    // ── office_admin backfill (AT bug #2, 2026-10-05) ────────────────────────

    /**
     * Simulates the exact pre-fix state: a role whose role_permissions rows
     * exist (so the table is no longer "unseeded") but were never given the
     * ppra_employment_letters keys at all — the real gap found in
     * config/corex-permissions.php's office_admin role_defaults. Running the
     * SAME idempotent backfill this fix relies on
     * (`corex:sync-permissions --merge-defaults`) must grant them without
     * touching any other existing grant for that role.
     */
    public function test_office_admin_role_gains_ppra_access_after_merge_defaults_backfill(): void
    {
        $role = Role::create(['name' => 'office_admin', 'label' => 'Office Admin', 'agency_id' => $this->agency->id]);

        // Pre-fix fixture: office_admin has SOME grants (so the table isn't
        // "unseeded"), deliberately none of them PPRA — this is the bug.
        RolePermission::create(['role' => 'office_admin', 'permission_key' => 'communications.view', 'scope' => 'own', 'agency_id' => $this->agency->id]);

        $officeAdmin = $this->user(['role' => 'office_admin', 'designation' => 'Candidate Property Practitioner']);

        $this->assertFalse($officeAdmin->hasPermission('ppra_employment_letters.view'));
        $this->assertFalse($officeAdmin->hasPermission('ppra_employment_letters.create'));

        Artisan::call('corex:sync-permissions', ['--merge-defaults' => true]);
        PermissionService::clearCache();

        $this->assertTrue($officeAdmin->hasPermission('ppra_employment_letters.view'));
        $this->assertTrue($officeAdmin->hasPermission('ppra_employment_letters.create'));

        // The pre-existing grant for this role is untouched — merge is additive-only.
        $this->assertTrue($officeAdmin->hasPermission('communications.view'));

        $role->delete();
    }

    // ── Admin create-on-behalf (bug #3, 2026-10-05) ──────────────────────────

    public function test_admin_creates_letter_on_behalf_and_it_awaits_the_signed_copy(): void
    {
        $this->seedLetterGrants(['agent', 'admin']);
        $agent = $this->user();
        $principal = $this->principal();

        $admin = $this->user(['role' => 'admin']);

        $store = $this->actingAs($admin)
            ->post(route('admin.ppra-employment-letters.store'), ['user_id' => $agent->id]);
        $store->assertRedirect();

        $letter = PpraEmploymentLetter::where('user_id', $agent->id)->firstOrFail();
        $this->assertSame(PpraEmploymentLetter::STATUS_AWAITING_SIGNED_COPY, $letter->status);
        $this->assertSame($admin->id, $letter->created_by_user_id);
        $this->assertSame($principal->id, $letter->principal_user_id);
        Mail::assertNothingSent();
    }

    public function test_admin_create_on_behalf_blocks_missing_merge_data(): void
    {
        $this->seedLetterGrants(['agent', 'admin']);
        $agent = $this->user(['id_number' => null]);
        $this->principal();
        $admin = $this->user(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('admin.ppra-employment-letters.store'), ['user_id' => $agent->id])
            ->assertRedirect();

        $this->assertDatabaseCount('ppra_employment_letters', 0);
    }

    public function test_admin_create_on_behalf_respects_branch_scope(): void
    {
        $this->seedLetterGrants(['agent', 'branch_manager', 'admin']);
        $branchB = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Stanford']);
        $agentOtherBranch = $this->user(['branch_id' => $branchB->id]);
        $this->principal();

        // branch_manager scope (unseeded fallback = 'branch') — the admin's own branch is $this->branch.
        $bm = $this->user(['role' => 'branch_manager']);

        $this->actingAs($bm)
            ->post(route('admin.ppra-employment-letters.store'), ['user_id' => $agentOtherBranch->id])
            ->assertStatus(403);

        $this->assertDatabaseCount('ppra_employment_letters', 0);
    }

    public function test_admin_create_on_behalf_cannot_target_another_agency(): void
    {
        $this->seedLetterGrants(['agent', 'admin']);
        $otherAgency = Agency::create(['name' => 'Cape Peninsula Properties', 'slug' => 'cape-peninsula-2']);
        $otherAgent = User::factory()->create([
            'agency_id' => $otherAgency->id, 'role' => 'agent', 'is_active' => true,
            'designation' => 'Property Practitioner', 'id_number' => '9001015800088', 'ffc_number' => '7654321',
        ]);

        $this->principal();
        $admin = $this->user(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('admin.ppra-employment-letters.store'), ['user_id' => $otherAgent->id])
            ->assertStatus(403);

        $this->assertDatabaseCount('ppra_employment_letters', 0);
    }

    // ── cc1 round 2 (2026-10-05 evening): picker eligibility, empty match, helper text, blocker link ──

    public function test_picker_lists_exactly_the_roles_ticked_in_role_manager_and_never_assistants_or_inactive(): void
    {
        // office_admin and the custom role are ticked; the practitioner-by-FFC-number rule is gone.
        $this->seedLetterGrants(['agent', 'admin', 'office_admin', 'sales_manager']);
        $this->principal();
        $admin = $this->user(['role' => 'admin']);

        // Angelique's shape: office_admin, Candidate Property Practitioner, own FFC number.
        $officeAdmin = $this->user(['role' => 'office_admin', 'name' => 'Angelique Venter', 'designation' => 'Candidate Property Practitioner']);
        // An agency-defined custom role, ticked.
        $custom = $this->user(['role' => 'sales_manager', 'name' => 'Custom Role Holder', 'ffc_number' => null]);
        // Not eligible.
        $assistant = $this->user(['role' => 'assistant', 'name' => 'An Assistant', 'ffc_number' => '5555555']);
        $inactive  = $this->user(['role' => 'agent', 'name' => 'Gone Agent', 'is_active' => false]);
        $unticked  = $this->user(['role' => 'receptionist', 'name' => 'Front Desk', 'ffc_number' => '8888888']);
        $otherAgency = Agency::create(['name' => 'Cape Peninsula Properties', 'slug' => 'cape-peninsula-3']);
        $foreign = User::factory()->create(['agency_id' => $otherAgency->id, 'role' => 'office_admin', 'name' => 'Foreign Office', 'is_active' => true, 'ffc_number' => '7777777']);

        $ids = app(PractitionerFfcRosterService::class)->letterCandidatesFor($this->agency->id)->pluck('id')->all();

        $this->assertContains($officeAdmin->id, $ids);
        $this->assertContains($custom->id, $ids, 'a ticked role needs no FFC number to be listed');
        $this->assertNotContains($assistant->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
        $this->assertNotContains($unticked->id, $ids, 'an FFC number alone no longer qualifies — the role must be ticked');
        $this->assertNotContains($foreign->id, $ids);

        // And the real screen shows her.
        $shown = $this->actingAs($admin)
            ->get(route('admin.ppra-employment-letters.create'))
            ->assertOk()
            ->assertSee('Angelique Venter')
            ->viewData('agents')->pluck('id')->all();
        $this->assertContains($officeAdmin->id, $shown);
        $this->assertNotContains($assistant->id, $shown);

        // Admin can start her letter (scope 'all'); store accepts her id.
        $this->actingAs($admin)
            ->post(route('admin.ppra-employment-letters.store'), ['user_id' => $officeAdmin->id])
            ->assertRedirect();
        $this->assertDatabaseHas('ppra_employment_letters', ['user_id' => $officeAdmin->id]);

        // A role that is not ticked is blocked on a direct POST too, not merely unlisted.
        $this->actingAs($admin)
            ->post(route('admin.ppra-employment-letters.store'), ['user_id' => $unticked->id])
            ->assertStatus(403);
    }

    public function test_unticking_the_role_removes_her_from_the_picker_and_her_portal_tab_and_reticking_restores_both(): void
    {
        $this->seedLetterGrants(['agent', 'admin', 'office_admin']);
        $this->principal();
        $admin = $this->user(['role' => 'admin']);
        $angelique = $this->user(['role' => 'office_admin', 'name' => 'Angelique Venter', 'designation' => 'Candidate Property Practitioner']);

        $pickerIds = fn () => $this->actingAs($admin)->get(route('admin.ppra-employment-letters.create'))
            ->assertOk()->viewData('agents')->pluck('id')->all();
        $portalHasTab = fn () => str_contains($this->actingAs($angelique)->get(route('agent.portal'))->assertOk()->getContent(), "sub.documents = 'ppra_employment_letter'");

        $this->assertContains($angelique->id, $pickerIds());
        $this->assertTrue($portalHasTab());

        // Untick, exactly as Role Manager's savePermissions does (soft-delete the row).
        RolePermission::where('agency_id', $this->agency->id)->where('role', 'office_admin')
            ->where('permission_key', PractitionerFfcRosterService::LETTER_PERMISSION)->delete();
        PermissionService::clearCache();

        $this->assertNotContains($angelique->id, $pickerIds());
        $this->assertFalse($portalHasTab());
        // Direct self-service POST is blocked as well, not just unlinked.
        $this->actingAs($angelique)->post(route('ppra-employment-letters.store'))->assertStatus(403);
        $this->assertDatabaseCount('ppra_employment_letters', 0);

        // Re-tick.
        RolePermission::withTrashed()->where('agency_id', $this->agency->id)->where('role', 'office_admin')
            ->where('permission_key', PractitionerFfcRosterService::LETTER_PERMISSION)->restore();
        PermissionService::clearCache();

        $this->assertContains($angelique->id, $pickerIds());
        $this->assertTrue($portalHasTab());
    }

    public function test_backfill_migration_grants_picker_roles_and_letter_holders_idempotently_and_reverses_cleanly(): void
    {
        $this->principal();                                                      // agent role, in today's picker
        $this->user(['role' => 'office_admin']);                                 // FFC holder -> today's picker
        $this->user(['role' => 'receptionist', 'ffc_number' => null]);           // not in picker, no letter
        $this->user(['role' => 'assistant', 'ffc_number' => '5555555']);         // never
        $this->user(['role' => 'sales_manager', 'is_active' => false]);          // inactive -> not in picker
        $letterHolder = $this->user(['role' => 'viewer', 'ffc_number' => null]); // not in picker but has a letter
        PpraEmploymentLetter::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'user_id' => $letterHolder->id,
            'principal_user_id' => $letterHolder->id, 'status' => PpraEmploymentLetter::STATUS_AWAITING_SIGNED_COPY,
            'created_by_user_id' => $letterHolder->id,
        ]);
        Role::create(['name' => 'admin', 'label' => 'Admin', 'agency_id' => $this->agency->id]);

        $migration = require base_path('database/migrations/2026_10_08_130000_grant_ppra_employment_letter_receive_permission.php');
        $migration->up();
        $migration->up(); // idempotent

        $roles = fn () => RolePermission::where('agency_id', $this->agency->id)
            ->where('permission_key', PractitionerFfcRosterService::LETTER_PERMISSION)->pluck('role')->sort()->values()->all();
        $this->assertSame(['admin', 'agent', 'office_admin', 'viewer'], $roles());
        $this->assertSame(1, RolePermission::where('agency_id', $this->agency->id)->where('role', 'office_admin')
            ->where('permission_key', PractitionerFfcRosterService::LETTER_PERMISSION)->count());

        // A role an admin has since unticked is NOT resurrected by anything but a re-run of up() on a fresh key.
        $migration->down();
        $this->assertSame([], $roles());
        $this->assertSame(0, RolePermission::withTrashed()->where('permission_key', PractitionerFfcRosterService::LETTER_PERMISSION)->count());
    }

    public function test_picker_keeps_branch_scope_for_non_practitioner_roles(): void
    {
        $this->seedLetterGrants(['agent', 'branch_manager', 'office_admin']);
        $branchB = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Stanford']);
        $otherBranchOfficeAdmin = $this->user(['role' => 'office_admin', 'branch_id' => $branchB->id, 'name' => 'Other Branch Office']);
        $sameBranchOfficeAdmin  = $this->user(['role' => 'office_admin', 'name' => 'Same Branch Office']);
        $this->principal();
        $bm = $this->user(['role' => 'branch_manager']); // unseeded fallback scope = 'branch'

        $shown = $this->actingAs($bm)
            ->get(route('admin.ppra-employment-letters.create'))
            ->assertOk()
            ->assertSee('Same Branch Office')
            ->viewData('agents')->pluck('id')->all();
        $this->assertContains($sameBranchOfficeAdmin->id, $shown);
        $this->assertNotContains($otherBranchOfficeAdmin->id, $shown);

        $this->actingAs($bm)
            ->post(route('admin.ppra-employment-letters.store'), ['user_id' => $otherBranchOfficeAdmin->id])
            ->assertStatus(403);
    }

    public function test_create_page_has_no_subtitle_and_renders_a_safe_no_match_row_even_with_an_apostrophe_name(): void
    {
        $this->seedLetterGrants(['agent', 'admin']);
        $this->principal();
        $this->user(['name' => "Sean O'Brien"]);
        $admin = $this->user(['role' => 'admin']);

        $html = $this->actingAs($admin)
            ->get(route('admin.ppra-employment-letters.create'))
            ->assertOk()
            ->assertDontSee('Pick the agent this letter is for', false)
            ->assertDontSee('this only starts it on their behalf', false)
            ->assertSee('No agents match')
            ->assertSee('data-testid="no-agents-match"', false)
            ->getContent();

        // The apostrophe must never reach an inline JS string raw (it would break Alpine's expression).
        $this->assertStringNotContainsString("'sean o'brien'", $html);
        $this->assertStringNotContainsString("sean o&#039;brien'.includes", $html);
    }

    public function test_portal_tab_has_no_helper_line_and_firm_number_blocker_links_to_company_settings_only_for_admins(): void
    {
        $this->agency->update(['ppra_number' => null]);

        // Explicit grants (table no longer "unseeded", so no fail-open): both roles may use the
        // letter, only admin may open Company Settings where the firm number lives.
        foreach (['admin', 'agent'] as $role) {
            foreach (['access_my_portal', 'ppra_employment_letters.view', 'ppra_employment_letters.create'] as $key) {
                RolePermission::create(['role' => $role, 'permission_key' => $key, 'scope' => 'own', 'agency_id' => $this->agency->id]);
            }
        }
        RolePermission::create(['role' => 'admin', 'permission_key' => 'manage_performance_settings', 'scope' => 'all', 'agency_id' => $this->agency->id]);
        foreach (['admin', 'agent'] as $role) {
            RolePermission::create(['role' => $role, 'permission_key' => PractitionerFfcRosterService::LETTER_PERMISSION, 'scope' => null, 'agency_id' => $this->agency->id]);
        }
        PermissionService::clearCache();

        $this->principal();
        $admin = $this->user(['role' => 'admin', 'name' => 'Portal Admin']);
        $plainAgent = $this->user(['role' => 'agent', 'name' => 'Portal Agent']);

        $service = app(PpraEmploymentLetterService::class);

        $adminMissing = collect($service->missingFieldsFor($admin, $this->agency, $admin))
            ->firstWhere('label', "The agency's PPRA firm number is not set");
        $this->assertNotNull($adminMissing);
        $this->assertSame(route('admin.company-settings') . '#company', $adminMissing['fix_url']);

        $agentMissing = collect($service->missingFieldsFor($plainAgent, $this->agency, $plainAgent))
            ->firstWhere('label', "The agency's PPRA firm number is not set");
        $this->assertNotNull($agentMissing);
        $this->assertNull($agentMissing['fix_url']);

        $page = $this->actingAs($admin)->get(route('agent.portal'))->assertOk();
        $page->assertDontSee('Confirmation of Employment letter for your FFC renewal', false);
        $page->assertSee("The agency's PPRA firm number is not set");
        $page->assertSee(route('admin.company-settings') . '#company', false);

        $agentPage = $this->actingAs($plainAgent)->get(route('agent.portal'))->assertOk();
        $agentPage->assertSee("The agency's PPRA firm number is not set");
        $agentPage->assertDontSee(route('admin.company-settings') . '#company', false);
    }
}
