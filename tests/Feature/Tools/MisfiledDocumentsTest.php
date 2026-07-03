<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Events\Document\DocumentRefiled;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AT-167 — prevent-at-source (contact-only page with no contact is blocked from
 * filing) + the Misfiled Documents register + Refile.
 */
final class MisfiledDocumentsTest extends TestCase
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
        Storage::fake('local');

        $this->agency = Agency::create(['name' => 'Misfile Agency', 'slug' => 'misfile-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => 'super_admin',
        ]);

        foreach ([
            'mandate' => ['Mandate', 'property'],
            'ids'     => ['ID Copy', 'contact'],
            'fica'    => ['FICA', 'contact'],
        ] as $slug => [$label, $grouping]) {
            $this->typeIds[$slug] = DB::table('document_types')->insertGetId([
                'slug' => $slug, 'label' => $label, 'sort_order' => 0, 'is_active' => true,
                'grouping' => $grouping, 'contact_roles' => json_encode(['seller_owner']),
                'fica_slot' => 'none', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->actingAs($this->user)->withoutVite();
    }

    private function makeProperty(): Property
    {
        return Property::create([
            'title' => 'Split Target', 'agency_id' => $this->agency->id,
            'agent_id' => $this->user->id, 'branch_id' => $this->branch->id,
            'listing_type' => 'sale', 'street_name' => 'Beach Rd', 'suburb' => 'Ballito',
            'town' => 'Ballito', 'province' => 'KZN', 'price' => 2950000, 'property_type' => 'House',
        ]);
    }

    private function makeContact(Property $p, string $role, string $first): Contact
    {
        $c = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'created_by_user_id' => $this->user->id, 'first_name' => $first, 'last_name' => 'Dlamini',
            'phone' => '07' . random_int(10000000, 99999999),
        ]);
        $p->contacts()->attach($c->id, ['role' => $role]);
        return $c;
    }

    /** A contact-only splitter document wrongly anchored to the property. */
    private function makeMisfiled(Property $p, string $slug = 'ids'): Document
    {
        $doc = Document::create([
            'original_name' => "pack__{$slug}__unassigned__g1.pdf",
            'storage_path' => "properties/{$p->id}/files/x_{$slug}.pdf",
            'disk' => 'public', 'mime_type' => 'application/pdf', 'size' => 100,
            'document_type_id' => $this->typeIds[$slug], 'source_type' => 'pdf_splitter',
            'source_id' => $p->id, 'uploaded_by' => $this->user->id,
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
        ]);
        $doc->properties()->attach($p->id);
        return $doc;
    }

    /** Build the session manifest link() reads (no PDF needed — the block returns before extraction). */
    private function seedManifest(array $labels): string
    {
        $id = 'pack__20260101_000000';
        Storage::disk('local')->put('private/splitter/tmp/' . $id . '/manifest.json', json_encode([
            'base' => 'pack', 'ts' => '20260101_000000',
            'origRel' => 'private/splitter/originals/' . $id . '.pdf',
            'outDirRel' => 'private/splitter/output/' . $id,
            'tmpRel' => 'private/splitter/tmp/' . $id,
            'pCount' => count($labels),
            'labels' => $labels, 'snippets' => [], 'pageScores' => [], 'docTypes' => [],
        ]));
        return $id;
    }

    // ── (a) PREVENT AT SOURCE ────────────────────────────────────────────
    public function test_link_blocks_contact_only_page_with_no_contact_and_files_nothing(): void
    {
        $p = $this->makeProperty();
        $id = $this->seedManifest(['1' => 'ids']); // one contact-only page

        $resp = $this->withSession(['splitter_manifest_id' => $id])
            ->post(route('tools.pdf_splitter.link'), [
                'property_id' => $p->id,
                'labels'      => [1 => 'ids'],
                'contacts'    => [], // NO contact assigned on the ID page
            ]);

        $resp->assertRedirect(route('tools.pdf_splitter.review'));
        $resp->assertSessionHasErrors('pdf');
        // Nothing was filed — no document created, no property link.
        $this->assertSame(0, Document::count());
        $this->assertSame(0, $p->fresh()->documents()->count());
    }

    // ── (b/c) REGISTER lists the misfile + confirms the property link ────
    public function test_register_lists_contact_only_doc_on_property_without_contact(): void
    {
        $p = $this->makeProperty();
        $doc = $this->makeMisfiled($p, 'ids');
        // A correctly-filed contact-only doc (on a contact) must NOT appear.
        $c = $this->makeContact($p, 'seller', 'Sipho');
        $ok = $this->makeMisfiled($p, 'fica');
        $ok->properties()->detach();
        $ok->contacts()->attach($c->id, ['party_role' => 'seller']);
        // A property-type doc on the property must NOT appear.
        $this->makeMisfiled($p, 'mandate');

        $resp = $this->get(route('admin.misfiled-documents.index'));
        $resp->assertOk();
        $resp->assertSee($doc->original_name);          // the misfiled ID is listed
        $resp->assertDontSee($ok->original_name);        // correctly-filed FICA excluded
        $resp->assertSee((string) $p->id);               // shows which property it's linked to
    }

    // ── REFILE moves the doc to the contact + removes the property anchor ──
    public function test_refile_attaches_contact_detaches_property_and_audits(): void
    {
        Event::fake([DocumentRefiled::class]);
        $p = $this->makeProperty();
        $c = $this->makeContact($p, 'seller', 'Sipho');
        $doc = $this->makeMisfiled($p, 'ids');

        $resp = $this->post(route('admin.misfiled-documents.refile', $doc), [
            'contact_ids' => [$c->id],
        ]);
        $resp->assertRedirect(route('admin.misfiled-documents.index'));

        $doc->refresh();
        $this->assertSame(1, $doc->contacts()->count(), 'now filed on the contact');
        $this->assertSame($c->id, $doc->contacts()->first()->id);
        $this->assertSame(0, $doc->properties()->count(), 'wrong property anchor removed');
        $this->assertNotNull(Document::find($doc->id), 'document is never hard-deleted');
        Event::assertDispatched(DocumentRefiled::class, fn ($e) => $e->document->id === $doc->id
            && in_array($c->id, $e->toContactIds, true));
    }

    public function test_refile_rejects_a_contact_not_on_the_property(): void
    {
        $p = $this->makeProperty();
        $other = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'created_by_user_id' => $this->user->id, 'first_name' => 'Stranger', 'last_name' => 'X', 'phone' => '0790000000',
        ]);
        $doc = $this->makeMisfiled($p, 'ids');

        $resp = $this->post(route('admin.misfiled-documents.refile', $doc), ['contact_ids' => [$other->id]]);
        $resp->assertSessionHasErrors('refile');
        $this->assertSame(0, $doc->fresh()->contacts()->count());
        $this->assertSame(1, $doc->fresh()->properties()->count(), 'unchanged — still misfiled until a valid contact is picked');
    }
}
