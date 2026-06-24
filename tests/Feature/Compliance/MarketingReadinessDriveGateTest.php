<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Property;
use App\Models\User;
use App\Services\Compliance\AgencyComplianceDocTypeService;
use App\Services\Compliance\MarketingReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Drive-document marketing-compliance gate (AT-94).
 *
 * The gate reads each agency's CONFIGURABLE required document-type list
 * against the property's Drive documents (property + seller contacts). A typed
 * Drive document present = gate met, no approval status checked (doctrine:
 * wet-ink docs are physically BM-signed before upload). E-signed docs auto-
 * file to the Drive with their type, so they satisfy the same gate.
 *
 * Covers: all-present (met), each-required-missing (blocked + named),
 * e-sign source still passes, FICA fica_submissions bridge, agency-config
 * (untick a type unblocks), soft-deleted doc does not count, photos/details
 * gates still enforced.
 */
final class MarketingReadinessDriveGateTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;
    private array $typeIds = []; // slug => document_types.id

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Gate Test Agency', 'slug' => 'gate-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
        ]);

        // Schema snapshot carries no seed data — create the doc-type catalogue.
        // grouping mirrors live: FICA is contact-level, the rest shared.
        foreach ([
            'mandate'    => ['Mandate', 'shared'],
            'fica'       => ['FICA', 'contact'],
            'disclosure' => ['Disclosure', 'shared'],
            'other'      => ['Other', 'shared'],
        ] as $slug => [$label, $grouping]) {
            $this->typeIds[$slug] = DB::table('document_types')->insertGetId([
                'slug' => $slug, 'label' => $label, 'sort_order' => 0,
                'is_active' => true, 'grouping' => $grouping,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Default required set for this agency: mandate + fica + disclosure.
        $svc = new AgencyComplianceDocTypeService();
        $svc->setRequired($this->agency->id, $this->typeIds['mandate'], true);
        $svc->setRequired($this->agency->id, $this->typeIds['fica'], true);
        $svc->setRequired($this->agency->id, $this->typeIds['disclosure'], true);

        $this->actingAs($this->user);
    }

    /** A property that already passes the photos + details gates. */
    private function makeListing(): Property
    {
        return Property::create([
            'title'        => 'Compliance Gate Listing',
            'agency_id'    => $this->agency->id,
            'agent_id'     => $this->user->id,
            'branch_id'    => $this->branch->id,
            'listing_type' => 'sale',
            'address'      => '14 Marine Drive',
            'street_name'  => 'Marine Drive',
            'suburb'       => 'Mtunzini',
            'town'         => 'Mtunzini',
            'province'     => 'KwaZulu-Natal',
            'price'        => 1850000,
            'property_type' => 'House',
            'erf_size_m2'  => 812,
            'gallery_images_json' => ['a.jpg', 'b.jpg', 'c.jpg', 'd.jpg'],
        ]);
    }

    private function addSeller(Property $property): Contact
    {
        $contact = Contact::create([
            'agency_id'          => $this->agency->id,
            'branch_id'          => $this->branch->id,
            'created_by_user_id' => $this->user->id,
            'first_name'         => 'Thabo',
            'last_name'          => 'Mkhize',
            'phone'              => '0834567890',
        ]);
        $property->contacts()->attach($contact->id, ['role' => 'seller']);

        return $contact;
    }

    private function fileDoc(string $slug, string $source = 'upload', ?Property $property = null, ?Contact $contact = null): Document
    {
        $doc = Document::create([
            'original_name'    => ucfirst($slug) . ' (Signed).pdf',
            'storage_path'     => 'docs/' . $slug . '-' . uniqid() . '.pdf',
            'disk'             => 'local',
            'mime_type'        => 'application/pdf',
            'size'             => 1024,
            'document_type_id' => $this->typeIds[$slug],
            'source_type'      => $source,
            'uploaded_by'      => $this->user->id,
        ]);
        if ($property) $doc->properties()->attach($property->id);
        if ($contact)  $doc->contacts()->attach($contact->id, ['party_role' => 'seller']);

        return $doc;
    }

    private function report(Property $property)
    {
        return (new MarketingReadinessService())->statusFor($property->fresh());
    }

    public function test_all_required_drive_docs_present_is_marketable(): void
    {
        $p = $this->makeListing();
        $seller = $this->addSeller($p);
        $this->fileDoc('mandate', 'upload', $p);
        $this->fileDoc('disclosure', 'upload', $p);
        $this->fileDoc('fica', 'upload', null, $seller); // FICA filed to seller contact

        $report = $this->report($p);

        $this->assertTrue($report->ready, 'All required types present + photos + details → ready. Blocked by: ' . implode(' | ', $report->blockedBy));
        $this->assertTrue((new MarketingReadinessService())->isMarketable($p->fresh()));
    }

    public function test_missing_one_required_type_blocks_and_names_it(): void
    {
        $p = $this->makeListing();
        $seller = $this->addSeller($p);
        $this->fileDoc('mandate', 'upload', $p);
        $this->fileDoc('fica', 'upload', null, $seller);
        // disclosure intentionally omitted

        $report = $this->report($p);

        $this->assertFalse($report->ready);
        $this->assertFalse($report->checklist['disclosure']['passed']);
        $this->assertTrue($report->checklist['mandate']['passed']);
        $this->assertStringContainsStringIgnoringCase('Disclosure', implode(' ', $report->blockedBy));
    }

    public function test_esigned_mandate_auto_filed_to_drive_still_satisfies_gate(): void
    {
        // No-regression: an e-signed mandate auto-files as source_type='esign'
        // with document_type_id=mandate. It must satisfy the same gate.
        $p = $this->makeListing();
        $seller = $this->addSeller($p);
        $this->fileDoc('mandate', 'esign', $p);       // <-- e-sign source
        $this->fileDoc('disclosure', 'upload', $p);
        $this->fileDoc('fica', 'esign', null, $seller);

        $report = $this->report($p);

        $this->assertTrue($report->ready, 'E-signed mandate should pass. Blocked by: ' . implode(' | ', $report->blockedBy));
        $this->assertTrue($report->checklist['mandate']['passed']);
    }

    public function test_fica_submissions_approval_bridges_when_no_fica_drive_doc(): void
    {
        $p = $this->makeListing();
        $seller = $this->addSeller($p);
        $this->fileDoc('mandate', 'upload', $p);
        $this->fileDoc('disclosure', 'upload', $p);
        // No FICA Drive doc — but the seller is approved in fica_submissions.
        DB::table('fica_submissions')->insert([
            'contact_id'      => $seller->id,
            'agency_id'       => $this->agency->id,
            'requested_by'    => $this->user->id,
            'token'           => 'tok-' . uniqid(),
            'token_expires_at' => now()->addDays(7),
            'status'          => 'approved',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $report = $this->report($p);

        $this->assertTrue($report->ready, 'FICA approved in fica_submissions should bridge. Blocked by: ' . implode(' | ', $report->blockedBy));
        $this->assertTrue($report->checklist['fica']['passed']);
    }

    public function test_unticking_a_required_type_unblocks_property(): void
    {
        $p = $this->makeListing();
        $this->addSeller($p);
        $this->fileDoc('mandate', 'upload', $p);
        // fica + disclosure missing → blocked
        $this->assertFalse($this->report($p)->ready);

        // Agency unticks fica + disclosure (per-agency config).
        $svc = new AgencyComplianceDocTypeService();
        $svc->setRequired($this->agency->id, $this->typeIds['fica'], false);
        $svc->setRequired($this->agency->id, $this->typeIds['disclosure'], false);

        $report = $this->report($p);
        $this->assertTrue($report->ready, 'Only mandate required now, and mandate is present. Blocked by: ' . implode(' | ', $report->blockedBy));
        $this->assertArrayNotHasKey('disclosure', $report->checklist);
    }

    public function test_soft_deleted_document_does_not_satisfy_gate(): void
    {
        $p = $this->makeListing();
        $seller = $this->addSeller($p);
        $mandate = $this->fileDoc('mandate', 'upload', $p);
        $this->fileDoc('disclosure', 'upload', $p);
        $this->fileDoc('fica', 'upload', null, $seller);
        $this->assertTrue($this->report($p)->ready);

        $mandate->delete(); // soft delete

        $report = $this->report($p);
        $this->assertFalse($report->ready);
        $this->assertFalse($report->checklist['mandate']['passed']);
    }

    public function test_photos_gate_still_blocks_when_under_minimum(): void
    {
        $p = $this->makeListing();
        $p->update(['gallery_images_json' => ['only-one.jpg']]);
        $seller = $this->addSeller($p);
        $this->fileDoc('mandate', 'upload', $p);
        $this->fileDoc('disclosure', 'upload', $p);
        $this->fileDoc('fica', 'upload', null, $seller);

        $report = $this->report($p);
        $this->assertFalse($report->ready);
        $this->assertFalse($report->checklist['photos']['passed']);
    }

    public function test_details_gate_still_blocks_when_field_missing(): void
    {
        $p = $this->makeListing();
        $p->update(['province' => '']); // required detail blank (NOT-NULL column, empty string)
        $seller = $this->addSeller($p);
        $this->fileDoc('mandate', 'upload', $p);
        $this->fileDoc('disclosure', 'upload', $p);
        $this->fileDoc('fica', 'upload', null, $seller);

        $report = $this->report($p);
        $this->assertFalse($report->ready);
        $this->assertFalse($report->checklist['details_complete']['passed']);
    }

    // ── Drive-tab compliance checklist (single source of truth with the gate) ──

    private function checklist(Property $property): array
    {
        $rows = (new MarketingReadinessService())->complianceChecklistFor($property->fresh());
        return collect($rows)->keyBy('slug')->toArray();
    }

    public function test_checklist_all_missing_mirrors_blocked_gate(): void
    {
        $p = $this->makeListing();
        $this->addSeller($p);

        $cl = $this->checklist($p);
        $this->assertCount(3, $cl); // mandate, fica, disclosure required
        $this->assertFalse($cl['mandate']['present']);
        $this->assertFalse($cl['fica']['present']);
        $this->assertFalse($cl['disclosure']['present']);
        $this->assertFalse($this->report($p)->ready);
    }

    public function test_checklist_never_disagrees_with_gate_missing_fica(): void
    {
        // mandate + disclosure present, FICA absent (no Drive doc, no approved
        // fica_submission) → gate blocked on FICA, checklist FICA unticked.
        $p = $this->makeListing();
        $this->addSeller($p);
        $this->fileDoc('mandate', 'upload', $p);
        $this->fileDoc('disclosure', 'upload', $p);

        $report = $this->report($p);
        $cl = $this->checklist($p);

        // Invariant: every checklist row's present === the gate's passed for that slug.
        foreach ($cl as $slug => $row) {
            $this->assertSame(
                $report->checklist[$slug]['passed'],
                $row['present'],
                "Checklist disagreed with gate for {$slug}"
            );
        }
        $this->assertFalse($cl['fica']['present']);
        $this->assertFalse($report->ready);
    }

    public function test_checklist_fica_row_routes_upload_to_seller_contact(): void
    {
        $p = $this->makeListing();
        $seller = $this->addSeller($p);
        $cl = $this->checklist($p);

        // FICA is a contact-grouped type → upload routes to the seller contact.
        $this->assertSame($seller->id, $cl['fica']['upload_contact_id']);
        // Mandate is shared → no contact routing.
        $this->assertNull($cl['mandate']['upload_contact_id']);
    }

    public function test_checklist_esigned_mandate_shows_ticked(): void
    {
        $p = $this->makeListing();
        $this->addSeller($p);
        $this->fileDoc('mandate', 'esign', $p);

        $cl = $this->checklist($p);
        $this->assertTrue($cl['mandate']['present']);
        $this->assertSame('esign', $cl['mandate']['doc']['source']);
    }

    public function test_checklist_respects_agency_config(): void
    {
        $p = $this->makeListing();
        $this->addSeller($p);
        $this->assertArrayHasKey('disclosure', $this->checklist($p));

        (new AgencyComplianceDocTypeService())->setRequired($this->agency->id, $this->typeIds['disclosure'], false);

        $cl = $this->checklist($p);
        $this->assertArrayNotHasKey('disclosure', $cl);
        $this->assertArrayHasKey('mandate', $cl);
    }

    public function test_inline_upload_with_preset_type_ticks_row_and_advances_gate(): void
    {
        Storage::fake('public');
        $p = $this->makeListing();
        $seller = $this->addSeller($p);
        $this->fileDoc('disclosure', 'upload', $p);
        // FICA via fica_submissions so only the mandate is outstanding.
        DB::table('fica_submissions')->insert([
            'contact_id' => $seller->id, 'agency_id' => $this->agency->id,
            'requested_by' => $this->user->id, 'token' => 'tok-' . uniqid(),
            'token_expires_at' => now()->addDays(7), 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertFalse($this->report($p)->ready); // mandate missing
        $this->assertFalse($this->checklist($p)['mandate']['present']);

        // Inline upload through the existing endpoint with the type pre-set.
        $resp = $this->post(route('corex.properties.files.store', $p), [
            'file' => UploadedFile::fake()->create('mandate-signed.pdf', 200, 'application/pdf'),
            'document_type_id' => $this->typeIds['mandate'],
        ]);
        $resp->assertSessionHasNoErrors();

        $this->assertTrue($this->checklist($p)['mandate']['present'], 'Mandate row should tick after inline upload');
        $this->assertTrue($this->report($p)->ready, 'Gate should recompute to ready after the mandate lands');
    }
}
