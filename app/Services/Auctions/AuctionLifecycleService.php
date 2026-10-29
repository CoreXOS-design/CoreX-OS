<?php

namespace App\Services\Auctions;

use App\Events\Auction\AuctionCancelled;
use App\Events\Auction\AuctionCompleted;
use App\Events\Auction\AuctionPublished;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\Property;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Auction status changes (.ai/specs/auctions.md §4.1): publish, cancel, complete, clone.
 * Phase 1 covers draft → published and the exits; `open`/`closed` arrive with the bidding engine (phase 3).
 */
class AuctionLifecycleService
{
    public function __construct(private AuctionLotService $lots) {}

    /** Make the auction (and its lots) visible on the public auction site and mark each property On Auction. */
    public function publish(Auction $auction, ?int $byUserId = null): Auction
    {
        if ($auction->status !== Auction::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'Only a draft auction can be published.']);
        }

        $problems = [];
        if (! $auction->starts_at) {
            $problems[] = 'Set when the auction starts.';
        }
        if ($auction->format === 'timed' && ! $auction->ends_at) {
            $problems[] = 'A timed auction needs a closing date and time.';
        }
        if ($auction->starts_at && $auction->ends_at && $auction->ends_at->lte($auction->starts_at)) {
            $problems[] = 'The auction must end after it starts.';
        }
        if (! $auction->increments()->exists()) {
            $problems[] = 'Add at least one bid increment.';
        }
        if (! $auction->lots()->live()->exists()) {
            $problems[] = 'Add at least one lot.';
        }
        if ($problems) {
            throw ValidationException::withMessages(['status' => implode(' ', $problems)]);
        }

        $auction = DB::transaction(function () use ($auction) {
            foreach ($auction->lots()->live()->where('status', AuctionLot::STATUS_DRAFT)->with('property')->get() as $lot) {
                $lot->forceFill(['status' => AuctionLot::STATUS_PUBLISHED])->save();
                $this->markOnAuction($lot->property);
            }
            $auction->forceFill(['status' => Auction::STATUS_PUBLISHED, 'published_at' => now()])->save();

            return $auction;
        });
        AuctionPublished::dispatch($auction, $byUserId);

        return $auction;
    }

    /** Called when a lot joins an auction that is already published, so its property is marked too. */
    public function markOnAuction(?Property $property): void
    {
        if (! $property || $property->status === Property::STATUS_ON_AUCTION) {
            return;
        }
        $property->pre_auction_status = $property->status;
        $property->status = Property::STATUS_ON_AUCTION;
        $property->save();
    }

    public function cancel(Auction $auction, ?string $reason, ?int $byUserId = null): Auction
    {
        if (in_array($auction->status, [Auction::STATUS_COMPLETED, Auction::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages(['status' => 'This auction is already ' . strtolower($auction->statusLabel()) . '.']);
        }

        $auction = DB::transaction(function () use ($auction, $reason) {
            foreach ($auction->lots()->live()->with('property')->get() as $lot) {
                $this->lots->withdraw($lot, 'Auction cancelled');
            }
            $auction->forceFill([
                'status' => Auction::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancel_reason' => $reason !== null ? mb_substr(trim($reason), 0, 300) : null,
            ])->save();

            return $auction;
        });
        AuctionCancelled::dispatch($auction, $byUserId);

        return $auction;
    }

    /** Close the books once every lot has a result. */
    public function complete(Auction $auction, ?int $byUserId = null): Auction
    {
        if (! in_array($auction->status, [Auction::STATUS_PUBLISHED, Auction::STATUS_OPEN, Auction::STATUS_CLOSED], true)) {
            throw ValidationException::withMessages(['status' => 'Only a published auction can be completed.']);
        }
        if ($auction->lots()->live()->exists()) {
            throw ValidationException::withMessages(['status' => 'Every lot needs a result (sold, passed in or withdrawn) before the auction can be completed.']);
        }

        $auction->forceFill(['status' => Auction::STATUS_COMPLETED, 'completed_at' => now()])->save();
        AuctionCompleted::dispatch($auction, $byUserId);

        return $auction;
    }

    /** A new draft with the same details, bid ladder and documents — no lots, no dates in the past. */
    public function cloneAuction(Auction $auction, int $userId): Auction
    {
        return DB::transaction(function () use ($auction, $userId) {
            $copy = $auction->replicate(['reference', 'status', 'published_at', 'cancelled_at', 'cancel_reason', 'completed_at', 'is_single_property', 'starts_at', 'ends_at', 'registration_closes_at']);
            $copy->reference = Auction::nextReference((int) $auction->agency_id);
            $copy->title = mb_substr($auction->title . ' (copy)', 0, 200);
            $copy->status = Auction::STATUS_DRAFT;
            $copy->created_by = $userId;
            $copy->save();

            $copy->syncIncrements($auction->increments->map(fn ($i) => [$i->from_amount, $i->increment])->all());
            foreach ($auction->documents as $doc) {
                $doc->replicate()->forceFill(['auction_id' => $copy->id])->save();
            }

            return $copy;
        });
    }
}
