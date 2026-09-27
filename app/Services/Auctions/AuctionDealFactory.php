<?php

namespace App\Services\Auctions;

use App\Exceptions\Deal\PropertyOwnerMismatchException;
use App\Models\AgencyAuctionSettings;
use App\Models\AuctionLot;
use App\Models\Deal;
use App\Models\DealV2\DealPipelineTemplate;
use App\Services\Deal\DealPropertyOwnerGate;
use App\Services\Deal\Dr1PipelineService;
use Illuminate\Support\Facades\DB;

/**
 * AT-432 Phase 3 — .ai/specs/auctions.md §12.2. Opens a Deal on a sold lot
 * through the SAME model, agent-pivot shape, and pipeline service the real
 * DR2 register (App\Http\Controllers\Dr2\DealRegisterController) uses —
 * never a parallel writer or a second commission calculation.
 *
 * IMPORTANT — research finding this build corrected mid-session: DealV2
 * (App\Models\DealV2\DealV2, App\Http\Controllers\DealV2\DealV2Controller)
 * is a RETIRED, soft-sunset prototype (AT-219) — every render entry point
 * redirects away. "DR2" in CURRENT terminology means
 * App\Http\Controllers\Dr2\DealRegisterController operating on the
 * legacy App\Models\Deal — confirmed by reading that controller's own
 * imports and store() method. This factory targets Deal, not DealV2.
 * DealV2's pipeline-template/step-instance tables WERE salvaged and are
 * genuinely still live — Dr1PipelineService (reused below) drives DR1
 * deals with them.
 *
 * Deliberately simpler than DealRegisterController::persistDeal() (180+
 * lines covering multi-property, external-agency firms, per-agent %
 * overrides, grant-conflict blocking): an auction lot is always exactly
 * one property, and the winning bidder — not any of our agents — sourced
 * the buyer, so there is no "selling agency" complexity to resolve. Those
 * are genuine simplifications for this narrower case, not gaps against
 * the general one.
 *
 * OPEN BUSINESS QUESTION (flagged, not resolved by this build): the spec
 * calls buyer's premium "agency income" (§12.2), which reads as NOT
 * splittable with the listing agent the way ordinary commission is — but
 * the existing commission engine (GenerateCommissionLedgerEntries →
 * CommissionCalculationService) has no "0% to agent" mode; it always
 * splits total_commission by the agents() pivot's agent_split_percent.
 * This factory's default, pending Johan's confirmation: buyer's premium
 * (and/or seller's commission, per fee_model) is folded into
 * total_commission and DOES split with the listing agent normally, same
 * as any other commission. If the answer is "no, 100% agency", the fix is
 * localised: attach the agent at 0% and let commissionExVat()'s pool stay
 * with the agency side — see createDealAgents() below.
 */
class AuctionDealFactory
{
    public function __construct(
        private readonly DealPropertyOwnerGate $ownerGate,
        private readonly Dr1PipelineService $pipelineService,
    ) {
    }

    /**
     * @throws PropertyOwnerMismatchException when the property has no
     *         linked seller — "there cannot be a deal without an owner"
     *         (DealPropertyOwnerGate), exactly the same gate a manually
     *         captured deal must pass.
     * @throws \RuntimeException when the lot is not actually sold, or the
     *         agency has no default deal_type='cash' pipeline template
     */
    public function createFromSoldLot(AuctionLot $lot, ?int $actorId = null): Deal
    {
        if (! in_array($lot->status, [AuctionLot::STATUS_SOLD], true)) {
            throw new \RuntimeException("Lot #{$lot->id} is not sold (status: {$lot->status}) — a deal cannot be opened yet.");
        }
        if ($lot->deal_id !== null) {
            throw new \RuntimeException("Lot #{$lot->id} already carries deal #{$lot->deal_id} — refusing to double-open.");
        }

        $property = $lot->property;
        if (! $property) {
            throw new \RuntimeException("Lot #{$lot->id} has no linked property.");
        }
        $this->ownerGate->assertHasKnownOwner($property);

        $agencyId = (int) $lot->agency_id;
        $winningBidder = $lot->winningBidder()->with('contact')->first();
        $buyerContact = $winningBidder?->contact;

        [$totalCommission, $notes] = $this->resolveCommission($lot, $agencyId);

        return DB::transaction(function () use ($lot, $property, $buyerContact, $totalCommission, $notes, $agencyId, $actorId) {
            $deal = new Deal();
            $deal->deal_no = $this->nextDealNo();

            $sellerContacts = $property->contacts()
                ->wherePivotIn('role', ['seller', 'co_seller', 'landlord'])
                ->wherePivotNull('deleted_at')
                ->get();

            $deal->fill([
                'agency_id' => $agencyId,
                'branch_id' => $property->branch_id,
                'period' => now()->format('Y-m'),
                'deal_date' => now()->toDateString(),
                // §12.2 — "auction sales are typically cash in the DR2 sense... the
                // build prompt sets the default and lets the agent change it. No
                // new deal_type value is invented for auctions."
                'deal_type' => 'cash',
                'property_id' => $property->id,
                'property_address' => $property->address,
                'property_value' => (float) $lot->hammer_price,
                'total_commission' => $totalCommission,
                'listing_split_percent' => 100,
                'selling_split_percent' => 0,
                'listing_external' => false,
                'listing_our_share_percent' => 100,
                'selling_external' => false,
                'selling_our_share_percent' => 100,
                'seller_name' => $sellerContacts->pluck('full_name')->filter()->implode(', ') ?: null,
                'buyer_name' => $buyerContact?->full_name,
                'attorney_provider_id' => AgencyAuctionSettings::defaultAttorneyProviderIdFor($agencyId),
                'accepted_status' => 'P',
                'commission_status' => 'Not Paid',
                // 'link_source' is a strict enum (manual|auto_address_match|
                // auto_address_date_match|presentation_link|admin_review) —
                // no 'auction' member exists. 'manual' is the closest honest
                // fit: the auctioneer's own fall-of-hammer action is exactly
                // as deliberate a link as a human picking the property by
                // hand. Caught via a real DB truncation error, not a guess.
                'link_source' => 'manual',
                'link_confidence' => 'exact',
                'remarks' => $notes,
                'created_by_id' => $actorId,
            ]);
            $deal->save();

            $this->createDealAgents($deal, $property);

            $templateId = DealPipelineTemplate::query()
                ->where('agency_id', $agencyId)->where('deal_type', 'cash')
                ->orderByDesc('is_default')->value('id');
            if ($templateId) {
                $this->pipelineService->createPipeline($deal, (int) $templateId);
            }

            $lot->deal_id = $deal->id;
            $lot->save();

            return $deal;
        });
    }

