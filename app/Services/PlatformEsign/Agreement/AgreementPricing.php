<?php

namespace App\Services\PlatformEsign\Agreement;

/**
 * Monthly fee calculation (spec §11.5). Rates come from the pinned wording version's rates_json — never from a view.
 *
 * Two price shapes, chosen by the pinned version's rates_json — so an agreement always prices by the shape it was sent with:
 *  - TIERS (versions up to the October 2026 price change): CoreX Team per seat, or CoreX Agency base fee + graduated seat tiers.
 *    One "number of agents" entry is split across the seat tiers automatically.
 *  - BANDS (spec §11.5a, Johan 2026-10-19): one flat monthly fee for the band the number of agents falls in (band1 … band8, each
 *    running up to bandN_max agents), plus each branch after the first. Above the last band the fee is on quotation: the sender
 *    records the quoted monthly fee at send time (rr_data.quoted_fee); without one the agreement cannot be completed.
 */
class AgreementPricing
{
    /** The tier-shaped rates (every version published before the band pricing). */
    public const DEFAULT_RATES = [
        'team_seat' => 450, 'team_max_seats' => 10,
        'agency_base' => 1495,
        'agency_t1' => 295, 'agency_t1_max' => 10,
        'agency_t2' => 250, 'agency_t2_max' => 20,
        'agency_t3' => 195,
        'branch' => 750,
        'quote_above_agents' => 40,
    ];

    /** The band-shaped rates (spec §11.5a) — the October 2026 price list. A version is band-shaped when its rates hold `band1`. */
    public const BAND_RATES = [
        'band1' => 450, 'band1_max' => 1,
        'band2' => 900, 'band2_max' => 2,
        'band3' => 1495, 'band3_max' => 5,
        'band4' => 2495, 'band4_max' => 10,
        'band5' => 3295, 'band5_max' => 15,
        'band6' => 3995, 'band6_max' => 20,
        'band7' => 4595, 'band7_max' => 25,
        'band8' => 5095, 'band8_max' => 30,
        'branch' => 750,
    ];

    public const BAND_COUNT = 8;

    /** Fee-table lines of a band-shaped version: one per band, the quoted fee above the last band, and additional branches. */
    public const BAND_LINES = ['band1', 'band2', 'band3', 'band4', 'band5', 'band6', 'band7', 'band8', 'quoted', 'branches'];

    public static function isBands(array $rates): bool
    {
        return array_key_exists('band1', $rates);
    }

    /** The version's rates completed with the defaults of ITS price shape (never a mix of the two). */
    public static function withDefaults(array $rates): array
    {
        return array_merge(self::isBands($rates) ? self::BAND_RATES : self::DEFAULT_RATES, $rates);
    }

    /** @return list<array{key:string,from:int,to:int,price:float}> the bands in order */
    public static function bands(array $rates): array
    {
        $rates = array_merge(self::BAND_RATES, $rates);
        $out = [];
        $from = 1;
        for ($i = 1; $i <= self::BAND_COUNT; $i++) {
            $to = (int) $rates['band' . $i . '_max'];
            $out[] = ['key' => 'band' . $i, 'from' => $from, 'to' => $to, 'price' => (float) $rates['band' . $i]];
            $from = $to + 1;
        }

        return $out;
    }

    /** The most agents the bands price; above this the fee is on quotation. */
    public static function topOfBands(array $rates): int
    {
        return (int) array_merge(self::BAND_RATES, $rates)['band' . self::BAND_COUNT . '_max'];
    }

    /** "1 agent", "2 agents", "3 to 5 agents" — how a band is named in the contract and on the billing pages. */
    public static function bandLabel(int $from, int $to): string
    {
        if ($from === $to) {
            return $from . ($from === 1 ? ' agent' : ' agents');
        }

        return $from . ' to ' . $to . ' agents';
    }

    /** @return array{lines:array<string,array{qty:int,rate:float,amount:float}>,subtotal:float,variation:float,total:float,over_quote_threshold:bool} */
    public static function compute(string $plan, int $agents, int $extraBranches, float $variation, array $rates, ?array $discount = null, float $quotedFee = 0.0): array
    {
        if (self::isBands($rates)) {
            return self::computeBands($agents, $extraBranches, $variation, $rates, $discount, $quotedFee);
        }
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
        // A percentage is worked out on the whole monthly subtotal (every recurring line); a rand amount is capped at it.
        $variation = $discount ? AgreementDiscount::amountFor($subtotal, $discount) : max(0, min($variation, $subtotal));

        return [
            'lines' => $lines, 'subtotal' => $subtotal, 'variation' => $variation, 'total' => round($subtotal - $variation, 2),
            // The discount spec and what is payable AFTER a time-limited discount ends (= the normal monthly fee).
            'discount' => $discount, 'discount_months' => $discount['months'] ?? null, 'full_total' => $subtotal,
            'over_quote_threshold' => $plan === 'agency' && $agents > (int) $rates['quote_above_agents'],
        ];
    }

