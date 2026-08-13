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
}
