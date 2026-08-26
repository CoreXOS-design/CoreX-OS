<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Deal;
use App\Models\DealV2\DealV2;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CX-109 (Johan, 2026-08-20) — the Unfiled Emails screen. Johan rejected the
 * in-deal-search direction (CX-108's original UI) and redirected: "unfiled
 * email arrives -> agent works through the unfiled pile -> picks the deal it
 * belongs to." This covers the list (unfiled only, newest first, searchable),
 * filing (attaches + leaves the list), and the suggestion flow (surfaces
 * related unfiled emails on file, never auto-files them).
 */
final class UnfiledEmailsTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;
    private User $agent;
    private Deal $deal;
    private int $dealV2Id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agencyId]);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'view_deals', 'agency_id' => $this->agencyId]);
        // Deal::visibleTo() (used by the deal picker + file()/fileBatch()) reads the
        // scope of 'deals.view' specifically (PermissionService::getDataScope) — a
        // plain 'view_deals' capability grant with no scope row resolves to NULL,
        // which visibleTo() reads as "see nothing" (whereRaw 1=0). 'own' matches the
        // deal_user pivot attach below.
        RolePermission::create(['role' => 'agent', 'permission_key' => 'deals.view', 'scope' => 'own', 'agency_id' => $this->agencyId]);
        Role::clearCache();
        PermissionService::clearCache();

        $this->agent = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent', 'is_active' => true,
        ]);

        $this->dealV2Id = (int) DB::table('deals_v2')->insertGetId([
            'reference' => 'DR2-' . Str::random(5), 'deal_type' => 'bond', 'listing_agent_id' => $this->agent->id,
            'purchase_price' => 1_500_000, 'commission_amount' => 75_000, 'commission_vat' => 11_250,
            'offer_date' => '2026-03-01', 'branch_id' => $this->branchId, 'agency_id' => $this->agencyId,
            'created_by_id' => $this->agent->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->deal = Deal::withoutEvents(fn () => Deal::withoutGlobalScopes()->create([
            'period' => '2026-03', 'deal_date' => '2026-03-01', 'property_value' => 1_500_000, 'total_commission' => 86_250,
            'reference' => 'REG-' . Str::random(5), 'deal_no' => random_int(1000, 9999), 'deal_type' => 'bond',
            'seller_name' => 'Test Seller', 'property_address' => '1 Test Rd',
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'deal_v2_id' => $this->dealV2Id,
        ]));
        // visibleTo() (used by the deal picker + file()/fileBatch()) scopes 'own' via the
        // deal_user pivot, not listing_agent_id — attach the test agent so this deal is
        // actually visible to them, matching how a real agent is on their own deal.
        $this->deal->agents()->attach($this->agent->id, ['side' => 'selling']);

        // CX-113 Phase A correction — the Unfiled Emails list now ALSO requires a deal-party
        // match (buyer/seller/supplier), on top of scope. Register comm()'s default
        // from_identifier as a deal-party contact so every existing test's fixture emails
        // keep passing that filter without individually opting in.
        $this->registerDealParty('conveyancer@bbb-attorneys.co.za');
    }

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        parent::tearDown();
    }

    /** Registers $email as a buyer/seller-role deal_contacts party on $this->deal. */
    private function registerDealParty(string $email): void
    {
        $contactId = (int) DB::table('contacts')->insertGetId([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'email' => $email,
            'first_name' => 'Test', 'last_name' => 'Party',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('deal_contacts')->insert([
            'deal_id' => $this->deal->id, 'contact_id' => $contactId, 'role' => 'seller',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function comm(array $over = []): Communication
    {
        return Communication::create(array_merge([
            'agency_id' => $this->agencyId,
            'channel' => Communication::CHANNEL_EMAIL,
            'direction' => Communication::DIRECTION_INBOUND,
            'external_id' => Str::random(14),
            'thread_key' => null,
            'from_identifier' => 'conveyancer@bbb-attorneys.co.za',
            'subject' => 'Transfer documents — 1 Test Rd',
            'body_text' => 'Body text',
            'occurred_at' => now(),
            'captured_at' => now(),
            'owner_user_id' => $this->agent->id,
            'has_attachments' => false,
        ], $over));
    }

    public function test_the_list_shows_only_unfiled_emails(): void
    {
        $unfiled = $this->comm(['subject' => 'Unfiled subject line']);
        $filed = $this->comm(['subject' => 'Already filed subject line']);
        CommunicationLink::create([
            'agency_id' => $this->agencyId, 'communication_id' => $filed->id,
            'linkable_type' => DealV2::class, 'linkable_id' => $this->dealV2Id,
            'link_method' => CommunicationLink::METHOD_MANUAL,
            'confirmed_by' => $this->agent->id, 'confirmed_at' => now(),
        ]);

        $resp = $this->actingAs($this->agent)->get(route('deals-dr2.unfiled-emails.index'))->assertOk();
        $resp->assertSee('Unfiled subject line');
        $resp->assertDontSee('Already filed subject line');
    }

    public function test_whatsapp_never_appears_on_the_unfiled_emails_screen(): void
    {
        $this->comm(['channel' => Communication::CHANNEL_WHATSAPP, 'subject' => 'Whatsapp on unfiled screen']);

        $resp = $this->actingAs($this->agent)->get(route('deals-dr2.unfiled-emails.index'))->assertOk();
        $resp->assertDontSee('Whatsapp on unfiled screen');
    }

    public function test_filing_an_email_attaches_it_and_removes_it_from_the_unfiled_list(): void
    {
        $comm = $this->comm(['subject' => 'File me subject line']);

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.unfiled-emails.file', $comm), ['deal_id' => $this->deal->id])
            ->assertCreated()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('communication_links', [
            'communication_id' => $comm->id,
            'linkable_type' => DealV2::class,
            'linkable_id' => $this->dealV2Id,
            'link_method' => CommunicationLink::METHOD_MANUAL,
            'confirmed_by' => $this->agent->id,
        ]);

        $resp = $this->actingAs($this->agent)->get(route('deals-dr2.unfiled-emails.index'))->assertOk();
        $resp->assertDontSee('File me subject line');
    }

    public function test_filing_surfaces_related_unfiled_emails_sharing_sender_or_subject_but_does_not_file_them(): void
    {
        $primary = $this->comm([
            'from_identifier' => 'linda@vdsatt.co.za',
            'subject' => 'REGISTRATION OF TRANSFER: 1 TEST RD',
        ]);
        $sameSender = $this->comm([
            'from_identifier' => 'Linda@VDSAtt.co.za', // case/whitespace differs — normalised match
            'subject' => 'A totally different subject',
        ]);
        $sameSubject = $this->comm([
            'from_identifier' => 'someone-else@example.com',
            'subject' => 'REGISTRATION OF TRANSFER: 1 TEST RD',
        ]);
        $unrelated = $this->comm([
            'from_identifier' => 'nobody@example.com',
            'subject' => 'Nothing to do with this deal',
        ]);

        $resp = $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.unfiled-emails.file', $primary), ['deal_id' => $this->deal->id])
            ->assertCreated();

        $suggestionIds = collect($resp->json('suggestions'))->pluck('id')->all();
        $this->assertContains($sameSender->id, $suggestionIds);
        $this->assertContains($sameSubject->id, $suggestionIds);
        $this->assertNotContains($unrelated->id, $suggestionIds);
        $this->assertNotContains($primary->id, $suggestionIds);

        // Suggested, not filed — only the primary email got an actual link.
        $this->assertDatabaseMissing('communication_links', ['communication_id' => $sameSender->id]);
        $this->assertDatabaseMissing('communication_links', ['communication_id' => $sameSubject->id]);
    }

    public function test_confirming_suggestions_via_file_batch_files_them_to_the_same_deal(): void
    {
        $primary = $this->comm(['from_identifier' => 'linda@vdsatt.co.za', 'subject' => 'REGISTRATION OF TRANSFER: 1 TEST RD']);
        $related = $this->comm(['from_identifier' => 'linda@vdsatt.co.za', 'subject' => 'Different subject, same sender']);

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.unfiled-emails.file', $primary), ['deal_id' => $this->deal->id])
            ->assertCreated();

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.unfiled-emails.file-batch'), [
                'deal_id' => $this->deal->id,
                'communication_ids' => [$related->id],
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('communication_links', [
            'communication_id' => $related->id,
            'linkable_type' => DealV2::class,
            'linkable_id' => $this->dealV2Id,
        ]);
    }

    public function test_deal_search_returns_only_deals_visible_to_the_agent(): void
    {
        // A deal this agent is NOT attached to — must not appear in their picker results.
        $otherDealV2Id = (int) DB::table('deals_v2')->insertGetId([
            'reference' => 'DR2-' . Str::random(5), 'deal_type' => 'cash', 'listing_agent_id' => $this->agent->id,
            'purchase_price' => 900_000, 'commission_amount' => 45_000, 'commission_vat' => 6_750,
            'offer_date' => '2026-03-01', 'branch_id' => $this->branchId, 'agency_id' => $this->agencyId,
            'created_by_id' => $this->agent->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Deal::withoutEvents(fn () => Deal::withoutGlobalScopes()->create([
            'period' => '2026-03', 'deal_date' => '2026-03-01', 'property_value' => 900_000, 'total_commission' => 51_750,
            'reference' => 'REG-' . Str::random(5), 'deal_no' => random_int(1000, 9999), 'deal_type' => 'cash',
            'seller_name' => 'Not My Deal Seller', 'property_address' => '2 Other Rd',
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'deal_v2_id' => $otherDealV2Id,
        ]));

        $results = $this->actingAs($this->agent)
            ->getJson(route('deals-dr2.unfiled-emails.deal-search') . '?q=Rd')
            ->assertOk()
            ->json();

        $labels = collect($results)->pluck('label')->implode(' | ');
        $this->assertStringContainsString('1 Test Rd', $labels);
        $this->assertStringNotContainsString('2 Other Rd', $labels);
    }

    /**
     * Johan, 2026-08-22: "DR2 no twin link to link comm to" — he picked a real search
     * result and filing refused. Root cause: dealSearch() never excluded deals with no
     * DR2 twin (deal_v2_id null — 74 of 154 real deals on staging), so the picker
     * offered results it could never file to. This is the search-side fix: such a deal
     * must never appear in the picker at all.
     */
    public function test_deal_search_excludes_a_deal_with_no_dr2_twin(): void
    {
        Deal::withoutEvents(fn () => Deal::withoutGlobalScopes()->create([
            'period' => '2026-03', 'deal_date' => '2026-03-01', 'property_value' => 900_000, 'total_commission' => 51_750,
            'reference' => 'REG-' . Str::random(5), 'deal_no' => random_int(1000, 9999), 'deal_type' => 'cash',
            'seller_name' => 'No Twin Seller', 'property_address' => '3 No Twin Rd',
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'deal_v2_id' => null,
        ]))->agents()->attach($this->agent->id, ['side' => 'selling']);

        $results = $this->actingAs($this->agent)
            ->getJson(route('deals-dr2.unfiled-emails.deal-search') . '?q=No Twin')
            ->assertOk()
            ->json();

        $this->assertEmpty($results);
    }

    /** Belt-and-suspenders: even a forged/stale deal_id for a no-twin deal gets a
     *  plain-language refusal, never the internal "DR2 twin"/deal_v2_id wording. */
    public function test_filing_to_a_deal_with_no_dr2_twin_fails_with_a_plain_language_message(): void
    {
        $noTwinDeal = Deal::withoutEvents(fn () => Deal::withoutGlobalScopes()->create([
            'period' => '2026-03', 'deal_date' => '2026-03-01', 'property_value' => 900_000, 'total_commission' => 51_750,
            'reference' => 'REG-' . Str::random(5), 'deal_no' => random_int(1000, 9999), 'deal_type' => 'cash',
            'seller_name' => 'No Twin Seller', 'property_address' => '3 No Twin Rd',
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'deal_v2_id' => null,
        ]));
        $noTwinDeal->agents()->attach($this->agent->id, ['side' => 'selling']);
        $comm = $this->comm(['subject' => 'File to a no-twin deal']);

        $resp = $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.unfiled-emails.file', $comm), ['deal_id' => $noTwinDeal->id])
            ->assertStatus(422);

        $message = $resp->json('message');
        $this->assertStringNotContainsString('twin', strtolower($message));
        $this->assertStringNotContainsString('deal_v2_id', $message);
        $this->assertStringContainsString('Deal Register', $message);
    }

    public function test_deal_search_matches_by_attorney_name_not_just_address_or_deal_no(): void
    {
        // CX-113 Phase C — "not just deal number": the row-level deal picker must also
        // find a deal by its attorney, the same way Phase B's email search does.
        DB::table('deals')->where('id', $this->deal->id)->update(['attorney_name' => 'Zanele Dlamini']);

        $results = $this->actingAs($this->agent)
            ->getJson(route('deals-dr2.unfiled-emails.deal-search') . '?q=Dlamini')
            ->assertOk()
            ->json();

        $labels = collect($results)->pluck('label')->implode(' | ');
        $this->assertStringContainsString('1 Test Rd', $labels);
    }

    // ── CX-113 Phase E — signal-scored, status-aware deal ranking ────────────

    /** Second "Santana"-style deal sharing an address term with $this->deal, for ranking tests. */
    private function makeCandidateDeal(array $over = []): Deal
    {
        $dealV2Id = (int) DB::table('deals_v2')->insertGetId(array_merge([
            'reference' => 'DR2-' . Str::random(5), 'deal_type' => 'cash', 'listing_agent_id' => $this->agent->id,
            'purchase_price' => 900_000, 'commission_amount' => 45_000, 'commission_vat' => 6_750,
            'offer_date' => '2026-03-01', 'branch_id' => $this->branchId, 'agency_id' => $this->agencyId,
            'created_by_id' => $this->agent->id, 'created_at' => now(), 'updated_at' => now(),
        ], []));
        $deal = Deal::withoutEvents(fn () => Deal::withoutGlobalScopes()->create(array_merge([
            'period' => '2026-03', 'deal_date' => '2026-03-01', 'property_value' => 900_000, 'total_commission' => 51_750,
            'reference' => 'REG-' . Str::random(5), 'deal_no' => random_int(1000, 9999), 'deal_type' => 'cash',
            'seller_name' => 'Candidate Seller', 'property_address' => 'Unit 9 Santana Close',
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'deal_v2_id' => $dealV2Id,
        ], $over)));
        // visibleTo() (deal-search's own gate) scopes 'own' via the deal_user pivot —
        // every candidate deal in these ranking tests must be attached the same way
        // setUp() attaches $this->deal, or it simply never appears in results at all.
        $deal->agents()->attach($this->agent->id, ['side' => 'selling']);

        return $deal;
    }

    /** Registers $email as the attorney on $deal via deals.attorney_provider_id (real join path). */
    private function registerAttorneyOnDeal(Deal $deal, string $email): void
    {
        $providerId = (int) DB::table('agency_service_providers')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Test Firm ' . Str::random(4), 'specialty' => 'transfer_attorney',
            'is_active' => 1, 'created_by_id' => $this->agent->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('agency_service_provider_contacts')->insert([
            'agency_id' => $this->agencyId, 'service_provider_id' => $providerId,
            'attorney_name' => 'Test Attorney', 'email' => $email,
            'is_active' => 1, 'created_by_id' => $this->agent->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('deals')->where('id', $deal->id)->update(['attorney_provider_id' => $providerId]);
    }

    public function test_deal_search_ranks_an_email_address_match_above_a_plain_text_match(): void
    {
        DB::table('deals')->where('id', $this->deal->id)->update(['property_address' => 'Unit 1 Santana Road']);
        $plain = $this->makeCandidateDeal(['property_address' => 'Unit 9 Santana Close']);
        $this->registerAttorneyOnDeal($plain, 'attorney-on-plain@example.com'); // unrelated to this comm

        // Deliberately NOT the shared default from_identifier — setUp() already
        // registers that one as a deal party, which would make it appear on 2 deals
        // and dilute the very "unique party" signal this test is isolating.
        $matched = $this->makeCandidateDeal(['property_address' => '5 Santana Ave']);
        $this->registerAttorneyOnDeal($matched, 'sole-attorney-for-this-test@example.com');

        $comm = $this->comm(['subject' => 'Nothing distinctive here', 'from_identifier' => 'sole-attorney-for-this-test@example.com']);

        $results = $this->actingAs($this->agent)
            ->getJson(route('deals-dr2.unfiled-emails.deal-search') . '?q=Santana&communication_id=' . $comm->id)
            ->assertOk()
            ->json();

        $this->assertSame($matched->id, $results[0]['id']); // email match sorts first
        $this->assertSame('email', $results[0]['signals'][0]['type']);
        $this->assertStringContainsString('attorney', $results[0]['signals'][0]['label']);
    }

    public function test_deal_search_weights_a_frequent_partys_email_far_below_a_unique_one(): void
    {
        // A "busy attorney" on 3 deals — Johan's own example ("koos from ooba does all
        // our bonds"). Matching them should barely move the ranking.
        $busy = 'busy-attorney@example.com';
        $busyDeal = $this->makeCandidateDeal(['property_address' => '1 Busy Rd']);
        $this->registerAttorneyOnDeal($busyDeal, $busy);
        $this->registerAttorneyOnDeal($this->makeCandidateDeal(['property_address' => '2 Busy Rd']), $busy);
        $this->registerAttorneyOnDeal($this->makeCandidateDeal(['property_address' => '3 Busy Rd']), $busy);

        // A unique party on the deal we actually want to find, sharing the same
        // search term as the busy deals so both are real candidates.
        $uniqueDeal = $this->makeCandidateDeal(['property_address' => '4 Busy Rd']);
        $this->registerAttorneyOnDeal($uniqueDeal, 'once-only@example.com');

        $comm = $this->comm(['subject' => 'No distinctive subject', 'from_identifier' => $busy]);

        $results = $this->actingAs($this->agent)
            ->getJson(route('deals-dr2.unfiled-emails.deal-search') . '?q=Busy&communication_id=' . $comm->id)
            ->assertOk()
            ->json();

        $busyResult = collect($results)->firstWhere('id', $busyDeal->id);
        $this->assertNotNull($busyResult);
        $this->assertStringContainsString('3 deals', $busyResult['signals'][0]['label']);
        $this->assertLessThan(20, $busyResult['signals'][0]['score']); // barely moves the ranking
    }

    /** Registers $email as a buyer/seller-role deal_contacts party on the given deal. */
    private function registerDealPartyOn(Deal $deal, string $email, string $role, string $firstName = 'Test', string $lastName = 'Party'): void
    {
        $contactId = (int) DB::table('contacts')->insertGetId([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'email' => $email,
            'first_name' => $firstName, 'last_name' => $lastName,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('deal_contacts')->insert([
            'deal_id' => $deal->id, 'contact_id' => $contactId, 'role' => $role,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * CX-113 Phase H (Johan, 2026-08-22, made twice): "just by having transfer
     * attorney email plus seller email plus buyer email... should already give us a
     * hell of a lot of positive linking... a high-frequency attorney alone barely
     * moves it; that same attorney PLUS the seller pins it." The SAME attorney as the
     * "barely moves the ranking" test above — still on 3 deals — but this time also
     * co-occurring with the seller on the SAME email. Corroboration must override the
     * frequency dilution entirely, not just partially.
     */
    public function test_deal_search_treats_two_matched_parties_as_near_conclusive_regardless_of_frequency(): void
    {
        $busyAttorney = 'busy-attorney-corrob@example.com';
        $target = $this->makeCandidateDeal(['property_address' => '9 Corrob Rd']);
        $this->registerAttorneyOnDeal($target, $busyAttorney);
        $this->registerAttorneyOnDeal($this->makeCandidateDeal(['property_address' => '10 Corrob Rd']), $busyAttorney);
        $this->registerAttorneyOnDeal($this->makeCandidateDeal(['property_address' => '11 Corrob Rd']), $busyAttorney);

        $sellerEmail = 'corrob-seller@example.com';
        $this->registerDealPartyOn($target, $sellerEmail, 'seller', 'Corrob', 'Seller');

        $comm = $this->comm([
            'subject' => 'No distinctive subject at all',
            'from_identifier' => $busyAttorney,
            'participant_identifiers' => [$busyAttorney, $sellerEmail],
        ]);

        $results = $this->actingAs($this->agent)
            ->getJson(route('deals-dr2.unfiled-emails.deal-search') . '?q=Corrob&communication_id=' . $comm->id)
            ->assertOk()
            ->json();

        $this->assertSame($target->id, $results[0]['id']); // ranks first despite the attorney being on 3 deals
        $corrob = collect($results[0]['signals'])->firstWhere('type', 'corroboration');
        $this->assertNotNull($corrob);
        $this->assertStringContainsString('2 parties', $corrob['label']);
        $this->assertStringContainsString('seller', $corrob['label']);
        $this->assertStringContainsString('attorney', $corrob['label']);
        $this->assertGreaterThanOrEqual(200, $corrob['score']);
        // Strictly greater than the single-party near-conclusive ceiling (100) —
        // corroboration must outrank even a UNIQUE single-party match, not just a diluted one.
        $this->assertGreaterThan(100, $corrob['score']);
    }

    public function test_deal_search_treats_three_matched_parties_as_certain(): void
    {
        $target = $this->makeCandidateDeal(['property_address' => '5 Certain Rd']);
        $attorneyEmail = 'certain-attorney@example.com';
        $this->registerAttorneyOnDeal($target, $attorneyEmail);
        $sellerEmail = 'certain-seller@example.com';
        $this->registerDealPartyOn($target, $sellerEmail, 'seller', 'Certain', 'Seller');
        $buyerEmail = 'certain-buyer@example.com';
        $this->registerDealPartyOn($target, $buyerEmail, 'buyer', 'Certain', 'Buyer');

        $comm = $this->comm([
            'subject' => 'No distinctive subject',
            'from_identifier' => $attorneyEmail,
            'participant_identifiers' => [$attorneyEmail, $sellerEmail, $buyerEmail],
        ]);

        $results = $this->actingAs($this->agent)
            ->getJson(route('deals-dr2.unfiled-emails.deal-search') . '?q=Certain&communication_id=' . $comm->id)
            ->assertOk()
            ->json();

        $corrob = collect($results[0]['signals'])->firstWhere('type', 'corroboration');
        $this->assertNotNull($corrob);
        $this->assertStringContainsString('3 parties', $corrob['label']);
        $this->assertStringContainsString('attorney', $corrob['label']);
        $this->assertStringContainsString('seller', $corrob['label']);
        $this->assertStringContainsString('buyer', $corrob['label']);
        $this->assertSame(300, $corrob['score']); // 3+ parties scores strictly higher than 2
    }

    public function test_deal_search_badges_a_party_surname_found_in_the_subject(): void
    {
        DB::table('deals')->where('id', $this->deal->id)->update([
            'property_address' => '1 Test Rd', 'seller_name' => 'Rabia Amra',
        ]);
        $comm = $this->comm(['subject' => 'Re: PROGRESS REPORT : SETION 4 SANTANA (AMRA / GOVENDER)']);

        $results = $this->actingAs($this->agent)
            ->getJson(route('deals-dr2.unfiled-emails.deal-search') . '?q=Test&communication_id=' . $comm->id)
            ->assertOk()
            ->json();

        $mine = collect($results)->firstWhere('id', $this->deal->id);
        $this->assertNotNull($mine);
        $subjectSignal = collect($mine['signals'])->firstWhere('type', 'subject');
        $this->assertNotNull($subjectSignal);
        $this->assertStringContainsString('Amra', $subjectSignal['label']);
        $this->assertStringContainsString('seller', $subjectSignal['label']);
    }

    public function test_deal_search_ranks_a_proceeding_deal_above_an_equally_signalled_declined_one(): void
    {
        $proceeding = $this->makeCandidateDeal(['property_address' => '1 Status Rd', 'accepted_status' => 'R']);
        $declined = $this->makeCandidateDeal(['property_address' => '2 Status Rd', 'accepted_status' => 'D']);
        $comm = $this->comm(['subject' => 'Neither deal has a content signal here']);

        $results = $this->actingAs($this->agent)
            ->getJson(route('deals-dr2.unfiled-emails.deal-search') . '?q=Status&communication_id=' . $comm->id)
            ->assertOk()
            ->json();

        $ids = collect($results)->pluck('id')->all();
        $this->assertLessThan(array_search($declined->id, $ids), array_search($proceeding->id, $ids));
        $this->assertSame('Declined', collect($results)->firstWhere('id', $declined->id)['status']);
        $this->assertSame('Registered', collect($results)->firstWhere('id', $proceeding->id)['status']);
    }

    public function test_deal_search_response_includes_property_address_status_and_party_names(): void
    {
        DB::table('deals')->where('id', $this->deal->id)->update([
            'property_address' => '1 Test Rd', 'seller_name' => 'A Seller', 'buyer_name' => 'A Buyer',
            'attorney_name' => 'An Attorney', 'accepted_status' => 'G',
        ]);

        $results = $this->actingAs($this->agent)
            ->getJson(route('deals-dr2.unfiled-emails.deal-search') . '?q=Test')
            ->assertOk()
            ->json();

        $mine = collect($results)->firstWhere('id', $this->deal->id);
        $this->assertSame('1 Test Rd', $mine['property_address']);
        $this->assertSame('Granted', $mine['status']);
        $this->assertSame('A Seller', $mine['seller_name']);
        $this->assertSame('A Buyer', $mine['buyer_name']);
        $this->assertSame('An Attorney', $mine['attorney_name']);
    }

    public function test_the_search_box_filters_by_subject_or_sender(): void
    {
        $this->registerDealParty('findme@example.com');
        $this->comm(['subject' => 'Findable subject XYZ']);
        $this->comm(['from_identifier' => 'findme@example.com', 'subject' => 'Different subject']);
        $this->comm(['subject' => 'Not matching anything']);

        $resp = $this->actingAs($this->agent)
            ->get(route('deals-dr2.unfiled-emails.index') . '?q=Findable')
            ->assertOk();
        $resp->assertSee('Findable subject XYZ');
        $resp->assertDontSee('Not matching anything');

        $resp2 = $this->actingAs($this->agent)
            ->get(route('deals-dr2.unfiled-emails.index') . '?q=findme')
            ->assertOk();
        $resp2->assertSee('findme@example.com');
    }

    // ── CX-113 Phase B — broadened search, filed-state filter ────────────────

    public function test_search_matches_the_deals_property_address_even_though_the_email_text_never_mentions_it(): void
    {
        $this->comm(['subject' => 'Weekly update, no address in it']);

        $resp = $this->actingAs($this->agent)
            ->get(route('deals-dr2.unfiled-emails.index') . '?q=' . urlencode('1 Test Rd'))
            ->assertOk();
        $resp->assertSee('Weekly update, no address in it');
    }

    public function test_search_matches_the_deals_seller_name(): void
    {
        DB::table('deals')->where('id', $this->deal->id)->update(['seller_name' => 'Zanele Dlamini']);
        $this->comm(['subject' => 'No name mentioned in this one']);

        $resp = $this->actingAs($this->agent)
            ->get(route('deals-dr2.unfiled-emails.index') . '?q=Dlamini')
            ->assertOk();
        $resp->assertSee('No name mentioned in this one');
    }

    public function test_search_matches_body_text_not_just_subject(): void
    {
        $this->comm(['subject' => 'Subject with nothing findable', 'body_text' => 'The FICA copy is attached for your records.']);

        $resp = $this->actingAs($this->agent)
            ->get(route('deals-dr2.unfiled-emails.index') . '?q=FICA')
            ->assertOk();
        $resp->assertSee('Subject with nothing findable');
    }

    public function test_search_matches_a_cced_recipient_not_just_the_sender(): void
    {
        $this->comm([
            'subject' => 'CCed recipient search target',
            'participant_identifiers' => ['conveyancer@bbb-attorneys.co.za', 'ccrecipient@example.com'],
        ]);

        $resp = $this->actingAs($this->agent)
            ->get(route('deals-dr2.unfiled-emails.index') . '?q=ccrecipient')
            ->assertOk();
        $resp->assertSee('CCed recipient search target');
    }

    public function test_filed_state_shows_only_filed_emails_with_the_deal_and_who_filed_it(): void
    {
        $filed = $this->comm(['subject' => 'Already filed subject']);
        $unfiled = $this->comm(['subject' => 'Still unfiled subject']);
        CommunicationLink::create([
            'agency_id' => $this->agencyId, 'communication_id' => $filed->id,
            'linkable_type' => DealV2::class, 'linkable_id' => $this->dealV2Id,
            'link_method' => CommunicationLink::METHOD_MANUAL,
            'confirmed_by' => $this->agent->id, 'confirmed_at' => now(),
        ]);

        $resp = $this->actingAs($this->agent)
            ->get(route('deals-dr2.unfiled-emails.index') . '?state=filed')
            ->assertOk();
        $resp->assertSee('Already filed subject');
        $resp->assertDontSee('Still unfiled subject');
        $resp->assertSee($this->agent->name); // who filed it
        $resp->assertSee((string) $this->deal->deal_no); // which deal
    }

    public function test_all_state_shows_both_filed_and_deal_party_matched_unfiled_emails(): void
    {
        $filed = $this->comm(['subject' => 'All-state filed subject']);
        $unfiled = $this->comm(['subject' => 'All-state unfiled subject']);
        CommunicationLink::create([
            'agency_id' => $this->agencyId, 'communication_id' => $filed->id,
            'linkable_type' => DealV2::class, 'linkable_id' => $this->dealV2Id,
            'link_method' => CommunicationLink::METHOD_MANUAL,
            'confirmed_by' => $this->agent->id, 'confirmed_at' => now(),
        ]);

        $resp = $this->actingAs($this->agent)
            ->get(route('deals-dr2.unfiled-emails.index') . '?state=all')
            ->assertOk();
        $resp->assertSee('All-state filed subject');
        $resp->assertSee('All-state unfiled subject');
    }

    public function test_move_action_on_a_filed_row_reuses_the_file_endpoint_with_move_true(): void
    {
        $comm = $this->comm(['subject' => 'Move me from the filed list']);
        CommunicationLink::create([
            'agency_id' => $this->agencyId, 'communication_id' => $comm->id,
            'linkable_type' => DealV2::class, 'linkable_id' => $this->dealV2Id,
            'link_method' => CommunicationLink::METHOD_MANUAL,
            'confirmed_by' => $this->agent->id, 'confirmed_at' => now(),
        ]);

        $otherDealV2Id = (int) DB::table('deals_v2')->insertGetId([
            'reference' => 'DR2-' . Str::random(5), 'deal_type' => 'cash', 'listing_agent_id' => $this->agent->id,
            'purchase_price' => 900_000, 'commission_amount' => 45_000, 'commission_vat' => 6_750,
            'offer_date' => '2026-03-01', 'branch_id' => $this->branchId, 'agency_id' => $this->agencyId,
            'created_by_id' => $this->agent->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherDeal = Deal::withoutEvents(fn () => Deal::withoutGlobalScopes()->create([
            'period' => '2026-03', 'deal_date' => '2026-03-01', 'property_value' => 900_000, 'total_commission' => 51_750,
            'reference' => 'REG-' . Str::random(5), 'deal_no' => random_int(1000, 9999), 'deal_type' => 'cash',
            'seller_name' => 'Move Target Seller', 'property_address' => '4 Move Target Rd',
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'deal_v2_id' => $otherDealV2Id,
        ]));
        $otherDeal->agents()->attach($this->agent->id, ['side' => 'selling']);

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.unfiled-emails.file', $comm), ['deal_id' => $otherDeal->id, 'move' => true])
            ->assertCreated()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('communication_links', [
            'communication_id' => $comm->id, 'linkable_id' => $otherDealV2Id, 'deleted_at' => null,
        ]);
        $this->assertDatabaseMissing('communication_links', [
            'communication_id' => $comm->id, 'linkable_id' => $this->dealV2Id, 'deleted_at' => null,
        ]);
    }

    // ── CX-113 Phase A — scope, agent picker, file-once ──────────────────────

    public function test_own_scope_hides_a_colleagues_email_the_agent_was_not_a_party_to(): void
    {
        $colleague = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent', 'is_active' => true,
        ]);
        $this->comm(['owner_user_id' => $colleague->id, 'subject' => 'Colleagues own email, not mine']);
        $mine = $this->comm(['subject' => 'My own email']);

        $resp = $this->actingAs($this->agent)->get(route('deals-dr2.unfiled-emails.index'))->assertOk();
        $resp->assertSee('My own email');
        $resp->assertDontSee('Colleagues own email, not mine');
    }

    public function test_own_scope_shows_an_email_the_agent_was_only_cced_on_even_if_a_colleague_owns_it(): void
    {
        // "HFC has no shared mailboxes — every person sees only what was actually sent to
        // or from them" — participant_identifiers match must work even when a COLLEAGUE's
        // mailbox happened to ingest the row first (owner_user_id = colleague).
        DB::table('communication_mailboxes')->insert([
            'agency_id' => $this->agencyId, 'user_id' => $this->agent->id,
            'username' => $this->agent->email, 'email_address' => $this->agent->email,
            'imap_host' => 'imap.example.test',
            'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $colleague = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent', 'is_active' => true,
        ]);
        $this->comm([
            'owner_user_id' => $colleague->id,
            'subject' => 'CCed to me, owned by a colleague',
            'participant_identifiers' => ['conveyancer@bbb-attorneys.co.za', strtolower($this->agent->email)],
        ]);

        $resp = $this->actingAs($this->agent)->get(route('deals-dr2.unfiled-emails.index'))->assertOk();
        $resp->assertSee('CCed to me, owned by a colleague');
    }

    public function test_branch_manager_default_scope_sees_the_branch_but_admin_sees_the_agency(): void
    {
        Role::create(['name' => 'branch_manager', 'label' => 'Branch Manager', 'agency_id' => $this->agencyId]);
        RolePermission::create(['role' => 'branch_manager', 'permission_key' => 'view_deals', 'agency_id' => $this->agencyId]);
        RolePermission::create(['role' => 'branch_manager', 'permission_key' => 'dr2_unfiled_emails.view', 'scope' => 'branch', 'agency_id' => $this->agencyId]);
        Role::create(['name' => 'admin', 'label' => 'Admin', 'agency_id' => $this->agencyId]);
        RolePermission::create(['role' => 'admin', 'permission_key' => 'view_deals', 'agency_id' => $this->agencyId]);
        RolePermission::create(['role' => 'admin', 'permission_key' => 'dr2_unfiled_emails.view', 'scope' => 'all', 'agency_id' => $this->agencyId]);
        Role::clearCache();
        PermissionService::clearCache();

        $bm = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'branch_manager', 'is_active' => true,
        ]);
        $admin = User::factory()->create(['agency_id' => $this->agencyId, 'role' => 'admin', 'is_active' => true]);

        $otherBranchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Other Branch', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherBranchAgent = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $otherBranchId, 'role' => 'agent', 'is_active' => true,
        ]);

        $this->comm(['subject' => 'My branch email']); // owner = $this->agent, same branch as BM
        $this->comm(['owner_user_id' => $otherBranchAgent->id, 'subject' => 'Other branch email']);

        // Branch manager, Role-Manager-granted scope 'branch' — sees the same-branch
        // agent's email, never the other branch's.
        $bmResp = $this->actingAs($bm)->get(route('deals-dr2.unfiled-emails.index'))->assertOk();
        $bmResp->assertSee('My branch email');
        $bmResp->assertDontSee('Other branch email');

        // Admin, Role-Manager-granted scope 'all' — sees both.
        $adminResp = $this->actingAs($admin)->get(route('deals-dr2.unfiled-emails.index'))->assertOk();
        $adminResp->assertSee('My branch email');
        $adminResp->assertSee('Other branch email');
    }

    public function test_scope_toggle_never_renders_an_option_past_the_role_ceiling(): void
    {
        // Plain agent role: ceiling is 'own'. Branch/Company pills must not render at all —
        // not merely be clamped server-side if clicked.
        $resp = $this->actingAs($this->agent)->get(route('deals-dr2.unfiled-emails.index'))->assertOk();
        $resp->assertDontSee('scope=branch', false);
        $resp->assertDontSee('scope=all', false);
    }

    public function test_a_forged_scope_request_past_the_ceiling_is_clamped_server_side(): void
    {
        // Same agent, no Branch/Company grant — even asking for ?scope=all directly must
        // NOT widen visibility. Server-side clamp is the real gate; the missing pill above
        // is only the UI half.
        $colleague = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent', 'is_active' => true,
        ]);
        $this->comm(['owner_user_id' => $colleague->id, 'subject' => 'Not visible even with a forged scope param']);

        $resp = $this->actingAs($this->agent)
            ->get(route('deals-dr2.unfiled-emails.index') . '?scope=all')
            ->assertOk();
        $resp->assertDontSee('Not visible even with a forged scope param');
    }

    public function test_agent_picker_shows_only_the_picked_agents_own_emails(): void
    {
        Role::create(['name' => 'admin', 'label' => 'Admin', 'agency_id' => $this->agencyId]);
        RolePermission::create(['role' => 'admin', 'permission_key' => 'view_deals', 'agency_id' => $this->agencyId]);
        RolePermission::create(['role' => 'admin', 'permission_key' => 'dr2_unfiled_emails.view', 'scope' => 'all', 'agency_id' => $this->agencyId]);
        Role::clearCache();
        PermissionService::clearCache();
        $admin = User::factory()->create(['agency_id' => $this->agencyId, 'role' => 'admin', 'is_active' => true]);

        $colleague = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent', 'is_active' => true,
        ]);
        $this->comm(['subject' => 'Belongs to the original agent']);
        $this->comm(['owner_user_id' => $colleague->id, 'subject' => 'Belongs to the colleague']);

        $resp = $this->actingAs($admin)
            ->get(route('deals-dr2.unfiled-emails.index') . '?agent_id=' . $colleague->id)
            ->assertOk();
        $resp->assertSee('Belongs to the colleague');
        $resp->assertDontSee('Belongs to the original agent');
    }

    public function test_a_forged_agent_id_outside_the_scope_ceiling_is_ignored_not_honoured(): void
    {
        // Branch manager, ceiling 'branch'. An agent_id belonging to someone OUTSIDE the
        // BM's branch is not in the picker's own candidate list — the filter is ignored
        // rather than honoured, so it can never be used to peek at another branch.
        Role::create(['name' => 'branch_manager', 'label' => 'Branch Manager', 'agency_id' => $this->agencyId]);
        RolePermission::create(['role' => 'branch_manager', 'permission_key' => 'view_deals', 'agency_id' => $this->agencyId]);
        RolePermission::create(['role' => 'branch_manager', 'permission_key' => 'dr2_unfiled_emails.view', 'scope' => 'branch', 'agency_id' => $this->agencyId]);
        Role::clearCache();
        PermissionService::clearCache();
        $bm = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'branch_manager', 'is_active' => true,
        ]);

        $otherBranchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Other Branch', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherBranchAgent = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $otherBranchId, 'role' => 'agent', 'is_active' => true,
        ]);
        $this->comm(['owner_user_id' => $otherBranchAgent->id, 'subject' => 'Other branch agent email, forged filter target']);
        $this->comm(['subject' => 'My branch email, falls back here']);

        $resp = $this->actingAs($bm)
            ->get(route('deals-dr2.unfiled-emails.index') . '?agent_id=' . $otherBranchAgent->id)
            ->assertOk();
        $resp->assertDontSee('Other branch agent email, forged filter target');
        $resp->assertSee('My branch email, falls back here'); // filter ignored, falls back to full branch scope
    }

    // ── CX-113 Phase A correction (Johan, 2026-08-21) — deal-party filter ────────────

    public function test_an_email_with_no_connection_to_any_deal_is_excluded_even_though_the_user_could_otherwise_see_it(): void
    {
        // Scope-visible (owned by the test agent), but the sender is nobody's deal
        // party anywhere — must not appear. This is the corrected premise itself:
        // scope alone is no longer sufficient.
        $this->comm(['from_identifier' => 'random-newsletter@nowhere.example.com', 'subject' => 'No deal connection at all']);

        $resp = $this->actingAs($this->agent)->get(route('deals-dr2.unfiled-emails.index'))->assertOk();
        $resp->assertDontSee('No deal connection at all');
    }

    public function test_deal_party_filter_includes_an_attorney_via_the_deal_provider_column(): void
    {
        // Exercises the SUPPLIER path via deals.attorney_provider_id ->
        // agency_service_provider_contacts.email — a genuinely different code path
        // from the buyer/seller deal_contacts shortcut the default fixture uses.
        $providerId = (int) DB::table('agency_service_providers')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Test Attorneys Inc', 'specialty' => 'transfer_attorney',
            'is_active' => 1, 'created_by_id' => $this->agent->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('agency_service_provider_contacts')->insert([
            'agency_id' => $this->agencyId, 'service_provider_id' => $providerId,
            'attorney_name' => 'Test Attorney', 'email' => 'attorney-person@testattorneys.co.za',
            'is_active' => 1, 'created_by_id' => $this->agent->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('deals')->where('id', $this->deal->id)->update(['attorney_provider_id' => $providerId]);

        $this->comm(['from_identifier' => 'attorney-person@testattorneys.co.za', 'subject' => 'Attorney provider column email']);

        $resp = $this->actingAs($this->agent)->get(route('deals-dr2.unfiled-emails.index'))->assertOk();
        $resp->assertSee('Attorney provider column email');
    }

    public function test_deal_party_filter_includes_a_coc_supplier_via_a_deal_step_work_order(): void
    {
        // Exercises the deal_step_work_orders path (COC / electrician / entomologist /
        // etc. — the agency-configured service_type list, not a fixed taxonomy).
        $stepInstanceId = (int) DB::table('deal_step_instances')->insertGetId([
            'deal_id' => $this->dealV2Id, 'dr1_deal_id' => $this->deal->id, 'agency_id' => $this->agencyId,
            'name' => 'Test Step', 'status' => 'not_started', 'trigger_type' => 'manual',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('deal_step_work_orders')->insert([
            'deal_step_instance_id' => $stepInstanceId, 'dr1_deal_id' => $this->deal->id, 'agency_id' => $this->agencyId,
            'service_type' => 'Electrician', 'responsible_party' => 'supplier',
            'recipient_email' => 'electrician@testcoc.co.za', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->comm(['from_identifier' => 'electrician@testcoc.co.za', 'subject' => 'COC work order supplier email']);

        $resp = $this->actingAs($this->agent)->get(route('deals-dr2.unfiled-emails.index'))->assertOk();
        $resp->assertSee('COC work order supplier email');
    }

    public function test_filing_a_second_time_to_a_different_deal_is_refused_not_silently_duplicated(): void
    {
        $comm = $this->comm(['subject' => 'Race condition target email']);

        $otherDealV2Id = (int) DB::table('deals_v2')->insertGetId([
            'reference' => 'DR2-' . Str::random(5), 'deal_type' => 'cash', 'listing_agent_id' => $this->agent->id,
            'purchase_price' => 900_000, 'commission_amount' => 45_000, 'commission_vat' => 6_750,
            'offer_date' => '2026-03-01', 'branch_id' => $this->branchId, 'agency_id' => $this->agencyId,
            'created_by_id' => $this->agent->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherDeal = Deal::withoutEvents(fn () => Deal::withoutGlobalScopes()->create([
            'period' => '2026-03', 'deal_date' => '2026-03-01', 'property_value' => 900_000, 'total_commission' => 51_750,
            'reference' => 'REG-' . Str::random(5), 'deal_no' => random_int(1000, 9999), 'deal_type' => 'cash',
            'seller_name' => 'Second Filer Seller', 'property_address' => '2 Second Rd',
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'deal_v2_id' => $otherDealV2Id,
        ]));
        $otherDeal->agents()->attach($this->agent->id, ['side' => 'selling']);

        // First filer wins.
        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.unfiled-emails.file', $comm), ['deal_id' => $this->deal->id])
            ->assertCreated();

        // Second filer, different deal, same email — refused, not a silent second link.
        $second = $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.unfiled-emails.file', $comm), ['deal_id' => $otherDeal->id])
            ->assertStatus(409)
            ->json();
        $this->assertTrue($second['already_filed']);
        $this->assertStringContainsString('1 Test Rd', $second['message']);

        $this->assertDatabaseHas('communication_links', [
            'communication_id' => $comm->id, 'linkable_id' => $this->dealV2Id, 'deleted_at' => null,
        ]);
        $this->assertDatabaseMissing('communication_links', [
            'communication_id' => $comm->id, 'linkable_id' => $otherDealV2Id, 'deleted_at' => null,
        ]);
    }

    public function test_move_true_releases_the_old_link_and_files_to_the_new_deal(): void
    {
        $comm = $this->comm(['subject' => 'Move me to another deal']);

        $otherDealV2Id = (int) DB::table('deals_v2')->insertGetId([
            'reference' => 'DR2-' . Str::random(5), 'deal_type' => 'cash', 'listing_agent_id' => $this->agent->id,
            'purchase_price' => 900_000, 'commission_amount' => 45_000, 'commission_vat' => 6_750,
            'offer_date' => '2026-03-01', 'branch_id' => $this->branchId, 'agency_id' => $this->agencyId,
            'created_by_id' => $this->agent->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherDeal = Deal::withoutEvents(fn () => Deal::withoutGlobalScopes()->create([
            'period' => '2026-03', 'deal_date' => '2026-03-01', 'property_value' => 900_000, 'total_commission' => 51_750,
            'reference' => 'REG-' . Str::random(5), 'deal_no' => random_int(1000, 9999), 'deal_type' => 'cash',
            'seller_name' => 'Move Target Seller', 'property_address' => '3 Move Rd',
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'deal_v2_id' => $otherDealV2Id,
        ]));
        $otherDeal->agents()->attach($this->agent->id, ['side' => 'selling']);

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.unfiled-emails.file', $comm), ['deal_id' => $this->deal->id])
            ->assertCreated();

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.unfiled-emails.file', $comm), ['deal_id' => $otherDeal->id, 'move' => true])
            ->assertCreated()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('communication_links', [
            'communication_id' => $comm->id, 'linkable_id' => $this->dealV2Id, 'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('communication_links', [
            'communication_id' => $comm->id, 'linkable_id' => $otherDealV2Id, 'deleted_at' => null,
        ]);
    }

    // ── CX-113 Phase D — auto-suggest from filing history ────────────────────

    public function test_suggest_recommends_the_deal_a_previous_email_from_the_same_sender_was_filed_to(): void
    {
        $first = $this->comm(['subject' => 'First one filed to seed the signal']);
        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.unfiled-emails.file', $first), ['deal_id' => $this->deal->id])
            ->assertCreated();

        // Different subject, no thread_key — isolates the match to sender_email only.
        $second = $this->comm(['subject' => 'A brand new email, same sender though']);

        $resp = $this->actingAs($this->agent)
            ->getJson(route('deals-dr2.unfiled-emails.suggest', $second))
            ->assertOk()
            ->json();

        $this->assertSame($this->deal->id, $resp['deal_id']);
        $this->assertStringContainsString('sender', $resp['reason']);
        $this->assertStringContainsString('1 previous email', $resp['reason']);
    }

    public function test_suggest_counts_multiple_prior_filings_and_prefers_thread_over_sender(): void
    {
        $threadKey = 'thread-' . Str::random(8);
        $first = $this->comm(['subject' => 'Filed #1', 'thread_key' => $threadKey]);
        $secondFiled = $this->comm(['subject' => 'Filed #2', 'thread_key' => $threadKey]);
        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.unfiled-emails.file', $first), ['deal_id' => $this->deal->id])
            ->assertCreated();
        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.unfiled-emails.file', $secondFiled), ['deal_id' => $this->deal->id])
            ->assertCreated();

        // Same thread AND same sender both point here — thread should win as the
        // more specific signal, and the count should reflect 2 prior hits.
        $third = $this->comm(['subject' => 'A reply on the same thread', 'thread_key' => $threadKey]);

        $resp = $this->actingAs($this->agent)
            ->getJson(route('deals-dr2.unfiled-emails.suggest', $third))
            ->assertOk()
            ->json();

        $this->assertSame($this->deal->id, $resp['deal_id']);
        $this->assertStringContainsString('thread', $resp['reason']);
        $this->assertStringContainsString('2 previous emails', $resp['reason']);
    }

    public function test_suggest_returns_empty_when_nothing_has_been_learned_yet(): void
    {
        $comm = $this->comm(['subject' => 'Never filed before, nothing to learn from']);

        $resp = $this->actingAs($this->agent)
            ->getJson(route('deals-dr2.unfiled-emails.suggest', $comm))
            ->assertOk()
            ->json();

        $this->assertEmpty($resp);
    }

    public function test_suggest_never_leaks_across_agencies(): void
    {
        $otherAgencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Other ' . Str::random(6), 'slug' => 'other-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherComm = Communication::create([
            'agency_id' => $otherAgencyId, 'channel' => Communication::CHANNEL_EMAIL,
            'direction' => Communication::DIRECTION_INBOUND, 'external_id' => Str::random(14),
            'from_identifier' => 'nobody@other-agency.example.com', 'subject' => 'Not this agency',
            'occurred_at' => now(), 'captured_at' => now(), 'has_attachments' => false,
        ]);

        $this->actingAs($this->agent)
            ->getJson(route('deals-dr2.unfiled-emails.suggest', $otherComm))
            ->assertNotFound();
    }

    /**
     * CX-113 Phase G (Johan, 2026-08-22) — "getting an email that should not be in
     * here so how do i remove it?" Real example: a Google Ads/web-design supplier
     * sitting in the DR2 unfiled queue. Reversible, agency-wide, reason-tagged, never
     * touches the Communication row or its contact link.
     */
    public function test_dismiss_removes_an_email_from_the_unfiled_list(): void
    {
        $comm = $this->comm(['subject' => 'Not deal related subject line']);

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.unfiled-emails.dismiss', $comm), ['reason' => 'supplier_marketing'])
            ->assertOk()
            ->assertJson(['ok' => true, 'reason' => 'Supplier/marketing']);

        $this->assertDatabaseHas('communication_dr2_dismissals', [
            'communication_id' => $comm->id,
            'reason' => 'supplier_marketing',
            'dismissed_by_user_id' => $this->agent->id,
        ]);

        $resp = $this->actingAs($this->agent)->get(route('deals-dr2.unfiled-emails.index'))->assertOk();
        $resp->assertDontSee('Not deal related subject line');
    }

    public function test_dismiss_excludes_the_email_from_the_all_state_too(): void
    {
        $comm = $this->comm(['subject' => 'Dismissed from all state subject']);
        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.unfiled-emails.dismiss', $comm), ['reason' => 'personal'])
            ->assertOk();

        $resp = $this->actingAs($this->agent)
            ->get(route('deals-dr2.unfiled-emails.index', ['state' => 'all']))
            ->assertOk();
        $resp->assertDontSee('Dismissed from all state subject');
    }

    public function test_dismissed_email_is_findable_under_the_removed_state_with_reason_and_who(): void
    {
        $comm = $this->comm(['subject' => 'Findable when removed subject']);
        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.unfiled-emails.dismiss', $comm), ['reason' => 'duplicate'])
            ->assertOk();

        $resp = $this->actingAs($this->agent)
            ->get(route('deals-dr2.unfiled-emails.index', ['state' => 'removed']))
            ->assertOk();
        $resp->assertSee('Findable when removed subject');
        $resp->assertSee('Duplicate');
        $resp->assertSee($this->agent->name, false);
    }

    public function test_restore_puts_a_dismissed_email_back_in_the_unfiled_list(): void
    {
        $comm = $this->comm(['subject' => 'Restored subject line']);
        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.unfiled-emails.dismiss', $comm), ['reason' => 'not_deal_related'])
            ->assertOk();

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.unfiled-emails.restore', $comm))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('communication_dr2_dismissals', [
            'communication_id' => $comm->id,
            'restored_by_user_id' => $this->agent->id,
        ]);

        $unfiled = $this->actingAs($this->agent)->get(route('deals-dr2.unfiled-emails.index'))->assertOk();
        $unfiled->assertSee('Restored subject line');

        $removed = $this->actingAs($this->agent)
            ->get(route('deals-dr2.unfiled-emails.index', ['state' => 'removed']))
            ->assertOk();
        $removed->assertDontSee('Restored subject line');
    }

    public function test_dismiss_rejects_an_unknown_reason(): void
    {
        $comm = $this->comm();

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.unfiled-emails.dismiss', $comm), ['reason' => 'made_up_reason'])
            ->assertStatus(422);
    }

    public function test_dismiss_with_other_reason_requires_free_text(): void
    {
        $comm = $this->comm();

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.unfiled-emails.dismiss', $comm), ['reason' => 'other'])
            ->assertStatus(422);

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.unfiled-emails.dismiss', $comm), ['reason' => 'other', 'reason_other' => 'Newsletter signup confirmation'])
            ->assertOk();

        $this->assertDatabaseHas('communication_dr2_dismissals', [
            'communication_id' => $comm->id,
            'reason' => 'other',
            'reason_other' => 'Newsletter signup confirmation',
        ]);
    }

    public function test_dismissal_is_agency_wide_not_just_visible_to_the_dismisser(): void
    {
        // Admin/scope=all so the colleague would see this email if it were NOT
        // dismissed — otherwise "they don't see it" proves nothing about the
        // dismissal itself (an unrelated agent at 'own' scope never would anyway).
        Role::create(['name' => 'admin', 'label' => 'Admin', 'agency_id' => $this->agencyId]);
        RolePermission::create(['role' => 'admin', 'permission_key' => 'view_deals', 'agency_id' => $this->agencyId]);
        RolePermission::create(['role' => 'admin', 'permission_key' => 'dr2_unfiled_emails.view', 'scope' => 'all', 'agency_id' => $this->agencyId]);
        Role::clearCache();
        PermissionService::clearCache();

        $comm = $this->comm(['subject' => 'Agency wide dismiss subject']);
        $colleague = User::factory()->create(['agency_id' => $this->agencyId, 'role' => 'admin', 'is_active' => true]);

        // Sanity check: BEFORE dismissal, the wide-scope colleague genuinely sees it.
        $before = $this->actingAs($colleague)->get(route('deals-dr2.unfiled-emails.index'))->assertOk();
        $before->assertSee('Agency wide dismiss subject');

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.unfiled-emails.dismiss', $comm), ['reason' => 'not_deal_related'])
            ->assertOk();

        $after = $this->actingAs($colleague)->get(route('deals-dr2.unfiled-emails.index'))->assertOk();
        $after->assertDontSee('Agency wide dismiss subject');
    }

    public function test_dismiss_does_not_touch_the_communication_or_its_contact_link(): void
    {
        $comm = $this->comm();
        $contactId = (int) DB::table('contacts')->insertGetId([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'email' => 'linked-contact@example.com',
            'first_name' => 'Linked', 'last_name' => 'Contact', 'created_at' => now(), 'updated_at' => now(),
        ]);
        CommunicationLink::create([
            'agency_id' => $this->agencyId, 'communication_id' => $comm->id,
            'linkable_type' => \App\Models\Contact::class, 'linkable_id' => $contactId,
            'link_method' => CommunicationLink::METHOD_DETERMINISTIC, 'confidence' => 100, 'confirmed_at' => now(),
        ]);

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.unfiled-emails.dismiss', $comm), ['reason' => 'not_deal_related'])
            ->assertOk();

        $this->assertDatabaseHas('communications', ['id' => $comm->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('communication_links', [
            'communication_id' => $comm->id, 'linkable_type' => \App\Models\Contact::class,
            'linkable_id' => $contactId, 'deleted_at' => null,
        ]);
    }

    // ── CX-113 Phase K — left-column evidence panel (Johan, 2026-08-22) ──────
    // "show the agents why we are matching an email to a deal... show what did
    // NOT match as well as what did." These cover the itemised seller/buyer/
    // email/subject breakdown for both a confident match and a non-confident
    // one — the panel must render honestly in both cases, not just when a
    // match is strong.

    public function test_evidence_panel_shows_matched_seller_email_and_unmatched_buyer_and_attorney_for_a_confident_match(): void
    {
        DB::table('deals')->where('id', $this->deal->id)->update([
            'property_address' => 'Aloha Park Estate', 'buyer_name' => 'Unseen Buyer', 'attorney_name' => 'Unseen Attorney',
        ]);

        $comm = $this->comm([
            'subject' => 'Aloha Park Estate documents',
            'from_identifier' => 'conveyancer@bbb-attorneys.co.za', // registered as seller in setUp()
        ]);

        $resp = $this->actingAs($this->agent)->get(route('deals-dr2.unfiled-emails.index'))->assertOk();

        // Seller — matched via the sender's email being the registered seller party.
        $resp->assertSee('Seller — matched', false);
        $resp->assertSee('conveyancer@bbb-attorneys.co.za', false);
        // Buyer — no matching email at all, shown honestly as not matched.
        $resp->assertSee('Buyer — not matched', false);
        $resp->assertSee('Unseen Buyer', false);
        // Subject — property address resolved (2+ significant words matched).
        $resp->assertSee('matched the property address', false);
        // Subject — attorney name never appears in the subject, shown as not matched.
        $resp->assertSee('attorney name (Unseen Attorney)', false);
        $resp->assertSee('not found in subject', false);
    }

    public function test_evidence_panel_shows_a_low_confidence_candidates_matched_and_unmatched_signals_when_no_confident_match_exists(): void
    {
        // Real case this mirrors (Johan): "linda@vdsatt.co.za — attorney on 9 deals"
        // — a party email that DOES match, but is so undiscriminating (registered on
        // many deals) that it must not, by itself, cross the confidence bar. Here the
        // sender is the seller on TWO deals (frequency-decayed email score), and the
        // property address genuinely appears in the body — real signals, correctly
        // weak, must still surface itemised (not hidden just because it isn't
        // confident) alongside the fields that did NOT match.
        DB::table('deals')->where('id', $this->deal->id)->update([
            'property_address' => 'Sunset Ridge', 'buyer_name' => null, 'attorney_name' => null,
        ]);

        $decoyDealV2Id = (int) DB::table('deals_v2')->insertGetId([
            'reference' => 'DR2-' . Str::random(5), 'deal_type' => 'bond', 'listing_agent_id' => $this->agent->id,
            'purchase_price' => 1_500_000, 'commission_amount' => 75_000, 'commission_vat' => 11_250,
            'offer_date' => '2026-03-01', 'branch_id' => $this->branchId, 'agency_id' => $this->agencyId,
            'created_by_id' => $this->agent->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $decoyDeal = Deal::withoutEvents(fn () => Deal::withoutGlobalScopes()->create([
            'period' => '2026-03', 'deal_date' => '2026-03-01', 'property_value' => 900_000, 'total_commission' => 50_000,
            'reference' => 'REG-' . Str::random(5), 'deal_no' => random_int(1000, 9999), 'deal_type' => 'bond',
            'seller_name' => 'Unrelated Seller', 'property_address' => '9 Unrelated Close',
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'deal_v2_id' => $decoyDealV2Id,
        ]));
        $decoyDeal->agents()->attach($this->agent->id, ['side' => 'selling']);
        $decoyContactId = (int) DB::table('contacts')->insertGetId([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'email' => 'conveyancer@bbb-attorneys.co.za',
            'first_name' => 'Test', 'last_name' => 'Party', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('deal_contacts')->insert([
            'deal_id' => $decoyDeal->id, 'contact_id' => $decoyContactId, 'role' => 'seller',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // conveyancer@bbb-attorneys.co.za (registered as seller in setUp() on $this->deal
        // too) is now a seller party on TWO deals — frequency 2 decays its email score
        // from 100 to 25, well under the 90-point bar even combined with the property hit.
        $comm = $this->comm([
            'subject' => 'Weekly update',
            'from_identifier' => 'conveyancer@bbb-attorneys.co.za',
            'body_text' => 'Please see attached regarding Sunset Ridge documents.',
        ]);

        $resp = $this->actingAs($this->agent)->get(route('deals-dr2.unfiled-emails.index'))->assertOk();

        $resp->assertSee('not confident enough', false);
        // Email evidence — matched, but shown with its real (weak) specificity.
        $resp->assertSee('seller on 2 deals', false);
        // Subject — the property address genuinely resolved.
        $resp->assertSee('matched the property address', false);
        // Buyer — nothing on record, nothing to match, shown honestly as not matched.
        $resp->assertSee('Buyer — not matched', false);
    }
}
