<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Models\Agency;
use App\Models\AgencyAuctionSettings;
use App\Models\AuctionBid;
use App\Models\AuctionRegistration;
use App\Models\AuctionRegistrationDocument;
use App\Models\BidderDocument;
use App\Models\ClientUser;
use App\Services\Auctions\BidEngine;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/** Staff screens S5 Bidders and S4 Bids (.ai/specs/auctions.md §5). */
final class BiddersAndBidsScreensTest extends BiddingTestCase
{
    /** A pending registration with the two required documents submitted. */
    private function pendingWithDocs(string $email = 'p@example.com'): AuctionRegistration
    {
        $reg = $this->registration($this->buyer($email), 'pending');
        foreach (['id_or_passport', 'proof_of_address'] as $kind) {
            $doc = BidderDocument::withoutAgencyStamping(fn () => BidderDocument::create(['agency_id' => $this->agency->id, 'client_user_id' => $reg->client_user_id, 'kind' => $kind, 'original_name' => "{$kind}.pdf", 'path' => "auction-bidders/{$kind}-{$email}.pdf"]));
            AuctionRegistrationDocument::withoutAgencyStamping(fn () => AuctionRegistrationDocument::create(['agency_id' => $this->agency->id, 'registration_id' => $reg->id, 'bidder_document_id' => $doc->id, 'kind' => $kind]));
        }

        return $reg;
    }

    // ── Bidders ────────────────────────────────────────────────────────────

    public function test_the_bidders_list_searches_filters_and_shows_an_empty_state(): void
    {
        $this->get(route('corex.auctions.bidders.index'))->assertOk()->assertSee('No one has registered to bid yet');

        $this->registration($this->buyer('alice@example.com'), 'approved');
        $this->registration($this->buyer('bob@example.com'), 'pending');

        $this->get(route('corex.auctions.bidders.index'))->assertSee('alice@example.com')->assertSee('bob@example.com');
        $this->get(route('corex.auctions.bidders.index', ['search' => 'alice']))->assertSee('alice@example.com')->assertDontSee('bob@example.com');
        $this->get(route('corex.auctions.bidders.index', ['view' => 'pending']))->assertSee('bob@example.com')->assertDontSee('alice@example.com');
        $this->get(route('corex.auctions.bidders.index', ['view' => 'approved']))->assertSee('alice@example.com')->assertDontSee('bob@example.com');
        $this->get(route('corex.auctions.bidders.index', ['search' => 'nobody-like-this']))->assertSee('No bidders match these filters');
    }

    public function test_the_winning_and_under_bidder_views_follow_the_bids(): void
    {
        $a = $this->registration($this->buyer('under@example.com'));
        $b = $this->registration($this->buyer('leader@example.com'));
        $c = $this->registration($this->buyer('nobids@example.com'));
        app(BidEngine::class)->place($this->lot, $a, 100000);
        app(BidEngine::class)->place($this->lot, $b, 105000);

        $this->get(route('corex.auctions.bidders.index', ['view' => 'winning']))->assertSee('leader@example.com')->assertDontSee('under@example.com')->assertDontSee('nobids@example.com');
        $this->get(route('corex.auctions.bidders.index', ['view' => 'underbidders']))->assertSee('under@example.com')->assertDontSee('leader@example.com')->assertDontSee('nobids@example.com');
    }

    public function test_approval_needs_every_required_document_accepted_then_issues_sequential_numbers(): void
    {
        Mail::fake();
        $first = $this->pendingWithDocs('one@example.com');
        $second = $this->pendingWithDocs('two@example.com');

        $this->post(route('corex.auctions.bidders.approve', $first->id))->assertSessionHasErrors('registration');
        $this->assertSame('pending', $first->fresh()->status);

        $this->post(route('corex.auctions.bidders.documents.accept-all', $first->id))->assertSessionHasNoErrors();
        $this->post(route('corex.auctions.bidders.approve', $first->id))->assertSessionHasNoErrors();
        $this->post(route('corex.auctions.bidders.documents.accept-all', $second->id));
        $this->post(route('corex.auctions.bidders.approve', $second->id));

        $this->assertSame([1, 2], [$first->fresh()->bidder_number, $second->fresh()->bidder_number]);
        $this->assertSame('approved', $first->fresh()->status);
        $this->assertSame($this->admin->id, (int) $first->fresh()->approved_by);
    }

