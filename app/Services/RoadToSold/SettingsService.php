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
 *  - Memoised per request/process; save paths call forget().
 */
class SettingsService
{
    /** @var array<string,self> */
    private static array $memo = [];

    private function __construct(
        private readonly int $agencyId,
        private readonly string $track,
        private readonly RtsSetting $row,
    ) {
    }

    public static function forAgency(int $agencyId, string $track = RtsSetting::TRACK_SALES): self
    {
        $key = $agencyId . '|' . $track;
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        $row = null;
        if ($agencyId > 0) {
            // Explicit agency filter: the model's AgencyScope is skipped for console/jobs.
            $row = RtsSetting::query()
                ->where('agency_id', $agencyId)
                ->where('track', $track)
                ->first();
        }

        return self::$memo[$key] = new self($agencyId, $track, $row ?? new RtsSetting());
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

    /** The most an agent can add to their day in one go. */
    public function addToDayMax(): int
    {
        return (int) $this->scalar('add_to_day_max');
    }

    /** Hot-pocket score weights: what one buyer / one lead / one sale adds to a suburb's score. */
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

    /** @return string[] buyer match levels that make a listing "stock for a buyer" */
    public function buyerMatchTiers(): array
    {
        return $this->pickList('buyer_match_tiers');
    }

    /** @return string[] buyer states that count as an active buyer */
    public function buyerActiveStates(): array
    {
        return $this->pickList('buyer_active_states');
    }

    /** @return string[] property statuses that count as "on the market" for the agent's own stock */
    public function onMarketStatuses(): array
    {
        return $this->pickList('on_market_statuses');
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
     * A pick-list setting: the stored values that are still in the catalogue (catalogue order), or the
     * standard list when nothing usable is stored.
     *
     * @return string[]
     */
    private function pickList(string $column): array
    {
        $stored  = $this->row->{$column};
        $allowed = array_keys(RtsSetting::LIST_CATALOGUES[$column]);
        $picked  = is_array($stored) ? array_values(array_intersect($allowed, array_map('strval', $stored))) : [];

        return $picked !== [] ? $picked : RtsSetting::LIST_DEFAULTS[$column];
    }

    /** Stored value, or the code default when the row is missing / the column is null. */
    private function scalar(string $column): mixed
    {
        $v = $this->row->{$column};

        return $v ?? RtsSetting::SCALAR_DEFAULTS[$column];
    }
}
