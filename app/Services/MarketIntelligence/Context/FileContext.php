<?php

declare(strict_types=1);

namespace App\Services\MarketIntelligence\Context;

use Illuminate\Support\Facades\DB;

/**
 * Read side of the stored market context (contract 11.1b): one indexed query, agency-bound, JSON already decoded. A file without a row is absent.
 */
final class FileContext
{
    /**
     * @param  list<int>  $trackedPropertyIds
     * @return array<int, array<string, mixed>>  keyed by tracked_property_id
     */
    public function forFiles(int $agencyId, array $trackedPropertyIds): array
    {
        if ($trackedPropertyIds === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('mic_file_context')->where('agency_id', $agencyId)->whereIn('tracked_property_id', $trackedPropertyIds)->get() as $r) {
            $out[(int) $r->tracked_property_id] = [
                'sale_class' => $r->sale_class,
                'suburb_id' => $r->suburb_id !== null ? (int) $r->suburb_id : null,
                'suburb_avg_sold_12m' => $r->suburb_avg_sold_12m !== null ? (int) $r->suburb_avg_sold_12m : null,
                'suburb_avg_sold_n' => (int) $r->suburb_avg_sold_n,
                'suburb_r_per_m2' => $r->suburb_r_per_m2 !== null ? (int) $r->suburb_r_per_m2 : null,
                'suburb_r_per_m2_n' => (int) $r->suburb_r_per_m2_n,
                'suburb_r_per_m2_basis' => $r->suburb_r_per_m2_basis,
                'own_size_m2' => $r->own_size_m2 !== null ? (int) $r->own_size_m2 : null,
                'own_size_basis' => $r->own_size_basis,
                'own_r_per_m2' => $r->own_r_per_m2 !== null ? (int) $r->own_r_per_m2 : null,
                'own_vs_suburb_pct' => $r->own_vs_suburb_pct !== null ? (float) $r->own_vs_suburb_pct : null,
                'asking_vs_avg_sold_pct' => $r->asking_vs_avg_sold_pct !== null ? (float) $r->asking_vs_avg_sold_pct : null,
                'nearby_sales' => json_decode((string) $r->nearby_sales, true) ?: [],
                'last_deeds_sale' => json_decode((string) $r->last_deeds_sale, true) ?: null,
                'matched_buyers' => json_decode((string) $r->matched_buyers, true) ?: ['strong' => 0, 'mid' => 0, 'top' => []],
                'our_history' => json_decode((string) $r->our_history, true) ?: ['claims' => 0, 'last_claim' => null, 'work_status' => null, 'work_reason' => null, 'mandates' => []],
                'computed_at' => $r->computed_at,
            ];
        }

        return $out;
    }
}
