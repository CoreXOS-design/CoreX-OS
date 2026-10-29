<?php

namespace App\Listeners\Auction;

use App\Events\Auction\AuctionLotSold;
use App\Services\Auctions\AuctionDealFactory;
use Illuminate\Support\Facades\Log;

/**
 * AuctionLotSold -> open the Deal. Synchronous on purpose (a queued listener cannot restore a domain event's readonly
 * eventId). A failure — usually "the property has no linked seller yet" — never undoes the sale: it is logged and the lot
 * page offers "Open deal" to retry once the seller has been added.
 */
class CreateDealOnLotSold
{
    public function handle(AuctionLotSold $event): void
    {
        $lot = $event->lot->fresh();
        if (! $lot || $lot->deal_id !== null) {
            return;   // idempotent: running twice opens one deal
        }

        try {
            app(AuctionDealFactory::class)->createFromSoldLot($lot);
        } catch (\Throwable $e) {
            Log::warning('Auction deal not opened automatically: ' . $e->getMessage(), ['lot' => $lot->id]);
        }
    }
}
