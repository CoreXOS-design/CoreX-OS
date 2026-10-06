<?php

namespace App\Services\PlatformEsign\Agreement;

/**
 * Monthly fee calculation (spec §11.5). Rates come from the pinned wording version's rates_json — never from a view.
 * One "number of agents" entry is split across the seat tiers automatically.
 */
class AgreementPricing
{
    public const DEFAULT_RATES = [
        'team_seat' => 450, 'team_max_seats' => 10,
        'agency_base' => 1495,
        'agency_t1' => 295, 'agency_t1_max' => 10,
        'agency_t2' => 250, 'agency_t2_max' => 20,
        'agency_t3' => 195,
        'branch' => 750,
        'quote_above_agents' => 40,
    ];

    /** @return array{lines:array<string,array{qty:int,rate:float,amount:float}>,subtotal:float,variation:float,total:float,over_quote_threshold:bool} */
    public static function compute(string $plan, int $agents, int $extraBranches, float $variation, array $rates): array
    {
        $rates = array_merge(self::DEFAULT_RATES, $rates);
        $agents = max(0, $agents);
        $extraBranches = max(0, $extraBranches);
        $line = fn (int $q, float $rate) => ['qty' => $q, 'rate' => $rate, 'amount' => round($q * $rate, 2)];
        $lines = [
            'team_seats' => $line(0, $rates['team_seat']), 'agency_base' => $line(0, $rates['agency_base']),
            'agency_t1' => $line(0, $rates['agency_t1']), 'agency_t2' => $line(0, $rates['agency_t2']),
            'agency_t3' => $line(0, $rates['agency_t3']), 'branches' => $line(0, $rates['branch']),
        ];
        if ($plan === 'team') {
            $lines['team_seats'] = $line($agents, $rates['team_seat']);
        } elseif ($plan === 'agency') {
            $t1 = min($agents, (int) $rates['agency_t1_max']);
            $t2 = min(max($agents - (int) $rates['agency_t1_max'], 0), (int) $rates['agency_t2_max'] - (int) $rates['agency_t1_max']);
            $t3 = max($agents - (int) $rates['agency_t2_max'], 0);
            $lines['agency_base'] = $line(1, $rates['agency_base']);
            $lines['agency_t1'] = $line($t1, $rates['agency_t1']);
            $lines['agency_t2'] = $line($t2, $rates['agency_t2']);
            $lines['agency_t3'] = $line($t3, $rates['agency_t3']);
            $lines['branches'] = $line($extraBranches, $rates['branch']);
        }
        $subtotal = round(array_sum(array_column($lines, 'amount')), 2);
        $variation = max(0, min($variation, $subtotal));

        return [
            'lines' => $lines, 'subtotal' => $subtotal, 'variation' => $variation, 'total' => round($subtotal - $variation, 2),
            'over_quote_threshold' => $plan === 'agency' && $agents > (int) $rates['quote_above_agents'],
        ];
    }

    /**
     * The plan the number of agents selects: CoreX Team up to team_max_seats, CoreX Agency above that. A plan the sender fixed
     * at send time ('team' | 'agency') wins. '' while no number of agents has been entered.
     */
    public static function planFor(int $agents, ?string $forced, array $rates): string
    {
        if (in_array($forced, ['team', 'agency'], true)) {
            return $forced;
        }

        return $agents < 1 ? '' : ($agents <= (int) $rates['team_max_seats'] ? 'team' : 'agency');
    }

    /**
     * THE one calculation (the client JS in agreement/_js.blade.php mirrors it line for line). Section 3 completes itself from
     * two entries — number of agents and number of branches: plan, additional branches (branches − 1, Agency only) and every fee line follow.
     *
     * @param array<string,mixed> $values recipient values (agents, branches)
     * @param array<string,mixed> $rr RR-side values (variation_amount, plan_forced)
     * @return array{lines:array,subtotal:float,variation:float,total:float,over_quote_threshold:bool,plan:string,extra_branches:int,forced:bool}
     */
    public static function derive(array $values, array $rr, array $rates): array
    {
        $rates = array_merge(self::DEFAULT_RATES, $rates);
        $agents = max(0, (int) ($values['agents'] ?? 0));
        $branches = max(0, (int) ($values['branches'] ?? 0));
        $forced = in_array($rr['plan_forced'] ?? null, ['team', 'agency'], true) ? $rr['plan_forced'] : null;
        $plan = self::planFor($agents, $forced, $rates);
        $extra = $plan === 'agency' ? max($branches - 1, 0) : 0; // the first branch is included in the base fee

        return self::compute($plan, $agents, $extra, (float) ($rr['variation_amount'] ?? 0), $rates)
            + ['plan' => $plan, 'extra_branches' => $extra, 'forced' => $forced !== null];
    }

    /** "R450", "R1 495" — the format used by the source wording. */
    public static function rate(float $n): string
    {
        return 'R' . self::number($n);
    }

    /** "1 495" or "1 495.50" */
    public static function number(float $n): string
    {
        return fmod($n, 1.0) === 0.0 ? number_format($n, 0, '.', ' ') : number_format($n, 2, '.', ' ');
    }
}
