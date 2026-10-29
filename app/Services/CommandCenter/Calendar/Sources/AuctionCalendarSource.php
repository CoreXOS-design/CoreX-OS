<?php

namespace App\Services\CommandCenter\Calendar\Sources;

use App\Contracts\CalendarSourceContract;
use App\Models\Auction;
use App\Models\AuctionLot;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Auctions on the calendar (.ai/specs/auctions.md §10):
 *   auction_date                   the auction's start
 *   auction_registration_closes    the bidder-registration deadline
 *   auction_confirmation_deadline  the seller's deadline on a lot sold below its reserve (only while still undecided)
 *
 * Draft and cancelled auctions are not a commitment worth putting on anyone's calendar, so they are left out.
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

    private function onCalendar()
    {
        return Auction::withoutGlobalScopes()->whereNull('deleted_at')
            ->whereNotIn('status', [Auction::STATUS_DRAFT, Auction::STATUS_CANCELLED]);
    }

    private function auctionDate(): Collection
    {
        return $this->onCalendar()->whereNotNull('starts_at')
            ->get(['id', 'agency_id', 'branch_id', 'reference', 'title', 'starts_at', 'created_by'])
            ->map(fn ($a) => [
                'event_type' => 'auction',
                'category' => 'auction_date',
                'title' => "Auction: {$a->title} ({$a->reference})",
                'event_date' => Carbon::parse($a->starts_at),
                'source_type' => Auction::class,
                'source_id' => $a->id,
                'user_id' => $a->created_by,
                'agency_id' => $a->agency_id,
                'branch_id' => $a->branch_id,
            ]);
    }

    private function registrationCloses(): Collection
    {
        return $this->onCalendar()->whereNotNull('registration_closes_at')
            ->get(['id', 'agency_id', 'branch_id', 'reference', 'title', 'registration_closes_at', 'created_by'])
            ->map(fn ($a) => [
                'event_type' => 'auction',
                'category' => 'auction_registration_closes',
                'title' => "Registration closes: {$a->title} ({$a->reference})",
                'event_date' => Carbon::parse($a->registration_closes_at),
                'source_type' => Auction::class,
                'source_id' => $a->id,
                'user_id' => $a->created_by,
                'agency_id' => $a->agency_id,
                'branch_id' => $a->branch_id,
            ]);
    }

    private function confirmationDeadline(): Collection
    {
        return AuctionLot::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('status', AuctionLot::STATUS_SOLD_SUBJECT)->whereNull('confirmed_at')->whereNotNull('confirmation_deadline')
            ->with(['property' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'agent_id', 'address', 'branch_id'), 'auction' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'branch_id')])
            ->get()
            ->map(fn ($lot) => [
                'event_type' => 'auction',
                'category' => 'auction_confirmation_deadline',
                'title' => 'Seller confirmation due — Lot ' . $lot->lot_number . ($lot->property ? ' (' . $lot->property->address . ')' : ''),
                'event_date' => Carbon::parse($lot->confirmation_deadline),
                'source_type' => AuctionLot::class,
                'source_id' => $lot->id,
                'user_id' => $lot->property?->agent_id,
                'agency_id' => $lot->agency_id,
                'branch_id' => $lot->property?->branch_id ?? $lot->auction?->branch_id,
                'property_id' => $lot->property_id,
            ]);
    }
}
