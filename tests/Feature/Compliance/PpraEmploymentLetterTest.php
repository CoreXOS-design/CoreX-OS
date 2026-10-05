<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Mail\Compliance\PpraEmploymentLetterPrincipalNotificationMail;
use App\Mail\Compliance\PpraEmploymentLetterSignedMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Compliance\PpraEmploymentLetter;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\AgentSignatureService;
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
 * Mirrors EvaluationCertificateSignTest's shape (same PIN mechanism,
 * AgentSignatureService, role_permissions unseeded → PermissionService
 * fails open per that test's own documented convention — agent='own',
 * branch_manager='branch', admin='all' for scopeVisibleTo()).
 */
final class PpraEmploymentLetterTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC';

    private Agency $agency;
    private Branch $branch;
    private AgentSignatureService $signatures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        Mail::fake();
        $this->signatures = app(AgentSignatureService::class);
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
            foreach (['access_my_portal', 'ppra_employment_letters.view', 'ppra_employment_letters.create',
                      'ppra_employment_letters.sign_as_principal'] as $key) {
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

    private function withSavedSignature(User $user, string $pin): void
    {
        $this->actingAs($user);
        $this->signatures->save($user, self::PNG, self::PNG, $pin);
    }

    // ── The full signing flow ────────────────────────────────────────────────

    public function test_full_signing_flow_draft_to_signed(): void
    {
        $agent = $this->user();
        $this->withSavedSignature($agent, '1111');
        $principal = $this->principal();
        $this->withSavedSignature($principal, '2222');

        $create = $this->actingAs($agent)->post(route('ppra-employment-letters.store'));
        $create->assertRedirect();

        $letter = PpraEmploymentLetter::where('user_id', $agent->id)->firstOrFail();
        $this->assertSame(PpraEmploymentLetter::STATUS_AWAITING_AGENT_SIGNATURE, $letter->status);
        $this->assertSame($principal->id, $letter->principal_user_id);
        $this->assertSame($this->branch->id, $letter->branch_id);

        // Unsigned preview downloads a real PDF with no exception.
        $this->actingAs($agent)
            ->get(route('ppra-employment-letters.download', $letter))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        // Agent signs.
        $this->actingAs($agent)
            ->post(route('ppra-employment-letters.sign-as-agent', $letter), ['pin' => '1111'])
            ->assertRedirect();

        $letter->refresh();
        $this->assertSame(PpraEmploymentLetter::STATUS_AWAITING_PRINCIPAL_SIGNATURE, $letter->status);
        $this->assertNotNull($letter->agent_signed_at);
        $this->assertSame('127.0.0.1', $letter->agent_signed_ip);
        $this->assertNull($letter->signed_pdf_path);

        Mail::assertSent(PpraEmploymentLetterPrincipalNotificationMail::class, fn ($m) => $m->hasTo($principal->email));

        // Principal signs.
        $this->actingAs($principal)
            ->post(route('ppra-employment-letters.sign-as-principal', $letter), ['pin' => '2222'])
            ->assertRedirect();

        $letter->refresh();
        $this->assertSame(PpraEmploymentLetter::STATUS_SIGNED, $letter->status);
        $this->assertNotNull($letter->principal_signed_at);
        $this->assertNotNull($letter->signed_pdf_path);
        Storage::assertExists($letter->signed_pdf_path);
        $this->assertStringStartsWith('%PDF', Storage::get($letter->signed_pdf_path));

        Mail::assertSent(PpraEmploymentLetterSignedMail::class, fn ($m) => $m->hasTo($agent->email));
        $this->assertDatabaseHas('notifications', [
            'type'          => 'ppra_employment_letter.signed',
            'notifiable_id' => $agent->id,
        ]);

        // The signed PDF is now immutable — download streams the FILED artifact.
        $this->actingAs($agent)
            ->get(route('ppra-employment-letters.download', $letter))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_wrong_pin_is_rejected_and_letter_untouched(): void
    {
        $agent = $this->user();
        $this->withSavedSignature($agent, '1111');
        $this->principal();

        $this->actingAs($agent)->post(route('ppra-employment-letters.store'));
        $letter = PpraEmploymentLetter::where('user_id', $agent->id)->firstOrFail();

        $this->actingAs($agent)
            ->post(route('ppra-employment-letters.sign-as-agent', $letter), ['pin' => '0000'])
            ->assertRedirect();

        $letter->refresh();
        $this->assertSame(PpraEmploymentLetter::STATUS_AWAITING_AGENT_SIGNATURE, $letter->status);
        $this->assertNull($letter->agent_signed_at);
    }

    // ── Missing merge data ───────────────────────────────────────────────────

    public function test_missing_merge_data_blocks_letter_creation(): void
    {
        $agent = $this->user(['id_number' => null, 'ffc_number' => null]);
        $this->withSavedSignature($agent, '1111');
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

    // ── Agent-is-principal: self-sign-both, no email round-trip ──────────────

    public function test_agent_who_is_the_principal_signs_both_blocks_with_no_round_trip_email(): void
    {
        $agent = $this->user([
            'designation'               => 'Principal',
            'is_principal_practitioner' => true,
        ]);
        $this->withSavedSignature($agent, '1234');

        $this->actingAs($agent)->post(route('ppra-employment-letters.store'))->assertRedirect();
        $letter = PpraEmploymentLetter::where('user_id', $agent->id)->firstOrFail();
        $this->assertSame($agent->id, $letter->principal_user_id);

        $this->actingAs($agent)
            ->post(route('ppra-employment-letters.sign-as-agent', $letter), ['pin' => '1234'])
            ->assertRedirect();

        $letter->refresh();
        $this->assertSame(PpraEmploymentLetter::STATUS_AWAITING_PRINCIPAL_SIGNATURE, $letter->status);
        // No principal email — the agent IS the principal, sitting right there.
        Mail::assertNotSent(PpraEmploymentLetterPrincipalNotificationMail::class);

        // Still goes through the second PIN step for the audit trail, same user.
        $this->actingAs($agent)
            ->post(route('ppra-employment-letters.sign-as-principal', $letter), ['pin' => '1234'])
            ->assertRedirect();

        $letter->refresh();
        $this->assertSame(PpraEmploymentLetter::STATUS_SIGNED, $letter->status);
        $this->assertNotNull($letter->agent_signed_at);
        $this->assertNotNull($letter->principal_signed_at);
    }

    // ── Cancel / archive ─────────────────────────────────────────────────────

    public function test_agent_can_cancel_an_unsigned_letter_and_signed_cannot_be_cancelled(): void
    {
        $agent = $this->user();
        $this->withSavedSignature($agent, '1111');
        $principal = $this->principal();
        $this->withSavedSignature($principal, '2222');

        $this->actingAs($agent)->post(route('ppra-employment-letters.store'));
        $letter = PpraEmploymentLetter::where('user_id', $agent->id)->firstOrFail();

        $this->actingAs($agent)->post(route('ppra-employment-letters.cancel', $letter))->assertRedirect();
        $this->assertSoftDeleted('ppra_employment_letters', ['id' => $letter->id]);

        // A signed letter cannot be cancelled this way.
        $letter2 = app(PpraEmploymentLetterService::class)->create($agent, $agent, null);
        $this->actingAs($agent)->post(route('ppra-employment-letters.sign-as-agent', $letter2), ['pin' => '1111']);
        $this->actingAs($principal)->post(route('ppra-employment-letters.sign-as-principal', $letter2->fresh()), ['pin' => '2222']);
        $letter2->refresh();
        $this->assertTrue($letter2->isSigned());

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
     * cc1's HR->Documents nav finding (2026-10-05, flagged for cc2 to close):
     * the admin register's own route middleware had no SCOPE check, only a
     * bare permission-exists check — an 'own'-scoped agent (every agent is
     * seeded 'own' on ppra_employment_letters.view for their My Portal
     * self-service flow) could still reach the ADMIN register by direct
     * URL, even though the sidebar never links there for them. Closed by
     * PpraEmploymentLetterController::assertAdminScope() — this register is
     * branch/all only, not even by direct URL.
     */
    public function test_own_scoped_agent_cannot_reach_admin_register_by_direct_url(): void
    {
        $agent = $this->user();
        $this->principal();

        $this->actingAs($agent)->post(route('ppra-employment-letters.store'));
        $letter = PpraEmploymentLetter::where('user_id', $agent->id)->firstOrFail();

        $this->actingAs($agent)->get(route('admin.ppra-employment-letters.index'))->assertStatus(403);
        $this->actingAs($agent)->get(route('admin.ppra-employment-letters.show', $letter->id))->assertStatus(403);
        $this->actingAs($agent)->get(route('admin.ppra-employment-letters.download', $letter->id))->assertStatus(403);
        $this->actingAs($agent)->get(route('admin.ppra-employment-letters.create'))->assertStatus(403);
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
        $this->assertTrue($officeAdmin->hasPermission('ppra_employment_letters.sign_as_principal'));

        // The pre-existing grant for this role is untouched — merge is additive-only.
        $this->assertTrue($officeAdmin->hasPermission('communications.view'));

        $role->delete();
    }

    // ── Admin create-on-behalf (bug #3, 2026-10-05) ──────────────────────────

    public function test_admin_creates_letter_on_behalf_and_agent_signs_with_own_pin(): void
    {
        $this->seedLetterGrants(['agent', 'admin']);
        $agent = $this->user();
        $this->withSavedSignature($agent, '4444');
        $principal = $this->principal();
        $this->withSavedSignature($principal, '5555');

        $admin = $this->user(['role' => 'admin']);

        $store = $this->actingAs($admin)
            ->post(route('admin.ppra-employment-letters.store'), ['user_id' => $agent->id]);
        $store->assertRedirect();

        $letter = PpraEmploymentLetter::where('user_id', $agent->id)->firstOrFail();
        $this->assertSame(PpraEmploymentLetter::STATUS_AWAITING_AGENT_SIGNATURE, $letter->status);
        $this->assertSame($admin->id, $letter->created_by_user_id);
        $this->assertSame($principal->id, $letter->principal_user_id);

        // The agent signs with THEIR OWN PIN — the admin never touches a signature.
        $this->actingAs($agent)
            ->post(route('ppra-employment-letters.sign-as-agent', $letter), ['pin' => '4444'])
            ->assertRedirect();

        $letter->refresh();
        $this->assertSame(PpraEmploymentLetter::STATUS_AWAITING_PRINCIPAL_SIGNATURE, $letter->status);
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
            'principal_user_id' => $letterHolder->id, 'status' => PpraEmploymentLetter::STATUS_SIGNED,
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
