<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Models\AgencyAuctionSettings;
use App\Models\AuctionBid;
use App\Models\AuctionLot;
use App\Services\Auctions\AuctionLotService;
use App\Services\Auctions\BidEngine;
use Illuminate\Validation\ValidationException;

/** The bidding rules (.ai/specs/auctions.md §8.4). */
final class BidEngineTest extends BiddingTestCase
{
    private function engine(): BidEngine
    {
        return app(BidEngine::class);
    }

    private function high(): ?float
    {
        $b = $this->lot->fresh()->highestBid();

        return $b ? (float) $b->amount : null;
    }

    private function leaderReg(): ?int
    {
        return $this->lot->fresh()->highestBid()?->registration_id;
    }

    private function expectRejection(callable $fn, string $contains): void
    {
        try {
            $fn();
            $this->fail('expected the bid to be rejected');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($contains, implode(' ', array_merge(...array_values($e->errors()))));
        }
    }

    // ── the rules ──────────────────────────────────────────────────────────

    public function test_the_first_bid_must_reach_the_opening_bid_and_later_bids_one_increment_more(): void
    {
        $a = $this->registration($this->buyer('a@example.com'));
        $b = $this->registration($this->buyer('b@example.com'));

        $this->assertSame(100000.0, $this->engine()->minimumNext($this->lot));
        $this->expectRejection(fn () => $this->engine()->place($this->lot, $a, 99999), 'at least R 100 000');

        $this->engine()->place($this->lot, $a, 100000);
        $this->assertSame(105000.0, $this->engine()->minimumNext($this->lot->fresh()), 'R5 000 increment below R1m');
        $this->expectRejection(fn () => $this->engine()->place($this->lot, $b, 104999), 'at least R 105 000');

        $this->engine()->place($this->lot, $b, 105000);
        $this->assertSame(105000.0, $this->high());
        $this->assertSame($b->id, $this->leaderReg());
    }

    public function test_the_increment_follows_the_ladder_as_the_price_climbs(): void
    {
        $a = $this->registration($this->buyer('a@example.com'));
        $this->lot->forceFill(['opening_bid' => 1000000])->save();

        $this->engine()->place($this->lot, $a, 1000000);

        $this->assertSame(1025000.0, $this->engine()->minimumNext($this->lot->fresh()), 'R25 000 from R1m');
    }

    public function test_the_leading_bidder_cannot_outbid_themselves(): void
    {
        $a = $this->registration();
        $this->engine()->place($this->lot, $a, 100000);

        $this->expectRejection(fn () => $this->engine()->place($this->lot, $a, 200000), 'already the highest bidder');
        $this->assertSame(1, AuctionBid::where('lot_id', $this->lot->id)->count());
    }

    public function test_two_bidders_cannot_both_win_the_same_price(): void
    {
        $a = $this->registration($this->buyer('a@example.com'));
        $b = $this->registration($this->buyer('b@example.com'));

        $this->engine()->place($this->lot, $a, 100000);
        $this->expectRejection(fn () => $this->engine()->place($this->lot, $b, 100000), 'at least R 105 000');

        $this->assertSame([$a->id], AuctionBid::where('lot_id', $this->lot->id)->pluck('registration_id')->all());
    }

    public function test_only_an_approved_registration_for_this_auction_may_bid(): void
    {
        foreach (['pending', 'declined', 'withdrawn'] as $i => $status) {
            $reg = $this->registration($this->buyer("s{$i}@example.com"), $status);
            $this->expectRejection(fn () => $this->engine()->place($this->lot, $reg, 100000), 'approved registration');
        }

        $other = $this->auction(['reference' => 'AUC-T-OTHER', 'status' => 'published']);
        $elsewhere = $this->registration($this->buyer('x@example.com'), 'approved', $other);
        $this->expectRejection(fn () => $this->engine()->place($this->lot, $elsewhere, 100000), 'approved registration');

        $this->assertSame(0, AuctionBid::count());
    }

