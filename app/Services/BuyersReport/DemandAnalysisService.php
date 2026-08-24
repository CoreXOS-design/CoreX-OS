<?php

namespace App\Services\BuyersReport;

use App\Services\CommandCenter\BuyerPipelineScope;
use Illuminate\Support\Facades\DB;

/**
 * "What buyers do we have now" -- demand analysis. Johan (2026-08-20):
 * "make it sliders - show me all buyers we have for apartments between
 * 500k and 1 mil". An interactive filter, not a pre-partitioned grid --
 * property type is multi-select (OR), price is a min/max range, and a
 * buyer matches on OVERLAP (their range touches the selected range), not
 * containment -- his own reasoning: "any buyer falling in this criteria"
 * would be hidden by a stricter contains-test.
 *
 * Source of the requirement data: the buyer's CORE MATCH
 * (`contact_matches`, listing_type='sale') -- Johan confirmed this is the
 * SAME record that puts a buyer/lead on the pipeline in the first place,
 * auto-created on intake. A buyer can hold more than one match row; the
 * PRIMARY one (is_primary=1, falling back to the oldest) is the buyer's
 * live requirement -- same precedence the pipeline card itself already
 * uses ($buyer->matches->firstWhere('is_primary', true) ?? first()).
 *
 * MAX_ROWS mirrors BuyersReportDrilldownService's cap -- see that class's
 * docblock for why (GATE 2, 2026-08-20).
 */
class DemandAnalysisService
{
    private const MAX_ROWS = 1000;

    /**
     * Real property types + observed price bounds for this scope --
     * confirmed from data, never a hardcoded guess (Johan, this task:
     * "confirm the real type list from the data").
     *
     * @return array{types: string[], price_min:int, price_max:int}
     */
    public function facets(BuyersReportScope $scope): array
    {
        $matchIds = $this->primaryMatchQuery($scope)->pluck('match_id');

        $types = [];
        DB::table('contact_matches')->whereIn('id', $matchIds)->whereNotNull('property_types')
            ->pluck('property_types')->each(function ($json) use (&$types) {
                foreach (json_decode($json, true) ?: [] as $t) {
                    if ($t !== null && $t !== '') {
                        $types[$t] = true;
                    }
                }
            });
        $typeList = array_keys($types);
        sort($typeList);

        $bounds = DB::table('contact_matches')->whereIn('id', $matchIds)
            ->selectRaw('MIN(price_min) as lo, MAX(price_max) as hi')->first();
        $lo = (int) ($bounds->lo ?? 0);
        $hi = (int) ($bounds->hi ?? 0);
        // Round the top outward to the next R250k so the slider's ceiling
        // never clips the most expensive real requirement in scope.
        $hi = $hi > 0 ? (int) (ceil($hi / 250000) * 250000) : 1000000;

        return ['types' => $typeList, 'price_min' => 0, 'price_max' => $hi];
    }

    /**
     * Coverage -- how many buyers in scope actually have the data the
     * sliders depend on. Always computed, always shown -- "if coverage is
     * poor the sliders will look empty and Johan needs to know that is a
     * data problem, not a build problem."
     *
     * @return array{total_buyers:int, no_match:int, no_type:int, no_price:int}
     */
    public function coverage(BuyersReportScope $scope): array
    {
        $buyerIds = $this->scopedBuyerIds($scope);
        $total = count($buyerIds);
        if ($total === 0) {
            return ['total_buyers' => 0, 'no_match' => 0, 'no_type' => 0, 'no_price' => 0];
        }

        $primary = $this->primaryMatchQuery($scope)->get()->keyBy('contact_id');
        $matchIds = $primary->pluck('match_id');
        $matches = DB::table('contact_matches')->whereIn('id', $matchIds)
            ->get(['id', 'property_types', 'price_min', 'price_max'])->keyBy('id');

        $noMatch = 0;
        $noType = 0;
        $noPrice = 0;
        foreach ($buyerIds as $bid) {
            $p = $primary->get($bid);
            if ($p === null) {
                $noMatch++;
                $noType++;
                $noPrice++;
                continue;
            }
            $m = $matches->get($p->match_id);
            $hasType = $m && $m->property_types !== null && (json_decode($m->property_types, true) ?: []) !== [];
            $hasPrice = $m && ($m->price_min !== null || $m->price_max !== null);
            if (!$hasType) {
                $noType++;
            }
            if (!$hasPrice) {
                $noPrice++;
            }
        }

        return ['total_buyers' => $total, 'no_match' => $noMatch, 'no_type' => $noType, 'no_price' => $noPrice];
    }

