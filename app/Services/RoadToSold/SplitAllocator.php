<?php

declare(strict_types=1);

namespace App\Services\RoadToSold;

use Illuminate\Validation\ValidationException;

/**
 * Splitting a total by percentages (road-to-sold.md section 21.3, Johan 27 Oct 2026): the agency total goes to its branches, a
 * branch total goes to its agents - on the Budgets screen and on the monthly Targets screen.
 *
 * One rule, used by the screen's live figures (the same arithmetic runs in the browser) AND by the server that saves:
 *  - the percentages must add up to 100 (within 0.01, so 33.33 + 33.33 + 33.34 and three thirds both pass);
 *  - the amounts are worked out from those percentages in whole units (cents for money, 1 for counts) with the largest-remainder
 *    method, so the parts ALWAYS add up to the total exactly - never a cent lost or invented.
 */
final class SplitAllocator
{
    /** How far from 100 the percentages may add up (a rounding allowance, not a tolerance for a wrong split). */
    public const TOLERANCE = 0.01;

    /**
     * @param  array<int|string,float|int|string> $percents id => percent
     */
    public static function tallies(array $percents): bool
    {
        return abs(self::sum($percents) - 100.0) <= self::TOLERANCE + 1e-9;
    }

    /** @param array<int|string,float|int|string> $percents */
    public static function sum(array $percents): float
    {
        return round(array_sum(array_map('floatval', $percents)), 4);
    }

    /**
     * Refuse a split that does not add up to 100. $what is the plain name of what is being split ("branch", "Sea View agents").
     *
     * @param  array<int|string,float|int|string> $percents
     * @throws ValidationException
     */
    public static function assertTallies(array $percents, string $what, string $field = 'split'): void
    {
        if ($percents === []) {
            return;
        }
        if (! self::tallies($percents)) {
            $sum = rtrim(rtrim(number_format(self::sum($percents), 2, '.', ''), '0'), '.');
            throw ValidationException::withMessages([$field => "The {$what} percentages add up to {$sum}%, not 100%. Nothing was saved - adjust them until they add up to 100%."]);
        }
    }

    /**
     * Share $total by $percents. Whole units of 10^-$decimals; the leftover units go to the biggest remainders (ties: the lower id),
     * so array_sum of the result equals $total exactly.
     *
     * @param  array<int|string,float|int|string> $percents id => percent
     * @return array<int|string,float|int>        id => amount, same keys and order
     */
    public static function allocate(float|int $total, array $percents, int $decimals = 0): array
    {
        $scale = 10 ** $decimals;
        $units = (int) round($total * $scale);
        $weights = array_map(fn ($p) => max(0.0, (float) $p), $percents);
        $sum = array_sum($weights);
        if ($units <= 0 || $sum <= 0) {
            return array_map(fn () => $decimals > 0 ? 0.0 : 0, $percents);
        }

        $base = [];
        $remainders = [];
        $given = 0;
        foreach ($weights as $id => $w) {
            $exact = $units * $w / $sum;
            $base[$id] = (int) floor($exact + 1e-9);
            $remainders[$id] = $exact - $base[$id];
            $given += $base[$id];
        }
        $left = $units - $given;
        if ($left > 0) {
            $order = array_keys($remainders);
            usort($order, fn ($a, $b) => [$remainders[$b], $a] <=> [$remainders[$a], $b]);
            foreach (array_slice($order, 0, $left) as $id) {
                $base[$id]++;
            }
        }

        $out = [];
        foreach ($percents as $id => $_) {
            $out[$id] = $decimals > 0 ? $base[$id] / $scale : $base[$id];
        }

        return $out;
    }

    /**
     * An even split that adds up to exactly 100.00 (the starting point when nothing has been split yet).
     *
     * @param  array<int,int|string> $ids
     * @return array<int|string,float>
     */
    public static function evenPercents(array $ids): array
    {
        $ids = array_values($ids);
        $n = count($ids);
        if ($n === 0) {
            return [];
        }
        $each = floor(10000 / $n) / 100;
        $out = array_fill_keys($ids, $each);
        $out[$ids[0]] = round(100 - $each * ($n - 1), 2);

        return $out;
    }

    /**
     * The percentages that reproduce $amounts (what is already saved), to 2 decimals, adding up to exactly 100.00; an even split
     * when nothing is saved.
     *
     * @param  array<int|string,float|int> $amounts id => amount
     * @return array<int|string,float>
     */
    public static function percentsOf(array $amounts): array
    {
        $ids = array_keys($amounts);
        $total = array_sum($amounts);
        if ($ids === [] || $total <= 0) {
            return self::evenPercents($ids);
        }
        $out = array_map(fn ($a) => round($a / $total * 100, 2), $amounts);
        $drift = round(100 - array_sum($out), 2);
        if ($drift !== 0.0) {
            $biggest = array_keys($out, max($out), true)[0];
            $out[$biggest] = round($out[$biggest] + $drift, 2);
        }

        return $out;
    }
}