    public function test_bidding_is_refused_outside_the_window_or_when_the_lot_or_auction_is_not_open(): void
    {
        $a = $this->registration();

        $this->lot->forceFill(['opens_at' => now()->addHour()])->save();
        $this->expectRejection(fn () => $this->engine()->place($this->lot, $a, 100000), 'opens');

        $this->lot->forceFill(['opens_at' => now()->subHour(), 'closes_at' => now()->subMinute()])->save();
        $this->expectRejection(fn () => $this->engine()->place($this->lot, $a, 100000), 'has closed');

        $this->lot->forceFill(['closes_at' => now()->addDay(), 'status' => AuctionLot::STATUS_WITHDRAWN])->save();
        $this->expectRejection(fn () => $this->engine()->place($this->lot, $a, 100000), 'has ended');

        $this->lot->forceFill(['status' => AuctionLot::STATUS_PUBLISHED])->save();
        $this->auc->forceFill(['status' => 'cancelled'])->save();
        $this->expectRejection(fn () => $this->engine()->place($this->lot, $a, 100000), 'not open for bidding');

        $this->auc->forceFill(['status' => 'published', 'format' => 'live'])->save();
        $this->expectRejection(fn () => $this->engine()->place($this->lot, $a, 100000), 'live auction');
    }

    public function test_the_first_bid_opens_the_lot_and_the_auction(): void
    {
        $this->engine()->place($this->lot, $this->registration(), 100000);

        $this->assertSame('open', $this->lot->fresh()->status);
        $this->assertSame('open', $this->auc->fresh()->status);
    }

    // ── soft close ─────────────────────────────────────────────────────────

    public function test_a_late_bid_extends_the_closing_time_but_an_early_one_does_not(): void
    {
        AgencyAuctionSettings::create(['agency_id' => $this->agency->id, 'soft_close_minutes' => 5]);
        $a = $this->registration($this->buyer('a@example.com'));
        $b = $this->registration($this->buyer('b@example.com'));

        $closes = $this->lot->fresh()->closes_at->copy();
        $this->engine()->place($this->lot, $a, 100000);
        $this->assertTrue($this->lot->fresh()->closes_at->eq($closes), 'a day to go: unchanged');

        $this->lot->forceFill(['closes_at' => now()->addMinutes(3)])->save();
        $before = $this->lot->fresh()->closes_at->copy();
        $this->engine()->place($this->lot, $b, 105000);
        $this->assertTrue($this->lot->fresh()->closes_at->eq($before->copy()->addMinutes(5)), 'inside the last 5 minutes: pushed out by 5');
    }

    public function test_the_auctions_own_soft_close_overrides_the_agency_setting_and_zero_switches_it_off(): void
    {
        AgencyAuctionSettings::create(['agency_id' => $this->agency->id, 'soft_close_minutes' => 5]);
        $this->auc->forceFill(['soft_close_minutes' => 0])->save();
        $this->lot->forceFill(['closes_at' => now()->addMinutes(2)])->save();
        $closes = $this->lot->fresh()->closes_at->copy();

        $this->engine()->place($this->lot, $this->registration(), 100000);

        $this->assertTrue($this->lot->fresh()->closes_at->eq($closes));
    }

    // ── auto-bids ──────────────────────────────────────────────────────────

    public function test_an_auto_bid_bids_the_minimum_then_defends_the_lead_one_increment_above_the_rival(): void
    {
        $a = $this->registration($this->buyer('a@example.com'));
        $b = $this->registration($this->buyer('b@example.com'));

        $this->engine()->setAutoBid($this->lot, $a, 200000);
        $this->assertSame(100000.0, $this->high(), 'bids only the minimum needed');
        $this->assertSame($a->id, $this->leaderReg());

        $this->engine()->place($this->lot, $b, 110000);
        $this->assertSame(115000.0, $this->high(), 'one increment above the rival');
        $this->assertSame($a->id, $this->leaderReg());

        $this->engine()->place($this->lot, $b, 190000);
        $this->assertSame(195000.0, $this->high());
        $this->assertSame($a->id, $this->leaderReg());

        $this->engine()->place($this->lot, $b, 250000);   // above A's maximum: A is beaten
        $this->assertSame(250000.0, $this->high());
        $this->assertSame($b->id, $this->leaderReg());
    }