    /**
     * The live filter. $types = selected property types (empty = no type
     * filter, matches everyone). $priceMin/$priceMax = the slider handles
     * (null = no price filter). Overlap matching on both axes, per Johan's
     * explicit instruction -- a buyer's range only needs to TOUCH the
     * selected range, not sit inside it.
     *
     * @param  string[]  $types
     * @return array{count:int, rows:array[], truncated:bool}
     */
    public function filter(BuyersReportScope $scope, array $types, ?int $priceMin, ?int $priceMax): array
    {
        $primary = $this->primaryMatchQuery($scope);
        $query = DB::table('contacts as c')
            ->joinSub($primary, 'pm', fn ($j) => $j->on('pm.contact_id', '=', 'c.id'))
            ->join('contact_matches as cm', 'cm.id', '=', 'pm.match_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.agent_id');

        if (!empty($types)) {
            $query->where(function ($q) use ($types) {
                foreach ($types as $t) {
                    $q->orWhereRaw('JSON_CONTAINS(cm.property_types, JSON_QUOTE(?))', [$t]);
                }
            });
        }
        if ($priceMin !== null || $priceMax !== null) {
            // Effective range per buyer: an unset min floors at 0, an
            // unset max is open-ended (no ceiling) -- both are real,
            // Johan-confirmed shapes (min-only / max-only requirements),
            // not data gaps, so they participate in overlap matching.
            // A buyer with NEITHER bound set has nothing to overlap against
            // -- excluded here, surfaced instead via coverage()'s no_price
            // count, never silently blended in as a false match.
            $lo = $priceMin ?? 0;
            $hi = $priceMax ?? PHP_INT_MAX;
            $query->where(function ($q) {
                $q->whereNotNull('cm.price_min')->orWhereNotNull('cm.price_max');
            })
                ->whereRaw('COALESCE(cm.price_min, 0) <= ?', [$hi])
                ->whereRaw('COALESCE(cm.price_max, 999999999) >= ?', [$lo]);
        }

        $total = (clone $query)->count();
        $rows = $query
            ->select(['c.first_name', 'c.last_name', 'u.name as agent_name', 'cm.property_types', 'cm.price_min', 'cm.price_max'])
            ->orderBy('c.first_name')
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn ($r) => [
                'name'  => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: 'Unnamed buyer',
                'agent' => $r->agent_name ?? 'Unassigned',
                'types' => implode(', ', $r->property_types !== null ? (json_decode($r->property_types, true) ?: []) : []),
                'price_min' => $r->price_min !== null ? (float) $r->price_min : null,
                'price_max' => $r->price_max !== null ? (float) $r->price_max : null,
            ])->all();

        return ['count' => $total, 'rows' => $rows, 'truncated' => $total > count($rows)];
    }

    /** @return int[] */
    private function scopedBuyerIds(BuyersReportScope $scope): array
    {
        $query = \App\Models\Contact::buyers();
        BuyerPipelineScope::apply($query, $scope->level, $scope->userId, $scope->branchId);

        return $query->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /**
     * contact_id -> resolved primary (or oldest) SALE match id, for buyers
     * in scope. A plain query builder instance (not ->get()) so callers
     * can joinSub() it directly.
     */
    private function primaryMatchQuery(BuyersReportScope $scope)
    {
        $buyerIds = $this->scopedBuyerIds($scope);
        if (empty($buyerIds)) {
            return DB::table('contact_matches')->whereRaw('1 = 0')
                ->selectRaw('contact_id, NULL as match_id');
        }

        return DB::table('contact_matches')
            ->whereIn('contact_id', $buyerIds)
            ->where('listing_type', 'sale')
            ->whereNull('deleted_at')
            ->groupBy('contact_id')
            ->selectRaw('contact_id, COALESCE(MAX(CASE WHEN is_primary = 1 THEN id END), MIN(id)) as match_id');
    }
}
