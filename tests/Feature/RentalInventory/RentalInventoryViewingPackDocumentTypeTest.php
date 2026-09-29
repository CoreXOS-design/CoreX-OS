<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInventory;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalInventory;
use App\Models\RentalInventoryLine;
use App\Models\RentalInventorySignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Johan, 2026-09-29 — "the inventory should be one of the documents an
 * agent can select for a viewing pack." The Viewing Pack's own eligibility
 * filter (ViewingPackDocumentService::eligibleDocumentsFor()) reads
 * `$document->documentType?->slug` — a filed Document with no
 * `document_type_id` is invisible to it no matter what
 * `document_types.buyer_pack_eligible` says. Proves
 * RentalInventoryRecordingController::stampInventoryListDocumentType()
 * (used by both the completion filing path and the wet-ink scan filing
 * path) actually sets it, and that the backfill migration fixes rows filed
 * before this build.
 */
final class RentalInventoryViewingPackDocumentTypeTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private DocumentType $inventoryListType;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'Viewing Pack Doc Type Agency', 'slug' => 'vp-doctype-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
        ]);
        $this->actingAs($this->agent);

        $this->inventoryListType = DocumentType::create([
            'slug' => 'inventory_list', 'label' => 'Inventory List', 'is_active' => true, 'buyer_pack_eligible' => true,
        ]);
    }

    public function test_completing_an_inventory_files_a_document_typed_for_the_viewing_pack(): void
    {
        $property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Viewing Pack Sale Property', 'status' => 'active', 'listing_type' => 'sale',
        ]);
        $seller = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Vusi', 'last_name' => 'Seller', 'email' => 'vp-seller-' . uniqid() . '@example.test',
            'created_by_user_id' => $this->agent->id,
        ]);
        $property->contacts()->attach($seller->id, ['role' => 'seller']);
        $room = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $property->id,
            'type' => 'kitchen', 'label' => 'Kitchen', 'source' => 'manual', 'sort_order' => 1,
            'created_by_user_id' => $this->agent->id,
        ]);
        $inventory = RentalInventory::startForProperty($property, $this->agent);
        RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $inventory->id,
            'property_room_id' => $room->id, 'room_label' => $room->label,
            'quantity' => 1, 'description' => 'Built-in oven', 'created_by_user_id' => $this->agent->id,
        ]);
        RentalInventorySignature::capture($inventory, RentalInventorySignature::PARTY_SELLER, 'signed', [
            'party_contact_id' => $seller->id, 'party_signature_path' => 'signatures/fake-seller.png',
        ]);
        RentalInventorySignature::capture($inventory, RentalInventorySignature::PARTY_AGENT, 'signed', [
            'party_signature_path' => 'signatures/fake.png',
        ]);

        $this->postJson(route('corex.rental-inventories.complete', $inventory))->assertOk();

        $document = Document::where('source_type', 'rental_inventory_report')->where('source_id', $inventory->id)->first();
        self::assertNotNull($document, 'The completed inventory report must be filed as a Document.');
        self::assertSame($this->inventoryListType->id, $document->document_type_id,
            'The filed report must carry document_type_id=inventory_list so the Viewing Pack can offer it.');
    }

    public function test_backfill_migration_types_an_already_filed_untyped_inventory_document(): void
    {
        $property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Backfill Sale Property', 'status' => 'active', 'listing_type' => 'sale',
        ]);
        $inventory = RentalInventory::startForProperty($property, $this->agent);

        // Pre-fix-shaped legacy row: filed, but never typed.
        $untypedId = DB::table('documents')->insertGetId([
            'agency_id' => $this->agency->id, 'original_name' => 'inventory-report.pdf',
            'storage_path' => 'signed-documents/rental_inventory_report/1/fake.pdf', 'disk' => 'local',
            'mime_type' => 'application/pdf', 'size' => 100,
            'source_type' => 'rental_inventory_report', 'source_id' => $inventory->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require base_path('database/migrations/2026_10_06_090200_backfill_inventory_document_type_id.php');
        $migration->up();

        self::assertSame($this->inventoryListType->id, DB::table('documents')->find($untypedId)->document_type_id);
    }
}
