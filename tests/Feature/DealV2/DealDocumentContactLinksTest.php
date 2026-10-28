<?php

namespace Tests\Feature\DealV2;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\DealV2\DealDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A document filed from a deal reaches the DEAL'S OWN parties only — never everyone linked to the
 * property (leads, other buyers) — and `deal-docs:fix-contact-links` removes the links the old
 * fan-out left behind. Spec: .ai/specs/contacts.md "Deal documents reach the deal's parties only".
 */
class DealDocumentContactLinksTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;
    private Property $property;
    private Deal $deal;
    private Contact $seller;
    private Contact $buyer;
    private Contact $lead;
    private Contact $otherBuyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Second Agency', 'slug' => 'sa-' . uniqid()]);
        $branch       = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent  = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        $this->property = Property::withoutEvents(fn () => Property::withoutGlobalScope(AgencyScope::class)->create([
            'external_id' => 'T-' . Str::random(6), 'title' => 'Unit 7', 'address' => '7 Test Road, Ramsgate',
            'agent_id' => $this->agent->id, 'agency_id' => $this->agency->id, 'branch_id' => $branch->id,
        ]));

        $this->seller     = $this->contact('Sam', 'Seller');
        $this->buyer      = $this->contact('Bea', 'Buyer');
        $this->lead       = $this->contact('Lee', 'Lead');
        $this->otherBuyer = $this->contact('Olly', 'OtherBuyer');

        // Everyone is linked to the property, the way Core Match / viewings / enquiries link them.
        foreach ([[$this->seller, 'owner'], [$this->buyer, 'buyer'], [$this->lead, 'lead'], [$this->otherBuyer, 'buyer']] as [$c, $role]) {
            $this->property->contacts()->attach($c->id, ['role' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->deal = $this->makeDeal($this->property);
        DB::table('deal_contacts')->insert([
            ['deal_id' => $this->deal->id, 'contact_id' => $this->seller->id, 'role' => 'seller', 'created_at' => now(), 'updated_at' => now()],
            ['deal_id' => $this->deal->id, 'contact_id' => $this->buyer->id, 'role' => 'buyer', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    // ── filing ───────────────────────────────────────────────────────────────

    public function test_a_seller_side_type_reaches_the_deal_seller_only(): void
    {
        $doc = $this->file('mandate', 'Mandate.pdf');

        $this->assertSame([$this->seller->id], $this->linked($doc));
        $this->assertSame('seller', $this->partyRole($doc, $this->seller));
    }

    public function test_a_proforma_is_seller_side_and_a_buyer_never_gets_the_commission_invoice(): void
    {
        $doc = $this->file('proforma_invoice', 'Proforma PF-0001.pdf');

        $this->assertSame([$this->seller->id], $this->linked($doc));
    }

    public function test_a_buyer_side_type_reaches_the_deal_buyer_only(): void
    {
        $doc = $this->file('bond_grant', 'Bond grant.pdf');

        $this->assertSame([$this->buyer->id], $this->linked($doc));
        $this->assertSame('buyer', $this->partyRole($doc, $this->buyer));
    }

    public function test_a_shared_type_reaches_both_deal_parties(): void
    {
        foreach (['otp' => 'Offer to Purchase.pdf', 'work_authorisation' => 'Work order.pdf', null => 'Email from attorney.pdf'] as $slug => $name) {
            $doc = $this->file($slug ?: null, $name);
            $this->assertEqualsCanonicalizing([$this->seller->id, $this->buyer->id], $this->linked($doc), $name);
        }
    }

    public function test_fica_goes_to_the_side_its_file_name_says_and_to_both_when_it_does_not(): void
    {
        $this->assertSame([$this->buyer->id], $this->linked($this->file('fica', 'FICA - Purchaser 7 Test Road.pdf')));
        $this->assertSame([$this->seller->id], $this->linked($this->file('fica', 'FICA - Seller 7 Test Road.pdf')));
        $this->assertSame([$this->seller->id], $this->linked($this->file('ids', "Seller's ID.pdf")));
        $this->assertEqualsCanonicalizing([$this->seller->id, $this->buyer->id], $this->linked($this->file('fica', 'scan0001.pdf')));
    }

    public function test_a_caller_can_say_which_side_explicitly(): void
    {
        $doc = $this->file('otp', 'Offer to Purchase.pdf', ['party_side' => 'buyer']);

        $this->assertSame([$this->buyer->id], $this->linked($doc));
    }

    public function test_a_lead_or_another_buyer_on_the_property_never_gets_a_deal_document(): void
    {
        foreach ([['otp', 'Offer.pdf'], ['mandate', 'Mandate.pdf'], ['fica', 'FICA - Seller.pdf'], [null, 'x.pdf']] as [$slug, $name]) {
            $linked = $this->linked($this->file($slug, $name));
            $this->assertNotContains($this->lead->id, $linked, $name);
            $this->assertNotContains($this->otherBuyer->id, $linked, $name);
        }
    }

    public function test_a_deal_with_no_recorded_parties_files_to_nobody_not_the_property_contacts(): void
    {
        $bare = $this->makeDeal($this->property);

        $doc = app(DealDocumentService::class)->fileDealDocumentFromDeal($bare, [
            'original_name' => 'Offer.pdf', 'storage_path' => 'deal-docs/' . Str::random(6) . '.pdf', 'source_type' => 'deal',
        ], $this->agent);

        $this->assertSame([], $this->linked($doc));
        $this->assertTrue($doc->properties()->where('properties.id', $this->property->id)->exists(), 'still on the property');
    }

    // ── clean-up command ─────────────────────────────────────────────────────

    public function test_dry_run_counts_and_changes_nothing(): void
    {
        $this->fanOutSet();
        $before = DB::table('document_contacts')->count();

        $this->artisan('deal-docs:fix-contact-links', ['--dry-run' => true])
            ->expectsOutputToContain('To remove: 6 links, 3 contacts, 3 documents.')
            ->assertExitCode(0);

        $this->assertSame($before, DB::table('document_contacts')->count());
        $this->assertSame([], glob(storage_path('app/backups/deal-docs-contact-links-*.json')) ?: [], 'no backup on a dry run');
    }

    public function test_apply_removes_only_the_wrong_rows_backs_up_and_audits(): void
    {
        [$otp, $sellerFica, $splitter, $signed, $proforma] = $this->fanOutSet();

        $this->artisan('deal-docs:fix-contact-links', ['--apply' => true])->assertExitCode(0);

        // Proforma: the seller keeps it, the buyer (wrong side) loses it.
        $this->assertSame([$this->seller->id], $this->linked($proforma));

        // OTP: only the lead and the other buyer went. Seller and buyer keep theirs.
        $this->assertEqualsCanonicalizing([$this->seller->id, $this->buyer->id], $this->linked($otp));
        // Seller's FICA: the buyer (wrong side) went too, along with the lead and the other buyer.
        $this->assertSame([$this->seller->id], $this->linked($sellerFica));
        // Not from a deal filing, and deliberate (party role set) links are untouched.
        $this->assertEqualsCanonicalizing([$this->lead->id], $this->linked($splitter));
        $this->assertEqualsCanonicalizing([$this->lead->id], $this->linked($signed));

        $backups = glob(storage_path('app/backups/deal-docs-contact-links-*.json')) ?: [];
        $this->assertCount(1, $backups);
        $payload = json_decode(File::get($backups[0]), true);
        $this->assertCount(6, $payload['rows']);
        $this->assertEqualsCanonicalizing(['not_deal_party', 'wrong_side'], array_values(array_unique(array_column($payload['rows'], 'reason'))));
        foreach ($backups as $b) {
            File::delete($b);
        }

        $this->assertSame(6, DB::table('contact_audit_log')->where('event_type', 'document_unlinked')->count());
        $this->assertSame(2, DB::table('contact_audit_log')->where('event_type', 'document_unlinked')
            ->where('contact_id', $this->buyer->id)->count(), 'the buyer lost the seller FICA and the proforma');

        // Idempotent.
        $this->artisan('deal-docs:fix-contact-links', ['--apply' => true])->expectsOutputToContain('Nothing to remove.')->assertExitCode(0);
    }

    public function test_a_deal_with_no_parties_keeps_the_property_sellers_links(): void
    {
        $bare = $this->makeDeal($this->property);
        $doc  = $this->documentFor($bare, 'otp', 'Offer.pdf');
        $doc->properties()->attach($this->property->id);
        $this->fanOut($doc, [$this->seller, $this->lead]);

        $this->artisan('deal-docs:fix-contact-links', ['--apply' => true])->assertExitCode(0);

        foreach (glob(storage_path('app/backups/deal-docs-contact-links-*.json')) ?: [] as $b) {
            File::delete($b);
        }
        $this->assertSame([$this->seller->id], $this->linked($doc), 'owner kept, lead removed');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** The old behaviour, reproduced: an OTP and a seller FICA fanned out to all four, a splitter doc and a signed doc untouched. */
    private function fanOutSet(): array
    {
        $everyone = [$this->seller, $this->buyer, $this->lead, $this->otherBuyer];

        $otp = $this->documentFor($this->deal, 'otp', 'Offer to Purchase.pdf');
        $this->fanOut($otp, $everyone);

        $sellerFica = $this->documentFor($this->deal, 'fica', 'FICA - Seller.pdf');
        $this->fanOut($sellerFica, $everyone);

        // The commission invoice also reached the deal's buyer - seller-side, so that is the wrong side.
        $proforma = $this->documentFor($this->deal, 'proforma_invoice', 'Proforma.pdf', 'proforma_generated');
        $this->fanOut($proforma, [$this->seller, $this->buyer]);

        // Not a deal filing — a splitter document linked to a lead on purpose.
        $splitter = $this->documentFor($this->deal, 'mandate', 'Mandate.pdf', 'pdf_splitter');
        $this->fanOut($splitter, [$this->lead]);

        // A deal filing but with a deliberate party role (e-sign style) — never touched.
        $signed = $this->documentFor($this->deal, 'otp', 'Signed OTP.pdf');
        DB::table('document_contacts')->insert(['document_id' => $signed->id, 'contact_id' => $this->lead->id, 'party_role' => 'witness',
            'created_at' => $signed->created_at, 'updated_at' => $signed->created_at]);

        return [$otp, $sellerFica, $splitter, $signed, $proforma];
    }

    private function documentFor(Deal $deal, ?string $slug, string $name, string $source = 'deal'): Document
    {
        return Document::create([
            'original_name' => $name, 'storage_path' => 'deal-docs/' . Str::random(8) . '.pdf', 'disk' => 'local', 'size' => 1,
            'document_type_id' => $slug ? $this->type($slug) : null, 'source_type' => $source, 'source_id' => $deal->id,
            'agency_id' => $this->agency->id, 'uploaded_by' => $this->agent->id,
        ]);
    }

    private function fanOut(Document $doc, array $contacts): void
    {
        foreach ($contacts as $c) {
            DB::table('document_contacts')->insert(['document_id' => $doc->id, 'contact_id' => $c->id, 'party_role' => null,
                'created_at' => $doc->created_at, 'updated_at' => $doc->created_at]);
        }
    }

    private function file(?string $slug, string $name, array $extra = []): Document
    {
        return app(DealDocumentService::class)->fileDealDocumentFromDeal($this->deal, [
            'original_name' => $name, 'storage_path' => 'deal-docs/' . Str::random(8) . '.pdf', 'source_type' => 'deal',
            'document_type_id' => $slug ? $this->type($slug) : null,
        ] + $extra, $this->agent);
    }

    private function type(string $slug): int
    {
        return DocumentType::query()->firstOrCreate(['slug' => $slug], ['label' => ucfirst($slug), 'sort_order' => 1, 'is_active' => true])->id;
    }

    /** @return int[] contact ids linked to the document */
    private function linked(Document $doc): array
    {
        return DB::table('document_contacts')->where('document_id', $doc->id)->pluck('contact_id')->map(fn ($i) => (int) $i)->all();
    }

    private function partyRole(Document $doc, Contact $c): ?string
    {
        return DB::table('document_contacts')->where('document_id', $doc->id)->where('contact_id', $c->id)->value('party_role');
    }

    private function contact(string $first, string $last): Contact
    {
        return Contact::withoutEvents(fn () => Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->agent->branch_id, 'first_name' => $first, 'last_name' => $last,
            'email' => strtolower($first) . Str::random(4) . '@example.co.za', 'created_by_user_id' => $this->agent->id, 'agent_id' => $this->agent->id,
        ]));
    }

    private function makeDeal(Property $property): Deal
    {
        $twinId = DB::table('deals_v2')->insertGetId([
            'reference' => 'DR2-' . Str::random(5), 'deal_type' => 'bond', 'listing_agent_id' => $this->agent->id,
            'purchase_price' => 1_000_000, 'commission_amount' => 50_000, 'commission_vat' => 7_500, 'offer_date' => '2026-10-01',
            'branch_id' => $this->agent->branch_id, 'agency_id' => $this->agency->id, 'created_by_id' => $this->agent->id,
            'property_id' => $property->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Deal::withoutEvents(fn () => Deal::withoutGlobalScopes()->create([
            'period' => '2026-10', 'deal_date' => '2026-10-01', 'property_value' => 1_000_000, 'total_commission' => 57_500,
            'reference' => 'REG-' . Str::random(5), 'deal_type' => 'bond', 'listing_agent_id' => $this->agent->id,
            'agency_id' => $this->agency->id, 'branch_id' => $this->agent->branch_id, 'property_id' => $property->id, 'deal_v2_id' => $twinId,
        ]));
    }
}
