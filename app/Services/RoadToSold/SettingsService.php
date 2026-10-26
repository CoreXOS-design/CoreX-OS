<?php

namespace App\Services\RoadToSold;

use App\Models\RoadToSold\RtsSetting;
use App\Models\User;

/**
 * AT-454 — typed read access to an agency's Road to Sold settings.
 * Contract published in .ai/specs/road-to-sold.md §2.1 — lanes 2 and 3 code
 * against these getters; do not rename without telling the conductor.
 *
 *  - A missing row means "defaults". Reading NEVER writes (STANDARDS Rule 17).
 *  - $agencyId <= 0 (owner with no agency, console, queued job) returns the
 *    defaults with nothing persisted.
 *  - Memoised for a few seconds only (MEMO_SECONDS), never for the life of a process: the nightly build
 *    is one long process and must see a setting saved while it runs. Save paths call forget(); every
 *    queued job and artisan command start drops the memo too (AppServiceProvider), and a long loop can
 *    call SettingsService::forget() itself or ask for forAgency(..., fresh: true).
 */
class SettingsService
{
    /** How long one instance may be reused. Short on purpose - see the class note. */
    public const MEMO_SECONDS = 15;

    /** @var array<string,array{0:int,1:self}> key => [stored at (unix), instance] */
    private static array $memo = [];

    private function __construct(
        private readonly int $agencyId,
        private readonly string $track,
        private readonly RtsSetting $row,
    ) {
    }

    public static function forAgency(int $agencyId, string $track = RtsSetting::TRACK_SALES, bool $fresh = false): self
    {
        $key = $agencyId . '|' . $track;
        if (! $fresh && isset(self::$memo[$key]) && (time() - self::$memo[$key][0]) < self::MEMO_SECONDS) {
            return self::$memo[$key][1];
        }

        $row = null;
        if ($agencyId > 0) {
            // Explicit agency filter: the model's AgencyScope is skipped for console/jobs.
            $row = RtsSetting::query()
                ->where('agency_id', $agencyId)
                ->where('track', $track)
                ->first();
        }

        $instance = new self($agencyId, $track, $row ?? new RtsSetting());
        self::$memo[$key] = [time(), $instance];

        return $instance;
    }

    public static function forUser(User $user, string $track = RtsSetting::TRACK_SALES): self
    {
        return self::forAgency((int) ($user->effectiveAgencyId() ?: 0), $track);
    }

    /** Drop the memo (after a save, and between tests). */
    public static function forget(?int $agencyId = null, ?string $track = null): void
    {
        if ($agencyId === null) {
            self::$memo = [];

            return;
        }
        foreach (array_keys(self::$memo) as $key) {
            [$a, $t] = explode('|', $key, 2);
            if ((int) $a === $agencyId && ($track === null || $t === $track)) {
                unset(self::$memo[$key]);
            }
        }
    }

    public function agencyId(): int
    {
        return $this->agencyId;
    }

    public function track(): string
    {
        return $this->track;
    }

    /** True when this agency has saved a settings row (false = running on defaults). */
    public function isCustomised(): bool
    {
        return $this->row->exists;
    }

    // ── Areas ─────────────────────────────────────────────────────────────

    public function lockAgentsToAreas(): bool
    {
        return (bool) $this->scalar('lock_agents_to_areas');
    }

    public function areaRefreshWarnDays(): int
    {
        return (int) $this->scalar('area_refresh_warn_days');
    }

    /** A suburb is a working area when the agency's own stock plus its Market Intelligence adverts there reach this many. */
    public function minAreaProperties(): int
    {
        return max(1, (int) $this->scalar('min_area_properties'));
    }

    /** @return string[] the portals this agency captures from ('p24', 'pp'); only these ask for a refresh. At least one. */
    public function refreshPortals(): array
    {
        return $this->list('refresh_portals');
    }

    /** Whose public holidays carry no target (two-letter country code of the public_holidays table). */
    public function holidayCountry(): string
    {
        $code = strtoupper(trim((string) $this->scalar('public_holiday_country')));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : RtsSetting::SCALAR_DEFAULTS['public_holiday_country'];
    }

    // ── Targets ───────────────────────────────────────────────────────────

