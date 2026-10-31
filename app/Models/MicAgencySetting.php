<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * MIC Work tab settings, one row per agency (.ai/specs/mic-work-tab.md section 9 + 11.1, AT-519). Every threshold has the spec default
 * and is read through a getter that validates it; a bad stored value falls back to the default. Reading never writes. The Settings page
 * (cc6) saves into `values` through save().
 */
class MicAgencySetting extends Model
{
    protected $table = 'mic_agency_settings';

    protected $guarded = [];

    protected $casts = ['values' => 'array'];

    public const DEFAULTS = [
        'score_weights'           => ['seller' => 30, 'buyer' => 25, 'pocket' => 20, 'known' => 15, 'reachable' => 10],
        'tiles_shown'             => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11],
        'price_band_edges'        => [1000000, 2000000, 3000000, 5000000],
        'dom_approx_window_days'  => 7,
        'fast_sale_days'          => 45,
        'stale_active_days'       => 14,
        'min_sales_for_pocket'    => 3,
        'stuck_dom_days'          => 90,
        'detail_pages_per_session' => 40,
        'context_min_sales'       => 3,
        'trusted_sales_after'     => '2026-06-26',
        'bulk_status_day_events'  => 10,
        'buyer_states_excluded'   => ['cold', 'lost'],
        // Settings page (cc6, spec section 9). Read by cc4's tiles (MicTileSettings) and cc3's capture limits through the static accessors below.
        'areas'                      => [],
        'refresh_interval_days'      => 7,
        'gone_tile_days'             => 30,
        'neighbour_months'           => 6,
        'neighbour_each_side'        => 5,
        'area_agency_pages_per_run'  => 10,
        'area_street_pages_per_run'  => 150,
        'opt_out_registry_check'     => false,
    ];

    /** The agency's settings; an unsaved defaults object when none stored. Never writes on read. */
    public static function for(int $agencyId): self
    {
        return static::query()->where('agency_id', $agencyId)->first() ?? new static(['agency_id' => $agencyId, 'values' => []]);
    }

    private function value(string $key): mixed
    {
        $v = $this->values[$key] ?? null;

        return $v === null ? self::DEFAULTS[$key] : $v;
    }

    private function positiveInt(string $key): int
    {
        $v = $this->value($key);

        return is_numeric($v) && (int) $v > 0 ? (int) $v : (int) self::DEFAULTS[$key];
    }

    /** @return array{seller:int,buyer:int,pocket:int,known:int,reachable:int} sums to 100, else the defaults. */
    public function scoreWeights(): array
    {
        $w = $this->value('score_weights');
        $keys = array_keys(self::DEFAULTS['score_weights']);
        if (! is_array($w) || array_diff($keys, array_keys($w)) !== [] || array_sum(array_map('intval', array_intersect_key($w, array_flip($keys)))) !== 100) {
            if (is_array($w) && $w !== self::DEFAULTS['score_weights']) {
                \Illuminate\Support\Facades\Log::warning('MIC score weights do not sum to 100; defaults used', ['agency_id' => $this->agency_id]);
            }

            return self::DEFAULTS['score_weights'];
        }

        return array_map('intval', array_intersect_key($w, array_flip($keys)));
    }

    /** @return list<int> tile numbers 1-11 */
    public function tilesShown(): array
    {
        $t = $this->value('tiles_shown');
        $t = is_array($t) ? array_values(array_unique(array_filter(array_map('intval', $t), fn ($n) => $n >= 1 && $n <= 11))) : [];

        return $t === [] ? self::DEFAULTS['tiles_shown'] : $t;
    }

    /** @return list<int> ascending price edges in rand (4 edges = 5 bands) */
    public function priceBandEdges(): array
    {
        $e = $this->value('price_band_edges');
        $e = is_array($e) ? array_values(array_map('intval', $e)) : [];
        $sorted = $e;
        sort($sorted);

        return count($e) === 4 && $sorted === $e && $e[0] > 0 ? $e : self::DEFAULTS['price_band_edges'];
    }

    public function domApproxWindowDays(): int { return $this->positiveInt('dom_approx_window_days'); }

    public function fastSaleDays(): int { return $this->positiveInt('fast_sale_days'); }

    public function staleActiveDays(): int { return $this->positiveInt('stale_active_days'); }

    public function minSalesForPocket(): int { return $this->positiveInt('min_sales_for_pocket'); }

    public function stuckDomDays(): int { return $this->positiveInt('stuck_dom_days'); }

    /** 5.9: below this many sales a suburb figure on the record card is null (no guessing). */
    public function contextMinSales(): int { return $this->positiveInt('context_min_sales'); }

    /** cc3 11.2.C: listing pages the extension may open per session. */
    public function detailPagesPerSession(): int { return $this->positiveInt('detail_pages_per_session'); }

    /** Sales before this date are import/backfill noise (the 26 Jun bulk import), not real days-to-sale (spec section 7). */
    public function trustedSalesAfter(): string
    {
        $d = (string) $this->value('trusted_sales_after');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : self::DEFAULTS['trusted_sales_after'];
    }

    /**
     * cc2 5.4: a day on which this many or more Under Offer / Sold status changes were made is a catch-up entry, not the real moment of
     * the sales (31 Sold changes on 27 Jun and 1 Jul 2026, each 2-6 days after the listed date). Those days are ignored when back-filling.
     */
    public function bulkStatusDayEvents(): int { return $this->positiveInt('bulk_status_day_events'); }

    /** @return list<string> buyer states that do not count as demand */
    public function buyerStatesExcluded(): array
    {
        $s = $this->value('buyer_states_excluded');

        return is_array($s) ? array_values(array_map('strval', $s)) : self::DEFAULTS['buyer_states_excluded'];
    }

    /** @return list<int> p24_suburbs.id the agency works (the areas it lists once in Settings); empty = derive from its active adverts. */
    public function areas(): array
    {
        $a = $this->value('areas');

        return is_array($a) ? array_values(array_unique(array_filter(array_map('intval', $a), fn ($n) => $n > 0))) : [];
    }

    public function refreshIntervalDaysValue(): int { return $this->positiveInt('refresh_interval_days'); }

    public function goneTileDaysValue(): int { return $this->positiveInt('gone_tile_days'); }

    public function neighbourMonthsValue(): int { return min(6, $this->positiveInt('neighbour_months')); }

    public function neighbourEachSideValue(): int { return min(15, $this->positiveInt('neighbour_each_side')); }

    public function areaAgencyPagesPerRunValue(): int { return $this->positiveInt('area_agency_pages_per_run'); }

    public function areaStreetPagesPerRunValue(): int { return $this->positiveInt('area_street_pages_per_run'); }

    /** Opt-out registry check before contacting an owner. OFF until NCC guidance (Johan, 31 Oct). */
    public function optOutRegistryCheck(): bool { return (bool) $this->value('opt_out_registry_check'); }

    // Static accessors by the names cc4 (MicTileSettings) and cc3 (11.2.C / area pages) asked for.
    public static function refreshIntervalDays(int $agencyId): int { return static::for($agencyId)->refreshIntervalDaysValue(); }

    public static function goneTileDays(int $agencyId): int { return static::for($agencyId)->goneTileDaysValue(); }

    public static function neighbourMonths(int $agencyId): int { return static::for($agencyId)->neighbourMonthsValue(); }

    public static function neighbourEachSide(int $agencyId): int { return static::for($agencyId)->neighbourEachSideValue(); }

    public static function areaAgencyPagesPerRun(int $agencyId): int { return static::for($agencyId)->areaAgencyPagesPerRunValue(); }

    public static function areaStreetPagesPerRun(int $agencyId): int { return static::for($agencyId)->areaStreetPagesPerRunValue(); }

    /** Merge $patch into the stored values (the Settings page's one write path). */
    public static function saveValues(int $agencyId, array $patch): self
    {
        $row = static::query()->firstOrNew(['agency_id' => $agencyId]);
        $row->values = array_merge($row->values ?? [], $patch);
        $row->save();

        return $row;
    }
}
