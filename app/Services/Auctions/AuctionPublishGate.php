<?php

namespace App\Services\Auctions;

use App\Models\AgencyAuctionSettings;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\User;
use App\Services\Compliance\MarketingReadinessService;

/**
 * AT-432 addendum — .ai/specs/auctions-advertising-mode.md §3. What must be
 * true before an auction catalogue goes public. Reuses the machinery that
 * already exists rather than inventing a second truth:
 *
 *  - SELLER AUTHORITY: MarketingReadinessService — the same gate every public
 *    listing already passes (signed mandate document, photos, details). An
 *    auction lot needs the seller's signed authority exactly as a
 *    private-treaty listing does.
 *  - FFC: the listing agent's and (for an internal auctioneer) the auctioneer's
 *    Fidelity Fund Certificate on the User record, as the compliance calendar
 *    already reads it (Property Practitioners Act 22 of 2019).
 *  - EXTERNAL AUCTIONEER: the details Settings → Auctions says an external
 *    auctioneer must supply, because the public advert names them.
 *
 * Returns plain-English blockers; an empty array means "clear to publish".
 */
class AuctionPublishGate
{
    public function __construct(private MarketingReadinessService $readiness)
    {
    }

    /** @return list<string> */
    public function blockers(Auction $auction): array
    {
        $blockers = [];

        $draftLots = $auction->lots()->where('status', AuctionLot::STATUS_DRAFT)->with('property.agent')->get();

        foreach ($draftLots as $lot) {
            $property = $lot->property;
            if (! $property) {
                continue;
            }
            $label = 'Lot '.$lot->lot_number.' ('.($property->address ?: $property->title ?: 'Property #'.$property->id).')';

            $report = $this->readiness->statusFor($property);
            if (! $report->ready) {
                foreach ($report->blockedBy as $reason) {
                    $blockers[] = $label.': '.$reason;
                }
            }

            // CPA s45 / Regulations ch.2 — the advert must say whether the lot is
            // subject to a reserve. Blank is not an answer: 0 means "no reserve".
            if ($lot->reserve_price === null) {
                $blockers[] = $label.': state whether it has a reserve price (enter 0 if it is sold without reserve) — the public advert must disclose this.';
            }

            if ($problem = $this->ffcProblem($property->agent)) {
                $blockers[] = $label.': listing agent — '.$problem;
            }
        }

        if ($auction->isInternal()) {
            if ($auction->auctioneer_user_id === null) {
                $blockers[] = 'Pick the auctioneer (an agency user) — this auction is marked as run by your own auctioneer.';
            } elseif ($problem = $this->ffcProblem($auction->auctioneerUser)) {
                $blockers[] = 'Auctioneer — '.$problem;
            }
        } else {
            $blockers = array_merge($blockers, $this->externalAuctioneerProblems($auction));
        }

        return $blockers;
    }

    private function ffcProblem(?User $user): ?string
    {
        if (! $user) {
            return 'no agent is assigned.';
        }
        if (! $user->ffc_expiry_date) {
            return $user->name.' has no Fidelity Fund Certificate expiry date on record.';
        }
        if ($user->ffc_expiry_date->lt(now()->startOfDay())) {
            return $user->name.'\'s Fidelity Fund Certificate expired on '.$user->ffc_expiry_date->format('d M Y').'.';
        }

        return null;
    }

    /** @return list<string> */
    private function externalAuctioneerProblems(Auction $auction): array
    {
        $required = AgencyAuctionSettings::externalAuctioneerRequiredFieldsFor((int) $auction->agency_id);
        $problems = [];

        if (in_array('company', $required, true) && blank($auction->auctioneer_company)) {
            $problems[] = 'Add the auction house name — the public advert names who runs the sale.';
        }
        if (in_array('licence_no', $required, true) && blank($auction->auctioneer_licence_no)) {
            $problems[] = 'Add the auctioneer\'s licence number.';
        }
        if (in_array('contact', $required, true) && blank($auction->auctioneer_phone) && blank($auction->auctioneer_email)) {
            $problems[] = 'Add a phone number or email for the auctioneer so buyers can reach them.';
        }

        return $problems;
    }
}
