<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInventory;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalInventory;
use App\Models\RentalInventoryLine;
use App\Models\RentalInventorySignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FindsOrCreatesDocumentTypes;
use Tests\TestCase;

/**
 * Conductor brief 2026-09-29 — wet-ink signing for rental Inventory, built
 * here for the first time. Mirrors the invariants
 * RentalInspectionSignature's own §16/§17 wet-ink build already established
 * (see RentalInventorySignature's own docblocks) — this file proves the
 * SAME set of behaviours land correctly on Inventory: the awaiting_wet_ink
 * tracking marker, direct wet-ink capture, resolving/correcting via
 * supersede-wet-ink, mixed-mode completion, the completion/agent-signing
 * gates, and the scan getting filed as a Document via the shared
 * SignedDocumentDistributionService.
 */
final class RentalInventoryWetInkTest extends TestCase
{
    use FindsOrCreatesDocumentTypes;
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private RentalInventory $inventory;
    private Contact $tenant;
    private Contact $landlord;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'Wet Ink Inventory Agency', 'slug' => 'wi-inventory-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        // Deliberately no CommunicationMailbox row — complete()'s own
        // auto-email path (unrelated to this build) must never open a real
        // SMTP connection from a test, same mail-safety convention
        // RentalInventoryDistributionTest already establishes.
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
        ]);
        $this->actingAs($this->agent);

        // The reference rows this build reuses for "a sensible doc type" —
        // not globally seeded in a test DB (DocumentTypesCatalogueSeeder
        // only runs via deploy:sync-reference-data), so created here
        // directly, same convention DocumentTypeClassifierTest already uses.
        $this->documentTypeId('inventory_list', 'Inventory List');
        $this->documentTypeId('inspection_report', 'Inspection Report'); // already in the snapshot catalogue — reused

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Wet Ink Inventory Property', 'status' => 'active', 'listing_type' => 'rental',
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

        $room = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'type' => 'lounge', 'label' => 'Lounge', 'source' => 'manual', 'sort_order' => 1,
            'created_by_user_id' => $this->agent->id,
        ]);

        $this->inventory = RentalInventory::start($this->property, $this->lease, $this->agent);
        RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $room->id, 'room_label' => $room->label,
            'quantity' => 1, 'description' => 'Samsung TV 55"', 'created_by_user_id' => $this->agent->id,
        ]);

        Storage::fake('public');
    }

    // ── The awaiting_wet_ink tracking marker ────────────────────────────

    public function test_agent_can_mark_a_tenant_as_awaiting_wet_ink(): void
    {
        $this->postJson(route('corex.rental-inventories.signatures.store', $this->inventory), [
            'party_role' => RentalInventorySignature::PARTY_TENANT,
            'disposition' => RentalInventorySignature::DISPOSITION_AWAITING_WET_INK,
            'party_contact_id' => $this->tenant->id,
        ])->assertStatus(201)->assertJsonFragment(['disposition' => 'awaiting_wet_ink', 'party_role' => 'tenant']);

        $this->assertDatabaseHas('rental_inventory_signatures', [
            'rental_inventory_id' => $this->inventory->id, 'party_role' => 'tenant',
            'party_contact_id' => $this->tenant->id, 'disposition' => 'awaiting_wet_ink',
            'wet_ink_upload_path' => null, 'party_signature_path' => null,
        ]);
    }

    public function test_the_agent_is_never_awaiting_wet_ink(): void
    {
        $this->postJson(route('corex.rental-inventories.signatures.store', $this->inventory), [
            'party_role' => RentalInventorySignature::PARTY_AGENT,
            'disposition' => RentalInventorySignature::DISPOSITION_AWAITING_WET_INK,
        ])->assertStatus(422);
    }

    public function test_the_agent_is_never_wet_ink(): void
    {
        $this->postJson(route('corex.rental-inventories.signatures.store', $this->inventory), [
            'party_role' => RentalInventorySignature::PARTY_AGENT,
            'disposition' => RentalInventorySignature::DISPOSITION_WET_INK,
            'wet_ink_file' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
        ])->assertStatus(422);
    }

    public function test_a_party_cannot_be_marked_awaiting_twice(): void
    {
        RentalInventorySignature::capture($this->inventory, RentalInventorySignature::PARTY_TENANT, RentalInventorySignature::DISPOSITION_AWAITING_WET_INK, [
            'party_contact_id' => $this->tenant->id, 'recorded_by_user_id' => $this->agent->id,
        ]);

        $this->postJson(route('corex.rental-inventories.signatures.store', $this->inventory), [
            'party_role' => RentalInventorySignature::PARTY_TENANT,
            'disposition' => RentalInventorySignature::DISPOSITION_AWAITING_WET_INK,
            'party_contact_id' => $this->tenant->id,
        ])->assertStatus(422);
    }

    // ── Direct wet-ink capture (no prior awaiting marker) ───────────────

    public function test_a_tenant_can_be_captured_as_wet_ink_directly_with_a_pdf_scan(): void
    {
        $response = $this->post(route('corex.rental-inventories.signatures.store', $this->inventory), [
            'party_role' => RentalInventorySignature::PARTY_TENANT,
            'disposition' => RentalInventorySignature::DISPOSITION_WET_INK,
            'party_contact_id' => $this->tenant->id,
            'wet_ink_file' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(201)->assertJsonFragment(['disposition' => 'wet_ink', 'party_role' => 'tenant']);
        $signatureId = $response->json('id');

        $this->assertDatabaseHas('rental_inventory_signatures', [
            'id' => $signatureId, 'disposition' => 'wet_ink',
        ]);
        $row = RentalInventorySignature::find($signatureId);
        $this->assertNotNull($row->wet_ink_upload_path);

        // Filed to the property's Document store, with a sensible doc type.
        $document = Document::where('source_type', 'rental_inventory_signature_wet_ink')
            ->where('source_id', $signatureId)->first();
        $this->assertNotNull($document, 'The wet-ink scan should be filed as a Document.');
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertSame(
            'inventory_list',
            DocumentType::find($document->document_type_id)?->slug,
        );
        $this->assertTrue($document->properties->pluck('id')->contains($this->property->id));
    }

    public function test_an_image_scan_is_wrapped_into_a_pdf_before_filing(): void
    {
        $response = $this->post(route('corex.rental-inventories.signatures.store', $this->inventory), [
            'party_role' => RentalInventorySignature::PARTY_TENANT,
            'disposition' => RentalInventorySignature::DISPOSITION_WET_INK,
            'party_contact_id' => $this->tenant->id,
            'wet_ink_file' => UploadedFile::fake()->image('scan.jpg'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(201);
        $signatureId = $response->json('id');

        $document = Document::where('source_type', 'rental_inventory_signature_wet_ink')
            ->where('source_id', $signatureId)->first();
        $this->assertNotNull($document);
        // fileToProperty() always writes a PDF — the image was wrapped, not filed raw.
        $this->assertSame('application/pdf', $document->mime_type);
    }

    public function test_wet_ink_upload_rejects_a_disallowed_file_type(): void
    {
        $this->postJson(route('corex.rental-inventories.signatures.store', $this->inventory), [
            'party_role' => RentalInventorySignature::PARTY_TENANT,
            'disposition' => RentalInventorySignature::DISPOSITION_WET_INK,
            'party_contact_id' => $this->tenant->id,
            'wet_ink_file' => UploadedFile::fake()->create('scan.txt', 10, 'text/plain'),
        ])->assertStatus(422);
    }

    public function test_wet_ink_upload_rejects_a_file_over_10mb(): void
    {
        $this->postJson(route('corex.rental-inventories.signatures.store', $this->inventory), [
            'party_role' => RentalInventorySignature::PARTY_TENANT,
            'disposition' => RentalInventorySignature::DISPOSITION_WET_INK,
            'party_contact_id' => $this->tenant->id,
            'wet_ink_file' => UploadedFile::fake()->create('scan.pdf', 10241, 'application/pdf'),
        ])->assertStatus(422);
    }

    // ── Resolving/correcting via supersede-wet-ink ──────────────────────

    public function test_uploading_a_scan_resolves_an_awaiting_wet_ink_row(): void
    {
        $awaiting = RentalInventorySignature::capture($this->inventory, RentalInventorySignature::PARTY_TENANT, RentalInventorySignature::DISPOSITION_AWAITING_WET_INK, [
            'party_contact_id' => $this->tenant->id, 'recorded_by_user_id' => $this->agent->id,
        ]);

        $response = $this->post(
            route('corex.rental-inventories.signatures.supersede-wet-ink', [$this->inventory, $awaiting]),
            ['wet_ink_file' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf')],
            ['Accept' => 'application/json'],
        );

        $response->assertStatus(201)->assertJsonFragment(['disposition' => 'wet_ink']);
        $replacementId = $response->json('id');

        $awaiting->refresh();
        $this->assertNotNull($awaiting->superseded_at);
        $this->assertSame($replacementId, $awaiting->superseded_by_signature_id);

        // Filed against the REPLACEMENT signature's own id — a distinct Document.
        $this->assertDatabaseHas('documents', [
            'source_type' => 'rental_inventory_signature_wet_ink', 'source_id' => $replacementId,
        ]);
    }

    public function test_a_wrong_wet_ink_upload_can_be_replaced(): void
    {
        // Captured via the real HTTP endpoint (not a direct model call) so the
        // FIRST upload is also actually filed — proving the replacement
        // doesn't remove it, not just that it was never there to begin with.
        $firstResponse = $this->post(route('corex.rental-inventories.signatures.store', $this->inventory), [
            'party_role' => RentalInventorySignature::PARTY_TENANT,
            'disposition' => RentalInventorySignature::DISPOSITION_WET_INK,
            'party_contact_id' => $this->tenant->id,
            'wet_ink_file' => UploadedFile::fake()->create('scan-wrong.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);
        $firstResponse->assertStatus(201);
        $first = RentalInventorySignature::find($firstResponse->json('id'));

        $response = $this->post(
            route('corex.rental-inventories.signatures.supersede-wet-ink', [$this->inventory, $first]),
            ['wet_ink_file' => UploadedFile::fake()->create('scan-corrected.pdf', 100, 'application/pdf')],
            ['Accept' => 'application/json'],
        );

        $response->assertStatus(201);
        $replacementId = $response->json('id');
        $first->refresh();
        $this->assertNotNull($first->superseded_at);
        $this->assertSame($replacementId, $first->superseded_by_signature_id);

        // The FIRST filing stays on disk/in the Document store — never removed (non-negotiable #1) —
        // AND a distinct new Document is filed against the replacement's own id.
        $this->assertDatabaseHas('documents', [
            'source_type' => 'rental_inventory_signature_wet_ink', 'source_id' => $first->id,
        ]);
        $this->assertDatabaseHas('documents', [
            'source_type' => 'rental_inventory_signature_wet_ink', 'source_id' => $replacementId,
        ]);
    }

    public function test_a_superseded_wet_ink_row_cannot_be_superseded_again(): void
    {
        $first = RentalInventorySignature::capture($this->inventory, RentalInventorySignature::PARTY_TENANT, RentalInventorySignature::DISPOSITION_WET_INK, [
            'party_contact_id' => $this->tenant->id, 'wet_ink_upload_path' => '/storage/fake/first.pdf',
            'recorded_by_user_id' => $this->agent->id,
        ]);
        $this->post(
            route('corex.rental-inventories.signatures.supersede-wet-ink', [$this->inventory, $first]),
            ['wet_ink_file' => UploadedFile::fake()->create('scan-2.pdf', 100, 'application/pdf')],
            ['Accept' => 'application/json'],
        );

        $this->postJson(
            route('corex.rental-inventories.signatures.supersede-wet-ink', [$this->inventory, $first->fresh()]),
            ['wet_ink_file' => UploadedFile::fake()->create('scan-3.pdf', 100, 'application/pdf')],
        )->assertStatus(422);
    }

    // ── Mixed mode + completion gates ────────────────────────────────────

    public function test_mixed_mode_tenant_wet_ink_landlord_canvas_signed_completes(): void
    {
        $this->post(route('corex.rental-inventories.signatures.store', $this->inventory), [
            'party_role' => RentalInventorySignature::PARTY_TENANT,
            'disposition' => RentalInventorySignature::DISPOSITION_WET_INK,
            'party_contact_id' => $this->tenant->id,
            'wet_ink_file' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(201);

        RentalInventorySignature::capture($this->inventory, RentalInventorySignature::PARTY_LANDLORD, RentalInventorySignature::DISPOSITION_SIGNED, [
            'party_contact_id' => $this->landlord->id, 'party_signature_path' => '/fake/landlord.png',
            'recorded_by_user_id' => $this->agent->id,
        ]);

        $this->postJson(route('corex.rental-inventories.signatures.store', $this->inventory), [
            'party_role' => RentalInventorySignature::PARTY_AGENT,
            'disposition' => RentalInventorySignature::DISPOSITION_SIGNED,
            'signature_image' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        ])->assertStatus(201);

        $this->postJson(route('corex.rental-inventories.complete', $this->inventory))->assertOk();
        $this->assertSame(RentalInventory::STATUS_COMPLETED, $this->inventory->fresh()->status);
    }

    public function test_completion_is_blocked_while_a_party_is_still_awaiting_wet_ink(): void
    {
        RentalInventorySignature::capture($this->inventory, RentalInventorySignature::PARTY_TENANT, RentalInventorySignature::DISPOSITION_AWAITING_WET_INK, [
            'party_contact_id' => $this->tenant->id, 'recorded_by_user_id' => $this->agent->id,
        ]);
        RentalInventorySignature::capture($this->inventory, RentalInventorySignature::PARTY_LANDLORD, RentalInventorySignature::DISPOSITION_SIGNED, [
            'party_contact_id' => $this->landlord->id, 'party_signature_path' => '/fake/landlord.png',
            'recorded_by_user_id' => $this->agent->id,
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('awaiting a paper signature');
        $this->inventory->markCompleted();
    }

    public function test_the_agent_cannot_sign_while_a_party_is_still_awaiting_wet_ink(): void
    {
        RentalInventorySignature::capture($this->inventory, RentalInventorySignature::PARTY_TENANT, RentalInventorySignature::DISPOSITION_AWAITING_WET_INK, [
            'party_contact_id' => $this->tenant->id, 'recorded_by_user_id' => $this->agent->id,
        ]);
        RentalInventorySignature::capture($this->inventory, RentalInventorySignature::PARTY_LANDLORD, RentalInventorySignature::DISPOSITION_SIGNED, [
            'party_contact_id' => $this->landlord->id, 'party_signature_path' => '/fake/landlord.png',
            'recorded_by_user_id' => $this->agent->id,
        ]);

        $this->postJson(route('corex.rental-inventories.signatures.store', $this->inventory), [
            'party_role' => RentalInventorySignature::PARTY_AGENT,
            'disposition' => RentalInventorySignature::DISPOSITION_SIGNED,
            'signature_image' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        ])->assertStatus(422);
    }

    // ── The completed/cancelled lock (f9bd98e83) agrees with this flow ──

    public function test_storing_a_signature_is_refused_once_the_inventory_is_completed(): void
    {
        RentalInventorySignature::capture($this->inventory, RentalInventorySignature::PARTY_TENANT, RentalInventorySignature::DISPOSITION_SIGNED, [
            'party_contact_id' => $this->tenant->id, 'party_signature_path' => '/fake/tenant.png',
            'recorded_by_user_id' => $this->agent->id,
        ]);
        RentalInventorySignature::capture($this->inventory, RentalInventorySignature::PARTY_LANDLORD, RentalInventorySignature::DISPOSITION_SIGNED, [
            'party_contact_id' => $this->landlord->id, 'party_signature_path' => '/fake/landlord.png',
            'recorded_by_user_id' => $this->agent->id,
        ]);
        RentalInventorySignature::capture($this->inventory, RentalInventorySignature::PARTY_AGENT, RentalInventorySignature::DISPOSITION_SIGNED, [
            'party_signature_path' => '/fake/agent.png', 'recorded_by_user_id' => $this->agent->id,
        ]);
        $this->inventory->markCompleted();

        $this->postJson(route('corex.rental-inventories.signatures.store', $this->inventory), [
            'party_role' => RentalInventorySignature::PARTY_TENANT,
            'disposition' => RentalInventorySignature::DISPOSITION_AWAITING_WET_INK,
            'party_contact_id' => $this->tenant->id,
        ])->assertStatus(409);
    }

    public function test_supersede_wet_ink_is_refused_once_the_inventory_is_completed(): void
    {
        $wetInk = RentalInventorySignature::capture($this->inventory, RentalInventorySignature::PARTY_TENANT, RentalInventorySignature::DISPOSITION_WET_INK, [
            'party_contact_id' => $this->tenant->id, 'wet_ink_upload_path' => '/storage/fake/first.pdf',
            'recorded_by_user_id' => $this->agent->id,
        ]);
        RentalInventorySignature::capture($this->inventory, RentalInventorySignature::PARTY_LANDLORD, RentalInventorySignature::DISPOSITION_SIGNED, [
            'party_contact_id' => $this->landlord->id, 'party_signature_path' => '/fake/landlord.png',
            'recorded_by_user_id' => $this->agent->id,
        ]);
        RentalInventorySignature::capture($this->inventory, RentalInventorySignature::PARTY_AGENT, RentalInventorySignature::DISPOSITION_SIGNED, [
            'party_signature_path' => '/fake/agent.png', 'recorded_by_user_id' => $this->agent->id,
        ]);
        $this->inventory->markCompleted();

        $this->postJson(
            route('corex.rental-inventories.signatures.supersede-wet-ink', [$this->inventory, $wetInk]),
            ['wet_ink_file' => UploadedFile::fake()->create('scan-2.pdf', 100, 'application/pdf')],
        )->assertStatus(409);
    }

    // ── Print for signature ──────────────────────────────────────────────

    public function test_print_for_signature_returns_a_pdf(): void
    {
        $response = $this->get(route('corex.rental-inventories.print-for-signature', $this->inventory));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }
}