    public function test_two_auto_bids_settle_in_one_step_the_higher_maximum_pays_one_increment_over_the_lower(): void
    {
        $a = $this->registration($this->buyer('a@example.com'));
        $b = $this->registration($this->buyer('b@example.com'));

        $this->engine()->setAutoBid($this->lot, $a, 200000);
        $this->engine()->setAutoBid($this->lot, $b, 300000);

        $this->assertSame($b->id, $this->leaderReg());
        $this->assertSame(205000.0, $this->high(), 'A\'s 200 000 + one 5 000 increment, not B\'s 300 000');
        $this->assertLessThanOrEqual(4, AuctionBid::where('lot_id', $this->lot->id)->count(), 'resolved directly, not bid-for-bid');
    }

    public function test_a_bid_that_exactly_matches_a_rivals_maximum_leaves_the_bidder_who_placed_it_in_front(): void
    {
        $a = $this->registration($this->buyer('a@example.com'));
        $b = $this->registration($this->buyer('b@example.com'));
        $this->engine()->setAutoBid($this->lot, $a, 200000);
        $this->engine()->place($this->lot, $b, 190000);   // A counters at 195 000

        $this->engine()->place($this->lot, $b, 200000);

        $this->assertSame(200000.0, $this->high());
        $this->assertSame($b->id, $this->leaderReg(), 'a tie on price goes to the bid actually placed at that price');
    }

    public function test_an_auto_bid_maximum_must_clear_the_current_minimum(): void
    {
        $a = $this->registration($this->buyer('a@example.com'));
        $b = $this->registration($this->buyer('b@example.com'));
        $this->engine()->place($this->lot, $a, 120000);

        $this->expectRejection(fn () => $this->engine()->setAutoBid($this->lot, $b, 124000), 'at least R 125 000');
        $this->expectRejection(fn () => $this->engine()->setAutoBid($this->lot, $a, 120000), 'higher than your current bid');
        $this->engine()->setAutoBid($this->lot, $a, 180000);   // the leader may raise the ceiling without bidding
        $this->assertSame(120000.0, $this->high());
    }

    public function test_an_auto_bid_can_be_cancelled(): void
    {
        $a = $this->registration($this->buyer('a@example.com'));
        $b = $this->registration($this->buyer('b@example.com'));
        $this->engine()->setAutoBid($this->lot, $a, 200000);
        $this->engine()->cancelAutoBid($this->lot, $a);

        $this->engine()->place($this->lot, $b, 110000);

        $this->assertSame($b->id, $this->leaderReg(), 'no auto-bid left to counter');
    }

    // ── retraction ─────────────────────────────────────────────────────────

    public function test_retracting_the_top_bid_hands_the_lead_back_and_switches_off_that_bidders_auto_bid(): void
    {
        $a = $this->registration($this->buyer('a@example.com'));
        $b = $this->registration($this->buyer('b@example.com'));
        $this->engine()->place($this->lot, $a, 100000);
        $this->engine()->setAutoBid($this->lot, $b, 400000);   // B leads, e.g. at 105 000
        $top = $this->lot->fresh()->highestBid();
        $this->assertSame($b->id, (int) $top->registration_id);

        $this->engine()->retract($top, $this->admin, 'Bidder typed the wrong amount');

        $this->assertSame('retracted', $top->fresh()->status);
        $this->assertSame($a->id, $this->leaderReg(), 'back to the next valid bid');
        $this->assertSame(0, \App\Models\AuctionAutoBid::withoutGlobalScopes()->whereNull('deleted_at')->where('registration_id', $b->id)->count(), 'the engine must not simply bid again for them');
        $this->assertSame('Bidder typed the wrong amount', $top->fresh()->retract_reason);
    }