    public function test_numbers_are_per_auction_and_approving_twice_keeps_the_same_number(): void
    {
        $other = $this->auction(['reference' => 'AUC-T-N2', 'status' => 'published']);
        AgencyAuctionSettings::create(['agency_id' => $this->agency->id, 'docs_required_individual' => []]);
        $a = $this->registration($this->buyer('a@example.com'), 'pending');
        $b = $this->registration($this->buyer('b@example.com'), 'pending', $other);

        $this->post(route('corex.auctions.bidders.approve', $a->id));
        $this->post(route('corex.auctions.bidders.approve', $b->id));
        $this->post(route('corex.auctions.bidders.approve', $a->id));

        $this->assertSame(1, $a->fresh()->bidder_number);
        $this->assertSame(1, $b->fresh()->bidder_number, 'each auction numbers its own bidders from 1');
    }

    public function test_a_rejected_document_blocks_approval_until_the_buyer_sends_a_better_one(): void
    {
        $reg = $this->pendingWithDocs();
        $doc = $reg->documents()->where('kind', 'id_or_passport')->first();

        $this->post(route('corex.auctions.bidders.documents.review', [$reg->id, $doc->id]), ['decision' => 'reject'])->assertSessionHasErrors('reason');
        $this->post(route('corex.auctions.bidders.documents.review', [$reg->id, $doc->id]), ['decision' => 'reject', 'reason' => 'Blurry'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $doc->fresh()->status);
        $this->assertSame('Blurry', $doc->fresh()->reject_reason);

        $this->post(route('corex.auctions.bidders.documents.accept-all', $reg->id));   // accepts only the SUBMITTED ones
        $this->post(route('corex.auctions.bidders.approve', $reg->id))->assertSessionHasErrors('registration');
    }

    public function test_declining_needs_a_reason_and_is_refused_for_a_bidder_with_live_bids(): void
    {
        $reg = $this->pendingWithDocs();
        $this->post(route('corex.auctions.bidders.decline', $reg->id), [])->assertSessionHasErrors('reason');
        $this->post(route('corex.auctions.bidders.decline', $reg->id), ['reason' => 'Documents do not match'])->assertSessionHasNoErrors();
        $this->assertSame('declined', $reg->fresh()->status);

        $bidder = $this->registration($this->buyer('live@example.com'));
        app(BidEngine::class)->place($this->lot, $bidder, 100000);
        $this->post(route('corex.auctions.bidders.decline', $bidder->id), ['reason' => 'Changed our mind'])->assertSessionHasErrors('registration');
        $this->assertSame('approved', $bidder->fresh()->status);
    }

    public function test_a_registration_can_be_withdrawn_archived_and_restored(): void
    {
        $reg = $this->pendingWithDocs();

        $this->delete(route('corex.auctions.bidders.withdraw', $reg->id))->assertRedirect();
        $this->assertSoftDeleted('auction_registrations', ['id' => $reg->id]);
        $this->get(route('corex.auctions.bidders.index', ['archived' => 1]))->assertSee('p@example.com');

        $this->post(route('corex.auctions.bidders.restore', $reg->id))->assertRedirect();
        $this->assertNull($reg->fresh()->deleted_at);
        $this->assertSame('pending', $reg->fresh()->status);
    }

    public function test_staff_can_view_a_bidders_document_but_another_agency_cannot_reach_the_registration(): void
    {
        Storage::fake('local');
        $reg = $this->pendingWithDocs();
        $doc = $reg->documents()->first();
        Storage::disk('local')->put($doc->document->path, 'PDF-BYTES');

        $this->get(route('corex.auctions.bidders.show', $reg->id))->assertOk()->assertSee('ID or passport copy');
        $this->get(route('corex.auctions.bidders.documents.download', [$reg->id, $doc->id]))->assertOk();

        $rival = Agency::create(['name' => 'Rival', 'slug' => 'rv-'.uniqid()]);
        $client = ClientUser::create(['email' => 'foreign@example.com']);
        $foreignAuction = \App\Models\Auction::withoutAgencyStamping(fn () => \App\Models\Auction::create(['agency_id' => $rival->id, 'reference' => 'AUC-R', 'title' => 'Rival', 'format' => 'timed', 'status' => 'published']));
        $foreign = AuctionRegistration::withoutAgencyStamping(fn () => AuctionRegistration::create(['agency_id' => $rival->id, 'auction_id' => $foreignAuction->id, 'client_user_id' => $client->id, 'status' => 'pending']));

        $this->get(route('corex.auctions.bidders.show', $foreign->id))->assertNotFound();
        $this->post(route('corex.auctions.bidders.approve', $foreign->id))->assertNotFound();
        $this->get(route('corex.auctions.bidders.index'))->assertDontSee('foreign@example.com');
    }

    public function test_an_agent_sees_only_registrations_for_auctions_they_can_see(): void
    {
        \Illuminate\Support\Facades\Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);
        \App\Services\PermissionService::clearCache();
        $agent = \App\Models\User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $mine = $this->auction(['reference' => 'AUC-T-MINE', 'created_by' => $agent->id, 'status' => 'published']);
        $reg = $this->registration($this->buyer('mine@example.com'), 'pending', $mine);
        $notMine = $this->registration($this->buyer('notmine@example.com'), 'pending');   // on the admin's auction

        $visible = AuctionRegistration::visibleTo($agent)->pluck('id')->all();

        $this->assertContains($reg->id, $visible);
        $this->assertNotContains($notMine->id, $visible);
        $this->assertGreaterThanOrEqual(2, AuctionRegistration::visibleTo($this->admin)->count());
    }

