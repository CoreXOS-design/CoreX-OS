<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLearnedRef;
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
 * CX-108 (Johan, 2026-08-20) — manual email-to-deal link/unlink, reachable
 * from DR2. Covers exactly the acceptance list Johan gave: link attaches,
 * unlink detaches, references are captured on link, unlinking does not
 * destroy the captured reference history.
 */
final class Dr2CommunicationLinkTest extends TestCase
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
            'thread_key' => 'thread-' . Str::random(6),
            'from_identifier' => 'conveyancer@bbb-attorneys.co.za',
            'subject' => 'RE: Transfer documents — 1 Test Rd',
            'occurred_at' => now(),
            'captured_at' => now(),
            'owner_user_id' => $this->agent->id,
            'has_attachments' => false,
        ], $over));
    }

    public function test_link_attaches_the_communication_to_the_deal(): void
    {
        $comm = $this->comm();

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.communications.link', $this->deal), ['communication_id' => $comm->id])
            ->assertCreated()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('communication_links', [
            'communication_id' => $comm->id,
            'linkable_type' => DealV2::class,
            'linkable_id' => $this->dealV2Id,
            'link_method' => CommunicationLink::METHOD_MANUAL,
            'confirmed_by' => $this->agent->id,
        ]);

        $listed = $this->actingAs($this->agent)
            ->getJson(route('deals-dr2.communications.index', $this->deal))
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $listed);
        $this->assertSame($comm->id, $listed[0]['communication_id']);
    }

    public function test_unlink_detaches_the_communication_from_the_deal(): void
    {
        $comm = $this->comm();

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.communications.link', $this->deal), ['communication_id' => $comm->id])
            ->assertCreated();

        $link = CommunicationLink::where('communication_id', $comm->id)->firstOrFail();

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.communications.unlink', ['deal' => $this->deal, 'link' => $link->id]))
            ->assertOk()
            ->assertJson(['ok' => true]);

        // Soft-deleted, not hard-deleted (non-negotiable #1) — the row survives, just trashed.
        $this->assertSoftDeleted('communication_links', ['id' => $link->id]);

        $listed = $this->actingAs($this->agent)
            ->getJson(route('deals-dr2.communications.index', $this->deal))
            ->assertOk()
            ->json('data');

        $this->assertCount(0, $listed);
    }

    public function test_linking_captures_sender_thread_and_subject_signals_against_the_deal(): void
    {
        $comm = $this->comm([
            'from_identifier' => 'Conveyancer@BBB-Attorneys.co.za',
            'thread_key' => 'THREAD-XYZ',
            'subject' => 'Transfer Documents — 1 Test Rd',
        ]);

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.communications.link', $this->deal), ['communication_id' => $comm->id])
            ->assertCreated();

        // Normalised (lowercase+trim) per CommunicationLearnedRef::normalizeValue().
        $this->assertDatabaseHas('communication_learned_refs', [
            'agency_id' => $this->agencyId,
            'deal_id' => $this->dealV2Id,
            'signal_type' => CommunicationLearnedRef::SIGNAL_SENDER_EMAIL,
            'signal_value' => 'conveyancer@bbb-attorneys.co.za',
            'is_verified' => false,
        ]);
        $this->assertDatabaseHas('communication_learned_refs', [
            'agency_id' => $this->agencyId,
            'deal_id' => $this->dealV2Id,
            'signal_type' => CommunicationLearnedRef::SIGNAL_THREAD_KEY,
            'signal_value' => 'thread-xyz',
        ]);
        $this->assertDatabaseHas('communication_learned_refs', [
            'agency_id' => $this->agencyId,
            'deal_id' => $this->dealV2Id,
            'signal_type' => CommunicationLearnedRef::SIGNAL_SUBJECT_PATTERN,
            'signal_value' => 'transfer documents — 1 test rd',
        ]);

        $ref = CommunicationLearnedRef::where('signal_type', CommunicationLearnedRef::SIGNAL_SENDER_EMAIL)
            ->where('signal_value', 'conveyancer@bbb-attorneys.co.za')->firstOrFail();
        $this->assertSame(1, $ref->hits);
    }

    public function test_unlinking_does_not_destroy_the_captured_reference_history(): void
    {
        $comm = $this->comm();

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.communications.link', $this->deal), ['communication_id' => $comm->id])
            ->assertCreated();

        $refCountBefore = CommunicationLearnedRef::where('deal_id', $this->dealV2Id)->count();
        $this->assertGreaterThan(0, $refCountBefore);

        $link = CommunicationLink::where('communication_id', $comm->id)->firstOrFail();
        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.communications.unlink', ['deal' => $this->deal, 'link' => $link->id]))
            ->assertOk();

        // The link is gone (soft-deleted), but the learned signals it produced are untouched.
        $refCountAfter = CommunicationLearnedRef::where('deal_id', $this->dealV2Id)->count();
        $this->assertSame($refCountBefore, $refCountAfter);
        $this->assertDatabaseHas('communication_learned_refs', [
            'deal_id' => $this->dealV2Id,
            'signal_type' => CommunicationLearnedRef::SIGNAL_SENDER_EMAIL,
            'signal_value' => 'conveyancer@bbb-attorneys.co.za',
        ]);
    }

    public function test_relinking_a_previously_unlinked_communication_restores_the_same_row(): void
    {
        $comm = $this->comm();

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.communications.link', $this->deal), ['communication_id' => $comm->id])
            ->assertCreated();
        $originalLinkId = CommunicationLink::where('communication_id', $comm->id)->firstOrFail()->id;

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.communications.unlink', ['deal' => $this->deal, 'link' => $originalLinkId]))
            ->assertOk();

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.communications.link', $this->deal), ['communication_id' => $comm->id])
            ->assertCreated();

        // Same row restored, not a duplicate — and it is active again (not trashed).
        $this->assertSame(1, CommunicationLink::where('communication_id', $comm->id)->count());
        $restored = CommunicationLink::where('communication_id', $comm->id)->firstOrFail();
        $this->assertSame($originalLinkId, $restored->id);
        $this->assertNull($restored->deleted_at);
    }

    public function test_search_excludes_communications_already_linked_to_the_deal(): void
    {
        $linked = $this->comm(['subject' => 'Transfer pack']);
        $unlinked = $this->comm(['subject' => 'Transfer pack part 2']);

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.communications.link', $this->deal), ['communication_id' => $linked->id])
            ->assertCreated();

        $results = $this->actingAs($this->agent)
            ->getJson(route('deals-dr2.communications.search', $this->deal) . '?q=Transfer')
            ->assertOk()
            ->json('data');

        $ids = array_column($results, 'id');
        $this->assertNotContains($linked->id, $ids);
        $this->assertContains($unlinked->id, $ids);
    }

    public function test_unlink_is_scoped_to_the_deal_it_belongs_to(): void
    {
        $otherDealV2Id = (int) DB::table('deals_v2')->insertGetId([
            'reference' => 'DR2-' . Str::random(5), 'deal_type' => 'cash', 'listing_agent_id' => $this->agent->id,
            'purchase_price' => 900_000, 'commission_amount' => 45_000, 'commission_vat' => 6_750,
            'offer_date' => '2026-03-01', 'branch_id' => $this->branchId, 'agency_id' => $this->agencyId,
            'created_by_id' => $this->agent->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherDeal = Deal::withoutEvents(fn () => Deal::withoutGlobalScopes()->create([
            'period' => '2026-03', 'deal_date' => '2026-03-01', 'property_value' => 900_000, 'total_commission' => 51_750,
            'reference' => 'REG-' . Str::random(5), 'deal_no' => random_int(1000, 9999), 'deal_type' => 'cash',
            'seller_name' => 'Other Seller', 'property_address' => '2 Other Rd',
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'deal_v2_id' => $otherDealV2Id,
        ]));

        $comm = $this->comm();
        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.communications.link', $this->deal), ['communication_id' => $comm->id])
            ->assertCreated();
        $link = CommunicationLink::where('communication_id', $comm->id)->firstOrFail();

        // Attempting to unlink this deal's link via a DIFFERENT deal's route must 404, not detach it.
        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.communications.unlink', ['deal' => $otherDeal, 'link' => $link->id]))
            ->assertNotFound();

        $this->assertDatabaseHas('communication_links', ['id' => $link->id, 'deleted_at' => null]);
    }
}
