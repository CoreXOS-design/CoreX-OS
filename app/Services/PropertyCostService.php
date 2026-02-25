<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PropertyCostService
{
    // Hardcoded defaults — used when DB has no records
    private const DEFAULT_CONV_BRACKETS = [
        ['max' => 100000, 'fee' => 6435], ['max' => 150000, 'fee' => 7460],
        ['max' => 200000, 'fee' => 8485], ['max' => 250000, 'fee' => 9510],
        ['max' => 290000, 'fee' => 10535], ['max' => 350000, 'fee' => 11560],
        ['max' => 400000, 'fee' => 12585], ['max' => 440000, 'fee' => 13610],
        ['max' => 500000, 'fee' => 14635], ['max' => 600000, 'fee' => 16620],
        ['max' => 700000, 'fee' => 18605], ['max' => 800000, 'fee' => 20590],
        ['max' => 900000, 'fee' => 22575], ['max' => 1000000, 'fee' => 24560],
        ['max' => 1200000, 'fee' => 26545], ['max' => 1400000, 'fee' => 28530],
        ['max' => 1600000, 'fee' => 30515], ['max' => 1800000, 'fee' => 32500],
        ['max' => 2000000, 'fee' => 34485], ['max' => 2200000, 'fee' => 36470],
        ['max' => 2400000, 'fee' => 38455], ['max' => 2600000, 'fee' => 40440],
        ['max' => 2800000, 'fee' => 42425], ['max' => 3000000, 'fee' => 44410],
        ['max' => 3200000, 'fee' => 46395], ['max' => 3400000, 'fee' => 48380],
        ['max' => 3600000, 'fee' => 50365], ['max' => 3800000, 'fee' => 52350],
        ['max' => 4000000, 'fee' => 54335], ['max' => 4200000, 'fee' => 56320],
        ['max' => 4400000, 'fee' => 58305], ['max' => 4600000, 'fee' => 60290],
        ['max' => 4800000, 'fee' => 62275], ['max' => 5000000, 'fee' => 64260],
        ['max' => 5750000, 'fee' => 69260], ['max' => 6000000, 'fee' => 69260],
        ['max' => 6750000, 'fee' => 74260], ['max' => 7000000, 'fee' => 74260],
        ['max' => 7750000, 'fee' => 79260], ['max' => 8000000, 'fee' => 79260],
        ['max' => 8750000, 'fee' => 84260], ['max' => 9000000, 'fee' => 84260],
        ['max' => 9750000, 'fee' => 89260], ['max' => 10000000, 'fee' => 89260],
    ];

    private const DEFAULT_DEEDS_BRACKETS = [
        ['max' => 100000, 'fee' => 50], ['max' => 200000, 'fee' => 114],
        ['max' => 300000, 'fee' => 727], ['max' => 600000, 'fee' => 906],
        ['max' => 800000, 'fee' => 1275], ['max' => 1000000, 'fee' => 1464],
        ['max' => 1200000, 'fee' => 1646], ['max' => 2000000, 'fee' => 1646],
        ['max' => 3000000, 'fee' => 2281], ['max' => 4000000, 'fee' => 2281],
        ['max' => 5000000, 'fee' => 2767], ['max' => 6000000, 'fee' => 2767],
        ['max' => 7000000, 'fee' => 3296], ['max' => 8000000, 'fee' => 3296],
        ['max' => 9000000, 'fee' => 3853], ['max' => 10000000, 'fee' => 3853],
    ];

    private const DEFAULT_DUTY_BRACKETS = [
        ['from' => 0, 'to' => 1210000, 'rate' => 0.00],
        ['from' => 1210000, 'to' => 1663800, 'rate' => 0.03],
        ['from' => 1663800, 'to' => 2329300, 'rate' => 0.06],
        ['from' => 2329300, 'to' => 2994800, 'rate' => 0.08],
        ['from' => 2994800, 'to' => 12100000, 'rate' => 0.11],
        ['from' => 12100000, 'to' => 999999999, 'rate' => 0.13],
    ];

    private static function getBrackets(string $type, array $default): array
    {
        try {
            return Cache::remember("fee_scale_{$type}", 3600, function () use ($type, $default) {
                $json = DB::table('calculator_fee_scales')
                    ->where('type', $type)
                    ->latest()
                    ->value('brackets');

                if ($json) {
                    $decoded = json_decode($json, true);
                    if (is_array($decoded) && !empty($decoded)) {
                        return $decoded;
                    }
                }

                return $default;
            });
        } catch (\Throwable $e) {
            // DB not available (e.g., migration not run yet) — use defaults
            return $default;
        }
    }

    /**
     * Get metadata about the active fee scales.
     */
    public static function getFeeScaleInfo(): array
    {
        try {
            $row = DB::table('calculator_fee_scales')
                ->where('type', 'conveyancing')
                ->latest()
                ->first();

            if ($row) {
                return [
                    'effective_date' => $row->effective_date,
                    'source_document' => $row->source_document,
                ];
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return [
            'effective_date' => null,
            'source_document' => null,
        ];
    }

    /**
     * Law Society Guideline Tariff — conveyancing fees.
     */
    public static function getConveyancingFee(float $amount): float
    {
        if ($amount <= 0) return 0;

        $brackets = self::getBrackets('conveyancing', self::DEFAULT_CONV_BRACKETS);

        foreach ($brackets as $bracket) {
            if ($amount <= $bracket['max']) {
                return (float) $bracket['fee'];
            }
        }

        // Above highest bracket: extrapolate (R5,000 per R250K bracket)
        $lastFee = end($brackets)['fee'] ?? 89260;
        $lastMax = end($brackets)['max'] ?? 10000000;
        $extra = ceil(($amount - $lastMax) / 250000) * 5000;
        return $lastFee + $extra;
    }

    /**
     * Deeds Office fee scale.
     */
    public static function getDeedsOfficeFee(float $amount): float
    {
        if ($amount <= 0) return 0;

        $brackets = self::getBrackets('deeds_office', self::DEFAULT_DEEDS_BRACKETS);

        foreach ($brackets as $bracket) {
            if ($amount <= $bracket['max']) {
                return (float) $bracket['fee'];
            }
        }

        $lastFee = end($brackets)['fee'] ?? 3853;
        return (float) $lastFee;
    }

    /**
     * SA Transfer Duty — progressive brackets.
     */
    public static function calcTransferDuty(float $price): float
    {
        $brackets = self::getBrackets('transfer_duty', self::DEFAULT_DUTY_BRACKETS);

        // Quick exit for zero band
        $firstNonZero = null;
        foreach ($brackets as $b) {
            if (($b['rate'] ?? 0) > 0) {
                $firstNonZero = $b['from'] ?? 0;
                break;
            }
        }
        if ($firstNonZero !== null && $price <= $firstNonZero) return 0;

        $duty = 0;
        foreach ($brackets as $bracket) {
            $from = (float) ($bracket['from'] ?? 0);
            $to = (float) ($bracket['to'] ?? PHP_FLOAT_MAX);
            $rate = (float) ($bracket['rate'] ?? 0);

            if ($price <= $from) break;

            $taxable = min($price, $to) - $from;
            if ($taxable > 0) {
                $duty += $taxable * $rate;
            }
        }

        return round($duty, 2);
    }

    /**
     * Fixed posts & petties amount.
     */
    public static function getPostsPetties(): float
    {
        return 1850.00;
    }

    /**
     * VAT at 15% on (conveyancing fee + posts & petties).
     */
    public static function calcVat(float $conveyancingFee): float
    {
        return round(($conveyancingFee + self::getPostsPetties()) * 0.15, 2);
    }

    /**
     * Transfer duty bracket description for display.
     */
    public static function getTransferDutyBracket(float $price): string
    {
        $brackets = self::getBrackets('transfer_duty', self::DEFAULT_DUTY_BRACKETS);

        for ($i = 0; $i < count($brackets); $i++) {
            $from = (float) ($brackets[$i]['from'] ?? 0);
            $to = (float) ($brackets[$i]['to'] ?? PHP_FLOAT_MAX);
            $rate = (float) ($brackets[$i]['rate'] ?? 0);

            if ($price <= $to) {
                $pct = ($rate * 100) . '%';
                if ($rate == 0) {
                    return 'R0 - R' . number_format($to) . ': 0%';
                }
                if ($to >= 999999999 || $to >= PHP_FLOAT_MAX) {
                    return 'R' . number_format($from + 1) . '+: ' . $pct . ' above R' . number_format($from);
                }
                return 'R' . number_format($from + 1) . ' - R' . number_format($to) . ': ' . $pct . ' above R' . number_format($from);
            }
        }

        return 'Above all brackets';
    }

    /**
     * Full transfer cost breakdown.
     */
    public static function calcTransferCosts(float $price): array
    {
        $convFee = self::getConveyancingFee($price);
        $posts = self::getPostsPetties();
        $vat = self::calcVat($convFee);
        $deeds = self::getDeedsOfficeFee($price);
        $duty = self::calcTransferDuty($price);
        $total = $convFee + $posts + $vat + $deeds + $duty;

        return [
            'conveyancing_fee' => round($convFee, 2),
            'posts_petties' => round($posts, 2),
            'vat' => round($vat, 2),
            'deeds_office' => round($deeds, 2),
            'transfer_duty' => round($duty, 2),
            'total' => round($total, 2),
        ];
    }

    /**
     * Full bond registration cost breakdown (no transfer duty).
     */
    public static function calcBondCosts(float $bondAmount): array
    {
        $convFee = self::getConveyancingFee($bondAmount);
        $posts = self::getPostsPetties();
        $vat = self::calcVat($convFee);
        $deeds = self::getDeedsOfficeFee($bondAmount);
        $total = $convFee + $posts + $vat + $deeds;

        return [
            'conveyancing_fee' => round($convFee, 2),
            'posts_petties' => round($posts, 2),
            'vat' => round($vat, 2),
            'deeds_office' => round($deeds, 2),
            'total' => round($total, 2),
        ];
    }

    /**
     * Clear cached fee scales (call after uploading new fee sheet).
     */
    public static function clearCache(): void
    {
        Cache::forget('fee_scale_conveyancing');
        Cache::forget('fee_scale_deeds_office');
        Cache::forget('fee_scale_transfer_duty');
    }
}
