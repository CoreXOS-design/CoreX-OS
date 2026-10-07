<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionSignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Conductor brief 2026-09-29 — "closing the same gaps on rental
 * INSPECTIONS": the awaiting_wet_ink tracking marker (new — §16/§17's
 * original wet-ink build only ever built a direct upload, never a
 * pre-marker), filing the scan as a property Document via the shared
 * SignedDocumentDistributionService (new), and the completion/agent-signing
 * gates recognising an awaiting party as not-yet-resolved (new). The
 * pre-existing wet_ink capture/supersede mechanics themselves are already
 * covered by RentalInspectionRecordingControllerTest — not re-proven here.
 */
final class RentalInspectionWetInkAwaitingTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Feature\RentalInspections\Concerns\RecordsAttendance;

    private const TEST_SIGNATURE_IMAGE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private Contact $tenant;
    private Contact $landlord;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'Wet Ink Inspection Agency', 'slug' => 'wi-inspection-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
        ]);
        $this->actingAs($this->agent);

        // The schema snapshot already carries this global reference row (a bare
        // create() hit the unique slug), while a bare test DB may not — so
        // find-or-create, never a blind insert.
        DocumentType::firstOrCreate(['slug' => 'inspection_report'], ['label' => 'Inspection Report', 'is_active' => true]);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Wet Ink Inspection Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);

        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 12000, 'start_date' => now()->subMonths(2),
            'created_by_user_id' => $this->agent->id,
        ]);

        $this->tenant = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Thabo', 'last_name' => 'Tenant', 'email' => 'tenant-' . uniqid() . '@example.test',
            'created_by_user_id' => $this->agent->id,
        ]);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);

        $this->landlord = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Lindiwe', 'last_name' => 'Landlord', 'email' => 'landlord-' . uniqid() . '@example.test',
            'created_by_user_id' => $this->agent->id,
        ]);
        $this->property->contacts()->attach($this->landlord->id, ['role' => 'landlord']);

        Storage::fake('public');
    }

    private function makeInspection(string $type = RentalInspection::TYPE_OUT): RentalInspection
    {
        $inspection = RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'type' => $type,
            'created_by_user_id' => $this->agent->id,
        ]);
        $inspection->startAwaitingSignature();

        return $inspection;
    }

    // ── The awaiting_wet_ink tracking marker ────────────────────────────

    public function test_agent_can_mark_a_tenant_as_awaiting_wet_ink(): void
    {
        $inspection = $this->makeInspection();

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => RentalInspectionSignature::PARTY_TENANT,
            'disposition' => RentalInspectionSignature::DISPOSITION_AWAITING_WET_INK,
            'party_contact_id' => $this->tenant->id,
        ])->assertStatus(201)->assertJsonFragment(['disposition' => 'awaiting_wet_ink', 'party_role' => 'tenant']);

        $this->assertDatabaseHas('rental_inspection_signatures', [
            'rental_inspection_id' => $inspection->id, 'party_role' => 'tenant',
            'party_contact_id' => $this->tenant->id, 'disposition' => 'awaiting_wet_ink',
            'wet_ink_upload_path' => null, 'party_signature_path' => null,
        ]);
    }

    public function test_the_agent_is_never_awaiting_wet_ink(): void
    {
        $inspection = $this->makeInspection();

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => RentalInspectionSignature::PARTY_AGENT,
            'disposition' => RentalInspectionSignature::DISPOSITION_AWAITING_WET_INK,
        ])->assertStatus(422);
    }

    // ── The scan gets filed as a Document ────────────────────────────────

    public function test_a_direct_wet_ink_capture_files_the_scan_as_a_document(): void
    {
        $inspection = $this->makeInspection();

        $response = $this->post(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => RentalInspectionSignature::PARTY_TENANT,
            'disposition' => RentalInspectionSignature::DISPOSITION_WET_INK,
            'party_contact_id' => $this->tenant->id,
            'wet_ink_file' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(201);
        $signatureId = $response->json('id');

        $document = Document::where('source_type', 'rental_inspection_signature_wet_ink')
            ->where('source_id', $signatureId)->first();
        $this->assertNotNull($document, 'The wet-ink scan should be filed as a Document.');
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertSame('inspection_report', DocumentType::find($document->document_type_id)?->slug);
        $this->assertTrue($document->properties->pluck('id')->contains($this->property->id));
    }

    public function test_resolving_an_awaiting_row_files_the_scan_against_the_replacement_signature(): void
    {
        $inspection = $this->makeInspection();
        $awaiting = RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_TENANT, RentalInspectionSignature::DISPOSITION_AWAITING_WET_INK, [
            'party_contact_id' => $this->tenant->id, 'recorded_by_user_id' => $this->agent->id,
        ]);

        $response = $this->post(
            route('corex.rental-inspections.signatures.supersede-wet-ink', [$inspection, $awaiting]),
            ['wet_ink_file' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf')],
            ['Accept' => 'application/json'],
        );

        $response->assertStatus(201)->assertJsonFragment(['disposition' => 'wet_ink']);
        $replacementId = $response->json('id');

        $awaiting->refresh();
        $this->assertNotNull($awaiting->superseded_at);
        $this->assertSame($replacementId, $awaiting->superseded_by_signature_id);

        $this->assertDatabaseHas('documents', [
            'source_type' => 'rental_inspection_signature_wet_ink', 'source_id' => $replacementId,
        ]);
    }

    // ── Mixed mode + completion gates ────────────────────────────────────

    public function test_mixed_mode_tenant_wet_ink_landlord_canvas_signed_completes(): void
    {
        $inspection = $this->makeInspection();

        $this->post(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => RentalInspectionSignature::PARTY_TENANT,
            'disposition' => RentalInspectionSignature::DISPOSITION_WET_INK,
            'party_contact_id' => $this->tenant->id,
            'wet_ink_file' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(201);

        RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_LANDLORD, RentalInspectionSignature::DISPOSITION_SIGNED, [
            'party_contact_id' => $this->landlord->id, 'party_signature_path' => '/fake/landlord.png',
            'recorded_by_user_id' => $this->agent->id,
        ]);

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => RentalInspectionSignature::PARTY_AGENT,
            'disposition' => RentalInspectionSignature::DISPOSITION_SIGNED,
            'signature_image' => self::TEST_SIGNATURE_IMAGE,
        ])->assertStatus(201);
        $this->recordAttendanceForEveryParty($inspection);

        $this->postJson(route('corex.rental-inspections.complete', $inspection))->assertOk();
        $this->assertSame(RentalInspection::STATUS_COMPLETED, $inspection->fresh()->status);
    }

    public function test_completion_is_blocked_while_a_party_is_still_awaiting_wet_ink(): void
    {
        $inspection = $this->makeInspection();
        RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_TENANT, RentalInspectionSignature::DISPOSITION_AWAITING_WET_INK, [
            'party_contact_id' => $this->tenant->id, 'recorded_by_user_id' => $this->agent->id,
        ]);
        RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_LANDLORD, RentalInspectionSignature::DISPOSITION_SIGNED, [
            'party_contact_id' => $this->landlord->id, 'party_signature_path' => '/fake/landlord.png',
            'recorded_by_user_id' => $this->agent->id,
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('awaiting a paper signature');
        $this->recordAttendanceForEveryParty($inspection);
        $inspection->markCompleted();
    }

    public function test_the_agent_cannot_sign_while_a_party_is_still_awaiting_wet_ink(): void
    {
        $inspection = $this->makeInspection();
        RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_TENANT, RentalInspectionSignature::DISPOSITION_AWAITING_WET_INK, [
            'party_contact_id' => $this->tenant->id, 'recorded_by_user_id' => $this->agent->id,
        ]);
        RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_LANDLORD, RentalInspectionSignature::DISPOSITION_SIGNED, [
            'party_contact_id' => $this->landlord->id, 'party_signature_path' => '/fake/landlord.png',
            'recorded_by_user_id' => $this->agent->id,
        ]);

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => RentalInspectionSignature::PARTY_AGENT,
            'disposition' => RentalInspectionSignature::DISPOSITION_SIGNED,
            'signature_image' => self::TEST_SIGNATURE_IMAGE,
        ])->assertStatus(422);
    }

    // ── Print for signature ──────────────────────────────────────────────

    public function test_print_for_signature_returns_a_pdf(): void
    {
        $inspection = $this->makeInspection();

        $response = $this->get(route('corex.rental-inspections.print-for-signature', $inspection));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }
}