    /** Business rule (conductor, 13 Oct): half-day approved leave halves that day's target. Default off. */
    public function halfDayLeaveHalvesTarget(): bool
    {
        return (bool) $this->scalar('half_day_leave_halves_target');
    }

    /** @return array<int,int> ISO weekday (1=Mon … 7=Sun) => target */
    public function defaultWeekdayTargets(): array
    {
        $stored = $this->row->default_weekday_targets;
        $out    = [];
        foreach (RtsSetting::DEFAULT_WEEKDAY_TARGETS as $day => $fallback) {
            $v          = is_array($stored) ? ($stored[$day] ?? $stored[(string) $day] ?? null) : null;
            $out[$day] = is_numeric($v) ? max(0, (int) $v) : $fallback;
        }

        return $out;
    }

    /** @return array{points:int,deals:int,listings:int,value:float} the agency default for the monthly targets (0 = none set) */
    public function defaultMonthlyTargets(): array
    {
        $stored = $this->row->default_monthly_targets;
        $out = [];
        foreach (RtsSetting::DEFAULT_MONTHLY_TARGETS as $key => $fallback) {
            $v = is_array($stored) ? ($stored[$key] ?? null) : null;
            $out[$key] = is_numeric($v) ? max(0, $key === 'value' ? (float) $v : (int) $v) : $fallback;
        }

        return $out;
    }

    public function missedItemsMode(): string
    {
        $mode = (string) $this->scalar('missed_items_mode');

        return $mode === RtsSetting::MISSED_ROLL ? RtsSetting::MISSED_ROLL : RtsSetting::MISSED_SHOW;
    }

    public function rollsOver(): bool
    {
        return $this->missedItemsMode() === RtsSetting::MISSED_ROLL;
    }

    // ── Selections (the daily list) ───────────────────────────────────────

    /** @return array<int,array{key:string,label:string,enabled:bool}> in the agency's order */
    public function selections(): array
    {
        $catalogue = RtsSetting::selectionCatalogue($this->track);

        return array_map(
            fn (array $i) => ['key' => $i['key'], 'label' => $catalogue[$i['key']], 'enabled' => $i['enabled']],
            RtsSetting::normaliseOrderedToggles($this->row->selections, $catalogue)
        );
    }

    /** @return string[] enabled selection keys, in the agency's order */
    public function enabledSelectionKeys(): array
    {
        return array_values(array_map(
            fn (array $i) => $i['key'],
            array_filter($this->selections(), fn (array $i) => $i['enabled'])
        ));
    }

    public function selectionEnabled(string $key): bool
    {
        return in_array($key, $this->enabledSelectionKeys(), true);
    }

    public function newOnMarketDays(): int
    {
        return (int) $this->scalar('new_on_market_days');
    }

    public function longOnMarketDays(): int
    {
        return (int) $this->scalar('long_on_market_days');
    }

    public function ownerKnownQuantity(): int
    {
        return (int) $this->scalar('owner_known_quantity');
    }

    public function stuckStockMinDaysOnMarket(): int
    {
        return (int) $this->scalar('stuck_stock_min_days');
    }

    public function stuckStockMaxViews(): int
    {
        return (int) $this->scalar('stuck_stock_max_views');
    }

    public function stuckStockMaxLeads(): int
    {
        return (int) $this->scalar('stuck_stock_max_leads');
    }

    /** How far back the hot-pockets selection looks at buyers, leads and sales. */
    public function hotPocketLookbackDays(): int
    {
        return (int) $this->scalar('hot_pocket_lookback_days');
    }

    /** How far back the price-drops selection looks for a reduction. */
    public function priceDropLookbackDays(): int
    {
        return (int) $this->scalar('price_drop_lookback_days');
    }

    /** How far back "my stock that is not selling" counts views and leads. */
    public function stuckStockLookbackDays(): int
    {
        return (int) $this->scalar('stuck_stock_lookback_days');
    }

    // ── Values lane 2 used to hard-code (spec 9.11) ───────────────────────

    /** @return string[] buyer-match tiers that count as a match worth a call (perfect / strong / approximate) */
    public function buyerMatchTiers(): array
    {
        return $this->list('buyer_match_tiers');
    }

