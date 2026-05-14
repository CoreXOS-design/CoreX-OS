<?php

declare(strict_types=1);

namespace App\Services\MarketIntelligence;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * F.6 — Market velocity. Avg days-on-market by price band, last 90 days,
 * delta vs the prior 90 days.
 *
 * Source: deals_v2 (registered sales) joined with properties for the
 * listing-start date. If the agency has no completed deals (HFC's
 * current state) the service returns bands with days_on_market=null
 * and data_available=false so the view can render an empty state.
 *
 * Spec: build-f-market-intelligence-redesign-spec.md §9.4.
 */
final class MarketVelocityService
{
    public const CACHE_TTL = 6 * 3600;

    /** @var array<int, array{label:string, min:int, max:int|null}> */
    private const BANDS = [
        ['label' => 'Entry',   'min' => 0,         'max' => 1_200_000],
        ['label' => 'Mid',     'min' => 1_200_000, 'max' => 2_500_000],
        ['label' => 'Upper',   'min' => 2_500_000, 'max' => 5_000_000],
        ['label' => 'Premium', 'min' => 5_000_000, 'max' => null],
    ];

    public function buildFor(int $agencyId): array
    {
        return Cache::remember("mi.velocity.{$agencyId}", self::CACHE_TTL, function () use ($agencyId) {
            return $this->compute($agencyId);
        });
    }

    private function compute(int $agencyId): array
    {
        $bands = [];
        $any = false;

        foreach (self::BANDS as $band) {
            $current = $this->bandStats($agencyId, $band, now()->subDays(90), now());
            $prior   = $this->bandStats($agencyId, $band, now()->subDays(180), now()->subDays(90));

            $delta = ($current['avg_days'] !== null && $prior['avg_days'] !== null)
                ? (int) round($current['avg_days'] - $prior['avg_days'])
                : null;

            if ($current['avg_days'] !== null) $any = true;

            $bands[] = [
                'band'           => $band['label'],
                'days_on_market' => $current['avg_days'] !== null ? (int) round($current['avg_days']) : null,
                'delta_days'     => $delta,
                'sold_count'     => $current['count'],
            ];
        }

        return [
            'bands'          => $bands,
            'data_available' => $any,
            'computed_at'    => now()->toIso8601String(),
        ];
    }

    private function bandStats(int $agencyId, array $band, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $query = DB::table('deals_v2 as d')
            ->join('properties as p', 'p.id', '=', 'd.property_id')
            ->where('p.agency_id', $agencyId)
            ->whereNull('d.deleted_at')
            ->whereNotNull('d.actual_registration')
            ->whereNotNull('d.purchase_price')
            ->whereBetween('d.actual_registration', [$from, $to])
            ->where('d.purchase_price', '>=', $band['min']);
        if ($band['max'] !== null) {
            $query->where('d.purchase_price', '<', $band['max']);
        }

        // Days on market = registration_date - created_at (property record creation
        // is the closest proxy CoreX has to "listed-at"). Refine in F.6.1 when a
        // dedicated listing_started_at column is added.
        $row = (clone $query)
            ->select(
                DB::raw('COUNT(*) as c'),
                DB::raw('AVG(DATEDIFF(d.actual_registration, p.created_at)) as avg_days'),
            )
            ->first();

        return [
            'count'    => $row && $row->c !== null ? (int) $row->c : 0,
            'avg_days' => $row && $row->avg_days !== null ? (float) $row->avg_days : null,
        ];
    }
}
