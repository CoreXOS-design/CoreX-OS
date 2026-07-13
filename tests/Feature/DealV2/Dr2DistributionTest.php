<?php

namespace Tests\Feature\DealV2;

use App\Mail\DealV2\DealPackMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Communications\Communication;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceProviderContact;
use App\Models\DealV2\DealDocumentDistribution;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\DealV2\DocumentDistributionMatrix;
use App\Services\DealV2\Dr2DistributionComposer;
use App\Services\DealV2\Dr2DistributionSendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class Dr2DistributionTest extends TestCase
{
    use RefreshDatabase;

    /** Build agency + deal (+twin) with a seller contact, an attorney, and a filed OTP doc. */
    private function makeDeal(array $opts = []): array
    {
        $agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Shelly Beach']);
        $agent  = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        $property = ($opts['no_property'] ?? false) ? null : \App\Models\Property::withoutEvents(fn () => \App\Models\Property::withoutGlobalScope(AgencyScope::class)->create([
            'external_id' => 'T-' . Str::random(6), 'title' => 'Home', 'address' => '12 Marine Dr', 'suburb' => 'Shelly Beach',
            'agent_id' => $agent->id, 'agency_id' => $agency->id, 'branch_id' => $branch->id,
        ]));
        $seller = null;
        if ($property && ! ($opts['no_seller'] ?? false)) {
            $seller = Contact::withoutEvents(fn () => Contact::create([
                'agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'Annelise', 'last_name' => 'vd Merwe',
                'email' => ($opts['seller_no_email'] ?? false) ? null : 'seller' . Str::random(4) . '@ex.co.za',
                'phone' => '0821234567', 'created_by_user_id' => $agent->id, 'agent_id' => $agent->id,
            ]));
            $property->contacts()->attach($seller->id, ['role' => 'seller', 'created_at' => now(), 'updated_at' => now()]);
        }

        // attorney firm + contact
        $firm = AgencyServiceProvider::create(['agency_id' => $agency->id, 'name' => 'BBB Inc', 'specialty' => 'transfer_attorney', 'email' => 'firm@bbb.co.za', 'is_active' => true, 'created_by_id' => $agent->id]);
        $attorney = AgencyServiceProviderContact::create(['agency_id' => $agency->id, 'service_provider_id' => $firm->id, 'attorney_name' => 'Adv Botha', 'email' => 'botha@bbb.co.za', 'is_active' => true, 'created_by_id' => $agent->id]);

        $twinId = DB::table('deals_v2')->insertGetId([
            'reference' => 'DR2-' . Str::random(5), 'deal_type' => 'bond', 'listing_agent_id' => $agent->id,
            'purchase_price' => 1_950_000, 'commission_amount' => 97_500, 'commission_vat' => 14_625,
            'offer_date' => '2026-03-01', 'branch_id' => $branch->id, 'agency_id' => $agency->id,
            'created_by_id' => $agent->id, 'property_id' => $property?->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $deal = Deal::withoutEvents(fn () => Deal::withoutGlobalScopes()->create([
            'period' => '2026-03', 'deal_date' => '2026-03-01', 'property_value' => 1_950_000, 'total_commission' => 112_125,
            'reference' => 'REG-' . Str::random(5), 'deal_no' => random_int(1000, 9999), 'deal_type' => 'bond',
            'seller_name' => 'Annelise vd Merwe', 'property_address' => '12 Marine Dr',
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $property?->id, 'deal_v2_id' => $twinId,
            'attorney_provider_id' => ($opts['no_attorney'] ?? false) ? null : $firm->id,
            'attorney_contact_id'  => ($opts['no_attorney'] ?? false) ? null : $attorney->id,
        ]));

        return compact('agency', 'agent', 'property', 'seller', 'firm', 'attorney', 'twinId', 'deal');
    }

    private function fileDoc(array $ctx, string $slug, int $size = 1000): Document
    {
        $type = DocumentType::firstOrCreate(['slug' => $slug], ['label' => ucfirst($slug), 'sort_order' => 1, 'is_active' => true]);
        $path = 'deals/x/' . Str::random(6) . '.pdf';
        Storage::disk('local')->put($path, str_repeat('x', $size));
        $doc = Document::withoutGlobalScopes()->create([
            'agency_id' => $ctx['agency']->id, 'original_name' => strtoupper($slug) . '.pdf', 'storage_path' => $path,
            'disk' => 'local', 'mime_type' => 'application/pdf', 'size' => $size, 'document_type_id' => $type->id,
            'source_type' => 'deal', 'source_id' => $ctx['deal']->id, 'deal_id' => $ctx['twinId'],
            'uploaded_by' => $ctx['agent']->id,
        ]);
        if ($ctx['property']) {
            $doc->properties()->syncWithoutDetaching([$ctx['property']->id]);
        }
        return $doc;
    }

    private function setMatrix(int $agencyId, string $slug, array $roles): void
    {
        $typeId = DocumentType::where('slug', $slug)->value('id');
        app(DocumentDistributionMatrix::class)->setTypeDistribution($agencyId, $typeId, $roles);
    }

    public function test_composer_resolves_parties_from_matrix_and_property_links(): void
    {
        $ctx = $this->makeDeal();
        $otp = $this->fileDoc($ctx, 'otp');
        $this->setMatrix($ctx['agency']->id, 'otp', ['seller', 'transfer_attorney']);

        $parties = collect(app(Dr2DistributionComposer::class)->parties($ctx['deal']))->keyBy('role');

        // Seller resolved from the property link, with the OTP as its matrix default doc.
        $this->assertNotEmpty($parties['seller']['recipients']);
        $this->assertSame($ctx['seller']->id, $parties['seller']['recipients'][0]['id']);
        $this->assertTrue($parties['seller']['default_documents']->contains('id', $otp->id));
        $this->assertTrue($parties['seller']['sendable']);

        // Attorney resolved from the supplier link.
        $this->assertSame('botha@bbb.co.za', $parties['transfer_attorney']['recipients'][0]['email']);
        $this->assertTrue($parties['transfer_attorney']['default_documents']->contains('id', $otp->id));
    }

    public function test_messy_party_data_is_graceful_never_crashes(): void
    {
        // No property, no attorney → parties resolve to empty, with notes, nothing sendable, no crash.
        $ctx = $this->makeDeal(['no_property' => true, 'no_attorney' => true]);
        $parties = collect(app(Dr2DistributionComposer::class)->parties($ctx['deal']))->keyBy('role');
        $this->assertFalse($parties['seller']['sendable']);
        $this->assertNotNull($parties['seller']['note']);
        $this->assertFalse($parties['transfer_attorney']['sendable']);

        // Seller with NO email → present but not sendable by email; note explains.
        $ctx2 = $this->makeDeal(['seller_no_email' => true]);
        $p2 = collect(app(Dr2DistributionComposer::class)->parties($ctx2['deal']))->firstWhere('role', 'seller');
        $this->assertFalse($p2['sendable']);
        $this->assertStringContainsString('email', strtolower((string) $p2['note']));
    }

    public function test_split_by_size_never_splits_a_document(): void
    {
        $sender = app(Dr2DistributionSendService::class);
        $limit  = 10_000;
        $docs = collect([
            (object) ['id' => 1, 'size' => 8000], (object) ['id' => 2, 'size' => 8000],  // together > limit
            (object) ['id' => 3, 'size' => 25_000],                                        // alone > limit
            (object) ['id' => 4, 'size' => 1000],
        ]);
        $parts = $sender->splitBySize($docs, $limit);
        foreach ($parts as $part) {
            $sum = $part->sum(fn ($d) => $d->size);
            // A part is under the limit UNLESS it is a single over-limit document (never split).
            $this->assertTrue($sum <= $limit || $part->count() === 1, 'a part exceeds the limit with >1 doc');
        }
        // The 25k doc is its own part.
        $this->assertTrue(collect($parts)->contains(fn ($p) => $p->count() === 1 && $p->first()->id === 3));
    }

    public function test_send_creates_distributions_and_three_pillar_comms_no_refile(): void
    {
        Storage::fake('local');
        Mail::fake();
        $ctx = $this->makeDeal();
        $otp = $this->fileDoc($ctx, 'otp');
        $docContactsBefore = $otp->contacts()->count();

        $composer = app(Dr2DistributionComposer::class);
        $recipient = $composer->recipientsFor($ctx['deal'], 'seller')[0];
        $result = app(Dr2DistributionSendService::class)->sendToParty(
            $ctx['deal'], 'seller', $recipient, [$otp->id], 'direct_attachment', 'email', 'Please find attached.', $ctx['agent']
        );

        // Distribution row on the twin, with channel + status sent.
        $dist = DealDocumentDistribution::withoutGlobalScopes()->where('deal_id', $ctx['twinId'])->first();
        $this->assertNotNull($dist);
        $this->assertSame('email', $dist->channel);
        $this->assertSame('sent', $dist->status);
        $this->assertNotNull($dist->communication_id);
        Mail::assertSent(DealPackMail::class);

        // 3-pillar comms: Contact + Property + DealV2 link rows.
        $comm = Communication::withoutGlobalScopes()->find($dist->communication_id);
        $types = $comm->links()->pluck('linkable_type')->all();
        $this->assertContains(\App\Models\Contact::class, $types);
        $this->assertContains(\App\Models\Property::class, $types);
        $this->assertContains(\App\Models\DealV2\DealV2::class, $types);

        // No duplicate FILING — the doc's contact links are unchanged (we sent an existing filed doc).
        $this->assertSame($docContactsBefore, $otp->fresh()->contacts()->count());
    }

    public function test_secure_link_mints_a_token_per_document(): void
    {
        Storage::fake('local');
        Mail::fake();
        $ctx = $this->makeDeal();
        $otp = $this->fileDoc($ctx, 'otp');
        $recipient = app(Dr2DistributionComposer::class)->recipientsFor($ctx['deal'], 'seller')[0];
        app(Dr2DistributionSendService::class)->sendToParty($ctx['deal'], 'seller', $recipient, [$otp->id], 'secure_link', 'email', null, $ctx['agent']);

        $dist = DealDocumentDistribution::withoutGlobalScopes()->where('deal_id', $ctx['twinId'])->first();
        $this->assertNotNull($dist->secure_token);
        $this->assertTrue((bool) $dist->otp_required);
    }

    public function test_whatsapp_channel_logs_without_dispatch(): void
    {
        Storage::fake('local');
        Mail::fake();
        $ctx = $this->makeDeal();
        $otp = $this->fileDoc($ctx, 'otp');
        $recipient = app(Dr2DistributionComposer::class)->recipientsFor($ctx['deal'], 'seller')[0];
        $result = app(Dr2DistributionSendService::class)->sendToParty($ctx['deal'], 'seller', $recipient, [$otp->id], 'direct_attachment', 'whatsapp', 'hi', $ctx['agent']);

        $dist = DealDocumentDistribution::withoutGlobalScopes()->where('deal_id', $ctx['twinId'])->first();
        $this->assertSame('whatsapp', $dist->channel);
        $this->assertSame('secure_link', $dist->delivery_mode);   // WA forces links
        $comm = Communication::withoutGlobalScopes()->find($dist->communication_id);
        $this->assertSame('whatsapp', $comm->channel);
        Mail::assertNothingSent();   // no real email dispatch for WhatsApp
    }

    public function test_distribution_is_soft_deletable(): void
    {
        Storage::fake('local');
        Mail::fake();
        $ctx = $this->makeDeal();
        $otp = $this->fileDoc($ctx, 'otp');
        $recipient = app(Dr2DistributionComposer::class)->recipientsFor($ctx['deal'], 'seller')[0];
        app(Dr2DistributionSendService::class)->sendToParty($ctx['deal'], 'seller', $recipient, [$otp->id], 'secure_link', 'email', null, $ctx['agent']);

        $dist = DealDocumentDistribution::withoutGlobalScopes()->where('deal_id', $ctx['twinId'])->first();
        $dist->delete();
        $this->assertSoftDeleted('deal_document_distributions', ['id' => $dist->id]);
    }
}
