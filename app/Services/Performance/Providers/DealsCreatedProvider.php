<?php

namespace App\Services\Performance\Providers;

/**
 * AT-366 — deals CREATED (captured on either register) per agent, DR1+DR2 deduped.
 * Date = the deal/offer date (DR1 deal_date, DR2 offer_date): a deal lands in the
 * period of its offer/deal date, not its capture timestamp.
 */
class DealsCreatedProvider extends AbstractDealMetricProvider
{
    public function key(): string { return 'deals_created'; }
    public function label(): string { return 'Deals created'; }
    protected function dr1DateColumn(): string { return 'deal_date'; }
    protected function dr2DateColumn(): string { return 'offer_date'; }
}
