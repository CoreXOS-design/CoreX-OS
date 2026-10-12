<?php

namespace App\Services\Finance;

/**
 * The ONE place a settlement row's arithmetic happens. The saved money lines
 * (DealMoneyLineRebuilder) and every settlement screen / print / payslip
 * (SettlementScreenRows) call this, so a figure on screen and the same figure saved
 * cannot differ — not even by a cent (2026-10-08: the screen worked unrounded, the saved lines
 * rounded at each step, and 65 of 167 QA1 deals disagreed by 1-2 cents).
 *
 * The steps are exactly the ones the saved lines have always been produced with:
 *   pool share  = round(side pool x share%, 2)
 *   agent gross = round(pool share x agent cut%, 2)
 *   company     = round(pool share - agent gross, 2)
 *   PAYE        = round(gross x PAYE%, 2)   or a fixed amount
 *   net         = round(gross - PAYE - deductions, 2)
 *
 * DELIBERATE EXCEPTION to "no floats in money code" (reported to Johan): these steps keep the
 * legacy float expressions, because Johan ruled that NO stored figure may change, and a
 * whole-cent re-implementation differs from the stored lines by one cent on exact half-cent ties
 * (e.g. R6,086.955 is stored as 6086.95; exact rounding gives 6086.96 — 24 stored cells on QA1).
 * The inputs and outputs are whole cents; only these steps use PHP's round(). Converting them is
 * AT-417, and needs Johan's go because it changes those stored cents.
 */
final class SettlementRowMath
{
    /**
     * @param int   $poolCents     the side's pool, ex VAT
     * @param mixed $sharePercent  this agent's share of the pool, e.g. "50.00"
     * @param mixed $cutPercent    the agent's cut of their share
     * @param bool  $payeIsPercent true = PAYE is a % of gross; false = a fixed amount
     * @param mixed $payeValue     the % or the fixed rand amount
     * @param mixed $deductions    rand
     * @param bool  $applyFixedPaye whether a FIXED PAYE counts yet (saved lines: only once paid; the
     *                              settlement screen previews it, so passes true)
     * @return array{pool_share:int,agent_gross:int,company:int,paye:int,deductions:int,net:int}
     */
    public static function compute(int $poolCents, $sharePercent, $cutPercent, bool $payeIsPercent, $payeValue, $deductions, bool $applyFixedPaye): array
    {
        $share = self::pct($sharePercent) / 100.0;   // 5000 → 50.0 (%)
        $cut = self::pct($cutPercent) / 100.0;
        $pool = $poolCents / 100.0;

        $poolShare = round($pool * ($share / 100.0), 2);
        $gross = round($poolShare * ($cut / 100.0), 2);
        $company = round($poolShare - $gross, 2);

        $payeVal = DealMoney::scaled($payeValue, 2) / 100.0;
        if ($payeIsPercent) {
            $paye = round($gross * ($payeVal / 100.0), 2);
        } else {
            $paye = $applyFixedPaye ? round($payeVal, 2) : 0.0;
        }

        $ded = round(DealMoney::scaled($deductions, 2) / 100.0, 2);

        return [
            'pool_share' => self::cents($poolShare),
            'agent_gross' => self::cents($gross),
            'company' => self::cents($company),
            'paye' => self::cents($paye),
            'deductions' => self::cents($ded),
            'net' => self::cents(round($gross - $paye - $ded, 2)),
        ];
    }

    /** A percent clamped to 0-100, as hundredths of a percent (50% = 5000). */
    public static function pct($v): int
    {
        return max(0, min(10000, DealMoney::scaled($v, 2)));
    }

    /** A 2-decimal rand float → whole cents, via its decimal text (no multiplication). */
    private static function cents(float $rand): int
    {
        return DealMoney::scaled($rand, 2);
    }
}
