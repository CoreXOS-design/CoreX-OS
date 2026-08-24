<?php

namespace Tests\Feature\DealV2;

use App\Mail\DealV2\DealPackMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationFilingSuspense;
use App\Models\Communications\CommunicationMailbox;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceProviderContact;
use App\Models\DealV2\DealDocumentDistribution;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Communications\EmailArchiveIngestor;
use App\Services\DealV2\Dr2DistributionComposer;
use App\Services\DealV2\Dr2DistributionSendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-231 §3.1/§3.8 verification — closes the one gap in the existing coverage
 * (Dr2DistributionTest::test_email_send_stamps_cx_deal_token_and_message_id_thread_key
 * proves the regex recovers the id from the real generated subject in isolation;
 * InboundCorrespondenceTest always hand-types the token into the inbound fixture).
 * Nothing here duplicates either — this is the actual outbound-code-generates ->
 * inbound-code-consumes round trip, plus the two edge cases Johan asked for
 * explicitly: Re:/Fwd: survival and an unknown/stale token never crashing or
 * mis-filing.
 */
class At231TokenRoundTripTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{agency:Agency,agent:User,property:Property,seller:Contact,firm:AgencyServiceProvider,attorney:AgencyServiceProviderContact,deal:Deal,twinId:int,mailbox:CommunicationMailbox} */
    private function makeWorld(): array
    {
        $agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Shelly Beach']);
        $agent  = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        $property = Property::withoutEvents(fn () => Property::withoutGlobalScope(AgencyScope::class)->create([
            'external_id' => 'T-' . Str::random(6), 'title' => 'Home', 'address' => '12 Marine Dr', 'suburb' => 'Shelly Beach',
            'agent_id' => $agent->id, 'agency_id' => $agency->id, 'branch_id' => $branch->id,
        ]));
        $seller = Contact::withoutEvents(fn () => Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'Annelise', 'last_name' => 'vd Merwe',
            'email' => 'seller' . Str::random(4) . '@ex.co.za', 'phone' => '0821234567',
            'created_by_user_id' => $agent->id, 'agent_id' => $agent->id,
        ]));
        $property->contacts()->attach($seller->id, ['role' => 'seller', 'created_at' => now(), 'updated_at' => now()]);

        $firm = AgencyServiceProvider::create(['agency_id' => $agency->id, 'name' => 'Van Dyk & Swart', 'specialty' => 'transfer_attorney', 'email' => 'firm@vds.co.za', 'is_active' => true, 'created_by_id' => $agent->id]);
        $attorney = AgencyServiceProviderContact::create(['agency_id' => $agency->id, 'service_provider_id' => $firm->id, 'attorney_name' => 'Adv Botha', 'email' => 'botha@vds.co.za', 'is_active' => true, 'created_by_id' => $agent->id]);

        $twinId = DB::table('deals_v2')->insertGetId([
            'reference' => 'DR2-' . Str::random(5), 'deal_type' => 'bond', 'listing_agent_id' => $agent->id,
            'purchase_price' => 1_950_000, 'commission_amount' => 97_500, 'commission_vat' => 14_625,
            'offer_date' => '2026-03-01', 'branch_id' => $branch->id, 'agency_id' => $agency->id,
            'created_by_id' => $agent->id, 'property_id' => $property->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $deal = Deal::withoutEvents(fn () => Deal::withoutGlobalScopes()->create([
            'period' => '2026-03', 'deal_date' => '2026-03-01', 'property_value' => 1_950_000, 'total_commission' => 112_125,
            'reference' => 'REG-' . Str::random(5), 'deal_no' => (string) random_int(1000, 9999), 'deal_type' => 'bond',
            'seller_name' => 'Annelise vd Merwe', 'property_address' => '12 Marine Dr',
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id, 'deal_v2_id' => $twinId,
            'attorney_provider_id' => $firm->id, 'attorney_contact_id' => $attorney->id,
        ]));

        $mailbox = CommunicationMailbox::create([
            'agency_id' => $agency->id, 'user_id' => $agent->id, 'email_address' => 'agent@hfcoastal.co.za',
            'imap_host' => 'imap.example.com', 'imap_port' => 993, 'username' => 'agent@hfcoastal.co.za',
            'auth_type' => 'imap', 'set_by' => 'user', 'active' => true,
        ]);

        return compact('agency', 'agent', 'property', 'seller', 'firm', 'attorney', 'deal', 'twinId', 'mailbox');
    }

    private function fileOtpDoc(array $w): Document
    {
        $type = DocumentType::firstOrCreate(['slug' => 'otp'], ['label' => 'OTP', 'sort_order' => 1, 'is_active' => true]);
        $path = 'deals/x/' . Str::random(6) . '.pdf';
        Storage::disk('local')->put($path, str_repeat('x', 1000));
        $doc = Document::withoutGlobalScopes()->create([
            'agency_id' => $w['agency']->id, 'original_name' => 'OTP.pdf', 'storage_path' => $path,
            'disk' => 'local', 'mime_type' => 'application/pdf', 'size' => 1000, 'document_type_id' => $type->id,
            'source_type' => 'deal', 'source_id' => $w['deal']->id, 'deal_id' => $w['twinId'],
            'uploaded_by' => $w['agent']->id,
        ]);
        $doc->properties()->syncWithoutDetaching([$w['property']->id]);

        return $doc;
    }

    private function ingest(array $w, array $msg): string
    {
        return app(EmailArchiveIngestor::class)->ingest($w['mailbox'], $msg, Communication::DIRECTION_INBOUND);
    }

    private function baseInbound(array $w, string $subject): array
    {
        return [
            'external_id'  => 'mid-' . Str::random(12) . '@vds.co.za',
            'thread_key'   => null,
            'from'         => 'botha@vds.co.za',
            'counterpart'  => 'botha@vds.co.za',
            'participants' => ['botha@vds.co.za', 'agent@hfcoastal.co.za'],
            'subject'      => $subject,
            'body_text'    => 'Please find the signed COC attached.',
            'occurred_at'  => Carbon::parse('2026-03-05 09:00:00'),
            'raw'          => 'Raw eml ' . Str::random(20),
            'attachments'  => [['filename' => 'COC.pdf', 'mime' => 'application/pdf', 'bytes' => 'PDFBYTES-' . Str::random(8)]],
        ];
    }

    /**
     * THE round trip: send a real deal pack (real code, real token construction),
     * capture the REAL persisted outbound subject, then feed that exact subject —
     * with a normal "Re: " reply prefix, since that's what actually comes back —
     * into the real inbound ingestion pipeline. Asserts it resolves to the SAME
     * deal that sent it, with no hand-typed token anywhere in this test.
     */
    public function test_outbound_stamped_token_round_trips_through_a_simulated_reply(): void
    {
        Storage::fake('local');
        Mail::fake();
        $w = $this->makeWorld();
        $otp = $this->fileOtpDoc($w);

        $recipient = app(Dr2DistributionComposer::class)->recipientsFor($w['deal'], 'transfer_attorney')[0];
        app(Dr2DistributionSendService::class)->sendToParty(
            $w['deal'], 'transfer_attorney', $recipient, [$otp->id], 'direct_attachment', 'email', 'Please find attached.', $w['agent']
        );

        $dist = DealDocumentDistribution::withoutGlobalScopes()->where('deal_id', $w['twinId'])->first();
        $this->assertNotNull($dist, 'outbound send recorded a distribution');
        $outboundComm = Communication::withoutGlobalScopes()->find($dist->communication_id);
        $this->assertNotNull($outboundComm);
        $realSubject = (string) $outboundComm->subject;
        $this->assertStringContainsString('[CX-D' . $w['deal']->id . ']', $realSubject, 'sanity: the real outbound subject actually carries the token');

        // Simulate the attorney's reply: "Re: " prefixed onto the EXACT real subject.
        $result = $this->ingest($w, $this->baseInbound($w, 'Re: ' . $realSubject));
        $this->assertSame(EmailArchiveIngestor::RESULT_PARKED, $result, 'known attorney sender parks, does not drop or crash');

        $inboundComm = Communication::withoutGlobalScopes()
            ->where('agency_id', $w['agency']->id)->where('direction', Communication::DIRECTION_INBOUND)->first();
        $this->assertNotNull($inboundComm);

        $suspense = CommunicationFilingSuspense::withoutGlobalScopes()->where('communication_id', $inboundComm->id)->first();
        $this->assertNotNull($suspense, 'the reply parked with a suggestion');
        $this->assertSame($w['deal']->id, (int) $suspense->suggested_deal_id, 'resolved back to the exact deal that sent it — via real code both directions');
        $this->assertSame(CommunicationFilingSuspense::CONF_HIGH, $suspense->confidence, 'token + known party email = HIGH');
    }

    /**
     * Re:/Fwd: prefixes — including a doubled prefix, which real mail clients
     * produce on a reply-to-a-forward — must not break the token match.
     */
    public function test_re_and_fwd_prefixes_do_not_break_token_matching(): void
    {
        Storage::fake('local');
        $w = $this->makeWorld();
        $token = '[CX-D' . $w['deal']->id . ']';
        $base = 'Documents — ' . $w['deal']->deal_no . ' ' . $token;

        foreach (['Re: ' . $base, 'Fwd: ' . $base, 'Fwd: Re: ' . $base, 'RE: ' . $base] as $subject) {
            $result = $this->ingest($w, $this->baseInbound($w, $subject));
            $this->assertSame(EmailArchiveIngestor::RESULT_PARKED, $result, "prefix variant failed to park: {$subject}");

            $comm = Communication::withoutGlobalScopes()
                ->where('agency_id', $w['agency']->id)->where('direction', Communication::DIRECTION_INBOUND)
                ->latest('id')->first();
            $suspense = CommunicationFilingSuspense::withoutGlobalScopes()->where('communication_id', $comm->id)->first();
            $this->assertNotNull($suspense, "no suspense row for: {$subject}");
            $this->assertSame($w['deal']->id, (int) $suspense->suggested_deal_id, "wrong deal resolved for: {$subject}");
        }
    }

    /**
     * A token that resolves to nothing real — never seeded, and separately a
     * genuinely deleted deal — must never crash the ingestor and must never be
     * mis-filed to any deal. Sender is a KNOWN attorney (so this exercises the
     * token-miss fallthrough, not the unrelated POPIA drop path).
     */
    public function test_unknown_and_deleted_deal_tokens_are_handled_safely(): void
    {
        Storage::fake('local');
        $w = $this->makeWorld();

        // 1) Token for a deal id that was never seeded at all.
        $bogusId = 999999999;
        $result = $this->ingest($w, $this->baseInbound($w, 'Documents — X [CX-D' . $bogusId . ']'));
        $this->assertSame(EmailArchiveIngestor::RESULT_PARKED, $result, 'known attorney still parks even when the token is bogus');

        $comm1 = Communication::withoutGlobalScopes()
            ->where('agency_id', $w['agency']->id)->where('direction', Communication::DIRECTION_INBOUND)
            ->latest('id')->first();
        $suspense1 = CommunicationFilingSuspense::withoutGlobalScopes()->where('communication_id', $comm1->id)->first();
        $this->assertNotNull($suspense1);
        $this->assertNotSame($bogusId, (int) $suspense1->suggested_deal_id, 'must never suggest the nonexistent id');
        // Falls through to the real deal via sender-email/single-active-deal corroboration —
        // proves it degrades gracefully, not that it silently invents a match for the bogus token.
        $this->assertSame($w['deal']->id, (int) $suspense1->suggested_deal_id);
        $this->assertNotSame(CommunicationFilingSuspense::CONF_HIGH, $suspense1->confidence, 'a bogus token must not earn HIGH confidence');

        // 2) Token for a deal that existed but is now soft-deleted. Sender is still a
        //    known attorney, so this still PARKS (per §3.2 the park decision is about
        //    sender identity, not deal resolution) — but with zero active candidate
        //    deals for the firm, it must fall to LOW/no-suggestion, never crash, and
        //    never point at the soft-deleted deal.
        $deletedDealId = $w['deal']->id;
        $w['deal']->delete();
        $result2 = $this->ingest($w, $this->baseInbound($w, 'Documents — X [CX-D' . $deletedDealId . ']'));
        $this->assertSame(EmailArchiveIngestor::RESULT_PARKED, $result2, 'known attorney still parks even with zero active deals left');

        $comm2 = Communication::withoutGlobalScopes()
            ->where('agency_id', $w['agency']->id)->where('direction', Communication::DIRECTION_INBOUND)
            ->latest('id')->first();
        $suspense2 = CommunicationFilingSuspense::withoutGlobalScopes()->where('communication_id', $comm2->id)->first();
        $this->assertNotNull($suspense2);
        $this->assertNotSame($deletedDealId, (int) $suspense2->suggested_deal_id, 'never suggests the soft-deleted deal');
        $this->assertSame(CommunicationFilingSuspense::CONF_LOW, $suspense2->confidence, 'zero active candidates after delete — LOW, no confident suggestion');
    }
}
