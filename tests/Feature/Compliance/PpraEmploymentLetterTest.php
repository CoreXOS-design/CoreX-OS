<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Mail\Compliance\PpraEmploymentLetterPrincipalNotificationMail;
use App\Mail\Compliance\PpraEmploymentLetterSignedMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Compliance\PpraEmploymentLetter;
use App\Models\User;
use App\Services\AgentSignatureService;
use App\Services\Compliance\PpraEmploymentLetterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