    /** @return string[] buyer states that count as an active buyer (new / warm / cold) */
    public function activeBuyerStates(): array
    {
        return $this->list('active_buyer_states');
    }

    /** @return string[] property statuses that count as on the market (stock that is not selling) */
    public function onMarketStatuses(): array
    {
        return $this->list('on_market_statuses');
    }

    /** The most properties one "Add to my day" press may add. */
    public function addToDayMax(): int
    {
        return (int) $this->scalar('add_to_day_max');
    }

    /** Rows in the first window read while scanning the pool; each further window doubles until the day is full or the pool ends. */
    public function scanPageSize(): int
    {
        return (int) $this->scalar('scan_page_size');
    }

    public function hotPocketWeightBuyers(): int
    {
        return (int) $this->scalar('hot_pocket_weight_buyers');
    }

    public function hotPocketWeightLeads(): int
    {
        return (int) $this->scalar('hot_pocket_weight_leads');
    }

    public function hotPocketWeightSales(): int
    {
        return (int) $this->scalar('hot_pocket_weight_sales');
    }

    // ── Outcomes / holds (read by lane 3) ─────────────────────────────────

    public function noAnswerDropAfter(): int
    {
        return (int) $this->scalar('no_answer_drop_after');
    }

    public function holdDays(): int
    {
        return (int) $this->scalar('hold_days');
    }

    // ── Summary bar (read by lane 3) ──────────────────────────────────────

    /** @return array<int,array{key:string,label:string,enabled:bool}> in the agency's order */
    public function summarySteps(): array
    {
        $catalogue = RtsSetting::summaryStepCatalogue($this->track);

        return array_map(
            fn (array $i) => ['key' => $i['key'], 'label' => $catalogue[$i['key']], 'enabled' => $i['enabled']],
            RtsSetting::normaliseOrderedToggles($this->row->summary_steps, $catalogue)
        );
    }

    public function summaryStepEnabled(string $key): bool
    {
        foreach ($this->summarySteps() as $step) {
            if ($step['key'] === $key) {
                return $step['enabled'];
            }
        }

        return false;
    }

    public function summaryGreenFromPct(): int
    {
        return (int) $this->scalar('summary_green_from_pct');
    }

    public function summaryRedBelowPct(): int
    {
        return (int) $this->scalar('summary_red_below_pct');
    }

    /** A task is "ahead" at or above this share of where the agent should be by now (v2 phase 2, spec 11.1). */
    public function summaryAheadFromPct(): int
    {
        return (int) $this->scalar('summary_ahead_from_pct');
    }

    /** How many hours back a captured task may be dated (spec 10.5 "Backdated completion"). */
    public function captureBackDateHours(): int
    {
        return (int) $this->scalar('capture_back_date_hours');
    }

    /** Do captured tasks wait for a manager's approval before they count (spec 10.4 review queue)? Off by default: they count at once. */
    public function captureNeedsReview(): bool
    {
        return (bool) $this->scalar('capture_needs_review');
    }

    /** green at/above the green line, red below the red line, amber between. */
    public function statusForPct(float $pct): string
    {
        if ($pct >= $this->summaryGreenFromPct()) {
            return 'green';
        }

        return $pct < $this->summaryRedBelowPct() ? 'red' : 'amber';
    }

    // ── internals ─────────────────────────────────────────────────────────

    /**
     * A stored list, kept to the known choices and in the choice order; the code default when the column
     * is NULL or holds nothing usable. An empty stored list is honoured only if it was saved deliberately
     * as empty - which the saver refuses for these four - so empty/garbage means "the default".
     *
     * @return string[]
     */
    private function list(string $column): array
    {
        $stored  = $this->row->{$column};
        $choices = array_keys(RtsSetting::listChoices()[$column]);
        if (! is_array($stored)) {
            return RtsSetting::LIST_DEFAULTS[$column];
        }
        $clean = array_values(array_filter($choices, fn ($c) => in_array($c, $stored, true)));

        return $clean === [] ? RtsSetting::LIST_DEFAULTS[$column] : $clean;
    }

    /** Stored value, or the code default when the row is missing / the column is null. */
    private function scalar(string $column): mixed
    {
        $v = $this->row->{$column};

        return $v ?? RtsSetting::SCALAR_DEFAULTS[$column];
    }
}
