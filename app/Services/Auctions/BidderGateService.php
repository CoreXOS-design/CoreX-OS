<?php

namespace App\Services\Auctions;

use App\Models\AgencyAuctionSettings;
use App\Models\AuctionBidder;

/**
 * AT-432 Phase 2 — .ai/specs/auctions.md §10.2. THE single place that
 * answers "may this bidder hold a paddle / place a bid?". Checked both by
 * the approval screen (to show which gate is blocking, in plain words) and
 * — non-negotiably — server-side before every bid is accepted, whatever the
 * screen shows (§10.2: "the gate is enforced server-side on every bid, not
 * just in the UI").
 */
class BidderGateService
{
    /**
     * @return string[] the unmet gate(s), in the order §10.2 lists them.
     *                   Empty = every enabled gate passes.
     */
    public function unmetGates(AuctionBidder $bidder): array
    {
        $agencyId = (int) $bidder->agency_id;
        $unmet = [];

        if (AgencyAuctionSettings::registrationRequiredFor($agencyId) && $bidder->status === AuctionBidder::STATUS_DRAFT) {
            $unmet[] = 'Registration not yet submitted.';
        }

        if (AgencyAuctionSettings::requireFicaBeforePaddleFor($agencyId) && ! $bidder->isFicaVerified()) {
            $unmet[] = 'FICA not verified.';
        }

        if (AgencyAuctionSettings::registrationDepositRequiredFor($agencyId) && ! $bidder->hasDeposit()) {
            $unmet[] = 'Registration deposit not received.';
        }

        if (AgencyAuctionSettings::requireSignedRulesBeforePaddleFor($agencyId) && ! $bidder->hasSignedRules()) {
            $unmet[] = 'Rules of Auction not signed.';
        }

        if ($bidder->bidding_for === 'entity' && $bidder->authority_document_id === null) {
            $unmet[] = 'Authority document (resolution/power of attorney) not on file.';
        }

        if (! $bidder->isApproved()) {
            $unmet[] = 'Not yet approved by staff.';
        }

        return $unmet;
    }

    public function canHoldPaddle(AuctionBidder $bidder): bool
    {
        return empty($this->unmetGates($bidder));
    }

    /** The exact check a bid-acceptance path must run before recording any bid (§10.2, §11.1). */
    public function canBid(AuctionBidder $bidder): bool
    {
        return $this->canHoldPaddle($bidder);
    }
}
