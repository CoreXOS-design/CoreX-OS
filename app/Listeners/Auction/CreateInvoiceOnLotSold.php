<?php

namespace App\Listeners\Auction;

use App\Events\Auction\AuctionLotSold;
use App\Services\Auctions\AuctionInvoiceService;
use Illuminate\Support\Facades\Log;

/**
 * AuctionLotSold -> a DRAFT bidder invoice for the winner (.ai/specs/auctions.md §17). Staff review and issue it. Idempotent: a lot
 * that already has a live invoice gets no second one. A failure never undoes the sale.
 */
class CreateInvoiceOnLotSold
{
    public function handle(AuctionLotSold $event): void
    {
        try {
            app(AuctionInvoiceService::class)->createDraftForSoldLot($event->lot);
        } catch (\Throwable $e) {
            Log::warning('Auction invoice draft not created: ' . $e->getMessage(), ['lot' => $event->lot->id]);
        }
    }
}
