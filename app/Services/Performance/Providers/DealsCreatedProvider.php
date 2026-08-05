<?php

namespace App\Services\Performance\Providers;

/** AT-366-B — DR2 deals CREATED (captured on the register) per agent, by offer_date. */
class DealsCreatedProvider extends AbstractDr2DealMetricProvider
{
    public function key(): string { return 'deals_created'; }
    public function label(): string { return 'Deals created'; }
    protected function dateColumn(): string { return 'offer_date'; }
}
