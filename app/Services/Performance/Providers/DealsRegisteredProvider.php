<?php

namespace App\Services\Performance\Providers;

/**
 * AT-366 — deals REGISTERED (transfer registered) per agent, DR1+DR2 deduped.
 * Wired to the REAL registration signal: DR1 deals.registration_date (populated on live)
 * + DR2 deals_v2.actual_registration. The original counted only actual_registration,
 * which is never set on live, so it always showed a false 0.
 */
class DealsRegisteredProvider extends AbstractDealMetricProvider
{
    public function key(): string { return 'deals_registered'; }
    public function label(): string { return 'Deals registered'; }
    protected function dr1DateColumn(): string { return 'registration_date'; }
    protected function dr2DateColumn(): string { return 'actual_registration'; }
}
