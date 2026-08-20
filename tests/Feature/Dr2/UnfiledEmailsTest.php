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
    }

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        parent::tearDown();
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

    public function test_the_search_box_filters_by_subject_or_sender(): void
    {
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
}
