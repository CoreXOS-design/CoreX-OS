<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Http\Controllers\Tools\PdfSplitterController;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\FicaDocument;
use App\Models\FicaSubmission;
use App\Models\Property;
use App\Models\User;
use App\Services\Compliance\AgencyComplianceDocTypeService;
use App\Services\Compliance\FicaWetInkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AT-105 — PDF Splitter destination-aware routing + FICA auto-kickoff.
 *
 * Proves: per-doc-type destination defaults (grouping-derived) + explicit
 * agency override; the splitter files each output to the configured
 * destination(s); the no-orphan fallback to the property; the FICA wet-ink
 * kickoff pre-populates the seller/owner contact and attaches the
 * fica/ids/por pages present in the pack; settings persistence; and that the
 * shared FicaWetInkService produces the same wet-ink shape the manual intake
 * does.
 */
final class PdfSplitterDestinationRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;
    private array $typeIds = [];
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->agency = Agency::create(['name' => 'Routing Agency', 'slug' => 'route-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
        ]);

        // Catalogue mirrors live grouping: contact-grouped = fica/ids/por.
        foreach ([
            'mandate' => ['Mandate', 'property'],
            'fica'    => ['FICA', 'contact'],
            'ids'     => ['ID Copy', 'contact'],
            'por'     => ['Proof of Residence', 'contact'],
            'other'   => ['Other', 'shared'],
        ] as $slug => [$label, $grouping]) {
            $this->typeIds[$slug] = DB::table('document_types')->insertGetId([
                'slug' => $slug, 'label' => $label, 'sort_order' => 0,
                'is_active' => true, 'grouping' => $grouping,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->tmpDir = sys_get_temp_dir() . '/at105-' . uniqid();
        @mkdir($this->tmpDir, 0777, true);

        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    private function makeProperty(): Property
    {
        return Property::create([
            'title' => 'Split Target', 'agency_id' => $this->agency->id,
            'agent_id' => $this->user->id, 'branch_id' => $this->branch->id,
            'listing_type' => 'sale', 'address' => '8 Compensation Beach Rd',
            'street_name' => 'Compensation Beach Rd', 'suburb' => 'Ballito',
            'town' => 'Ballito', 'province' => 'KwaZulu-Natal',
            'price' => 2950000, 'property_type' => 'House',
        ]);
    }

    private function makeSeller(Property $p, string $role = 'seller'): Contact
    {
        $c = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'created_by_user_id' => $this->user->id,
            'first_name' => 'Nokuthula', 'last_name' => 'Dlamini', 'phone' => '0721234567',
            'id_number' => '8801014800087',
        ]);
        $p->contacts()->attach($c->id, ['role' => $role]);
        return $p->fresh()->contacts()->where('contacts.id', $c->id)->first() ?? $c;
    }

    /** Write a fake split-output PDF named base__slug.pdf and return its abs path. */
    private function outFile(string $slug): string
    {
        $path = $this->tmpDir . "/pack__{$slug}.pdf";
        file_put_contents($path, "%PDF-1.4 fake {$slug}\n");
        return $path;
    }

    private function callLink(Property $p, ?Contact $c, array $outFiles, int $agencyId): array
    {
        $m = new ReflectionMethod(PdfSplitterController::class, 'linkOutputsToDestinations');
        $m->setAccessible(true);
        return $m->invoke(app(PdfSplitterController::class), $p, $c, $outFiles, $agencyId);
    }

    private function callKickoff(Contact $c, int $agencyId, array $outBySlug): ?FicaSubmission
    {
        $m = new ReflectionMethod(PdfSplitterController::class, 'kickoffWetInkFica');
        $m->setAccessible(true);
        return $m->invoke(app(PdfSplitterController::class), $c, $agencyId, $outBySlug);
    }

    // ── Part 1: destination config ──────────────────────────────────────

    public function test_defaults_follow_grouping(): void
    {
        $svc = new AgencyComplianceDocTypeService();
        $a = $this->agency->id;

        $this->assertSame(['property' => true, 'contact' => false], $svc->destinationForSlug($a, 'mandate'));
        $this->assertSame(['property' => false, 'contact' => true], $svc->destinationForSlug($a, 'ids'));
        $this->assertSame(['property' => false, 'contact' => true], $svc->destinationForSlug($a, 'por'));
        $this->assertSame(['property' => false, 'contact' => true], $svc->destinationForSlug($a, 'fica'));
        $this->assertSame(['property' => true, 'contact' => false], $svc->destinationForSlug($a, 'other'));
        // Unknown slug never orphans — defaults to property.
        $this->assertSame(['property' => true, 'contact' => false], $svc->destinationForSlug($a, 'no_such_slug'));
    }

    public function test_explicit_override_both_and_neither(): void
    {
        $svc = new AgencyComplianceDocTypeService();
        $a = $this->agency->id;

        $svc->setDestination($a, $this->typeIds['mandate'], true, true);
        $this->assertSame(['property' => true, 'contact' => true], $svc->destinationForSlug($a, 'mandate'));

        $svc->setDestination($a, $this->typeIds['ids'], false, false);
        $this->assertSame(['property' => false, 'contact' => false], $svc->destinationForSlug($a, 'ids'));

        // Map covers all active types.
        $this->assertArrayHasKey($this->typeIds['mandate'], $svc->destinationMapFor($a));
        $this->assertCount(5, $svc->destinationMapFor($a));
    }

    // ── Property seller resolver ────────────────────────────────────────

    public function test_seller_resolver_role_match_sole_and_ambiguous(): void
    {
        // Seller-side role.
        $p1 = $this->makeProperty();
        $seller = $this->makeSeller($p1, 'owner');
        $this->assertNotNull($p1->fresh()->sellerOwnerContact());
        $this->assertSame($seller->id, $p1->fresh()->sellerOwnerContact()->id);

        // Sole contact with odd role → fallback.
        $p2 = $this->makeProperty();
        $sole = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'created_by_user_id' => $this->user->id, 'first_name' => 'Sole', 'last_name' => 'Contact', 'phone' => '0700000001',
        ]);
        $p2->contacts()->attach($sole->id, ['role' => 'unknown']);
        $this->assertSame($sole->id, $p2->fresh()->sellerOwnerContact()->id);

        // Ambiguous: two non-seller contacts → null (no wrong guess).
        $p3 = $this->makeProperty();
        foreach (['buyer', 'buyer'] as $i => $r) {
            $b = Contact::create([
                'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
                'created_by_user_id' => $this->user->id, 'first_name' => "B{$i}", 'last_name' => 'X', 'phone' => "07000001{$i}",
            ]);
            $p3->contacts()->attach($b->id, ['role' => $r]);
        }
        $this->assertNull($p3->fresh()->sellerOwnerContact());

        // No contacts → null.
        $this->assertNull($this->makeProperty()->sellerOwnerContact());
    }

    // ── Part 2: splitter files per settings ─────────────────────────────

    public function test_files_route_to_configured_destinations(): void
    {
        $p = $this->makeProperty();
        $c = $this->makeSeller($p, 'seller');

        $out = [$this->outFile('mandate'), $this->outFile('ids'), $this->outFile('other')];
        $res = $this->callLink($p, $c, $out, $this->agency->id);

        // mandate → property; ids → contact; other → property (shared default).
        $this->assertSame(2, $res['property']); // mandate + other
        $this->assertSame(1, $res['contact']);  // ids
        $this->assertSame(0, $res['fallback']);

        $p = $p->fresh();
        $c = $c->fresh();
        $this->assertSame(2, $p->documents()->count());
        $this->assertSame(1, $c->documents()->count());

        // The ID copy is on the contact, not the property.
        $idDoc = $c->documents()->first();
        $this->assertSame($this->typeIds['ids'], $idDoc->document_type_id);
        $this->assertSame(0, $p->documents()->where('documents.id', $idDoc->id)->count());
    }

    public function test_both_ticked_files_to_property_and_contact(): void
    {
        (new AgencyComplianceDocTypeService())->setDestination($this->agency->id, $this->typeIds['mandate'], true, true);

        $p = $this->makeProperty();
        $c = $this->makeSeller($p, 'seller');

        $res = $this->callLink($p, $c, [$this->outFile('mandate')], $this->agency->id);
        $this->assertSame(1, $res['property']);
        $this->assertSame(1, $res['contact']);
        // ONE Document, linked to BOTH pillars.
        $this->assertSame(1, $p->fresh()->documents()->count());
        $this->assertSame(1, $c->fresh()->documents()->count());
        $this->assertSame($p->fresh()->documents()->first()->id, $c->fresh()->documents()->first()->id);
    }

    public function test_contact_destined_doc_falls_back_to_property_when_no_contact(): void
    {
        // No-orphan guarantee: ID copy is contact-destined, but the property
        // has no resolvable seller → it anchors to the property.
        $p = $this->makeProperty();

        $res = $this->callLink($p, null, [$this->outFile('ids')], $this->agency->id);
        $this->assertSame(0, $res['property']);
        $this->assertSame(0, $res['contact']);
        $this->assertSame(1, $res['fallback']);
        $this->assertSame(1, $p->fresh()->documents()->count());
    }

    public function test_both_unticked_falls_back_to_property(): void
    {
        (new AgencyComplianceDocTypeService())->setDestination($this->agency->id, $this->typeIds['other'], false, false);
        $p = $this->makeProperty();
        $c = $this->makeSeller($p, 'seller');

        $res = $this->callLink($p, $c, [$this->outFile('other')], $this->agency->id);
        $this->assertSame(1, $res['fallback']);
        $this->assertSame(1, $p->fresh()->documents()->count());
        $this->assertSame(0, $c->fresh()->documents()->count());
    }

    // ── Part 3: FICA auto-kickoff ───────────────────────────────────────

    public function test_kickoff_creates_wet_ink_with_present_docs(): void
    {
        $p = $this->makeProperty();
        $c = $this->makeSeller($p, 'seller');

        // Pack has FICA form + ID, but NO proof of residence.
        $bySlug = ['fica' => $this->outFile('fica'), 'ids' => $this->outFile('ids')];
        $sub = $this->callKickoff($c, $this->agency->id, $bySlug);

        $this->assertNotNull($sub);
        $this->assertSame('wet_ink', $sub->intake_type);
        $this->assertSame($c->id, $sub->contact_id);
        $this->assertSame($this->agency->id, $sub->agency_id);
        $this->assertSame('pdf_splitter', $sub->form_data['intake']['source'] ?? null);

        $slots = FicaDocument::where('fica_submission_id', $sub->id)->pluck('document_type')->all();
        sort($slots);
        $this->assertSame(['fica_form', 'id_copy'], $slots); // POR absent → not attached
    }

    public function test_kickoff_attaches_all_three_when_present(): void
    {
        $p = $this->makeProperty();
        $c = $this->makeSeller($p, 'seller');

        $bySlug = [
            'fica' => $this->outFile('fica'),
            'ids'  => $this->outFile('ids'),
            'por'  => $this->outFile('por'),
        ];
        $sub = $this->callKickoff($c, $this->agency->id, $bySlug);

        $slots = FicaDocument::where('fica_submission_id', $sub->id)->pluck('document_type')->all();
        sort($slots);
        $this->assertSame(['fica_form', 'id_copy', 'proof_of_address'], $slots);
    }

    // ── Settings persistence (Part 1 UI) ────────────────────────────────

    public function test_bulk_save_persists_destination_choice(): void
    {
        $this->user->update(['role' => 'super_admin']);

        $resp = $this->post(route('admin.settings.document-types.bulk-save'), [
            'types' => [
                ['id' => $this->typeIds['mandate'], 'label' => 'Mandate', 'sort_order' => 0, 'is_active' => 1,
                 'save_to_property' => '1', 'save_to_contact' => '1'],
            ],
        ]);
        $resp->assertSessionHasNoErrors();

        $this->assertSame(
            ['property' => true, 'contact' => true],
            (new AgencyComplianceDocTypeService())->destinationForSlug($this->agency->id, 'mandate'),
        );
    }

    // ── FICA trigger keys off CONTACT state, not property compliance ────

    public function test_split_docs_record_originating_property_as_source_id(): void
    {
        // Provenance: every split doc (incl. contact-only ID) records which
        // property it was split against, so a contact's "Not Property-Linked"
        // doc is still traceable to its split.
        $p = $this->makeProperty();
        $c = $this->makeSeller($p, 'seller');
        $this->callLink($p, $c, [$this->outFile('mandate'), $this->outFile('ids')], $this->agency->id);

        $docs = \App\Models\Document::where('source_type', 'pdf_splitter')->get();
        $this->assertCount(2, $docs);
        foreach ($docs as $d) {
            $this->assertSame($p->id, (int) $d->source_id, 'split doc must record originating property');
        }
    }

    public function test_search_returns_seller_and_incomplete_fica_status(): void
    {
        // The toggle keys off this: an unverified seller (no approved FICA)
        // returns 'incomplete' regardless of the property's compliance snapshot.
        $p = $this->makeProperty();
        $this->makeSeller($p, 'seller');

        $resp = $this->getJson(route('tools.pdf_splitter.properties.search', ['q' => 'Compensation']));
        $resp->assertOk();
        $row = collect($resp->json())->firstWhere('id', $p->id);

        $this->assertNotNull($row, 'property should be in search results');
        $this->assertStringContainsString('Nokuthula', $row['seller']);
        $this->assertSame('incomplete', $row['seller_fica']);
    }

    public function test_search_seller_fica_complete_when_approved(): void
    {
        $p = $this->makeProperty();
        $c = $this->makeSeller($p, 'seller');
        \App\Models\FicaSubmission::create([
            'contact_id' => $c->id, 'agency_id' => $this->agency->id,
            'requested_by' => $this->user->id, 'status' => 'approved',
            'intake_type' => 'wet_ink', 'entity_type' => 'natural',
            'verified_at' => now(),
        ]);

        $resp = $this->getJson(route('tools.pdf_splitter.properties.search', ['q' => 'Compensation']));
        $row = collect($resp->json())->firstWhere('id', $p->id);
        $this->assertSame('complete', $row['seller_fica']);
    }

    public function test_dedupe_finds_active_fica_but_ignores_terminal(): void
    {
        $p = $this->makeProperty();
        $c = $this->makeSeller($p, 'seller');

        $existing = app(FicaWetInkService::class)->create($c, $this->agency->id, ['status' => 'submitted']);

        $m = new ReflectionMethod(PdfSplitterController::class, 'existingActiveFica');
        $m->setAccessible(true);
        $found = $m->invoke(app(PdfSplitterController::class), $c);
        $this->assertNotNull($found, 'an in-flight FICA must be found for dedupe');
        $this->assertSame($existing->id, $found->id);

        // Terminal outcomes do not block a fresh verification.
        $existing->update(['status' => 'rejected']);
        $this->assertNull($m->invoke(app(PdfSplitterController::class), $c));
        $existing->update(['status' => 'approved']);
        $this->assertNull($m->invoke(app(PdfSplitterController::class), $c));
    }
}
