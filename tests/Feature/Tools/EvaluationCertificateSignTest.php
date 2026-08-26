<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Document;
use App\Models\EvaluationCertificate;
use App\Models\Property;
use App\Models\User;
use App\Services\AgentSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4 — the evaluation-certificate PIN-sign surface (tools.cma.evaluation.sign).
 *
 * Proves the finalising sign end-to-end at the mechanism level: a full-status
 * practitioner with a saved signature + PIN places it, the immutable signed PDF is
 * baked (cc6's dompdf render) and filed to signed_pdf_path, and download() then
 * streams THAT artifact. Guards proven: wrong PIN, candidate, impersonation, re-sign.
 *
 * (role_permissions is unseeded in the test DB → PermissionService fails open, the
 * suite-wide convention, so access_calculators passes without seeding.)
 */
final class EvaluationCertificateSignTest extends TestCase
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
        $this->signatures = app(AgentSignatureService::class);
        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal']);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
    }

    private function practitioner(string $designation): User
    {
        return User::factory()->create([
            'name' => 'Prac ' . $designation, 'role' => 'agent', 'designation' => $designation,
            'branch_id' => $this->branch->id, 'agency_id' => $this->agency->id, 'is_active' => true,
        ]);
    }

    private function withSavedSignature(User $user, string $pin = '1234'): void
    {
        $this->actingAs($user); // save() guards impersonation; a clean acting context
        $this->signatures->save($user, self::PNG, self::PNG, $pin);
    }

    private function draftCertificate(User $creator): EvaluationCertificate
    {
        return EvaluationCertificate::create([
            'agency_id'              => $this->agency->id,
            'address'                => '12 Smith Street, Shelly Beach',
            'property_type'          => 'House',
            'estimated_market_value' => 2500000,
            'bedrooms'               => 3,
            'bathrooms'              => 2,
            'status'                 => EvaluationCertificate::STATUS_DRAFT,
            'created_by_user_id'     => $creator->id,
        ]);
    }

    public function test_full_status_agent_signs_and_files_an_immutable_pdf(): void
    {
        $agent = $this->practitioner('Property Practitioner');
        $this->withSavedSignature($agent, '4321');
        $cert = $this->draftCertificate($agent);

        $res = $this->actingAs($agent)
            ->postJson(route('tools.cma.evaluation.sign', $cert), ['pin' => '4321']);

        $res->assertOk()->assertJson(['ok' => true, 'status' => EvaluationCertificate::STATUS_AUTHORISED]);

        $cert->refresh();
        $this->assertSame(EvaluationCertificate::STATUS_AUTHORISED, $cert->status);
        $this->assertSame($agent->id, $cert->signed_by_user_id);
        $this->assertNotNull($cert->signed_pdf_path, 'signed_pdf_path must be stamped');
        Storage::assertExists($cert->signed_pdf_path);
        $this->assertStringStartsWith('%PDF', Storage::get($cert->signed_pdf_path), 'the filed artifact is a real PDF');
    }

    public function test_download_streams_the_filed_signed_artifact_after_signing(): void
    {
        $agent = $this->practitioner('Property Practitioner');
        $this->withSavedSignature($agent, '4321');
        $cert = $this->draftCertificate($agent);

        $this->actingAs($agent)->postJson(route('tools.cma.evaluation.sign', $cert), ['pin' => '4321'])->assertOk();

        $this->actingAs($agent)
            ->get(route('tools.cma.evaluation.download', $cert))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_wrong_pin_is_rejected_and_certificate_untouched(): void
    {
        $agent = $this->practitioner('Property Practitioner');
        $this->withSavedSignature($agent, '4321');
        $cert = $this->draftCertificate($agent);

        $this->actingAs($agent)
            ->postJson(route('tools.cma.evaluation.sign', $cert), ['pin' => '0000'])
            ->assertStatus(422);

        $cert->refresh();
        $this->assertSame(EvaluationCertificate::STATUS_DRAFT, $cert->status);
        $this->assertNull($cert->signed_by_user_id);
        $this->assertNull($cert->signed_pdf_path);
    }

    public function test_candidate_practitioner_cannot_finalise(): void
    {
        $candidate = $this->practitioner('Candidate Property Practitioner');
        $this->withSavedSignature($candidate, '4321');
        $cert = $this->draftCertificate($candidate);

        $this->actingAs($candidate)
            ->postJson(route('tools.cma.evaluation.sign', $cert), ['pin' => '4321'])
            ->assertStatus(403);

        $this->assertSame(EvaluationCertificate::STATUS_DRAFT, $cert->fresh()->status);
    }

    public function test_impersonator_can_never_place_a_saved_signature(): void
    {
        $agent = $this->practitioner('Property Practitioner');
        $this->withSavedSignature($agent, '4321');
        $cert = $this->draftCertificate($agent);

        $this->actingAs($agent)
            ->withSession(['impersonator_id' => 999])
            ->postJson(route('tools.cma.evaluation.sign', $cert), ['pin' => '4321'])
            ->assertStatus(403);

        $this->assertNull($cert->fresh()->signed_pdf_path);
    }

    public function test_an_already_signed_certificate_cannot_be_re_signed(): void
    {
        $agent = $this->practitioner('Property Practitioner');
        $this->withSavedSignature($agent, '4321');
        $cert = $this->draftCertificate($agent);

        $this->actingAs($agent)->postJson(route('tools.cma.evaluation.sign', $cert), ['pin' => '4321'])->assertOk();

        $this->actingAs($agent)
            ->postJson(route('tools.cma.evaluation.sign', $cert), ['pin' => '4321'])
            ->assertStatus(409);
    }

    public function test_signed_certificate_is_filed_to_the_linked_property_drive(): void
    {
        $agent = $this->practitioner('Property Practitioner');
        $this->withSavedSignature($agent, '4321');

        $property = Property::create([
            'agency_id' => $this->agency->id, 'agent_id' => $agent->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Test property', 'suburb' => 'Margate',
            'property_type' => 'house', 'status' => 'draft', 'price' => 0,
        ]);
        $cert = EvaluationCertificate::create([
            'agency_id' => $this->agency->id, 'address' => '380 Wilfred Street, Shelly Beach',
            'property_id' => $property->id, 'status' => EvaluationCertificate::STATUS_DRAFT,
            'created_by_user_id' => $agent->id,
        ]);

        $this->actingAs($agent)->postJson(route('tools.cma.evaluation.sign', $cert), ['pin' => '4321'])->assertOk();

        // The signed certificate now appears on the property's document drive.
        $this->assertTrue(
            $property->documents()->where('source_type', 'eval_cert')->where('source_id', $cert->id)->exists(),
            'signed certificate should be filed to the linked property drive'
        );
        // Filed exactly once, named by the property address.
        $doc = Document::where('source_type', 'eval_cert')->where('source_id', $cert->id)->get();
        $this->assertCount(1, $doc);
        $this->assertSame('380-Wilfred-Street-Shelly-Beach-Evaluation-Certificate.pdf', $doc->first()->original_name);
        $this->assertSame($cert->fresh()->signed_pdf_path, $doc->first()->storage_path);
    }

    public function test_certificate_with_no_property_files_nothing(): void
    {
        $agent = $this->practitioner('Property Practitioner');
        $this->withSavedSignature($agent, '4321');
        $cert = $this->draftCertificate($agent);   // no property_id

        $this->actingAs($agent)->postJson(route('tools.cma.evaluation.sign', $cert), ['pin' => '4321'])->assertOk();

        $this->assertSame(0, Document::where('source_type', 'eval_cert')->where('source_id', $cert->id)->count());
    }

    // ── Candidate authorisation flow ────────────────────────────────────────────

    public function test_candidate_submits_for_authorisation(): void
    {
        $candidate = $this->practitioner('Candidate Property Practitioner');
        $this->withSavedSignature($candidate, '1111');
        $cert = $this->draftCertificate($candidate);

        $this->actingAs($candidate)
            ->postJson(route('tools.cma.evaluation.submit', $cert), ['pin' => '1111'])
            ->assertOk()->assertJson(['status' => 'pending_authorisation']);

        $cert->refresh();
        $this->assertSame(EvaluationCertificate::STATUS_PENDING_AUTHORISATION, $cert->status);
        $this->assertSame($candidate->id, $cert->signed_by_user_id);   // candidate evaluated + signed
        $this->assertNotNull($cert->candidate_signature_image);        // snapshotted for later bake
        $this->assertNull($cert->signed_pdf_path);                     // not finalised yet
    }

    public function test_a_full_status_practitioner_cannot_submit(): void
    {
        $agent = $this->practitioner('Property Practitioner');
        $this->withSavedSignature($agent, '4321');
        $cert = $this->draftCertificate($agent);

        $this->actingAs($agent)
            ->postJson(route('tools.cma.evaluation.submit', $cert), ['pin' => '4321'])
            ->assertStatus(403);
    }

    public function test_full_status_authorises_a_candidate_certificate_and_files_it(): void
    {
        $candidate = $this->practitioner('Candidate Property Practitioner');
        $this->withSavedSignature($candidate, '1111');
        $authoriser = $this->practitioner('Property Practitioner');   // same branch → eligible
        $this->withSavedSignature($authoriser, '2222');

        $property = Property::create([
            'agency_id' => $this->agency->id, 'agent_id' => $candidate->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'P', 'suburb' => 'Margate',
            'property_type' => 'house', 'status' => 'draft', 'price' => 0,
        ]);
        $cert = EvaluationCertificate::create([
            'agency_id' => $this->agency->id, 'address' => '9 Ocean Drive', 'property_id' => $property->id,
            'status' => EvaluationCertificate::STATUS_DRAFT, 'created_by_user_id' => $candidate->id,
        ]);

        $this->actingAs($candidate)->postJson(route('tools.cma.evaluation.submit', $cert), ['pin' => '1111'])->assertOk();
        $this->actingAs($authoriser)
            ->postJson(route('tools.cma.evaluation.authorise', $cert->fresh()), ['pin' => '2222'])
            ->assertOk()->assertJson(['status' => 'authorised']);

        $cert->refresh();
        $this->assertSame(EvaluationCertificate::STATUS_AUTHORISED, $cert->status);
        $this->assertSame($candidate->id, $cert->signed_by_user_id);       // Evaluated & signed by = candidate
        $this->assertSame($authoriser->id, $cert->authorised_by_user_id);  // Authorised by = full-status
        $this->assertNotNull($cert->signed_pdf_path);
        Storage::assertExists($cert->signed_pdf_path);
        $this->assertTrue(
            $property->documents()->where('source_type', 'eval_cert')->where('source_id', $cert->id)->exists(),
            'authorised candidate certificate should be filed to the property drive'
        );
    }

    public function test_authoriser_rejects_with_note_then_candidate_resubmits(): void
    {
        $candidate = $this->practitioner('Candidate Property Practitioner');
        $this->withSavedSignature($candidate, '1111');
        $authoriser = $this->practitioner('Property Practitioner');
        $this->withSavedSignature($authoriser, '2222');
        $cert = $this->draftCertificate($candidate);

        $this->actingAs($candidate)->postJson(route('tools.cma.evaluation.submit', $cert), ['pin' => '1111'])->assertOk();

        // Reject requires a note.
        $this->actingAs($authoriser)->postJson(route('tools.cma.evaluation.reject', $cert->fresh()), ['note' => ''])->assertStatus(422);

        // Reject with a note → returned to the candidate.
        $this->actingAs($authoriser)
            ->postJson(route('tools.cma.evaluation.reject', $cert->fresh()), ['note' => 'Re-check the comparables.'])
            ->assertOk()->assertJson(['status' => 'rejected']);
        $cert->refresh();
        $this->assertSame(EvaluationCertificate::STATUS_REJECTED, $cert->status);
        $this->assertSame('Re-check the comparables.', $cert->reject_note);

        // Candidate resubmits → pending again, note cleared.
        $this->actingAs($candidate)
            ->postJson(route('tools.cma.evaluation.submit', $cert->fresh()), ['pin' => '1111'])
            ->assertOk()->assertJson(['status' => 'pending_authorisation']);
        $this->assertNull($cert->fresh()->reject_note);
    }

    public function test_candidate_cannot_authorise_and_stranger_branch_cannot_either(): void
    {
        $candidate = $this->practitioner('Candidate Property Practitioner');
        $this->withSavedSignature($candidate, '1111');
        $cert = $this->draftCertificate($candidate);
        $this->actingAs($candidate)->postJson(route('tools.cma.evaluation.submit', $cert), ['pin' => '1111'])->assertOk();

        // A candidate cannot authorise.
        $this->actingAs($candidate)
            ->postJson(route('tools.cma.evaluation.authorise', $cert->fresh()), ['pin' => '1111'])
            ->assertStatus(403);

        // A full-status practitioner in ANOTHER branch is not an eligible authoriser.
        $otherBranch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Other']);
        $stranger = User::factory()->create([
            'name' => 'Stranger', 'role' => 'agent', 'designation' => 'Property Practitioner',
            'branch_id' => $otherBranch->id, 'agency_id' => $this->agency->id, 'is_active' => true,
        ]);
        $this->withSavedSignature($stranger, '3333');
        $this->actingAs($stranger)
            ->postJson(route('tools.cma.evaluation.authorise', $cert->fresh()), ['pin' => '3333'])
            ->assertStatus(403);
    }

    public function test_queue_is_scoped_by_role(): void
    {
        $candidate = $this->practitioner('Candidate Property Practitioner');
        $this->withSavedSignature($candidate, '1111');
        $authoriser = $this->practitioner('Property Practitioner');
        $cert = $this->draftCertificate($candidate);
        $this->actingAs($candidate)->postJson(route('tools.cma.evaluation.submit', $cert), ['pin' => '1111'])->assertOk();

        $this->actingAs($candidate)->getJson(route('tools.cma.evaluation.queue'))
            ->assertOk()->assertJson(['role' => 'candidate'])
            ->assertJsonFragment(['id' => $cert->id, 'status' => 'pending_authorisation']);

        $this->actingAs($authoriser)->getJson(route('tools.cma.evaluation.queue'))
            ->assertOk()->assertJson(['role' => 'authoriser'])
            ->assertJsonFragment(['id' => $cert->id]);
    }

    public function test_candidate_submit_notifies_eligible_authorisers(): void
    {
        $candidate = $this->practitioner('Candidate Property Practitioner');
        $this->withSavedSignature($candidate, '1111');
        $authoriser = $this->practitioner('Property Practitioner');   // same branch → eligible
        $cert = $this->draftCertificate($candidate);

        $this->actingAs($candidate)->postJson(route('tools.cma.evaluation.submit', $cert), ['pin' => '1111'])->assertOk();

        // A bell notification lands for the eligible authoriser, linking to the eval screen.
        $this->assertDatabaseHas('notifications', [
            'type'            => 'evalcert.authorisation_pending',
            'notifiable_id'   => $authoriser->id,
            'notifiable_type' => User::class,
        ]);
    }

    public function test_pending_authorisation_count_is_scoped_to_eligible_authorisers(): void
    {
        $candidate = $this->practitioner('Candidate Property Practitioner');
        $this->withSavedSignature($candidate, '1111');
        $authoriser = $this->practitioner('Property Practitioner');   // eligible (same branch)
        $cert = $this->draftCertificate($candidate);
        $this->actingAs($candidate)->postJson(route('tools.cma.evaluation.submit', $cert), ['pin' => '1111'])->assertOk();

        $svc = app(\App\Services\EvaluationAuthorisationService::class);
        $this->assertSame(1, $svc->pendingCountFor($authoriser));   // authoriser sees the badge count
        $this->assertSame(0, $svc->pendingCountFor($candidate));    // the candidate is not an authoriser

        $otherBranch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Other']);
        $stranger = User::factory()->create([
            'name' => 'Stranger', 'role' => 'agent', 'designation' => 'Property Practitioner',
            'branch_id' => $otherBranch->id, 'agency_id' => $this->agency->id, 'is_active' => true,
        ]);
        $this->assertSame(0, $svc->pendingCountFor($stranger));      // different branch → not eligible
    }
}
