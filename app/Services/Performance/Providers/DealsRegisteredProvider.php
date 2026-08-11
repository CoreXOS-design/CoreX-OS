<?php

namespace App\Services\Performance\Providers;

/** AT-366-B — DR2 deals REGISTERED (transfer registered) per agent, by actual_registration. */
class DealsRegisteredProvider extends AbstractDr2DealMetricProvider
{
    public function key(): string { return 'deals_registered'; }
    public function label(): string { return 'Deals registered'; }
    protected function dateColumn(): string { return 'actual_registration'; }
}
