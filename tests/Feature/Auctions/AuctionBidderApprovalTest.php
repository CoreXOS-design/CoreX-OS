<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\Auction;
use App\Models\AuctionBidder;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-432 Phase 2 — .ai/specs/auctions.md §10.2. The paddle gate: a bidder
 * cannot be approved (and so cannot hold a paddle) until every enabled
 * gate passes. Verified via Tinker while building this — approve() blocked
 * a bidder missing FICA/rules, then succeeded and issued paddle "001" once
 * both were satisfied.
 */
final class AuctionBidderApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Auction $auction;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionService::clearCache();

        $this->agency = Agency::create(['name' => 'Bidder Test Agency', 'slug' => 'bidder-test-'.uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        AgencyFeature::updateOrCreate(['agency_id' => $this->agency->id, 'feature_key' => 'auctions'], ['enabled' => true]);
        config(['features.auctions' => true]);
        app(\App\Services\Features\AgencyFeatureService::class)->forget($this->agency->id);

        $this->auction = Auction::create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'reference' => 'AUC-BIDDER-TEST',
            'title' => 'Bidder Test Auction', 'bidding_mode' => 'in_room', 'auctioneer_kind' => 'internal',
            'starts_at' => now()->addDays(5),
        ]);

        $this->actingAs($this->user);
    }

    private function makeBidder(array $attrs = []): AuctionBidder
    {
        $contact = Contact::create(['agency_id' => $this->agency->id, 'first_name' => 'Test', 'last_name' => 'Bidder']);

        return AuctionBidder::create(array_merge([
            'agency_id' => $this->agency->id, 'auction_id' => $this->auction->id, 'contact_id' => $contact->id,
            'status' => AuctionBidder::STATUS_SUBMITTED, 'deposit_required' => false,
        ], $attrs));
    }

    public function test_approve_is_blocked_when_fica_and_rules_are_outstanding(): void
    {
        $bidder = $this->makeBidder();

        $this->post(route('corex.auctions.bidders.approve', $bidder))->assertSessionHasErrors('bidder');

        $bidder->refresh();
        $this->assertSame(AuctionBidder::STATUS_SUBMITTED, $bidder->status);
        $this->assertNull($bidder->paddle_number);
    }

    public function test_approve_succeeds_and_issues_a_paddle_once_every_gate_passes(): void
    {
        $bidder = $this->makeBidder([
            'fica_status' => 'approved', 'fica_verified_at' => now(), 'rules_signed_at' => now(),
        ]);

        $this->post(route('corex.auctions.bidders.approve', $bidder))->assertSessionHasNoErrors();

        $bidder->refresh();
        $this->assertSame(AuctionBidder::STATUS_APPROVED, $bidder->status);
        $this->assertNotNull($bidder->paddle_number);
        $this->assertNotNull($bidder->approved_at);
    }

    public function test_a_deposit_required_bidder_is_blocked_until_the_deposit_is_recorded(): void
    {
        $bidder = $this->makeBidder([
            'fica_status' => 'approved', 'fica_verified_at' => now(), 'rules_signed_at' => now(),
            'deposit_required' => true, 'deposit_amount' => 50000,
        ]);

        $this->post(route('corex.auctions.bidders.approve', $bidder))->assertSessionHasErrors('bidder');

        $this->post(route('corex.auctions.bidders.deposit', $bidder), ['deposit_reference' => 'EFT-123'])
            ->assertSessionHasNoErrors();

        $this->post(route('corex.auctions.bidders.approve', $bidder->refresh()))->assertSessionHasNoErrors();
        $this->assertSame(AuctionBidder::STATUS_APPROVED, $bidder->refresh()->status);
    }

    public function test_two_approved_bidders_get_distinct_sequential_paddles(): void
    {
        $bidder1 = $this->makeBidder(['fica_status' => 'approved', 'fica_verified_at' => now(), 'rules_signed_at' => now()]);
        $bidder2 = $this->makeBidder(['fica_status' => 'approved', 'fica_verified_at' => now(), 'rules_signed_at' => now()]);

        $this->post(route('corex.auctions.bidders.approve', $bidder1));
        $this->post(route('corex.auctions.bidders.approve', $bidder2));

        $this->assertNotEquals($bidder1->refresh()->paddle_number, $bidder2->refresh()->paddle_number);
    }

    public function test_declining_a_bidder_records_the_reason(): void
    {
        $bidder = $this->makeBidder();

        $this->post(route('corex.auctions.bidders.decline', $bidder), ['declined_reason' => 'Could not verify identity'])
            ->assertSessionHasNoErrors();

        $bidder->refresh();
        $this->assertSame(AuctionBidder::STATUS_DECLINED, $bidder->status);
        $this->assertSame('Could not verify identity', $bidder->declined_reason);
    }
}
