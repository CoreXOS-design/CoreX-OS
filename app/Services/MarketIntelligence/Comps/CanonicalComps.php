<?php

declare(strict_types=1);

namespace App\Services\MarketIntelligence\Comps;

use App\Services\MarketIntelligence\PropertyTypes\PropertyTypeResolver;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * MIC step 5.3 (.ai/specs/mic-work-tab.md section 11.1, AT-524): the ONE read path for CMA sold prices. Tile 9 and the "against the
 * market" column read through here and never count the same sale twice.
 *
 * "Canonical comps" = live market_report_comp_rows of type comp or subject with a sale price and date and `duplicate_of_id IS NULL`
 * (also available as `MarketReportCompRow::canonicalSales()`). Always filtered to ONE agency (reports are agency data).
 *   - `suburb_id` = `p24_suburb_id` (NULL = suburb text the resolver could not place; such sales are left out of per-suburb figures).
 *   - `basis`: `floor` = sectional title (section number or scheme name present), else `erf` = freehold. `extent_m2` is the section's floor
 *     size on a `floor` sale and the stand size on an `erf` sale, so R/m2 is only ever averaged WITHIN one basis, never across.
 *   - R/m2 is price / extent computed here; the stored `r_per_m2` column is not reliable.
 *   - `type_key` is the 5.2 canonical type of the CMA type text. CMA text is mostly a title-type label (Residence / Residential), which maps to
 *     `other`: it cannot say house vs flat. `land` is real. Use `basis` for the freehold-vs-sectional split.
 * The price window ($minPrice / $maxPrice) drops obvious capture errors (default R50,000 to R20,000,000); callers should pass the
 * agency's own limits when MicAgencySetting carries them.
 */
final class CanonicalComps
{
    public const BASIS_FLOOR = 'floor';
    public const BASIS_ERF = 'erf';

    public function __construct(private readonly PropertyTypeResolver $types) {}

    /** Canonical sale rows of one agency. Columns: id, p24_suburb_id, property_type, scheme_name, section_number, extent_m2, sale_date, sale_price, basis. */
    public function query(int $agencyId, ?CarbonInterface $since = null, int $minPrice = 50_000, int $maxPrice = 20_000_000): Builder
    {
        $q = DB::table('market_report_comp_rows')
            ->where('agency_id', $agencyId)->whereNull('deleted_at')->whereNull('duplicate_of_id')
            ->whereIn('row_type', ['comp', 'subject'])
            ->whereNotNull('sale_date')->whereBetween('sale_price', [$minPrice, $maxPrice])
            ->selectRaw("id, p24_suburb_id, property_type, scheme_name, section_number, extent_m2, sale_date, sale_price, "
                . "CASE WHEN (section_number IS NOT NULL AND section_number <> '') OR (scheme_name IS NOT NULL AND scheme_name <> '') THEN 'floor' ELSE 'erf' END as basis");
        if ($since !== null) {
            $q->where('sale_date', '>=', $since->toDateString());
        }

        return $q;
    }

    /**
     * Sold prices per suburb (and canonical type) over the last $months months.
     *
     * @return array<int, array{suburb_id:int, sales:int, sales_12m:int, avg_sold:int, median_sold:int,
     *                          r_per_m2_erf:?int, erf_sales:int, r_per_m2_floor:?int, floor_sales:int,
     *                          by_type:array<string, array{sales:int, avg_sold:int}>}>  keyed by suburb_id
     */
    public function bySuburb(int $agencyId, CarbonInterface $asOf, int $months = 24, int $minPrice = 50_000, int $maxPrice = 20_000_000): array
    {
        $rows = $this->query($agencyId, $asOf->copy()->subMonths($months), $minPrice, $maxPrice)
            ->whereNotNull('p24_suburb_id')->where('sale_date', '<=', $asOf->toDateString())->get();

        $cut12 = $asOf->copy()->subMonths(12)->toDateString();
        $by = [];
        foreach ($rows as $r) {
            $by[(int) $r->p24_suburb_id][] = $r;
        }

        $out = [];
        foreach ($by as $suburbId => $list) {
            $prices = array_map(fn ($r) => (int) $r->sale_price, $list);
            sort($prices);
            $perM2 = [self::BASIS_ERF => [], self::BASIS_FLOOR => []];
            $types = [];
            foreach ($list as $r) {
                if ((int) $r->extent_m2 > 0) {
                    $perM2[$r->basis][] = $r->sale_price / $r->extent_m2;
                }
                $t = $this->types->keyFor($r->property_type, $agencyId) ?? 'other';
                $types[$t][] = (int) $r->sale_price;
            }
            $out[$suburbId] = [
                'suburb_id'      => $suburbId,
                'sales'          => count($list),
                'sales_12m'      => count(array_filter($list, fn ($r) => (string) $r->sale_date >= $cut12)),
                'avg_sold'       => (int) round(array_sum($prices) / count($prices)),
                'median_sold'    => self::median($prices),
                'r_per_m2_erf'   => $perM2[self::BASIS_ERF] ? (int) round(array_sum($perM2[self::BASIS_ERF]) / count($perM2[self::BASIS_ERF])) : null,
                'erf_sales'      => count($perM2[self::BASIS_ERF]),
                'r_per_m2_floor' => $perM2[self::BASIS_FLOOR] ? (int) round(array_sum($perM2[self::BASIS_FLOOR]) / count($perM2[self::BASIS_FLOOR])) : null,
                'floor_sales'    => count($perM2[self::BASIS_FLOOR]),
                'by_type'        => array_map(fn ($p) => ['sales' => count($p), 'avg_sold' => (int) round(array_sum($p) / count($p))], $types),
            ];
        }
        ksort($out);

        return $out;
    }

    /** @param list<int> $sorted */
    private static function median(array $sorted): int
    {
        $n = count($sorted);
        $mid = intdiv($n, 2);

        return $n % 2 ? $sorted[$mid] : (int) round(($sorted[$mid - 1] + $sorted[$mid]) / 2);
    }
}
