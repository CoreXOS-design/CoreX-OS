<?php

namespace App\Services\Auctions;

use App\Models\AuctionBid;
use App\Models\AuctionLot;
use App\Models\AuctionRegistration;
use App\Models\Deal;
use App\Models\DealV2\DealPipelineTemplate;
use App\Services\Deal\DealPropertyOwnerGate;
use App\Services\Deal\Dr1PipelineService;
use Illuminate\Support\Facades\DB;

/**
 * Opens the Deal (pillar) for a sold lot (.ai/specs/auctions.md §8.6), through the same model, agent pivot and pipeline
 * service the Deal Register uses — never a parallel writer. The deal carries the property, the hammer price as the property
 * value, the seller(s) from the property, the winning bidder as buyer and the listing agent.
 *

 * The deal's commission is the buyer's commission INCLUDING VAT (the Deal Register's own convention), worked out by the same
 * AuctionFeeCalculator that prices the bidder invoice; it splits with the listing agent like any other commission. An agency
 * that charges no buyer's commission gets 0, to be captured on the deal as for any other sale.
 *
 * Refuses (and the lot page offers "Open deal" to retry) when the property has no linked seller — the same "no deal
 * without an owner" gate a manually captured deal must pass.
 */
class AuctionDealFactory
{
    public function __construct(
        private readonly DealPropertyOwnerGate $ownerGate,
        private readonly Dr1PipelineService $pipelineService,
        private readonly AuctionFeeCalculator $fees,
    ) {}

    /**
     * @throws \App\Exceptions\Deal\PropertyOwnerMismatchException when the property has no linked seller
     * @throws \RuntimeException when the lot is not sold, or already has a deal
     */
    public function createFromSoldLot(AuctionLot $lot, ?int $actorId = null): Deal
    {
        if ($lot->status !== AuctionLot::STATUS_SOLD) {
            throw new \RuntimeException("Lot {$lot->lot_number} is not sold yet, so there is no deal to open.");
        }
        if ($lot->deal_id !== null) {
            throw new \RuntimeException("Lot {$lot->lot_number} already has a deal.");
        }
        $property = $lot->property()->withoutGlobalScopes()->first();
        if (! $property) {
            throw new \RuntimeException("Lot {$lot->lot_number} has no property.");
        }
        $this->ownerGate->assertHasKnownOwner($property);

        $agencyId = (int) $lot->agency_id;
        $bid = $lot->winning_bid_id ? AuctionBid::withoutGlobalScopes()->find($lot->winning_bid_id) : null;
        $winner = $bid ? AuctionRegistration::withoutGlobalScopes()->with('contact')->find($bid->registration_id) : null;
        $buyerName = $winner?->bidder_type === 'company' ? $winner->company_name : $winner?->contact?->full_name;

        $auction = \App\Models\Auction::withTrashed()->withoutGlobalScopes()->with('increments')->find($lot->auction_id);
        $commission = $auction ? $this->fees->dealCommission($auction, (float) $lot->hammer_price) : 0.0;

        return DB::transaction(function () use ($lot, $property, $agencyId, $buyerName, $actorId, $commission) {
            $sellers = $property->contacts()->wherePivotIn('role', ['seller', 'co_seller', 'landlord'])->wherePivotNull('deleted_at')->get();

            $deal = new Deal();
            $deal->deal_no = $this->nextDealNo($agencyId);
            $deal->fill([
                'agency_id' => $agencyId,
                'branch_id' => $property->branch_id,
                'period' => now()->format('Y-m'),
                'deal_date' => now()->toDateString(),
                'deal_type' => 'cash',
                'property_id' => $property->id,
                'property_address' => $property->address,
                'property_value' => (float) $lot->hammer_price,
                'total_commission' => $commission,
                'listing_split_percent' => 100,
                'selling_split_percent' => 0,
                'listing_external' => false,
                'listing_our_share_percent' => 100,
                'selling_external' => false,
                'selling_our_share_percent' => 100,
                'seller_name' => $sellers->pluck('full_name')->filter()->implode(', ') ?: null,
                'buyer_name' => $buyerName,
                'accepted_status' => 'P',
                'commission_status' => 'Not Paid',
                'link_source' => 'manual',
                'link_confidence' => 'exact',
                'remarks' => sprintf('Sold at auction (Lot %d) for R %s.', $lot->lot_number, number_format((float) $lot->hammer_price, 0, '.', ' '))
                    . ($commission > 0 ? sprintf(" Buyer's commission R %s (incl. VAT).", number_format($commission, 2, '.', ' ')) : ' No buyer\'s commission was charged: capture the commission here if one applies.'),
                'created_by_id' => $actorId,
            ]);
            $deal->save();

            if ($property->agent_id) {
                $deal->agents()->attach($property->agent_id, ['side' => 'listing', 'agent_split_percent' => 100]);
            }

            $templateId = DealPipelineTemplate::query()->where('agency_id', $agencyId)->where('deal_type', 'cash')->orderByDesc('is_default')->value('id');
            if ($templateId) {
                $this->pipelineService->createPipeline($deal, (int) $templateId);
            }

            $lot->forceFill(['deal_id' => $deal->id])->save();

            return $deal;
        });
    }

    /** Same numbering as the Deal Register (D-123 and plain numbers), counted within this agency. */
    private function nextDealNo(int $agencyId): string
    {
        $base = fn () => Deal::query()->where('agency_id', $agencyId);

        $plain = (int) $base()->whereRaw("deal_no NOT LIKE 'D-%'")->whereRaw("deal_no REGEXP '^[0-9]+$'")->max('deal_no');
        $prefixed = (int) $base()->selectRaw('MAX(CAST(SUBSTR(deal_no, 3) AS UNSIGNED)) as m')->where('deal_no', 'like', 'D-%')->value('m');
        $max = max($plain, $prefixed, 0);

        return (string) ($max <= 0 ? 1001 : $max + 1);
    }
}
