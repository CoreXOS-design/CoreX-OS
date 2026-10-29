<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\Property;
use App\Services\AdTemplates\AdListingFacts;
use App\Services\Auctions\AuctionLotService;
use App\Services\MarketingCopyService;
use App\Services\Syndication\Property24\Property24ListingMapper;
use Carbon\Carbon;

/** What an auction property tells the outside world: the Property24 badge/date, the ad blocks and the AI copy facts. */
final class AuctionPortalFeedsTest extends AuctionsV2TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->agency->forceFill(['p24_agency_id' => '12345'])->save();   // the P24 mapper refuses an agency with no portal id
    }

    private function publishedLot(array $auction = [], array $lot = []): AuctionLot
    {
        $a = $this->auction($auction + ['venue_name' => 'The Boardwalk', 'venue_address' => 'Shelly Beach']);
        $l = app(AuctionLotService::class)->saveForProperty($this->property(), $lot + [
            'auction_id' => $a->id, 'opening_bid' => 800000, 'reserve_price' => 1100000, 'guide_price_min' => 900000, 'guide_price_max' => 1200000,
        ], $this->admin);
        $l->forceFill(['status' => AuctionLot::STATUS_PUBLISHED])->save();

        return $l->fresh();
    }

    public function test_a_timed_auction_property_gets_p24_auction_info_with_the_closing_moment(): void
    {
        $lot = $this->publishedLot(['format' => 'timed', 'ends_at' => Carbon::parse('2026-12-10 14:30:00'), 'starts_at' => Carbon::parse('2026-12-01 09:00:00')]);

        $listing = (new Property24ListingMapper())->map($lot->property->refresh(), false);

        $this->assertSame('2026-12-10T14:30:00', $listing['auctionInfo']['date']);
        $this->assertSame('The Boardwalk', $listing['auctionInfo']['venue']);
        $this->assertContains($listing['status'], ['Active', 'NewListing'], 'P24 has no Auction status; the badge comes from auctionInfo');
    }

    public function test_a_live_auction_uses_the_auction_start(): void
    {
        $lot = $this->publishedLot(['format' => 'live', 'starts_at' => Carbon::parse('2026-12-05 11:00:00'), 'ends_at' => Carbon::parse('2026-12-05 17:00:00')]);

        $this->assertSame('2026-12-05T11:00:00', (new Property24ListingMapper())->map($lot->property->refresh(), false)['auctionInfo']['date']);
    }

    public function test_a_draft_lot_or_a_private_treaty_property_gets_no_auction_info(): void
    {
        $draft = app(AuctionLotService::class)->saveForProperty($this->property('Draft one'), ['auction_id' => $this->auction()->id], $this->admin);
        $this->assertArrayNotHasKey('auctionInfo', (new Property24ListingMapper())->map($draft->property->refresh(), false));

        $plain = $this->property('Plain sale');
        $this->assertArrayNotHasKey('auctionInfo', (new Property24ListingMapper())->map($plain->refresh(), false));
    }

    public function test_ad_blocks_show_the_auction_date_and_venue_only_while_it_is_advertisable(): void
    {
        $lot = $this->publishedLot(['format' => 'live', 'starts_at' => now()->addDays(5)->setTime(11, 0), 'ends_at' => now()->addDays(5)->setTime(17, 0)]);

        $facts = AdListingFacts::for($lot->property->refresh());
        $this->assertSame(now()->addDays(5)->format('j M Y') . ' · 11:00', $facts['auction_date']);
        $this->assertSame('The Boardwalk, Shelly Beach', $facts['auction_venue']);

        $lot->forceFill(['status' => AuctionLot::STATUS_PASSED_IN])->save();
        $this->assertSame('', AdListingFacts::for($lot->property->refresh())['auction_date'], 'a finished lot is not advertised');
    }

    public function test_an_online_only_auction_without_a_venue_says_online_auction(): void
    {
        $a = $this->auction(['format' => 'timed', 'venue_name' => null, 'venue_address' => null, 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(4)]);
        $lot = app(AuctionLotService::class)->saveForProperty($this->property(), ['auction_id' => $a->id], $this->admin);
        $lot->forceFill(['status' => AuctionLot::STATUS_PUBLISHED])->save();

        $this->assertSame('Online auction', AdListingFacts::for($lot->property->refresh())['auction_venue']);
    }

    public function test_the_ai_copy_facts_carry_the_auction_details_but_never_the_reserve(): void
    {
        $lot = $this->publishedLot(['format' => 'timed', 'ends_at' => now()->addDays(9)->setTime(15, 0)]);

        $m = new \ReflectionMethod(MarketingCopyService::class, 'collectAllowedFacts');
        $m->setAccessible(true);
        $facts = $m->invoke(app(MarketingCopyService::class), $lot->property->refresh())[0];

        $this->assertStringContainsString('AUCTION listing', $facts);
        $this->assertStringContainsString('Auction date: ', $facts);
        $this->assertStringContainsString('Opening bid: R 800 000', $facts);
        $this->assertStringContainsString('Guide price: R 900 000', $facts);
        $this->assertStringNotContainsString('1 100 000', $facts, 'the reserve must never reach the AI prompt');
        $this->assertStringNotContainsString('1100000', $facts);
    }
}
