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
use Tests\Concerns\FindsOrCreatesDocumentTypes;
use Tests\TestCase;

/**
 * AT-167 — prevent-at-source (contact-only page with no contact is blocked from
 * filing) + the Misfiled Documents register + Refile.
 */
final class MisfiledDocumentsTest extends TestCase
{
    use FindsOrCreatesDocumentTypes;
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
            $this->typeIds[$slug] = $this->documentTypeId($slug, $label, [
                'grouping' => $grouping, 'contact_roles' => json_encode(['seller_owner']), 'fica_slot' => 'none',
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

    /**
     * A minimal valid N-page PDF. link() extracts each label group from the ORIGINAL with qpdf BEFORE it
     * applies the contact-only block (the block needs the groups), so the original has to be a real PDF.
     */
    private function minimalPdf(int $pages): string
    {
        $objs = ["<</Type/Catalog/Pages 2 0 R>>"];
        $kids = [];
        for ($i = 0; $i < $pages; $i++) {
            $kids[] = (3 + $i) . ' 0 R';
        }
        $objs[] = '<</Type/Pages/Kids[' . implode(' ', $kids) . ']/Count ' . $pages . '>>';
        for ($i = 0; $i < $pages; $i++) {
            $objs[] = '<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>';
        }
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $n => $body) {
            $offsets[] = strlen($out);
            $out .= ($n + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $out .= sprintf("%010d 00000 n \n", $o);
        }
        $out .= "trailer\n<</Root 1 0 R/Size " . (count($objs) + 1) . ">>\nstartxref\n" . $xref . "\n%%EOF\n";

        return $out;
    }

    /** Build the session manifest link() reads, plus the original PDF it extracts from. */
    private function seedManifest(array $labels): string
    {
        $id = 'pack__20260101_000000';
        Storage::disk('local')->put('private/splitter/originals/' . $id . '.pdf', $this->minimalPdf(max(1, count($labels))));
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

        $resp = $this->withSession(['splitter_batch' => [$id]])
            ->post(route('tools.pdf_splitter.link'), [
                'property_id' => $p->id,
                'labels'      => [$id => [1 => 'ids']],
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
