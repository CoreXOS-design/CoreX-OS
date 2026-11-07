<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Mail\Auctions\AuctionBidderMail;
use App\Models\AgencyAuctionSettings;
use App\Models\AuctionLot;
use App\Models\Contact;
use App\Models\Deal;
use App\Services\Auctions\AuctionDealFactory;
use App\Services\Auctions\AuctionLifecycleService;
use App\Services\Auctions\AuctionLotService;
use App\Services\Auctions\BidEngine;
use App\Services\Auctions\LotResultService;
use App\Services\Property\ContactPropertyLinker;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/** What happens after the hammer: the Deal, and the seller's decision on a sale made below the reserve (.ai/specs/auctions.md §8.6). */
final class AfterTheHammerTest extends BiddingTestCase
{
    /** Link a seller to the lot's property, as an agent does on the Contacts tab. */
    private function withSeller(): Contact
    {
        $seller = Contact::create(['first_name' => 'Sarah', 'last_name' => 'Seller', 'email' => 'sarah@example.com', 'phone' => '0829990000', 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id]);
        ContactPropertyLinker::link($seller->id, $this->lot->property_id, 'seller');

        return $seller;
    }

    /** A timed lot that ended with one bid of $amount by buyer $email. */
    private function endedWith(float $amount, string $email = 'win@example.com'): AuctionLot
    {
        $reg = $this->registration($this->buyer($email));
        app(BidEngine::class)->place($this->lot, $reg, $amount);
        $this->lot->forceFill(['closes_at' => now()->subMinute()])->save();
        app(LotResultService::class)->conclude($this->lot->id);

        return $this->lot->fresh();
    }

    // ── the Deal ───────────────────────────────────────────────────────────

    public function test_a_sold_lot_opens_a_deal_with_the_price_the_seller_the_buyer_and_the_listing_agent(): void
    {
        $this->withSeller();
        $lot = $this->endedWith(160000);

        $this->assertSame('sold', $lot->status);
        $this->assertNotNull($lot->deal_id, 'the sale opened a deal automatically');

        $deal = Deal::findOrFail($lot->deal_id);
        $this->assertSame($this->agency->id, (int) $deal->agency_id);
        $this->assertSame($lot->property_id, (int) $deal->property_id);
        $this->assertEquals(160000, (float) $deal->property_value);
        $this->assertSame('cash', $deal->deal_type);
        $this->assertStringContainsString('Sarah Seller', (string) $deal->seller_name);
        $this->assertSame($this->admin->id, (int) $deal->agents()->first()?->id, 'the property\'s listing agent is on the deal');
        $this->assertStringContainsString('Sold at auction', (string) $deal->remarks);
    }

    public function test_a_second_sold_event_never_opens_a_second_deal(): void
    {
        $this->withSeller();
        $lot = $this->endedWith(160000);
        $first = $lot->deal_id;

        event(new \App\Events\Auction\AuctionLotSold($lot));
        event(new \App\Events\Auction\AuctionLotSold($lot->fresh()));

        $this->assertSame($first, $lot->fresh()->deal_id);
        $this->assertSame(1, Deal::where('property_id', $lot->property_id)->count());
    }

    public function test_a_sale_with_no_seller_on_the_property_still_sells_and_the_deal_can_be_opened_later(): void
    {
        $lot = $this->endedWith(160000);

        $this->assertSame('sold', $lot->status, 'the sale stands');
        $this->assertNull($lot->deal_id, 'but there was no seller to put on a deal');

        $this->post(route('corex.auctions.lots.open-deal', $lot->id))->assertSessionHasErrors('deal');
        $this->get(route('corex.auctions.lots.edit', $lot->id))->assertOk()->assertSee('No deal has been opened yet')->assertSee('Open the deal');

        $this->withSeller();
        $this->post(route('corex.auctions.lots.open-deal', $lot->id))->assertSessionHasNoErrors();
        $this->assertNotNull($lot->fresh()->deal_id);
        $this->get(route('corex.auctions.lots.edit', $lot->id))->assertSee('A deal was opened for this sale');
    }

    public function test_the_deal_factory_refuses_a_lot_that_is_not_sold(): void
    {
        $this->withSeller();
        $this->expectException(\RuntimeException::class);
        app(AuctionDealFactory::class)->createFromSoldLot($this->lot->fresh());
    }

    public function test_deal_numbers_continue_within_the_agency(): void
    {
        $this->withSeller();
        $lot = $this->endedWith(160000);
        $this->assertEquals('1001', Deal::findOrFail($lot->deal_id)->deal_no, 'the first deal of a new agency');
    }

    // ── the seller's decision ──────────────────────────────────────────────

    public function test_a_below_reserve_sale_waits_for_the_seller_and_shows_on_the_lot_page(): void
    {
        $this->withSeller();
        $lot = $this->endedWith(120000);

        $this->assertSame('sold_subject_to_confirmation', $lot->status);
        $this->assertNull($lot->deal_id, 'no deal until the sale is confirmed');
        $this->assertNotNull($lot->confirmation_deadline);
        $this->get(route('corex.auctions.lots.edit', $lot->id))->assertOk()->assertSee('Seller confirms the sale')->assertSee('Seller declines')->assertSee('below the reserve');
    }

    public function test_the_confirmation_deadline_follows_the_agencys_setting(): void
    {
        AgencyAuctionSettings::create(['agency_id' => $this->agency->id, 'confirmation_days' => 10]);
        $lot = $this->endedWith(120000);

        $this->assertTrue($lot->confirmation_deadline->between(now()->addDays(10)->subMinute(), now()->addDays(10)->addMinute()));
    }

