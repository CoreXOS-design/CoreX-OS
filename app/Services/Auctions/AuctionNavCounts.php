<?php

namespace App\Services\Auctions;

use App\Models\AuctionBid;
use App\Models\AuctionEnquiry;
use App\Models\AuctionRegistration;
use App\Models\AuctionSellerSubmission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The numbers on the Auctions sidebar links (.ai/specs/auctions.md §19.3). Each counts what its own screen is for, and only what the person
 * is allowed to see (the same `visibleTo` scopes as the screens). They never throw: a broken count must not break the page around it.
 *
 *  - Enquiries: enquiries still "New".
 *  - Seller leads: "Sell at auction" submissions still New.
 *  - Bidders: registrations still awaiting approval (what the Bidders screen's "Awaiting approval" tab lists).
 *  - Bids: online bids and auto-bids placed since the person last opened the Bids screen (floor bids are typed in by staff, so not counted).
 */
class AuctionNavCounts
{
    public const BIDS = 'bids';

    private array $memo = [];

    public function enquiries(User $user): int
    {
        return $this->memo["enq{$user->id}"] ??= $this->safe(function () use ($user) {
            if (! $user->hasPermission('auctions.enquiries.view')) {
                return 0;
            }

            return AuctionEnquiry::visibleTo($user)->where('status', AuctionEnquiry::STATUS_NEW)->count();
        });
    }

    public function sellerLeads(User $user): int
    {
        return $this->memo["sell{$user->id}"] ??= $this->safe(function () use ($user) {
            if (! $user->hasPermission('auctions.sellers.view')) {
                return 0;
            }

            return AuctionSellerSubmission::visibleTo($user)->where('status', AuctionSellerSubmission::STATUS_NEW)->count();
        });
    }

    public function registrations(User $user): int
    {
        return $this->memo["reg{$user->id}"] ??= $this->safe(function () use ($user) {
            if (! $user->hasPermission('auctions.bidders.view')) {
                return 0;
            }

            return AuctionRegistration::visibleTo($user)->where('status', AuctionRegistration::STATUS_PENDING)->count();
        });
    }

    public function bids(User $user): int
    {
        return $this->memo["bids{$user->id}"] ??= $this->safe(function () use ($user) {
            if (! $user->hasPermission('auctions.bids.view')) {
                return 0;
            }

            $seen = DB::table('auction_screen_visits')->where('user_id', $user->id)->where('screen', self::BIDS)->value('seen_at');
            if (! $seen) {
                $this->markBidsSeen($user);   // first time: start counting from now, not from the beginning of history

                return 0;
            }

            return AuctionBid::visibleTo($user)->valid()->where('source', '!=', 'floor')->where('placed_at', '>', $seen)->count();
        });
    }

    public function total(User $user): int
    {
        return $this->enquiries($user) + $this->bids($user) + $this->registrations($user) + $this->sellerLeads($user);
    }

    /** Called when the person opens the Bids screen. */
    public function markBidsSeen(User $user): void
    {
        $this->safe(function () use ($user) {
            DB::table('auction_screen_visits')->upsert(
                [['user_id' => $user->id, 'screen' => self::BIDS, 'seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]],
                ['user_id', 'screen'],
                ['seen_at', 'updated_at'],
            );
            unset($this->memo["bids{$user->id}"]);

            return 0;
        });
    }

    private function safe(callable $f): int
    {
        try {
            return (int) $f();
        } catch (\Throwable $e) {
            Log::warning('Auction sidebar count failed: ' . $e->getMessage());

            return 0;
        }
    }
}
