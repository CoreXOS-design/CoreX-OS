<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Http\Controllers\Tools\PdfSplitterController;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Document;
use App\Models\FicaDocument;
use App\Models\FicaSubmission;
use App\Models\Property;
use App\Models\User;
use App\Services\Compliance\AgencyComplianceDocTypeService;
use App\Services\Compliance\FicaWetInkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AT-105 enhancement — PDF Splitter MANY-TO-MANY per-page contact routing +
 * multi-FICA kickoff.
 *
 * Proves: per-doc-type contact_roles SET + fica_slot config (catalogue default +
 * agency override); the role-aware multi-contact resolver (joint sellers/buyers);
 * many-to-many filing (one page → all its ticked contacts; no-orphan fallback);
 * and the multi-FICA kickoff (one wet-ink verification per distinct assigned
 * contact — a FICA page ticked for two contacts yields two processes; per-contact
 * dedupe; compliance permission gate). The Save-To defaults + search seller_fica
 * + dedupe behaviours from the original AT-105 build are retained.
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
            'role'      => 'super_admin', // holds access_compliance for FICA paths
        ]);

        // Catalogue mirrors the live seed: grouping + the new contact_roles SET
        // and fica_slot. (Tests insert their own rows AFTER migrations, so the
        // migration's global seed does not reach them — set them explicitly.)
        foreach ([
            'mandate'           => ['Mandate', 'property', ['seller_owner'], 'none'],
            'fica'              => ['FICA', 'contact', ['seller_owner'], 'fica_form'],
            'ids'               => ['ID Copy', 'contact', ['seller_owner'], 'id'],
            'por'               => ['Proof of Residence', 'contact', ['seller_owner'], 'por'],
            'offer_to_purchase' => ['Offer to Purchase', 'shared', ['seller_owner', 'buyer'], 'none'],
            'other'             => ['Other', 'shared', [], 'none'],
        ] as $slug => [$label, $grouping, $roles, $slot]) {
            $this->typeIds[$slug] = DB::table('document_types')->insertGetId([
                'slug' => $slug, 'label' => $label, 'sort_order' => 0,
                'is_active' => true, 'grouping' => $grouping,
                'contact_roles' => json_encode($roles), 'fica_slot' => $slot,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->tmpDir = sys_get_temp_dir() . '/at105e-' . uniqid();
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

    private function makeContact(Property $p, string $role, string $first, string $phone): Contact
    {
        $c = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'created_by_user_id' => $this->user->id,
            'first_name' => $first, 'last_name' => 'Dlamini', 'phone' => $phone,
            'id_number' => '8801014800087',
        ]);
        $p->contacts()->attach($c->id, ['role' => $role]);
        return $c;
    }

    /** Write a fake split-output PDF and return its abs path. */
    private function outFile(string $slug): string
    {
        $path = $this->tmpDir . "/pack__{$slug}_" . uniqid() . '.pdf';
        file_put_contents($path, "%PDF-1.4 fake {$slug}\n");
        return $path;
    }

    /** Build a filing/FICA group as link() builds them. */
    private function group(string $label, array $contactIds, string $file): array
    {
        return ['label' => $label, 'contact_ids' => $contactIds, 'pages' => [1], 'file' => $file];
    }

    private function attached(Property $p): Collection
    {
        return $p->fresh()->contacts()->get()->keyBy('id');
    }

    private function callFile(Property $p, array $groups, int $agencyId, Collection $attached): array
    {
        $m = new ReflectionMethod(PdfSplitterController::class, 'fileGroupsToDestinations');
        $m->setAccessible(true);
        return $m->invoke(app(PdfSplitterController::class), $p, $groups, $agencyId, $attached);
    }

    private function callFica(array $groups, array $routing, int $agencyId, Collection $attached, $user, ?string &$note): array
    {
        $m = new ReflectionMethod(PdfSplitterController::class, 'kickoffMultiFica');
        $m->setAccessible(true);
        $args = [$groups, $routing, $agencyId, $attached, $user, &$note];
        return $m->invokeArgs(app(PdfSplitterController::class), $args);
    }

    private function routing(): array
    {
        return app(AgencyComplianceDocTypeService::class)->routingMapBySlugFor($this->agency->id);
    }

    // ── Part 1: contact_roles + fica_slot routing config ────────────────

    public function test_routing_defaults_and_override(): void
    {
        $svc = new AgencyComplianceDocTypeService();
        $a = $this->agency->id;

        $r = $svc->routingForSlug($a, 'offer_to_purchase');
        $this->assertSame(['seller_owner', 'buyer'], $r['contact_roles']);
        $this->assertSame('none', $r['fica_slot']);

        $this->assertSame(['seller_owner'], $svc->routingForSlug($a, 'fica')['contact_roles']);
        $this->assertSame('fica_form', $svc->routingForSlug($a, 'fica')['fica_slot']);
        $this->assertSame('id', $svc->routingForSlug($a, 'ids')['fica_slot']);

        // Override inherits-then-replaces.
        $svc->setRoleConfig($a, $this->typeIds['fica'], ['buyer', 'tenant'], 'id');
        $r2 = $svc->routingForSlug($a, 'fica');
        $this->assertSame(['buyer', 'tenant'], $r2['contact_roles']);
        $this->assertSame('id', $r2['fica_slot']);

        // Unknown slug → empty/none, never a crash.
        $this->assertSame(['contact_roles' => [], 'fica_slot' => 'none'], $svc->routingForSlug($a, 'no_such'));
    }

    // ── Part 2: role-aware multi-contact resolver ───────────────────────

    public function test_contacts_for_role_multi_and_seller_owner_spans_both(): void
    {
        $p = $this->makeProperty();
        $s1 = $this->makeContact($p, 'seller', 'Sipho', '0721111111');
        $s2 = $this->makeContact($p, 'owner', 'Thandi', '0722222222'); // owner ∈ seller_owner
        $b1 = $this->makeContact($p, 'buyer', 'Bongi', '0723333333');
        $b2 = $this->makeContact($p, 'buyer', 'Lerato', '0724444444');

        $sellers = $p->fresh()->contactsForRole('seller_owner');
        $this->assertEqualsCanonicalizing([$s1->id, $s2->id], $sellers->pluck('id')->all());

        $buyers = $p->fresh()->contactsForRole('buyer');
        $this->assertEqualsCanonicalizing([$b1->id, $b2->id], $buyers->pluck('id')->all());

        $this->assertCount(0, $p->fresh()->contactsForRole('tenant'));
        $this->assertCount(0, $p->fresh()->contactsForRole('none'));
    }

    // ── Part 5/2: many-to-many filing ───────────────────────────────────

    public function test_otp_page_files_to_all_ticked_contacts(): void
    {
        // OTP routed to seller_owner + buyer; Save-To contact ON. One page ticked
        // for 2 sellers + 2 buyers → ONE Document linked to all 4 (and property).
        (new AgencyComplianceDocTypeService())->setDestination($this->agency->id, $this->typeIds['offer_to_purchase'], true, true);

        $p = $this->makeProperty();
        $s1 = $this->makeContact($p, 'seller', 'Sipho', '0721111111');
        $s2 = $this->makeContact($p, 'seller', 'Thandi', '0722222222');
        $b1 = $this->makeContact($p, 'buyer', 'Bongi', '0723333333');
        $b2 = $this->makeContact($p, 'buyer', 'Lerato', '0724444444');

        $groups = [$this->group('offer_to_purchase', [$s1->id, $s2->id, $b1->id, $b2->id], $this->outFile('offer_to_purchase'))];
        $res = $this->callFile($p, $groups, $this->agency->id, $this->attached($p));

        $this->assertSame(1, $res['property']);
        $this->assertSame(4, $res['contact']);   // four party attachments
        $this->assertSame(0, $res['fallback']);

        // ONE Document, shared across the property + all four contacts.
        $this->assertSame(1, Document::where('source_type', 'pdf_splitter')->count());
        foreach ([$s1, $s2, $b1, $b2] as $c) {
            $this->assertSame(1, $c->fresh()->documents()->count(), "doc must be on contact {$c->id}");
        }
        $docId = $p->fresh()->documents()->first()->id;
        $this->assertSame($docId, $s1->fresh()->documents()->first()->id);
    }

    public function test_contact_destined_doc_with_no_ticked_contact_falls_back_to_property(): void
    {
        $p = $this->makeProperty();
        // ids = contact-destined; ticked for NOBODY → no-orphan anchor to property.
        $groups = [$this->group('ids', [], $this->outFile('ids'))];
        $res = $this->callFile($p, $groups, $this->agency->id, $this->attached($p));

        $this->assertSame(0, $res['property']);
        $this->assertSame(0, $res['contact']);
        $this->assertSame(1, $res['fallback']);
        $this->assertSame(1, $p->fresh()->documents()->count());
    }

    public function test_split_docs_record_originating_property_as_source_id(): void
    {
        $p = $this->makeProperty();
        $s1 = $this->makeContact($p, 'seller', 'Sipho', '0721111111');
        $groups = [
            $this->group('mandate', [$s1->id], $this->outFile('mandate')),
            $this->group('ids', [$s1->id], $this->outFile('ids')),
        ];
        $this->callFile($p, $groups, $this->agency->id, $this->attached($p));

        $docs = Document::where('source_type', 'pdf_splitter')->get();
        $this->assertCount(2, $docs);
        foreach ($docs as $d) {
            $this->assertSame($p->id, (int) $d->source_id);
        }
    }

    // ── Part 5: multi-FICA kickoff ──────────────────────────────────────

    public function test_multi_fica_one_process_per_contact(): void
    {
        $p = $this->makeProperty();
        $s1 = $this->makeContact($p, 'seller', 'Sipho', '0721111111');
        $s2 = $this->makeContact($p, 'seller', 'Thandi', '0722222222');

        // FICA page ticked for BOTH sellers; ID page only for s1.
        $groups = [
            $this->group('fica', [$s1->id, $s2->id], $this->outFile('fica')),
            $this->group('ids', [$s1->id], $this->outFile('ids')),
        ];
        $note = null;
        $results = $this->callFica($groups, $this->routing(), $this->agency->id, $this->attached($p), $this->user, $note);

        $this->assertNull($note);
        $this->assertCount(2, $results, 'one verification per distinct contact');

        // s1 → fica_form + id_copy ; s2 → fica_form only.
        $subS1 = FicaSubmission::where('contact_id', $s1->id)->firstOrFail();
        $subS2 = FicaSubmission::where('contact_id', $s2->id)->firstOrFail();
        $this->assertSame('wet_ink', $subS1->intake_type);

        $slotsS1 = FicaDocument::where('fica_submission_id', $subS1->id)->pluck('document_type')->sort()->values()->all();
        $slotsS2 = FicaDocument::where('fica_submission_id', $subS2->id)->pluck('document_type')->all();
        $this->assertSame(['fica_form', 'id_copy'], $slotsS1);
        $this->assertSame(['fica_form'], $slotsS2);
    }

    public function test_fica_auto_attaches_id_and_por_by_assignment_per_contact(): void
    {
        // ADDITION 1 — each contact's FICA pre-fills its ID/POR/form slots from
        // the SAME contact's assigned pages (matched by tick, never by role).
        $p = $this->makeProperty();
        $elize = $this->makeContact($p, 'buyer', 'Elize', '0721111111');
        $sello = $this->makeContact($p, 'seller', 'Sello', '0722222222');

        // Elize (buyer) ticked on FICA + ID + POR; Sello (seller) on FICA only.
        $groups = [
            $this->group('fica', [$elize->id], $this->outFile('fica')),
            $this->group('ids',  [$elize->id], $this->outFile('ids')),
            $this->group('por',  [$elize->id], $this->outFile('por')),
            $this->group('fica', [$sello->id], $this->outFile('fica2')),
        ];
        $note = null;
        $results = $this->callFica($groups, $this->routing(), $this->agency->id, $this->attached($p), $this->user, $note);
        $this->assertCount(2, $results);

        $elizeSub = FicaSubmission::where('contact_id', $elize->id)->firstOrFail();
        $selloSub = FicaSubmission::where('contact_id', $sello->id)->firstOrFail();

        $this->assertSame(
            ['fica_form', 'id_copy', 'proof_of_address'],
            FicaDocument::where('fica_submission_id', $elizeSub->id)->pluck('document_type')->sort()->values()->all()
        );
        // Sello gets only HIS form — Elize's ID/POR never leak to him.
        $this->assertSame(['fica_form'], FicaDocument::where('fica_submission_id', $selloSub->id)->pluck('document_type')->all());
    }

    public function test_fica_with_only_id_assigned_starts_and_attaches_id_leaving_por_empty(): void
    {
        // ADDITION 1 — attach-what's-present: a contact with only an ID page (no
        // FICA form, no POR) still starts a verification with the ID attached.
        $p = $this->makeProperty();
        $c = $this->makeContact($p, 'seller', 'Sipho', '0721111111');

        $groups = [$this->group('ids', [$c->id], $this->outFile('ids'))];
        $note = null;
        $results = $this->callFica($groups, $this->routing(), $this->agency->id, $this->attached($p), $this->user, $note);

        $this->assertCount(1, $results);
        $sub = FicaSubmission::where('contact_id', $c->id)->firstOrFail();
        $this->assertSame(['id_copy'], FicaDocument::where('fica_submission_id', $sub->id)->pluck('document_type')->all());
    }

    public function test_fica_page_with_two_contacts_yields_two_processes(): void
    {
        $p = $this->makeProperty();
        $s1 = $this->makeContact($p, 'seller', 'Sipho', '0721111111');
        $s2 = $this->makeContact($p, 'owner', 'Thandi', '0722222222');

        $groups = [$this->group('fica', [$s1->id, $s2->id], $this->outFile('fica'))];
        $note = null;
        $results = $this->callFica($groups, $this->routing(), $this->agency->id, $this->attached($p), $this->user, $note);

        $this->assertCount(2, $results);
        $this->assertSame(1, FicaSubmission::where('contact_id', $s1->id)->count());
        $this->assertSame(1, FicaSubmission::where('contact_id', $s2->id)->count());
    }

    public function test_multi_fica_dedupes_per_contact(): void
    {
        $p = $this->makeProperty();
        $s1 = $this->makeContact($p, 'seller', 'Sipho', '0721111111');
        $s2 = $this->makeContact($p, 'seller', 'Thandi', '0722222222');

        // s1 already has an in-flight verification → reused, not duplicated.
        $existing = app(FicaWetInkService::class)->create($s1, $this->agency->id, ['status' => 'submitted']);

        $groups = [$this->group('fica', [$s1->id, $s2->id], $this->outFile('fica'))];
        $note = null;
        $results = $this->callFica($groups, $this->routing(), $this->agency->id, $this->attached($p), $this->user, $note);

        $this->assertCount(2, $results);
        $this->assertSame(1, FicaSubmission::where('contact_id', $s1->id)->count(), 'no duplicate for s1');
        $reused = collect($results)->firstWhere('reused', true);
        $this->assertNotNull($reused);
        $this->assertStringContainsString((string) $existing->id, $reused['url']);
    }

    public function test_multi_fica_blocked_without_compliance_permission(): void
    {
        $p = $this->makeProperty();
        $s1 = $this->makeContact($p, 'seller', 'Sipho', '0721111111');

        // A user that definitively lacks access_compliance — gate must refuse and
        // create nothing. (Mocked so the assertion is independent of how the test
        // environment seeds role permissions.)
        $denied = \Mockery::mock(User::class);
        $denied->shouldReceive('hasPermission')->with('access_compliance')->andReturn(false);

        $groups = [$this->group('fica', [$s1->id], $this->outFile('fica'))];
        $note = null;
        $results = $this->callFica($groups, $this->routing(), $this->agency->id, $this->attached($p), $denied, $note);

        $this->assertSame([], $results);
        $this->assertStringContainsString('compliance access', (string) $note);
        $this->assertSame(0, FicaSubmission::count());
    }

    public function test_multi_fica_notes_when_no_fica_page_assigned(): void
    {
        $p = $this->makeProperty();
        $s1 = $this->makeContact($p, 'seller', 'Sipho', '0721111111');

        // Only a mandate page (fica_slot=none) assigned → nothing to FICA.
        $groups = [$this->group('mandate', [$s1->id], $this->outFile('mandate'))];
        $note = null;
        $results = $this->callFica($groups, $this->routing(), $this->agency->id, $this->attached($p), $this->user, $note);

        $this->assertSame([], $results);
        $this->assertStringContainsString('no FICA-tagged page', (string) $note);
    }

    // ── propertyContacts endpoint (drives the review selector) ──────────

    public function test_property_contacts_endpoint_returns_attached_with_fica_state(): void
    {
        $p = $this->makeProperty();
        $this->makeContact($p, 'seller', 'Sipho', '0721111111');
        $this->makeContact($p, 'buyer', 'Bongi', '0723333333');

        $resp = $this->getJson(route('tools.pdf_splitter.properties.contacts', $p));
        $resp->assertOk();
        $rows = collect($resp->json('contacts'));
        $this->assertCount(2, $rows);
        $sipho = $rows->firstWhere('name', 'Sipho Dlamini');
        $this->assertSame('seller', $sipho['role']);
        $this->assertSame('incomplete', $sipho['fica_status']);
    }

    // ── Retained AT-105 behaviours ──────────────────────────────────────

    public function test_save_to_defaults_follow_grouping(): void
    {
        $svc = new AgencyComplianceDocTypeService();
        $a = $this->agency->id;
        $this->assertSame(['property' => true, 'contact' => false], $svc->destinationForSlug($a, 'mandate'));
        $this->assertSame(['property' => false, 'contact' => true], $svc->destinationForSlug($a, 'ids'));
        $this->assertSame(['property' => true, 'contact' => false], $svc->destinationForSlug($a, 'no_such_slug'));
    }

    public function test_bulk_save_persists_roles_and_slot_and_destination(): void
    {
        $resp = $this->post(route('admin.settings.document-types.bulk-save'), [
            'types' => [
                ['id' => $this->typeIds['offer_to_purchase'], 'label' => 'Offer to Purchase', 'sort_order' => 0, 'is_active' => 1,
                 'save_to_property' => '1', 'save_to_contact' => '1',
                 'contact_roles' => ['seller_owner', 'buyer'], 'fica_slot' => 'none'],
                ['id' => $this->typeIds['ids'], 'label' => 'ID Copy', 'sort_order' => 0, 'is_active' => 1,
                 'save_to_contact' => '1', 'contact_roles' => ['seller_owner'], 'fica_slot' => 'id'],
            ],
        ]);
        $resp->assertSessionHasNoErrors();

        $svc = new AgencyComplianceDocTypeService();
        $this->assertSame(['seller_owner', 'buyer'], $svc->routingForSlug($this->agency->id, 'offer_to_purchase')['contact_roles']);
        $this->assertSame('id', $svc->routingForSlug($this->agency->id, 'ids')['fica_slot']);
        $this->assertSame(['property' => true, 'contact' => true], $svc->destinationForSlug($this->agency->id, 'offer_to_purchase'));
    }

    public function test_search_returns_seller_and_incomplete_fica_status(): void
    {
        $p = $this->makeProperty();
        $this->makeContact($p, 'seller', 'Nokuthula', '0721234567');

        $resp = $this->getJson(route('tools.pdf_splitter.properties.search', ['q' => 'Compensation']));
        $resp->assertOk();
        $row = collect($resp->json())->firstWhere('id', $p->id);
        $this->assertNotNull($row);
        $this->assertStringContainsString('Nokuthula', $row['seller']);
        $this->assertSame('incomplete', $row['seller_fica']);
    }

    public function test_dedupe_finds_active_fica_but_ignores_terminal(): void
    {
        $p = $this->makeProperty();
        $c = $this->makeContact($p, 'seller', 'Nokuthula', '0721234567');

        $existing = app(FicaWetInkService::class)->create($c, $this->agency->id, ['status' => 'submitted']);

        $m = new ReflectionMethod(PdfSplitterController::class, 'existingActiveFica');
        $m->setAccessible(true);
        $found = $m->invoke(app(PdfSplitterController::class), $c);
        $this->assertNotNull($found);
        $this->assertSame($existing->id, $found->id);

        $existing->update(['status' => 'rejected']);
        $this->assertNull($m->invoke(app(PdfSplitterController::class), $c));
        $existing->update(['status' => 'approved']);
        $this->assertNull($m->invoke(app(PdfSplitterController::class), $c));
    }
}