    public function test_a_retraction_needs_a_reason_and_a_lot_that_has_not_finished(): void
    {
        $a = $this->registration();
        $bid = $this->engine()->place($this->lot, $a, 100000);

        $this->expectRejection(fn () => $this->engine()->retract($bid, $this->admin, '  '), 'reason');

        $this->lot->forceFill(['status' => AuctionLot::STATUS_SOLD])->save();
        $this->expectRejection(fn () => $this->engine()->retract($bid, $this->admin, 'Too late'), 'finished');
        $this->assertSame('valid', $bid->fresh()->status);
    }

    // ── what the public sees ───────────────────────────────────────────────

    public function test_the_public_state_never_contains_the_reserve_or_bidder_identities(): void
    {
        $a = $this->registration($this->buyer('a@example.com'));
        $this->engine()->place($this->lot, $a, 100000);

        $state = $this->engine()->state($this->lot->fresh());
        $json = json_encode($state);

        $this->assertStringNotContainsString('150000', $json);
        $this->assertStringNotContainsString('a@example.com', $json);
        $this->assertArrayNotHasKey('leader_number', $state);
        $this->assertSame('not_met', $state['reserve']);
        $this->assertSame(100000.0, $state['current_bid']);
        $this->assertSame('open', $state['phase']);
    }

    public function test_reserve_met_and_the_agency_switches_follow_the_settings(): void
    {
        AgencyAuctionSettings::create(['agency_id' => $this->agency->id, 'reserve_visibility' => 'hidden', 'show_bid_count' => false, 'show_bidder_numbers' => true]);
        $a = $this->registration($this->buyer('a@example.com'));
        $this->engine()->place($this->lot, $a, 100000);
        $b = $this->registration($this->buyer('b@example.com'));
        $this->engine()->place($this->lot, $b, 160000);

        $state = $this->engine()->state($this->lot->fresh());
        $this->assertNull($state['reserve'], 'agency chose to say nothing about the reserve');
        $this->assertArrayNotHasKey('bid_count', $state);
        $this->assertSame($b->bidder_number, $state['leader_number'], 'agency chose to show bidder numbers');

        AgencyAuctionSettings::where('agency_id', $this->agency->id)->update(['reserve_visibility' => 'met_not_met']);
        $this->assertSame('met', $this->engine()->state($this->lot->fresh())['reserve']);
    }

    public function test_the_state_tells_a_bidder_whether_they_are_winning_or_outbid(): void
    {
        $a = $this->registration($this->buyer('a@example.com'));
        $b = $this->registration($this->buyer('b@example.com'));
        $c = $this->registration($this->buyer('c@example.com'));
        $this->engine()->place($this->lot, $a, 100000);
        $this->engine()->place($this->lot, $b, 105000);

        $lot = $this->lot->fresh();
        $this->assertSame('winning', $this->engine()->state($lot, $b)['you']);
        $this->assertSame('outbid', $this->engine()->state($lot, $a)['you']);
        $this->assertSame('none', $this->engine()->state($lot, $c)['you']);
    }

    // ── the lot is locked once there are bids ─────────────────────────────

    public function test_once_there_are_bids_the_window_opening_bid_and_reserve_cannot_change(): void
    {
        $this->engine()->place($this->lot, $this->registration(), 100000);
        $service = app(AuctionLotService::class);
        $property = $this->lot->property->fresh();

        $this->expectRejection(fn () => $service->saveForProperty($property, ['auction_id' => $this->auc->id, 'opening_bid' => 90000, 'reserve_price' => 150000], $this->admin), 'already has bids');
        $this->expectRejection(fn () => $service->saveForProperty($property, ['auction_id' => $this->auc->id, 'opening_bid' => 100000, 'reserve_price' => 175000], $this->admin), 'already has bids');

        // the guide price and the description are still editable
        $lot = $service->saveForProperty($property, [
            'auction_id' => $this->auc->id, 'opening_bid' => 100000, 'reserve_price' => 150000,
            'opens_at' => $this->lot->windowOpensAt()->format('Y-m-d\TH:i'), 'closes_at' => $this->lot->fresh()->windowClosesAt()->format('Y-m-d\TH:i'),
            'guide_price_min' => 120000, 'guide_price_max' => 160000,
        ], $this->admin);
        $this->assertSame('120000.00', $lot->guide_price_min);
    }
}