    public function test_the_seller_confirms_and_the_lot_is_sold_with_a_deal_and_only_the_winner_is_emailed(): void
    {
        $this->withSeller();
        Mail::fake();
        $other = $this->registration($this->buyer('lost@example.com'));
        app(BidEngine::class)->place($this->lot, $other, 100000);
        $lot = $this->endedWith(120000, 'win@example.com');
        Mail::assertSent(AuctionBidderMail::class, fn ($m) => $m->kind === 'ended' && $m->hasTo('lost@example.com'));
        Mail::fake();   // forget the close-time mails: only what confirming sends counts

        $this->post(route('corex.auctions.lots.confirm', $lot->id))->assertSessionHasNoErrors();

        $lot = $lot->fresh();
        $this->assertSame('sold', $lot->status);
        $this->assertNotNull($lot->confirmed_at);
        $this->assertNull($lot->confirmation_deadline);
        $this->assertNotNull($lot->deal_id, 'confirming the sale opens the deal');
        Mail::assertSent(AuctionBidderMail::class, fn ($m) => $m->kind === 'won' && $m->hasTo('win@example.com'));
        Mail::assertNotSent(AuctionBidderMail::class, fn ($m) => $m->hasTo('lost@example.com'));
    }

    public function test_the_seller_declines_and_the_lot_is_passed_in_the_property_released_and_the_winner_told(): void
    {
        Mail::fake();
        $this->withSeller();
        $lot = $this->endedWith(120000, 'win@example.com');
        $property = $lot->property;
        $property->forceFill(['status' => 'on_auction', 'pre_auction_status' => 'active'])->save();

        $this->post(route('corex.auctions.lots.decline', $lot->id), [])->assertSessionHasErrors('reason');
        $this->post(route('corex.auctions.lots.decline', $lot->id), ['reason' => 'Too low'])->assertSessionHasNoErrors();

        $lot = $lot->fresh();
        $this->assertSame('passed_in', $lot->status);
        $this->assertStringContainsString('Too low', (string) $lot->result_note);
        $this->assertNull($lot->deal_id);
        $this->assertSame('active', $property->fresh()->status);
        Mail::assertSent(AuctionBidderMail::class, fn ($m) => $m->kind === 'not_accepted' && $m->hasTo('win@example.com') && $m->facts['amount'] === 'R 120 000');
    }

    public function test_confirm_and_decline_only_work_on_a_lot_waiting_for_the_seller(): void
    {
        $this->withSeller();
        $lot = $this->endedWith(160000);   // sold outright

        $this->post(route('corex.auctions.lots.confirm', $lot->id))->assertSessionHasErrors('lot');
        $this->post(route('corex.auctions.lots.decline', $lot->id), ['reason' => 'x'])->assertSessionHasErrors('lot');
        $this->assertSame('sold', $lot->fresh()->status);
    }

    public function test_an_auction_cannot_be_completed_until_the_seller_has_decided(): void
    {
        $this->withSeller();
        $lot = $this->endedWith(120000);
        $lifecycle = app(AuctionLifecycleService::class);

        try {
            $lifecycle->complete($this->auc->fresh());
            $this->fail('a lot awaiting the seller blocks completion');
        } catch (ValidationException) {
        }

        app(LotResultService::class)->confirmSale($lot);
        $lifecycle->complete($this->auc->fresh(), $this->admin->id);
        $this->assertSame('completed', $this->auc->fresh()->status);
    }

    // ── lifecycle events ───────────────────────────────────────────────────

    public function test_publishing_cancelling_completing_and_withdrawing_are_logged_as_domain_events(): void
    {
        $lifecycle = app(AuctionLifecycleService::class);
        $draft = $this->auction(['reference' => 'AUC-T-EV']);
        $lot = app(AuctionLotService::class)->saveForProperty($this->property('Event house'), ['auction_id' => $draft->id], $this->admin);

        $lifecycle->publish($draft, $this->admin->id);
        $this->assertDatabaseHas('domain_event_log', ['event_name' => 'App\\Events\\Auction\\AuctionPublished']);

        app(AuctionLotService::class)->withdraw($lot->fresh(), 'Seller changed their mind');
        $this->assertDatabaseHas('domain_event_log', ['event_name' => 'App\\Events\\Auction\\AuctionLotWithdrawn']);

        $lifecycle->cancel($draft->fresh(), 'Venue unavailable', $this->admin->id);
        $this->assertDatabaseHas('domain_event_log', ['event_name' => 'App\\Events\\Auction\\AuctionCancelled']);

        $done = $this->auction(['reference' => 'AUC-T-DONE', 'status' => 'published']);
        $lifecycle->complete($done, $this->admin->id);
        $this->assertDatabaseHas('domain_event_log', ['event_name' => 'App\\Events\\Auction\\AuctionCompleted']);
    }

    // ── the buyer's side ───────────────────────────────────────────────────

    public function test_the_winner_sees_the_lot_they_won_in_my_account(): void
    {
        $this->withSeller();
        $lot = $this->endedWith(160000, 'win@example.com');
        $user = $this->buyer('win@example.com');
        \Illuminate\Support\Facades\Auth::guard('web')->forgetUser();
        $this->flushSession();
        \Illuminate\Support\Facades\Auth::guard('client-web')->login($user);

        $this->get('/auctions/' . $this->agency->slug . '/account')->assertOk()->assertSee('Lots I have won')->assertSee('You won at R 160 000');

        // someone who did not win does not
        $loser = $this->buyer('nope@example.com');
        $this->flushSession();
        \Illuminate\Support\Facades\Auth::guard('client-web')->login($loser);
        $this->get('/auctions/' . $this->agency->slug . '/account')->assertDontSee('Lots I have won');
    }
}
