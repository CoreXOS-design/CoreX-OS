<?php

namespace App\Observers;

use App\Jobs\RebuildDealMoneyLinesJob;
use App\Models\DealSettlement;

class DealSettlementObserver
{
    public function saved(DealSettlement $settlement): void
    {
        // Rebuild ONLY this settlement's deal's money lines, off the web request. Previously this ran
        // `deals:recalc-money-lines` with no filter — an all-deals rebuild (O(all deals) + a full
        // Artisan bootstrap) synchronously on EVERY settlement save, the same 502 risk fixed in
        // DealObserver::saved(). The math is unchanged: rebuildDealId() runs the identical per-deal
        // rebuildSingleDeal() the all-deals path ran — same rows for this deal (settlement values feed
        // it via deal_settlements). deal_settlements.deal_id is NOT NULL, but guard defensively.
        if ($settlement->deal_id) {
            RebuildDealMoneyLinesJob::dispatch((int) $settlement->deal_id);
        }
    }
}
