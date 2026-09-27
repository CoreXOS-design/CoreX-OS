<?php

namespace App\Services\CommandCenter\Calendar\Sources;

use App\Contracts\CalendarSourceContract;
use App\Models\Auction;
use App\Models\AuctionLot;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * AT-432 — .ai/specs/auctions.md §16. Lights up the 3 auction event
 * classes computable from Phase 1 data:
 *   auction_date                  — auctions.starts_at
 *   auction_registration_closes   — auctions.registration_closes_at
 *   auction_confirmation_deadline — auction_lots.confirmation_deadline
 *                                   (only while still awaiting the
 *                                   seller's decision — confirmed_at null)
 *
 * NOT yet emitted (§16's other three classes need tables/data Phase 1
 * does not build): auction_viewing needs auction_lot_viewings (Phase 2),
 * auction_deposit_refund_due needs auction_bidders (Phase 2),
 * auction_balance_due needs the resulting Deal (Phase 3). Their vocabulary
 * is registered in CalendarEventClassSeeder now so the settings screen and
 * onboarding wizard can reference them; each source ships alongside its
 * own phase.
 *
 * Draft auctions/lots are excluded — a still-being-configured auction is
 * not yet a commitment worth putting on anyone's calendar (mirrors
 * PropertyCalendarSource's ACTIVE_STATUSES gate for mandate_expiry).
 */
class AuctionCalendarSource implements CalendarSourceContract
{
    public function name(): string
    {
        return 'AuctionCalendarSource';
    }

    public function syncAll(): Collection
    {
        return collect()
            ->merge($this->auctionDate())
            ->merge($this->registrationCloses())
            ->merge($this->confirmationDeadline());
    }

    private function auctionDate(): Collection
    {
        return Auction::withoutGlobalScopes()
            ->whereNotNull('starts_at')
            ->where('status', '!=', Auction::STATUS_DRAFT)
            ->get(['id', 'agency_id', 'branch_id', 'reference', 'title', 'starts_at', 'auctioneer_user_id'])
            ->map(fn ($a) => [
                'event_type'  => 'auction',
                'category'    => 'auction_date',
                'title'       => "Auction: {$a->title} ({$a->reference})",
                'event_date'  => Carbon::parse($a->starts_at),
                'source_type' => Auction::class,
                'source_id'   => $a->id,
                'user_id'     => $a->auctioneer_user_id,
                'agency_id'   => $a->agency_id,
                'branch_id'   => $a->branch_id,
            ]);
    }

    private function registrationCloses(): Collection
    {
        return Auction::withoutGlobalScopes()
            ->whereNotNull('registration_closes_at')
            ->where('status', '!=', Auction::STATUS_DRAFT)
            ->get(['id', 'agency_id', 'branch_id', 'reference', 'title', 'registration_closes_at', 'auctioneer_user_id'])
            ->map(fn ($a) => [
                'event_type'  => 'auction',
                'category'    => 'auction_registration_closes',
                'title'       => "Registration closes: {$a->title} ({$a->reference})",
                'event_date'  => Carbon::parse($a->registration_closes_at),
                'source_type' => Auction::class,
                'source_id'   => $a->id,
                'user_id'     => $a->auctioneer_user_id,
                'agency_id'   => $a->agency_id,
                'branch_id'   => $a->branch_id,
            ]);
    }

    private function confirmationDeadline(): Collection
    {
        return AuctionLot::withoutGlobalScopes()
            ->whereNotNull('confirmation_deadline')
            ->whereNull('confirmed_at')
            ->where('status', AuctionLot::STATUS_SOLD_SUBJECT_TO_CONFIRMATION)
            ->with(['property:id,agent_id,address,suburb', 'auction:id,branch_id'])
            ->get(['id', 'agency_id', 'auction_id', 'property_id', 'lot_number', 'confirmation_deadline'])
            ->map(fn ($lot) => [
                'event_type'  => 'auction',
                'category'    => 'auction_confirmation_deadline',
                'title'       => 'Seller confirmation due — Lot '.$lot->lot_number.($lot->property ? ' ('.$lot->property->address.')' : ''),
                'event_date'  => Carbon::parse($lot->confirmation_deadline),
                'source_type' => AuctionLot::class,
                'source_id'   => $lot->id,
                'user_id'     => $lot->property?->agent_id,
                'agency_id'   => $lot->agency_id,
                'branch_id'   => $lot->auction?->branch_id,
                'property_id' => $lot->property_id,
            ]);
    }
}
