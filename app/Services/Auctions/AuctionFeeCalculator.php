<?php

namespace App\Services\Auctions;

use App\Models\AgencyAuctionSettings;
use App\Models\Auction;
use App\Models\PerformanceSetting;
use Carbon\Carbon;

/**
 * The buyer's side of a sale (.ai/specs/auctions.md §17). The same sum drives the bidder invoice, the Deal's commission and the
 * fee notice buyers read before they bid, so they can never disagree.
 *
 *   commission = max(minimum, hammer x percent)        (ex VAT)
 *   VAT        = commission x the agency's vat_rate
 *   total      = hammer + commission + VAT
 *   deposit    = hammer x deposit %   (due within deposit_due_days of the sale)
 *   balance    = total - deposit      (due within balance_due_days of the sale)
 */
class AuctionFeeCalculator
{
    /** Buyer's commission % for an auction: its own override, else the agency default. */
    public function commissionPercent(Auction $auction): float
    {
        return $auction->buyers_commission_percent !== null
            ? max(0.0, (float) $auction->buyers_commission_percent)
            : AgencyAuctionSettings::buyersCommissionPercentFor((int) $auction->agency_id);
    }

    public function depositPercent(Auction $auction): float
    {
        return $auction->deposit_percent !== null
            ? max(0.0, (float) $auction->deposit_percent)
            : AgencyAuctionSettings::depositPercentFor((int) $auction->agency_id);
    }

    public function vatRate(int $agencyId): float
    {
        return max(0.0, (float) PerformanceSetting::get('vat_rate', 15, $agencyId));
    }

    /**
     * @return array{hammer_price: float, commission_percent: float, commission_amount: float, vat_rate: float, vat_amount: float,
     *               total_amount: float, deposit_percent: float, deposit_amount: float, deposit_due_at: Carbon, balance_amount: float, balance_due_at: Carbon}
     */
    public function forSale(Auction $auction, float $hammer, ?Carbon $saleDate = null): array
    {
        $agencyId = (int) $auction->agency_id;
        $saleDate ??= now();

        $percent = $this->commissionPercent($auction);
        $commission = 0.0;
        if ($percent > 0) {
            $commission = max(AgencyAuctionSettings::buyersCommissionMinimumFor($agencyId), $hammer * $percent / 100);
        }
        $commission = round($commission, 2);

        $vatRate = $commission > 0 ? $this->vatRate($agencyId) : 0.0;
        $vat = round($commission * $vatRate / 100, 2);
        $total = round($hammer + $commission + $vat, 2);

        $depositPercent = $this->depositPercent($auction);
        $deposit = min($total, round($hammer * $depositPercent / 100, 2));

        return [
            'hammer_price' => round($hammer, 2),
            'commission_percent' => $percent,
            'commission_amount' => $commission,
            'vat_rate' => $vatRate,
            'vat_amount' => $vat,
            'total_amount' => $total,
            'deposit_percent' => $depositPercent,
            'deposit_amount' => $deposit,
            'deposit_due_at' => $saleDate->copy()->addDays(AgencyAuctionSettings::depositDueDaysFor($agencyId))->endOfDay(),
            'balance_amount' => round($total - $deposit, 2),
            'balance_due_at' => $saleDate->copy()->addDays(AgencyAuctionSettings::balanceDueDaysFor($agencyId))->endOfDay(),
        ];
    }

    /** What the Deal's commission field gets: the buyer's commission INCLUDING VAT (the Deal Register's own convention). */
    public function dealCommission(Auction $auction, float $hammer): float
    {
        $f = $this->forSale($auction, $hammer);

        return round($f['commission_amount'] + $f['vat_amount'], 2);
    }

    /** The plain-English fee notice buyers read before bidding. Empty when the agency charges nothing and takes no deposit. */
    public function notice(Auction $auction): string
    {
        $agencyId = (int) $auction->agency_id;
        $parts = [];
        if (($p = $this->commissionPercent($auction)) > 0) {
            $min = AgencyAuctionSettings::buyersCommissionMinimumFor($agencyId);
            $parts[] = "A buyer's commission of {$this->pct($p)}% of the price is added" . ($this->vatRate($agencyId) > 0 ? ', plus VAT' : '')
                . ($min > 0 ? ' (minimum R ' . number_format($min, 0, '.', ' ') . ')' : '') . '.';
        }
        if (($d = $this->depositPercent($auction)) > 0) {
            $days = AgencyAuctionSettings::depositDueDaysFor($agencyId);
            $parts[] = "The winning bidder pays a deposit of {$this->pct($d)}% of the price within {$days} " . ($days === 1 ? 'day' : 'days') . ' of the sale.';
        }

        return implode(' ', $parts);
    }

    private function pct(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