    // ── Bids ───────────────────────────────────────────────────────────────

    public function test_the_bids_list_shows_every_bid_with_the_bidder_to_staff_and_filters_them(): void
    {
        $this->get(route('corex.auctions.bids.index'))->assertOk()->assertSee('No bids yet');

        $a = $this->registration($this->buyer('a@example.com'));
        $b = $this->registration($this->buyer('b@example.com'));
        app(BidEngine::class)->place($this->lot, $a, 100000);
        app(BidEngine::class)->place($this->lot, $b, 105000);

        $this->get(route('corex.auctions.bids.index'))->assertOk()->assertSee('R 100 000')->assertSee('R 105 000')->assertSee('#2');
        $this->get(route('corex.auctions.bids.index', ['search' => '105000']))->assertSee('R 105 000')->assertDontSee('R 100 000');
        $this->get(route('corex.auctions.bids.index', ['search' => 'Bid Cottage']))->assertSee('R 100 000');
        $this->get(route('corex.auctions.bids.index', ['registration_id' => $a->id]))->assertSee('R 100 000')->assertDontSee('R 105 000');
        $this->get(route('corex.auctions.bids.index', ['search' => 'zzzz']))->assertSee('No bids match these filters');
    }

    public function test_a_bid_is_retracted_from_the_bids_screen_with_a_reason_and_stays_listed(): void
    {
        $a = $this->registration($this->buyer('a@example.com'));
        $bid = app(BidEngine::class)->place($this->lot, $a, 100000);

        $this->post(route('corex.auctions.bids.retract', $bid->id), [])->assertSessionHasErrors('reason');
        $this->post(route('corex.auctions.bids.retract', $bid->id), ['reason' => 'Entered by mistake'])->assertSessionHasNoErrors();

        $this->assertSame('retracted', $bid->fresh()->status);
        $this->get(route('corex.auctions.bids.index'))->assertSee('Entered by mistake');
        $this->get(route('corex.auctions.bids.index', ['status' => 'valid']))->assertDontSee('Entered by mistake');
        $this->assertSame(1, AuctionBid::withoutGlobalScopes()->count(), 'retracted, never deleted');
    }

    public function test_another_agencys_bids_are_invisible_and_cannot_be_retracted(): void
    {
        $rival = Agency::create(['name' => 'Rival', 'slug' => 'rb-'.uniqid()]);
        $foreign = AuctionBid::withoutAgencyStamping(fn () => AuctionBid::create(['agency_id' => $rival->id, 'auction_id' => $this->auc->id, 'lot_id' => $this->lot->id, 'registration_id' => $this->registration()->id, 'amount' => 1, 'placed_at' => now()]));

        // the lot belongs to this agency so this bid IS visible to us; a bid whose lot is in another agency is not
        $rivalLot = \App\Models\AuctionLot::withoutAgencyStamping(fn () => \App\Models\AuctionLot::create(['agency_id' => $rival->id, 'auction_id' => $this->auc->id, 'property_id' => $this->property('Rival house')->id, 'lot_number' => 99, 'status' => 'published']));
        $hidden = AuctionBid::withoutAgencyStamping(fn () => AuctionBid::create(['agency_id' => $rival->id, 'auction_id' => $this->auc->id, 'lot_id' => $rivalLot->id, 'registration_id' => $this->registration($this->buyer('z@example.com'))->id, 'amount' => 777, 'placed_at' => now()]));

        $this->get(route('corex.auctions.bids.index'))->assertDontSee('R 777');
        $this->post(route('corex.auctions.bids.retract', $hidden->id), ['reason' => 'x'])->assertNotFound();
    }

    public function test_the_property_auction_tab_lists_the_lots_bids_for_staff(): void
    {
        $a = $this->registration($this->buyer('a@example.com'));
        app(BidEngine::class)->place($this->lot, $a, 100000);

        $this->get(route('corex.properties.show', $this->lot->property_id) . '?tab=auction')->assertOk()->assertSee('data-auction-bids', false)->assertSee('R 100 000')->assertSee('locked');
    }
}
