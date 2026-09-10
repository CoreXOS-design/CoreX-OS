<?php

namespace App\Services\Finance;

/**
 * The single authoritative formula for a deal side's internal (our-agency)
 * commission pool, ex VAT.
 *
 * "Our Share %" only ever means something for a side handed to an EXTERNAL
 * agency — what % of that side's money comes back to us. It has no meaning
 * on our own (internal) side, which always keeps 100% of its split. Every
 * caller that needs a side's internal pool must go through this method so
 * there is exactly one place this rule is expressed.
 */
class CommissionPoolCalculator
{
    public static function internalPool(float $commissionExVat, bool $isExternal, $splitPercent): float
    {
        if ($isExternal) {
            return 0.0;
        }

        $split = max(0.0, min(100.0, (float) ($splitPercent ?? 50)));

        return $commissionExVat * ($split / 100.0);
    }
}
