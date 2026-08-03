<?php

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Locks the contract for the mobile Property Drive (read-only file list + download).
 * Backend for: .ai/specs/mobile-property-drive.md
 */
class MobilePropertyDriveTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $user;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-realty']);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);

        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $branch->id,
            'role'      => 'agent',
        ]);

        $this->property = Property::create([
            'agency_id'     => $this->agency->id,
            'agent_id'      => $this->user->id,
            'branch_id'     => $this->user->branch_id,
            'title'         => 'Sea-view 3 bed',
            'suburb'        => 'Uvongo',
            'city'          => 'Margate',
            'province'      => 'KwaZulu-Natal',
            'property_type' => 'house',
            'listing_type'  => 'sale',
            'status'        => 'active',
            'price'         => 2495000,
        ]);
    }

    private function makeDocument(array $overrides = []): Document
    {
        $path = 'properties/' . $this->property->id . '/files/' . uniqid() . '.pdf';
        Storage::disk('local')->put($path, 'pdf-bytes');

        $doc = Document::create(array_merge([
            'agency_id'     => $this->agency->id,
            'original_name' => 'Mandate Agreement.pdf',
            'storage_path'  => $path,
            'disk'          => 'local',
            'mime_type'     => 'application/pdf',
            'size'          => 9,
            'source_type'   => 'upload',
            'uploaded_by'   => $this->user->id,
        ], $overrides));

        $doc->properties()->attach($this->property->id);

        return $doc;
    }

    public function test_lists_documents_filed_on_the_property_with_folder_counts(): void
    {
        $type = DocumentType::create(['slug' => 'mandate', 'label' => 'Mandate', 'sort_order' => 1, 'is_active' => true]);
        $this->makeDocument(['document_type_id' => $type->id]);
        $this->makeDocument(['original_name' => 'Unfiled scan.pdf', 'document_type_id' => null]);

        $res = $this->actingAs($this->user)
            ->getJson("/api/v1/mobile/properties/{$this->property->id}/documents");

        $res->assertOk()->assertJsonStructure([
            'property_id',
            'folders' => [['document_type_id', 'label', 'slug', 'count']],
            'documents' => [['id', 'original_name', 'mime_type', 'size', 'human_size', 'document_type', 'source_type', 'uploaded_by', 'created_at', 'can_download', 'download_url']],
        ]);

        $this->assertCount(2, $res->json('documents'));

        $folders = collect($res->json('folders'));
        $this->assertSame(1, $folders->firstWhere('slug', 'mandate')['count']);
        $this->assertSame(1, $folders->firstWhere('document_type_id', null)['count']);
    }

    public function test_does_not_leak_documents_from_other_properties(): void
    {
        $other = Property::create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->user->id, 'branch_id' => $this->user->branch_id,
            'title' => 'Other listing', 'suburb' => 'Uvongo', 'city' => 'Margate', 'province' => 'KwaZulu-Natal',
            'property_type' => 'house', 'listing_type' => 'sale', 'status' => 'active', 'price' => 1000000,
        ]);
        $stray = Document::create([
            'agency_id' => $this->agency->id, 'original_name' => 'Other.pdf', 'storage_path' => 'x.pdf',
            'disk' => 'local', 'mime_type' => 'application/pdf', 'size' => 1, 'source_type' => 'upload',
            'uploaded_by' => $this->user->id,
        ]);
        $stray->properties()->attach($other->id);

        $res = $this->actingAs($this->user)
            ->getJson("/api/v1/mobile/properties/{$this->property->id}/documents");

        $res->assertOk();
        $this->assertCount(0, $res->json('documents'));
    }

    public function test_agent_without_property_access_is_forbidden(): void
    {
        $stranger = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->user->branch_id,
            'role'      => 'agent',
        ]);

        $res = $this->actingAs($stranger)
            ->getJson("/api/v1/mobile/properties/{$this->property->id}/documents");

        $res->assertForbidden();
    }

    public function test_download_streams_the_file_when_pivot_linked(): void
    {
        $doc = $this->makeDocument();

        $res = $this->actingAs($this->user)
            ->get("/api/v1/mobile/properties/{$this->property->id}/documents/{$doc->id}/download");

        $res->assertOk();
        $res->assertHeader('content-disposition');
        $this->assertStringContainsString('Mandate Agreement.pdf', $res->headers->get('content-disposition'));
    }

    public function test_download_404s_for_a_document_not_linked_to_the_property(): void
    {
        $unlinked = Document::create([
            'agency_id' => $this->agency->id, 'original_name' => 'Unlinked.pdf', 'storage_path' => 'y.pdf',
            'disk' => 'local', 'mime_type' => 'application/pdf', 'size' => 1, 'source_type' => 'upload',
            'uploaded_by' => $this->user->id,
        ]);

        $res = $this->actingAs($this->user)
            ->get("/api/v1/mobile/properties/{$this->property->id}/documents/{$unlinked->id}/download");

        $res->assertNotFound();
    }
}
