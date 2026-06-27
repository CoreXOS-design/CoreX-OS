<?php

declare(strict_types=1);

namespace Tests\Feature\CoreX;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Property Drive multi-file upload (AT-106).
 *
 * The Drive tab upload accepts one OR many files in a single submit. When more
 * than one file is chosen the UI renders a Document Type selector under each
 * item; those map to document_types[] keyed by the same FileList index, so each
 * file lands tagged with its own type. The legacy single-file contract
 * (file + document_type_id, used by the compliance-checklist preset upload)
 * must keep working unchanged.
 */
final class PropertyDriveMultiUploadTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;
    private array $typeIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->agency = Agency::create(['name' => 'Drive Test Agency', 'slug' => 'drive-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
        ]);

        foreach (['mandate' => 'Mandate', 'disclosure' => 'Disclosure'] as $slug => $label) {
            $this->typeIds[$slug] = DB::table('document_types')->insertGetId([
                'slug' => $slug, 'label' => $label, 'sort_order' => 0,
                'is_active' => true, 'grouping' => 'shared',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->actingAs($this->user);
    }

    private function makeListing(): Property
    {
        return Property::create([
            'title'        => 'Drive Upload Listing',
            'agency_id'    => $this->agency->id,
            'agent_id'     => $this->user->id,
            'branch_id'    => $this->branch->id,
            'listing_type' => 'sale',
            'address'      => '14 Marine Drive',
            'suburb'       => 'Mtunzini',
            'price'        => 1850000,
            'property_type' => 'House',
        ]);
    }

    public function test_multiple_files_each_get_their_own_document_type(): void
    {
        $p = $this->makeListing();

        $resp = $this->post(route('corex.properties.files.store', $p), [
            'files' => [
                UploadedFile::fake()->create('mandate.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->create('disclosure.pdf', 120, 'application/pdf'),
            ],
            'document_types' => [
                $this->typeIds['mandate'],
                $this->typeIds['disclosure'],
            ],
        ]);

        $resp->assertSessionHasNoErrors();

        $docs = $p->fresh()->documents()->get();
        $this->assertCount(2, $docs);
        $byName = $docs->keyBy('original_name');
        $this->assertSame($this->typeIds['mandate'], (int) $byName['mandate.pdf']->document_type_id);
        $this->assertSame($this->typeIds['disclosure'], (int) $byName['disclosure.pdf']->document_type_id);
    }

    public function test_untagged_items_are_stored_with_null_type(): void
    {
        $p = $this->makeListing();

        $resp = $this->post(route('corex.properties.files.store', $p), [
            'files' => [
                UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->create('b.pdf', 100, 'application/pdf'),
            ],
            'document_types' => [
                $this->typeIds['mandate'],
                '', // left as "Document Type (optional)" — becomes null
            ],
        ]);

        $resp->assertSessionHasNoErrors();

        $docs = $p->fresh()->documents()->get()->keyBy('original_name');
        $this->assertSame($this->typeIds['mandate'], (int) $docs['a.pdf']->document_type_id);
        $this->assertNull($docs['b.pdf']->document_type_id);
    }

    public function test_shared_contact_links_every_uploaded_file(): void
    {
        $p = $this->makeListing();
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'created_by_user_id' => $this->user->id,
            'first_name' => 'Thabo', 'last_name' => 'Mkhize', 'phone' => '0834567890',
        ]);
        $p->contacts()->attach($contact->id, ['role' => 'seller']);

        $this->post(route('corex.properties.files.store', $p), [
            'files' => [
                UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->create('b.pdf', 100, 'application/pdf'),
            ],
            'contact_id' => $contact->id,
        ])->assertSessionHasNoErrors();

        foreach ($p->fresh()->documents()->get() as $doc) {
            $this->assertTrue($doc->contacts()->where('contacts.id', $contact->id)->exists());
        }
    }

    public function test_legacy_single_file_contract_still_works(): void
    {
        // The compliance-checklist preset upload posts file + document_type_id.
        $p = $this->makeListing();

        $resp = $this->post(route('corex.properties.files.store', $p), [
            'file' => UploadedFile::fake()->create('mandate-signed.pdf', 200, 'application/pdf'),
            'document_type_id' => $this->typeIds['mandate'],
        ]);

        $resp->assertSessionHasNoErrors();

        $docs = $p->fresh()->documents()->get();
        $this->assertCount(1, $docs);
        $this->assertSame($this->typeIds['mandate'], (int) $docs->first()->document_type_id);
    }

    public function test_disallowed_file_type_is_rejected(): void
    {
        $p = $this->makeListing();

        $resp = $this->from(route('corex.properties.show', $p))->post(route('corex.properties.files.store', $p), [
            'files' => [
                UploadedFile::fake()->create('evil.php', 10, 'application/x-php'),
            ],
        ]);

        $resp->assertSessionHasErrors('files.0');
        $this->assertCount(0, $p->fresh()->documents()->get());
    }
}
