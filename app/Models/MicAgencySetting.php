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
        'gone_confirm_crawls'        => 2,
        'neighbour_months'           => 6,
        'neighbour_each_side'        => 5,
        'area_agency_pages_per_run'  => 10,
        'area_street_pages_per_run'  => 150,
        'opt_out_registry_check'     => false,
        // cc4's "approx read" and "working suburb" rules (AT-519, spec 11.1): were hard-coded 50 / 3 / 3
        'approx_read_min_share_pct'  => 50,
        'approx_read_min_adverts'    => 3,
        'working_suburb_min_count'   => 3,
        // Adverts view (AT-528, spec 13.7)
        'default_view'               => 'adverts',
        'rows_per_page'              => 25,
        'reduced_window_days'        => 90,
        'stale_window_days'          => 7,
        'mandate_length_days'        => 90,
        'mandate_words'              => ['sole mandate', 'sole and exclusive', 'exclusive mandate', 'sole agent', 'sole agency'],
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

    /**
     * Days after which a suburb's market data counts as stale. This is the SAME setting the Settings page saves as "Market data refresh - Every N days"
     * (`refresh_interval_days`, 7 by default) - an alias, never a second value.
     */
    public function refreshStaleDays(): int { return $this->refreshIntervalDaysValue(); }

    /** A crawl counts as an "approx read" of a suburb only when it saw at least this share (%) of the suburb's live adverts. Default 50, 1-100. */
    public function approxReadMinSharePct(): int { return min(100, $this->positiveInt('approx_read_min_share_pct')); }

    /** ...and at least this many adverts. Default 3. */
    public function approxReadMinAdverts(): int { return $this->positiveInt('approx_read_min_adverts'); }

    /**
     * With no Areas ticked, a suburb counts as "ours" when it has at least this many of our stock, claims or leads in the last 12 months. Default 3.
     * (Settings page, under Areas: "Count a suburb as ours from N listings/claims/leads".)
     */
    public function workingSuburbMinCount(): int { return $this->positiveInt('working_suburb_min_count'); }

    /** Adverts view (spec 13.7): which view a user sees first when they have no remembered choice: `adverts` (default) or `list`. */
    public function defaultView(): string
    {
        $v = (string) $this->value('default_view');

        return in_array($v, ['adverts', 'list'], true) ? $v : self::DEFAULTS['default_view'];
    }

    /** Rows per page on the Work screen (default 25; 10-100). */
    public function rowsPerPage(): int
    {
        return max(10, min(100, $this->positiveInt('rows_per_page')));
    }

    /** A price cut inside this many days makes an advert "Reduced" (default 90; 1-730). */
    public function reducedWindowDays(): int
    {
        return min(730, $this->positiveInt('reduced_window_days'));
    }

    /** An advert not seen for more than this many days shows "not seen since N d" (default 7; 1-365). */
    public function staleWindowDays(): int
    {
        return min(365, $this->positiveInt('stale_window_days'));
    }

    /**
     * Wording that marks an advert as a sole mandate when it appears in the description (lower case, trimmed, no duplicates, 3-60 characters each, at most 20).
     * An empty or unusable list falls back to the defaults, so the flag never silently switches off.
     *
     * @return list<string>
     */
    public function mandateWords(): array
    {
        return self::cleanMandateWords($this->value('mandate_words')) ?: self::DEFAULTS['mandate_words'];
    }

    /**
     * @param  mixed  $raw  a list, or the text of a textarea (one phrase per line or comma separated)
     * @return list<string>
     */
    public static function cleanMandateWords(mixed $raw): array
    {
        $items = is_string($raw) ? preg_split('/[\r\n,]+/', $raw) : (is_array($raw) ? $raw : []);
        $out = [];
        foreach ($items as $w) {
            $w = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $w)));
            if (mb_strlen($w) >= 3 && mb_strlen($w) <= 60 && self::isMandatePhrase($w) && ! in_array($w, $out, true)) {
                $out[] = $w;
            }
        }

        return array_slice($out, 0, 20);
    }

    /** a mandate phrase must say "sole" or "exclusive" next to what is sole - mandate, agent or agency - or say both ("sole and exclusive"); "open mandate" or "mandate" alone never flags */
    public static function isMandatePhrase(string $w): bool
    {
        $has = fn (string $alt) => preg_match('/(?<![\p{L}\p{N}])(' . $alt . ')(?![\p{L}\p{N}])/u', $w) === 1;

        return ($has('sole') && $has('exclusive')) || (($has('sole') || $has('exclusive')) && $has('mandate|agent|agency'));
    }

    public static function defaultViewFor(int $agencyId): string { return static::for($agencyId)->defaultView(); }

    public static function rowsPerPageFor(int $agencyId): int { return static::for($agencyId)->rowsPerPage(); }

    public static function reducedWindowDaysFor(int $agencyId): int { return static::for($agencyId)->reducedWindowDays(); }

    public static function staleWindowDaysFor(int $agencyId): int { return static::for($agencyId)->staleWindowDays(); }

    public function mandateLengthDays(): int
    {
        return min(730, $this->positiveInt('mandate_length_days'));
    }

    public static function mandateLengthDaysFor(int $agencyId): int { return static::for($agencyId)->mandateLengthDays(); }

    /** @return list<string> */
    public static function mandateWordsFor(int $agencyId): array { return static::for($agencyId)->mandateWords(); }

    /** how many consecutive COMPLETE crawls of an advert's own suburb must miss it before it is set gone (1 = the first miss) */
    public function goneConfirmCrawls(): int { return max(1, min(5, $this->positiveInt('gone_confirm_crawls'))); }

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
        $patch = self::validateAdvertSettings($patch);
        $words = $row->values['mandate_words'] ?? null;
        $row->values = array_merge($row->values ?? [], $patch);
        $row->save();
        if (array_key_exists('mandate_words', $patch) && $patch['mandate_words'] !== $words) {
            // the agency changed its mandate words: every advert's stored wording flag is re-matched (only adverts with text are touched)
            try {
                app(\App\Services\Prospecting\MicWork\SoleMandateMatcher::class)->rematchAgency($agencyId, true);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('sole mandate re-match failed', ['agency_id' => $agencyId, 'message' => $e->getMessage()]);
            }
        }

        return $row;
    }

    /**
     * Normalise the Adverts-view keys on the way in (the Settings page may post strings): an unusable value is dropped, so the default applies.
     *
     * @param  array<string,mixed>  $patch
     * @return array<string,mixed>
     */
    public static function validateAdvertSettings(array $patch): array
    {
        if (array_key_exists('default_view', $patch) && ! in_array($patch['default_view'], ['adverts', 'list'], true)) {
            unset($patch['default_view']);
        }
        foreach (['rows_per_page' => [10, 100], 'reduced_window_days' => [1, 730], 'stale_window_days' => [1, 365], 'mandate_length_days' => [1, 730], 'gone_confirm_crawls' => [1, 5]] as $key => [$lo, $hi]) {
            if (array_key_exists($key, $patch)) {
                if (is_numeric($patch[$key])) {
                    $patch[$key] = max($lo, min($hi, (int) $patch[$key]));
                } else {
                    unset($patch[$key]);
                }
            }
        }
        if (array_key_exists('mandate_words', $patch)) {
            $clean = self::cleanMandateWords($patch['mandate_words']);
            if ($clean === []) {
                unset($patch['mandate_words']);
            } else {
                $patch['mandate_words'] = $clean;
            }
        }

        return $patch;
    }
}
