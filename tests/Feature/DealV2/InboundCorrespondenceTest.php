<?php

namespace Tests\Feature\DealV2;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationFilingSuspense;
use App\Models\Communications\CommunicationLearnedRef;
use App\Models\Communications\CommunicationLink;
use App\Models\Communications\CommunicationMailbox;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceProviderContact;
use App\Models\DealV2\DealV2;
use App\Models\Document;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Communications\CorrespondenceFilingService;
use App\Services\Communications\EmailArchiveIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-231 P2 — inbound attorney correspondence: park (known attorney only),
 * resolve (token > thread_key > single-active-deal), first-verify + file + learn,
 * silent auto on a learned second email, reassign (correct the learned pattern),
 * and the POPIA drop for unknown senders.
 */
class InboundCorrespondenceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{agency:Agency,agent:User,property:Property,firm:AgencyServiceProvider,attorney:AgencyServiceProviderContact,deal:Deal,twinId:int,mailbox:CommunicationMailbox} */
    private function makeWorld(array $opts = []): array
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
            'email' => 'annelise' . Str::random(3) . '@ex.co.za', 'phone' => '0821234567',
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

        return compact('agency', 'agent', 'property', 'firm', 'attorney', 'deal', 'twinId', 'mailbox');
    }

    private function inbound(array $w, array $over = []): array
    {
        return array_merge([
            'external_id'  => 'mid-' . Str::random(12) . '@vds.co.za',
            'thread_key'   => null,
            'from'         => 'botha@vds.co.za',
            'counterpart'  => 'botha@vds.co.za',
            'participants' => ['botha@vds.co.za', 'agent@hfcoastal.co.za'],
            'subject'      => 'Re: Documents — ' . $w['deal']->deal_no . ' [CX-D' . $w['deal']->id . ']',
            'body_text'    => 'Please find the signed COC attached.',
            'occurred_at'  => Carbon::parse('2026-03-05 09:00:00'),
            'raw'          => 'Raw eml ' . Str::random(20),
            'attachments'  => [['filename' => 'COC.pdf', 'mime' => 'application/pdf', 'bytes' => 'PDFBYTES-' . Str::random(8)]],
        ], $over);
    }

    private function ingest(array $w, array $msg): string
    {
        return app(EmailArchiveIngestor::class)->ingest($w['mailbox'], $msg, Communication::DIRECTION_INBOUND);
    }

    public function test_known_attorney_token_email_parks_with_high_suggestion_not_yet_filed(): void
    {
        Storage::fake('local');
        $w = $this->makeWorld();

        $result = $this->ingest($w, $this->inbound($w));
        $this->assertSame(EmailArchiveIngestor::RESULT_PARKED, $result);

        $comm = Communication::withoutGlobalScopes()->where('agency_id', $w['agency']->id)->first();
        $this->assertNotNull($comm);
        $this->assertSame(Communication::DIRECTION_INBOUND, $comm->direction);

        $suspense = CommunicationFilingSuspense::withoutGlobalScopes()->where('communication_id', $comm->id)->first();
        $this->assertNotNull($suspense);
        $this->assertSame(CommunicationFilingSuspense::CONF_HIGH, $suspense->confidence);
        $this->assertSame($w['deal']->id, (int) $suspense->suggested_deal_id);
        $this->assertSame(CommunicationFilingSuspense::STATUS_PENDING, $suspense->status);

        // HIGH = provisional (unconfirmed) deal link, but NOT filed yet — no Document.
        $this->assertSame(0, Document::withoutGlobalScopes()->where('source_type', 'inbound_email')->count());
        $provisional = CommunicationLink::withoutGlobalScopes()
            ->where('communication_id', $comm->id)->where('linkable_type', DealV2::class)->first();
        $this->assertNotNull($provisional);
        $this->assertNull($provisional->confirmed_at, 'deal link stays provisional until first-verify');
    }

    public function test_unknown_sender_still_drops_popia_scope(): void
    {
        Storage::fake('local');
        $w = $this->makeWorld();

        $result = $this->ingest($w, $this->inbound($w, [
            'from' => 'random@nowhere.co.za', 'counterpart' => 'random@nowhere.co.za',
            'participants' => ['random@nowhere.co.za'],
            'subject' => 'Hello (no token)',
        ]));

        $this->assertSame(EmailArchiveIngestor::RESULT_DROPPED, $result);
        $this->assertSame(0, Communication::withoutGlobalScopes()->where('agency_id', $w['agency']->id)->count());
        $this->assertSame(0, CommunicationFilingSuspense::withoutGlobalScopes()->count());
    }

    public function test_first_verify_files_three_pillars_and_learns_the_ref(): void
    {
        Storage::fake('local');
        $w = $this->makeWorld();
        $this->ingest($w, $this->inbound($w));

        $comm = Communication::withoutGlobalScopes()->where('agency_id', $w['agency']->id)->first();
        $suspense = CommunicationFilingSuspense::withoutGlobalScopes()->where('communication_id', $comm->id)->first();

        app(CorrespondenceFilingService::class)->verify($suspense, $w['deal']->id, $w['agent']);

        // Filed: a Document anchored on the deal twin + property, tagged inbound_email.
        $doc = Document::withoutGlobalScopes()->where('source_type', 'inbound_email')->first();
        $this->assertNotNull($doc);
        $this->assertSame($w['twinId'], (int) $doc->deal_id);
        $this->assertTrue($doc->properties()->where('properties.id', $w['property']->id)->exists());

        // Learned: the cx_token signal is saved verified against the deal.
        $learned = CommunicationLearnedRef::withoutGlobalScopes()
            ->where('signal_type', CommunicationLearnedRef::SIGNAL_CX_TOKEN)
            ->where('signal_value', 'cx-d' . $w['deal']->id)->first();
        $this->assertNotNull($learned);
        $this->assertTrue((bool) $learned->is_verified);
        $this->assertSame($w['deal']->id, (int) $learned->deal_id);

        $this->assertSame(CommunicationFilingSuspense::STATUS_VERIFIED, $suspense->fresh()->status);
    }

    public function test_second_same_ref_email_auto_files_silently_after_verify(): void
    {
        Storage::fake('local');
        $w = $this->makeWorld();
        // First email → verify → learns.
        $this->ingest($w, $this->inbound($w));
        $first = Communication::withoutGlobalScopes()->where('agency_id', $w['agency']->id)->first();
        $s1 = CommunicationFilingSuspense::withoutGlobalScopes()->where('communication_id', $first->id)->first();
        app(CorrespondenceFilingService::class)->verify($s1, $w['deal']->id, $w['agent']);

        // Second email, SAME token, different message id.
        $result = $this->ingest($w, $this->inbound($w, ['external_id' => 'mid-second-' . Str::random(8) . '@vds.co.za']));
        $this->assertSame(EmailArchiveIngestor::RESULT_PARKED, $result);

        $second = Communication::withoutGlobalScopes()->where('agency_id', $w['agency']->id)
            ->where('id', '!=', $first->id)->first();
        $this->assertNotNull($second);

        // Silent: NO new pending suspense for the second email.
        $this->assertSame(0, CommunicationFilingSuspense::withoutGlobalScopes()
            ->where('communication_id', $second->id)->where('status', 'pending')->count());

        // Auto-filed: the second email produced its own Document link.
        $docLink = CommunicationLink::withoutGlobalScopes()
            ->where('communication_id', $second->id)
            ->where('linkable_type', (new Document())->getMorphClass())->first();
        $this->assertNotNull($docLink, 'second email auto-files a document');
    }

    public function test_known_attorney_single_active_deal_no_token_is_medium(): void
    {
        Storage::fake('local');
        $w = $this->makeWorld();

        $result = $this->ingest($w, $this->inbound($w, ['subject' => 'Transfer update (no token)']));
        $this->assertSame(EmailArchiveIngestor::RESULT_PARKED, $result);

        $suspense = CommunicationFilingSuspense::withoutGlobalScopes()->first();
        $this->assertSame(CommunicationFilingSuspense::CONF_MEDIUM, $suspense->confidence);
        $this->assertSame($w['deal']->id, (int) $suspense->suggested_deal_id);
        $this->assertSame(CommunicationLearnedRef::SIGNAL_SENDER_EMAIL, $suspense->matched_signal_type);
    }

    public function test_reassign_withdraws_old_docs_refiles_and_corrects_learned(): void
    {
        Storage::fake('local');
        $w = $this->makeWorld();
        // A SECOND deal for the same firm (so reassign has a target) — makeWorld's firm gains a 2nd deal.
        $w2Twin = DB::table('deals_v2')->insertGetId([
            'reference' => 'DR2-' . Str::random(5), 'deal_type' => 'bond', 'listing_agent_id' => $w['agent']->id,
            'purchase_price' => 2_000_000, 'commission_amount' => 100_000, 'commission_vat' => 15_000,
            'offer_date' => '2026-04-01', 'branch_id' => $w['deal']->branch_id, 'agency_id' => $w['agency']->id,
            'created_by_id' => $w['agent']->id, 'property_id' => $w['property']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $dealB = Deal::withoutEvents(fn () => Deal::withoutGlobalScopes()->create([
            'period' => '2026-04', 'deal_date' => '2026-04-01', 'property_value' => 2_000_000, 'total_commission' => 115_000,
            'reference' => 'REG-' . Str::random(5), 'deal_no' => (string) random_int(1000, 9999), 'deal_type' => 'bond',
            'seller_name' => 'Annelise vd Merwe', 'property_address' => '12 Marine Dr',
            'agency_id' => $w['agency']->id, 'branch_id' => $w['deal']->branch_id, 'property_id' => $w['property']->id, 'deal_v2_id' => $w2Twin,
            'attorney_provider_id' => $w['firm']->id, 'attorney_contact_id' => $w['attorney']->id,
        ]));

        // File to deal A first (verify).
        $this->ingest($w, $this->inbound($w));
        $comm = Communication::withoutGlobalScopes()->where('agency_id', $w['agency']->id)->first();
        $suspense = CommunicationFilingSuspense::withoutGlobalScopes()->where('communication_id', $comm->id)->first();
        app(CorrespondenceFilingService::class)->verify($suspense, $w['deal']->id, $w['agent']);

        $oldDoc = Document::withoutGlobalScopes()->where('source_type', 'inbound_email')->first();
        $this->assertSame($w['twinId'], (int) $oldDoc->deal_id);

        // Reassign to deal B.
        app(CorrespondenceFilingService::class)->reassign($comm, $dealB->id, $w['agent'], 'wrong deal');

        // Old document soft-deleted (no hard delete, no orphan).
        $this->assertSoftDeleted('documents', ['id' => $oldDoc->id]);
        // New document on deal B.
        $newDoc = Document::withoutGlobalScopes()->where('source_type', 'inbound_email')->where('deal_id', $w2Twin)->first();
        $this->assertNotNull($newDoc);
        // Learned ref corrected to deal B.
        $learned = CommunicationLearnedRef::withoutGlobalScopes()
            ->where('signal_value', 'cx-d' . $w['deal']->id)->first();
        $this->assertSame($dealB->id, (int) $learned->deal_id, 'learned pattern re-pointed to the corrected deal');
    }

    public function test_verify_to_deleted_deal_is_refused(): void
    {
        Storage::fake('local');
        $w = $this->makeWorld();
        $this->ingest($w, $this->inbound($w));
        $comm = Communication::withoutGlobalScopes()->where('agency_id', $w['agency']->id)->first();
        $suspense = CommunicationFilingSuspense::withoutGlobalScopes()->where('communication_id', $comm->id)->first();

        $w['deal']->delete(); // soft-delete the target

        $this->expectException(\DomainException::class);
        app(CorrespondenceFilingService::class)->verify($suspense, $w['deal']->id, $w['agent']);
    }
}
