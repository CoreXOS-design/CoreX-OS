<?php

namespace App\Listeners\Auction;

use App\Events\Auction\AuctionLotSold;
use App\Services\Auctions\AuctionDealFactory;
use Illuminate\Support\Facades\Log;

/**
 * AT-432 Phase 3 — .ai/specs/auctions.md §17: AuctionLotSold's known
 * subscribers include "Deal creation". Sync (never queued — a queued
 * listener on a domain event fatals, per corex-domain-events-spec.md and
 * this codebase's own memory of that exact incident: AbstractDomainEvent's
 * parent readonly $eventId cannot be restored from the child scope on
 * deserialisation).
 *
 * Prevent-or-absorb: never let a failed deal-open (most commonly, the
 * property has no linked seller contact yet — DealPropertyOwnerGate) break
 * the fall-of-hammer transaction that already committed. The lot detail
 * page's "Open Deal" action (AuctionLotController::openDeal) is the
 * manual retry path for exactly this failure — see its own docblock.
 */
class CreateDealOnLotSold
{
    public function handle(AuctionLotSold $event): void
    {
        try {
            app(AuctionDealFactory::class)->createFromSoldLot($event->lot, $event->actorId);
        } catch (\Throwable $e) {
            Log::warning('CreateDealOnLotSold: automatic deal creation failed — use the lot page\'s Open Deal action to retry', [
                'auction_lot_id' => $event->lot->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
