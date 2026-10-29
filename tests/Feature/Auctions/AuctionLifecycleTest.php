<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Models\Auction;
use App\Models\AuctionDocument;
use App\Models\AuctionLot;
use App\Models\Property;
use App\Services\Auctions\AuctionLifecycleService;
use App\Services\Auctions\AuctionLotService;
use Illuminate\Validation\ValidationException;

final class AuctionLifecycleTest extends AuctionsV2TestCase
{
    private function lifecycle(): AuctionLifecycleService
    {
        return app(AuctionLifecycleService::class);
    }

    private function lotIn(Auction $auction, string $title = 'House'): AuctionLot
    {
        return app(AuctionLotService::class)->saveForProperty($this->property($title), ['auction_id' => $auction->id], $this->admin);
    }

    public function test_publishing_needs_a_lot_a_ladder_and_dates(): void
    {
        $empty = $this->auction();
        try {
            $this->lifecycle()->publish($empty);
            $this->fail('an auction with no lots must not publish');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('lot', strtolower($e->errors()['status'][0]));
        }

        $noDate = $this->auction(['reference' => 'AUC-T-ND', 'starts_at' => null]);
        $this->lotIn($noDate);
        $this->expectException(ValidationException::class);
        $this->lifecycle()->publish($noDate);
    }

    public function test_publishing_publishes_the_lots_and_marks_each_property_on_auction(): void
    {
        $auction = $this->auction();
        $lot = $this->lotIn($auction);

        $this->lifecycle()->publish($auction);

        $this->assertSame('published', $auction->fresh()->status);
        $this->assertNotNull($auction->fresh()->published_at);
        $this->assertSame('published', $lot->fresh()->status);
        $p = $lot->property->fresh();
        $this->assertSame(Property::STATUS_ON_AUCTION, $p->status);
        $this->assertSame('active', $p->pre_auction_status);
    }

    public function test_only_a_draft_can_be_published(): void
    {
        $auction = $this->auction(['status' => 'published']);
        $this->expectException(ValidationException::class);
        $this->lifecycle()->publish($auction);
    }

    public function test_cancelling_withdraws_every_lot_and_restores_the_properties(): void
    {
        $auction = $this->auction();
        $lot = $this->lotIn($auction);
        $this->lifecycle()->publish($auction);

        $this->lifecycle()->cancel($auction->fresh(), 'Venue unavailable');

        $this->assertSame('cancelled', $auction->fresh()->status);
        $this->assertSame('Venue unavailable', $auction->fresh()->cancel_reason);
        $this->assertSame('withdrawn', $lot->fresh()->status);
        $p = $lot->property->fresh();
        $this->assertSame('active', $p->status, 'back to the status it had before the auction');
        $this->assertFalse($p->isAuction());
    }

    public function test_completing_needs_every_lot_to_have_a_result(): void
    {
        $auction = $this->auction();
        $lot = $this->lotIn($auction);
        $this->lifecycle()->publish($auction);

        try {
            $this->lifecycle()->complete($auction->fresh());
            $this->fail('a live lot blocks completion');
        } catch (ValidationException) {
            // expected
        }

        $lot->forceFill(['status' => AuctionLot::STATUS_PASSED_IN])->save();
        $this->lifecycle()->complete($auction->fresh());
        $this->assertSame('completed', $auction->fresh()->status);
    }

    public function test_clone_copies_the_ladder_and_documents_but_not_lots_or_dates(): void
    {
        $auction = $this->auction();
        $this->lotIn($auction);
        AuctionDocument::create(['agency_id' => $this->agency->id, 'auction_id' => $auction->id, 'kind' => 'conditions_of_sale', 'name' => 'Conditions', 'path' => 'x/y.pdf', 'visibility' => 'public']);

        $copy = $this->lifecycle()->cloneAuction($auction->fresh(), $this->admin->id);

        $this->assertSame('draft', $copy->status);
        $this->assertNotSame($auction->reference, $copy->reference);
        $this->assertNull($copy->starts_at);
        $this->assertSame(0, $copy->lots()->count());
        $this->assertCount(2, $copy->increments);
        $this->assertCount(1, $copy->documents);
    }

    public function test_the_increment_for_a_price_uses_the_highest_rung_that_applies(): void
    {
        $auction = $this->auction()->load('increments');

        $this->assertSame(5000.0, $auction->incrementFor(750000));
        $this->assertSame(25000.0, $auction->incrementFor(1000000));
        $this->assertSame(25000.0, $auction->incrementFor(3000000));
    }

    public function test_references_are_per_agency_sequential_and_never_reused(): void
    {
        $a = Auction::nextReference($this->agency->id);
        $this->assertMatchesRegularExpression('/^AUC-\d{4}-0001$/', $a);

        $first = $this->auction(['reference' => $a]);
        $first->delete();   // archived rows still count
        $this->assertMatchesRegularExpression('/^AUC-\d{4}-0002$/', Auction::nextReference($this->agency->id));
    }
}