    /**
     * Band-shaped fee (spec §11.5a): the band the agents fall in is charged its flat fee (quantity 1); above the last band the quoted fee
     * the sender recorded is charged instead. Every branch after the first is an additional branch, whatever the band.
     */
    private static function computeBands(int $agents, int $extraBranches, float $variation, array $rates, ?array $discount, float $quotedFee): array
    {
        $rates = array_merge(self::BAND_RATES, $rates);
        $agents = max(0, $agents);
        $extraBranches = max(0, $extraBranches);
        $quotedFee = max(0.0, $quotedFee);
        $line = fn (int $q, float $rate) => ['qty' => $q, 'rate' => $rate, 'amount' => round($q * $rate, 2)];
        $lines = [];
        $priced = false;
        foreach (self::bands($rates) as $b) {
            $in = $agents >= $b['from'] && $agents <= $b['to'];
            $lines[$b['key']] = $line($in ? 1 : 0, $b['price']);
            $priced = $priced || $in;
        }
        $over = $agents > self::topOfBands($rates);
        $lines['quoted'] = $line($over && $quotedFee > 0 ? 1 : 0, $quotedFee);
        $priced = $priced || ($over && $quotedFee > 0);
        $lines['branches'] = $line($priced ? $extraBranches : 0, (float) $rates['branch']);
        $subtotal = round(array_sum(array_column($lines, 'amount')), 2);
        $variation = $discount ? AgreementDiscount::amountFor($subtotal, $discount) : max(0, min($variation, $subtotal));

        return [
            'lines' => $lines, 'subtotal' => $subtotal, 'variation' => $variation, 'total' => round($subtotal - $variation, 2),
            'discount' => $discount, 'discount_months' => $discount['months'] ?? null, 'full_total' => $subtotal,
            'over_quote_threshold' => $over,
        ];
    }

    /**
     * The band-shaped "plan": the line that carries the agents' fee — 'band1' … 'band8', 'quoted' above the last band when the sender
     * recorded a quoted fee, '' while nothing can be priced (no agents yet, or above the last band without a quoted fee).
     */
    public static function bandPlanFor(int $agents, array $rates, float $quotedFee): string
    {
        if ($agents < 1) {
            return '';
        }
        foreach (self::bands($rates) as $b) {
            if ($agents >= $b['from'] && $agents <= $b['to']) {
                return $b['key'];
            }
        }

        return $quotedFee > 0 ? 'quoted' : '';
    }

    /** The sender's quoted monthly fee for an agreement above the last band (0 when none). */
    public static function quotedFee(array $rr): float
    {
        $v = AgreementFields::cleanMoney((string) ($rr['quoted_fee'] ?? ''));

        return $v === '' ? 0.0 : (float) $v;
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
        $agents = max(0, (int) ($values['agents'] ?? 0));
        $branches = max(0, (int) ($values['branches'] ?? 0));
        if (self::isBands($rates)) {
            // Band pricing has no plan to fix: the band follows the agents; the first branch is included whatever the band.
            $quoted = self::quotedFee($rr);
            $plan = self::bandPlanFor($agents, $rates, $quoted);
            $extra = $plan !== '' ? max($branches - 1, 0) : 0;

            return self::compute($plan, $agents, $extra, (float) ($rr['variation_amount'] ?? 0), $rates, AgreementDiscount::fromRr($rr), $quoted)
                + ['plan' => $plan, 'extra_branches' => $extra, 'forced' => false];
        }
        $rates = array_merge(self::DEFAULT_RATES, $rates);
        $forced = in_array($rr['plan_forced'] ?? null, ['team', 'agency'], true) ? $rr['plan_forced'] : null;
        $plan = self::planFor($agents, $forced, $rates);
        $extra = $plan === 'agency' ? max($branches - 1, 0) : 0; // the first branch is included in the base fee

        $spec = AgreementDiscount::fromRr($rr);

        return self::compute($plan, $agents, $extra, (float) ($rr['variation_amount'] ?? 0), $rates, $spec)
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
