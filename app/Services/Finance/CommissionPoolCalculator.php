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

    /**
     * The sibling rule: what a deal side owes OUT to another agency.
     *
     * Only an EXTERNAL side owes anything out — the whole of that side's
     * money (inc VAT, as the settlement screens print it). An internal side
     * keeps 100% of its split (see internalPool() above), so it can never
     * also owe a payable: the two halves of the settlement checksum
     * (internal pool + external payable ex VAT = total ex VAT) reconcile by
     * construction. Prod-promotion audit 2026-09-16, finding A1: the
     * rebuilder used to derive a phantom payable from our_share_percent on
     * an internal side, which made every defect-shaped deal un-payable
     * ("Checksum is R63,750 but Total Commission (ex VAT) is R51,000").
     *
     * our_share_percent is deliberately NOT a factor on the external side
     * either — that branch is byte-for-byte the pre-existing behaviour
     * (CommissionInternalPoolShareDefectTest pins it).
     */
    public static function externalPayable(float $sideAmountIncVat, bool $isExternal): float
    {
        if (! $isExternal) {
            return 0.0;
        }

        return max(0.0, $sideAmountIncVat);
    }
}
