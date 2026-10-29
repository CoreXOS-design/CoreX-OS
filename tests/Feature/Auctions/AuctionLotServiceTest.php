<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Models\Auction;
use App\Models\AuctionLot;
use App\Services\Auctions\AuctionLotService;
use Illuminate\Validation\ValidationException;

final class AuctionLotServiceTest extends AuctionsV2TestCase
{
    private function service(): AuctionLotService
    {
        return app(AuctionLotService::class);
    }

    public function test_a_one_property_auction_is_created_from_the_bidding_window(): void
    {
        $p = $this->property();

        $lot = $this->service()->saveForProperty($p, [
            'opens_at' => now()->addDays(3)->toDateTimeString(), 'closes_at' => now()->addDays(5)->toDateTimeString(),
            'opening_bid' => 900000, 'reserve_price' => 1200000,
        ], $this->admin);

        $this->assertTrue($lot->auction->is_single_property);
        $this->assertSame(1, $lot->lot_number);
        $this->assertSame('draft', $lot->status);
        $this->assertSame($p->title, $lot->auction->title);
        $this->assertCount(4, $lot->auction->increments, 'the agency default ladder is copied in');
        $this->assertTrue($p->fresh()->isAuction());
    }

    public function test_the_tab_and_the_lots_screen_edit_the_same_single_lot(): void
    {
        $p = $this->property();
        $auction = $this->auction();

        $first = $this->service()->saveForProperty($p, ['auction_id' => $auction->id, 'opening_bid' => 500000], $this->admin);
        $second = $this->service()->saveForProperty($p->fresh(), ['auction_id' => $auction->id, 'opening_bid' => 600000], $this->admin);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, AuctionLot::where('property_id', $p->id)->count());
        $this->assertSame('600000.00', $second->fresh()->opening_bid);
    }

    public function test_bidding_must_close_after_it_opens(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->saveForProperty($this->property(), [
            'opens_at' => now()->addDays(5)->toDateTimeString(), 'closes_at' => now()->addDays(3)->toDateTimeString(),
        ], $this->admin);
    }

    public function test_the_reserve_cannot_be_below_the_opening_bid(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->saveForProperty($this->property(), [
            'auction_id' => $this->auction()->id, 'opening_bid' => 800000, 'reserve_price' => 700000,
        ], $this->admin);
    }

    public function test_a_lot_window_must_sit_inside_a_multi_property_auction(): void
    {
        $auction = $this->auction(['starts_at' => now()->addDays(10), 'ends_at' => now()->addDays(12)]);

        $this->expectException(ValidationException::class);
        $this->service()->saveForProperty($this->property(), [
            'auction_id' => $auction->id,
            'opens_at' => now()->addDays(9)->toDateTimeString(), 'closes_at' => now()->addDays(11)->toDateTimeString(),
        ], $this->admin);
    }

    public function test_a_single_property_with_no_auction_and_no_dates_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->saveForProperty($this->property(), [], $this->admin);
    }

    public function test_lot_numbers_run_on_and_are_never_reused(): void
    {
        $auction = $this->auction();
        $a = $this->service()->saveForProperty($this->property('One'), ['auction_id' => $auction->id], $this->admin);
        $b = $this->service()->saveForProperty($this->property('Two'), ['auction_id' => $auction->id], $this->admin);
        $b->delete();
        $c = $this->service()->saveForProperty($this->property('Three'), ['auction_id' => $auction->id], $this->admin);

        $this->assertSame([1, 2, 3], [$a->lot_number, $b->lot_number, $c->lot_number]);
    }

    public function test_withdrawing_releases_the_property_for_another_auction(): void
    {
        $p = $this->property();
        $lot = $this->service()->saveForProperty($p, ['auction_id' => $this->auction()->id], $this->admin);

        $this->service()->withdraw($lot, 'Seller changed their mind');

        $this->assertSame('withdrawn', $lot->fresh()->status);
        $this->assertNull($p->fresh()->currentAuctionLot());
        $this->assertFalse($p->fresh()->isAuction());

        $again = $this->service()->saveForProperty($p->fresh(), ['auction_id' => $this->auction(['reference' => 'AUC-T-2'])->id], $this->admin);
        $this->assertNotSame($lot->id, $again->id);
    }

    public function test_a_published_lot_cannot_be_moved_to_another_auction(): void
    {
        $p = $this->property();
        $lot = $this->service()->saveForProperty($p, ['auction_id' => $this->auction()->id], $this->admin);
        $lot->forceFill(['status' => AuctionLot::STATUS_PUBLISHED])->save();
        $other = $this->auction(['reference' => 'AUC-T-3']);

        $this->expectException(ValidationException::class);
        $this->service()->saveForProperty($p->fresh(), ['auction_id' => $other->id], $this->admin);
    }

    public function test_a_finished_or_cancelled_auction_takes_no_new_lots(): void
    {
        $auction = $this->auction(['status' => Auction::STATUS_CANCELLED]);

        $this->expectException(ValidationException::class);
        $this->service()->saveForProperty($this->property(), ['auction_id' => $auction->id], $this->admin);
    }
}
