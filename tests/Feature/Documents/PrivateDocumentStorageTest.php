<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Document;
use App\Models\Property;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sensitive documents never live on the public disk and open only through ONE authenticated route.
 * Spec: .ai/specs/private-document-storage.md
 */
final class PrivateDocumentStorageTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $owner;       // the listing agent + uploader
    private User $colleague;   // same agency + branch, 'own' scope: not their listing
    private User $branchAgent; // same branch, 'branch' scope
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');

        $this->agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        Role::create(['name' => 'branch_agent', 'label' => 'Branch agent', 'agency_id' => $this->agency->id]);

        $this->owner       = $this->agent('agent');
        $this->colleague   = $this->agent('agent');
        $this->branchAgent = $this->agent('branch_agent');

        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->owner->id,
            'title' => 'Marine Drive', 'street_name' => 'Marine Drive', 'street_number' => '14',
            'suburb' => 'Margate', 'city' => 'Margate', 'status' => 'active',
        ]);

        $this->grant('agent', 'properties.view', 'own');
        $this->grant('branch_agent', 'properties.view', 'branch');
        foreach (['agent', 'branch_agent'] as $role) {
            $this->grant($role, 'access_properties', null);
        }
        PermissionService::clearCache();
        PermissionService::forceProductionPosture();
    }

    // ---------------------------------------------------------------- the route

    public function test_a_guest_is_sent_to_login(): void
    {
        $doc = $this->mandate();

        $this->get(route('corex.documents.file', $doc))->assertRedirect();
    }

    public function test_another_agency_gets_404_not_403(): void
    {
        $doc = $this->mandate();

        $other = Agency::create(['name' => 'Cape', 'slug' => 'cape-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $other->id, 'name' => 'Cape Town']);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $other->id]);
        $stranger = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $otherBranch->id, 'role' => 'agent', 'is_active' => true]);

        $this->actingAs($stranger)->get(route('corex.documents.file', $doc))->assertNotFound();
    }

    public function test_an_own_scope_agent_outside_the_listing_is_denied(): void
    {
        $doc = $this->mandate();

        $this->actingAs($this->colleague)->get(route('corex.documents.file', $doc))->assertForbidden();
    }

    public function test_the_listing_agent_can_open_it_inline_with_no_cache_headers(): void
    {
        $doc = $this->mandate();

        $response = $this->actingAs($this->owner)->get(route('corex.documents.file', $doc));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('%PDF-1.4 mandate', $response->streamedContent());
    }

    public function test_download_flag_forces_an_attachment(): void
    {
        $doc = $this->mandate();

        $response = $this->actingAs($this->owner)->get(route('corex.documents.file', $doc) . '?download=1');

        $response->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_a_branch_scope_agent_in_the_same_branch_can_open_it(): void
    {
        $doc = $this->mandate();

        $this->actingAs($this->branchAgent)->get(route('corex.documents.file', $doc))->assertOk();
    }

    public function test_an_uploader_can_open_their_own_unlinked_document(): void
    {
        $doc = $this->mandate(attach: false, uploader: $this->colleague);

        $this->actingAs($this->colleague)->get(route('corex.documents.file', $doc))->assertOk();
        $this->actingAs($this->owner)->get(route('corex.documents.file', $doc))->assertForbidden();
    }

    public function test_identity_documents_need_the_compliance_permission(): void
    {
        $doc = $this->mandate();
        $doc->update(['source_type' => 'fica']);

        // The listing agent has record access but no fica.view / compliance.view.
        $this->actingAs($this->owner)->get(route('corex.documents.file', $doc))->assertForbidden();

        $this->grant('agent', 'fica.view', null);
        PermissionService::clearCache();

        $this->actingAs($this->owner)->get(route('corex.documents.file', $doc))->assertOk();
    }

    public function test_an_archived_document_is_not_served(): void
    {
        $doc = $this->mandate();
        $doc->delete();

        $this->actingAs($this->owner)->get(route('corex.documents.file', $doc->id))->assertNotFound();
    }

    public function test_the_document_url_helper_never_builds_a_storage_url(): void
    {
        $doc = $this->mandate();

        $this->assertSame(route('corex.documents.file', $doc), $doc->url());
        $this->assertStringNotContainsString('/storage/', $doc->url());
    }

    // ---------------------------------------------------------------- the writers

    public function test_a_buyer_acceptance_signature_is_written_to_the_private_disk(): void
    {
        $png = 'data:image/png;base64,' . base64_encode((string) $this->tinyPng());

        $stored = \App\Models\RentalInventoryBuyerAcceptance::storeCanvasImage($png, $this->property->id);

        $this->assertStringStartsWith('private:rental-inventory-buyer-acceptances/', $stored);
        $this->assertTrue(Storage::disk('local')->exists(substr($stored, strlen('private:'))));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    // ---------------------------------------------------------------- the move command

    public function test_the_command_moves_public_documents_private_and_the_old_public_path_is_gone(): void
    {
        $legacy = $this->legacyPublicDocument();
        $orphanPath = "properties/{$this->property->id}/files/orphan.pdf";
        Storage::disk('public')->put($orphanPath, '%PDF orphan');
        $photo = "properties/{$this->property->id}/gallery.jpg";
        Storage::disk('public')->put($photo, 'jpeg-bytes');

        Artisan::call('documents:privatise');

        $legacy->refresh();
        $this->assertSame('local', $legacy->disk);
        $this->assertFalse(Storage::disk('public')->exists($legacy->storage_path), 'old public path must be gone');
        $this->assertTrue(Storage::disk('local')->exists($legacy->storage_path));
        $this->assertFalse(Storage::disk('public')->exists($orphanPath), 'orphans are swept too');
        $this->assertTrue(Storage::disk('local')->exists($orphanPath));
        $this->assertTrue(Storage::disk('public')->exists($photo), 'property photos stay public');

        // …and it still opens through the route afterwards.
        $this->actingAs($this->owner)->get(route('corex.documents.file', $legacy))->assertOk();
    }

    public function test_dry_run_changes_nothing(): void
    {
        $legacy = $this->legacyPublicDocument();

        Artisan::call('documents:privatise', ['--dry-run' => true]);

        $this->assertSame('public', $legacy->fresh()->disk);
        $this->assertTrue(Storage::disk('public')->exists($legacy->storage_path));
        $this->assertFalse(Storage::disk('local')->exists($legacy->storage_path));
    }

    public function test_the_command_is_idempotent(): void
    {
        $this->legacyPublicDocument();

        Artisan::call('documents:privatise');
        $first = Artisan::output();
        Artisan::call('documents:privatise');
        $second = Artisan::output();

        $this->assertMatchesRegularExpression('/Done\. [1-9]\d* moved/', $first);
        $this->assertStringContainsString('Done. 0 moved', $second);
        $this->assertSame(0, DB::table('documents')->where('disk', 'public')->count());
    }

    public function test_a_legacy_public_signature_row_is_rewritten_to_the_private_form(): void
    {
        $rel = "properties/{$this->property->id}/rental-inventory-buyer-acceptances/old.png";
        Storage::disk('public')->put($rel, (string) $this->tinyPng());

        $table = 'rental_inventory_buyer_acceptances';
        if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
            $this->markTestSkipped('table missing');
        }
        // Only the column under test is needed to prove the rewrite; build the row with the real columns.
        DB::statement('SET FOREIGN_KEY_CHECKS=0'); // the row's parents are irrelevant to the file move
        $id = DB::table($table)->insertGetId($this->acceptanceRow('/storage/' . $rel));
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        Artisan::call('documents:privatise');

        $this->assertSame('private:' . $rel, DB::table($table)->where('id', $id)->value('party_signature_path'));
        $this->assertFalse(Storage::disk('public')->exists($rel));
        $this->assertTrue(Storage::disk('local')->exists($rel));
    }

    // ---------------------------------------------------------------- helpers

    private function agent(string $role): User
    {
        return User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => $role, 'is_active' => true,
        ]);
    }

    private function mandate(bool $attach = true, ?User $uploader = null): Document
    {
        $path = "properties/{$this->property->id}/files/" . uniqid() . '-mandate.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 mandate');

        $doc = Document::create([
            'original_name' => 'mandate.pdf',
            'storage_path'  => $path,
            'disk'          => 'local',
            'mime_type'     => 'application/pdf',
            'size'          => 16,
            'source_type'   => 'upload',
            'uploaded_by'   => ($uploader ?? $this->owner)->id,
        ]);
        if ($attach) {
            $doc->properties()->attach($this->property->id);
        }

        return $doc;
    }

    private function legacyPublicDocument(): Document
    {
        $path = "properties/{$this->property->id}/files/legacy-" . uniqid() . '.pdf';
        Storage::disk('public')->put($path, '%PDF-1.4 legacy');

        $doc = Document::create([
            'original_name' => 'legacy.pdf',
            'storage_path'  => $path,
            'disk'          => 'public',
            'mime_type'     => 'application/pdf',
            'size'          => 15,
            'source_type'   => 'pdf_splitter',
            'uploaded_by'   => $this->owner->id,
        ]);
        $doc->properties()->attach($this->property->id);

        return $doc;
    }

    private function grant(string $role, string $key, ?string $scope): void
    {
        RolePermission::updateOrCreate(
            ['role' => $role, 'permission_key' => $key, 'agency_id' => $this->agency->id],
            ['scope' => $scope],
        );
    }

    private function tinyPng(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }

    /** @return array<string,mixed> */
    private function acceptanceRow(string $signaturePath): array
    {
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('rental_inventory_buyer_acceptances');
        $row = ['party_signature_path' => $signaturePath, 'created_at' => now(), 'updated_at' => now()];
        // Satisfy NOT NULL columns without a default with neutral values.
        foreach (DB::select('SHOW COLUMNS FROM rental_inventory_buyer_acceptances') as $c) {
            $name = $c->Field;
            if (isset($row[$name]) || $c->Null === 'YES' || $c->Default !== null || str_contains((string) $c->Extra, 'auto_increment')) {
                continue;
            }
            $row[$name] = match (true) {
                str_contains($c->Type, 'int')      => 1,
                str_contains($c->Type, 'datetime'),
                str_contains($c->Type, 'timestamp') => now(),
                str_contains($c->Type, 'date')     => now()->toDateString(),
                default                            => $name === 'disposition' ? 'signed' : 'x',
            };
        }

        return array_intersect_key($row, array_flip($columns));
    }
}