    /**
     * The property's own listing agent handles both sides — an auction lot
     * has no separate "selling agency" the way an open-mandate private-
     * treaty sale might; the winning bidder came through the auction
     * itself, not through any agent's own selling effort. 100% listing /
     * 0% selling split above reflects that (selling_split_percent=0 means
     * CommissionPoolCalculator::internalPool() attributes nothing to the
     * (empty) selling side — see App\Models\Deal::sellingPool()).
     */
    private function createDealAgents(Deal $deal, \App\Models\Property $property): void
    {
        if ($property->agent_id) {
            $deal->agents()->attach($property->agent_id, ['side' => 'listing', 'agent_split_percent' => 100]);
        }
    }

    /**
     * §12.2's fee_model-driven total: buyer's premium and/or seller's
     * commission. Both figures are already INC VAT (BuyersPremiumCalculator;
     * sellers_commission_percent applied the same way Deal::total_commission
     * always has been) — matches Deal::commissionExVat()'s own "captured
     * INCL VAT" convention, so no double VAT application downstream.
     *
     * @return array{0: float, 1: ?string} [total_commission, a remarks note
     *         breaking down the two components when both are present]
     */
    private function resolveCommission(AuctionLot $lot, int $agencyId): array
    {
        $feeModel = AgencyAuctionSettings::feeModelFor($agencyId);
        $premium = in_array($feeModel, ['buyers_premium', 'both'], true)
            ? (new BuyersPremiumCalculator())->calculate($lot)
            : 0.0;

        $sellersCommission = 0.0;
        if (in_array($feeModel, ['sellers_commission', 'both'], true)) {
            $percent = $lot->sellers_commission_percent !== null
                ? (float) $lot->sellers_commission_percent
                : AgencyAuctionSettings::sellersCommissionPercentFor($agencyId);
            if ($percent !== null && $percent > 0) {
                $vatRate = ((float) \App\Models\PerformanceSetting::get('vat_rate', 15)) / 100.0;
                $sellersCommission = (float) $lot->hammer_price * ($percent / 100) * (1 + $vatRate);
            }
        }

        $notes = null;
        if ($premium > 0 && $sellersCommission > 0) {
            $notes = sprintf(
                "Auction sale — buyer's premium R%s + seller's commission R%s.",
                number_format($premium, 2), number_format($sellersCommission, 2),
            );
        } elseif ($premium > 0) {
            $notes = sprintf("Auction sale — buyer's premium R%s.", number_format($premium, 2));
        } elseif ($sellersCommission > 0) {
            $notes = sprintf("Auction sale — seller's commission R%s.", number_format($sellersCommission, 2));
        }

        return [round($premium + $sellersCommission, 2), $notes];
    }

    /** Same numbering scheme as Dr2\DealRegisterController::store() — kept identical so the register never sees two numbering rules. */
    private function nextDealNo(): string
    {
        $maxNumericOnly = (int) Deal::query()
            ->whereRaw("deal_no NOT LIKE 'D-%'")
            ->whereRaw("deal_no REGEXP '^[0-9]+$'")
            ->max('deal_no');

        $maxFromPrefixed = (int) Deal::query()
            ->selectRaw("MAX(CAST(SUBSTR(deal_no, 3) AS UNSIGNED)) as m")
            ->where('deal_no', 'like', 'D-%')
            ->value('m');

        $maxNumeric = max($maxNumericOnly, $maxFromPrefixed, 0);
        if ($maxNumeric <= 0) {
            $maxNumeric = 1000;
        }

        return (string) ($maxNumeric + 1);
    }
}
