<?php

declare(strict_types=1);

namespace App\Services\RoadToSold;

use App\Models\User;
use App\Services\Performance\Period;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Road to Sold belt TARGETS - the ONE place a "should be here" figure is worked out (.ai/specs/road-to-sold.md 23.29, Johan 3 Nov 2026 09:29:
 * "we track from the MIC auto-load all the way to Active; count what sits where").
 *
 *   Two hand-set ends:   MIC intake per day (the existing weekday targets: rts.daily_stock) and Listings (Active) per month (the agent's own monthly
 *                        target in Budgets & Targets, else the agency default monthly target, else the settings' standard).
 *   Everything between:  DERIVED by walking back from Active through the step-to-step conversion rates:
 *                            should(station) = should(next station) / rate(station -> next)
 *                        shown as the recommendation. A station may be OVERRIDDEN: the settings' own per-step target, else the agency's recipe task target for the
 *                        step (a recipe target IS a hand-set per-step target - Johan, 3 Nov 11:02); blank = derived.
 *   Rates:               REAL, from the belt history (BeltFunnelService "reached", the same counting as the funnel) for the scope over a rolling window
 *                        (`target_window_days`). A step with fewer than `target_min_sample` records through it uses the agency's default rate and is
 *                        labelled "estimate".
 *   Scope:               'me' = the agent, 'branch' = the agent's branch, 'agency' = the agency (the tile caret). Everything is read inside the agency and
 *                        only for the people of that scope; nothing of another agent's is ever returned row by row.
 *
 * The worked example (the test): 5 listings a month at 2.5% MIC -> Active = 200 from MIC a month = 10 a working day over 20 working days.
 * Read only.
 */
final class TargetsService
{
    /** The belt from the MIC auto-load to Active: the stations a target is worked out for. Advertised follows Active and has no target of its own. */
    public const CHAIN = ['loaded', 'claimed', 'owner', 'contacted', 'booked', 'prepared', 'held', 'won', 'draft', 'active'];

    /** The seed conversion % from a station to the next (23.29.2; one per step, the agency changes them in the settings). Their product is 2.5% MIC -> Active. */
    public const DEFAULT_RATES = [
        'loaded' => 40.0, 'claimed' => 60.0, 'owner' => 80.0, 'contacted' => 50.0, 'booked' => 90.0,
        'prepared' => 95.0, 'held' => 40.0, 'won' => 95.0, 'draft' => 80.0,
    ];

    /** Summary-bar step => the belt station it reads (23.13). */
    public const SUMMARY_STATION = ['evaluations_booked' => 'booked', 'evaluations_done' => 'held', 'mandates_signed' => 'won', 'listings_live' => 'active'];

    /** @var array<string,array<string,mixed>> */
    private array $memo = [];

    public function __construct(
        private readonly BeltFunnelService $funnel,
        private readonly TargetService $targets,
        private readonly WorkdayService $workdays,
        private readonly RecipeProgressService $recipe,
    ) {
    }

    /** The people of a scope (active, not assistants), each with the branch they belong to. @return array<int,array{id:int,branch_id:?int}> */
    public function people(User $agent, string $scope): array
    {
        $agencyId = (int) ($agent->effectiveAgencyId() ?: 0);
        if ($scope === 'me') {
            return [['id' => (int) $agent->id, 'branch_id' => $agent->branch_id ? (int) $agent->branch_id : null]];
        }
        if ($scope === 'branch' && ! $agent->branch_id) {
            return [];
        }

        return DB::table('users')->where('agency_id', $agencyId)->where('is_active', 1)->whereNull('deleted_at')
            ->where(fn ($w) => $w->whereNull('is_assistant')->orWhere('is_assistant', 0))
            ->when($scope === 'branch', fn ($q) => $q->where('branch_id', (int) $agent->branch_id))
            ->limit(300)->get(['id', 'branch_id'])->map(fn ($u) => ['id' => (int) $u->id, 'branch_id' => $u->branch_id ? (int) $u->branch_id : null])->all();
    }

    /**
     * The conversion rate from every station to the next for a scope: station => [rate (0..1), estimate (bool), sample (records through the station)].
     *
     * @return array<string,array{rate:float,estimate:bool,sample:int}>
     */
    public function rates(User $agent, string $scope, CarbonInterface $day, string $track = 'sales'): array
    {
        $key = 'rates|' . $agent->id . '|' . $scope . '|' . $day->toDateString() . '|' . $track;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        $agencyId = (int) ($agent->effectiveAgencyId() ?: 0);
        $settings = SettingsService::forAgency($agencyId, $track);
        $defaults = $settings->stepRates();
        $min = $settings->targetMinSample();
        $end = CarbonImmutable::instance($day)->endOfDay();
        $period = new Period($end->subDays($settings->targetWindowDays())->startOfDay(), $end, 'Rolling window', 'window');
        $ids = array_column($this->people($agent, $scope), 'id');
        $reached = array_fill_keys(self::CHAIN, 0);
        foreach ($ids === [] ? [] : $this->funnel->figures($agencyId, $period, $track, $ids) as $one) {
            foreach (self::CHAIN as $st) {
                $reached[$st] += (int) ($one['reached'][$st] ?? 0);
            }
        }
        $agencyRates = $scope === 'agency' ? null : $this->rates($agent, 'agency', $day, $track);
        $out = [];
        foreach (array_keys(self::DEFAULT_RATES) as $i => $from) {
            $to = self::CHAIN[$i + 1];
            $n = $reached[$from];
            $real = $n >= $min && $n > 0 ? min(1.0, $reached[$to] / $n) : null;
            if ($real !== null && $real > 0) {
                $out[$from] = ['rate' => $real, 'estimate' => false, 'sample' => $n, 'reached_next' => $reached[$to]];
            } elseif ($agencyRates !== null && ! $agencyRates[$from]['estimate']) {
                // a thin step of the agent / branch uses the agency's measured rate for that step before it falls back to the seed
                $out[$from] = ['rate' => $agencyRates[$from]['rate'], 'estimate' => false, 'sample' => $n, 'reached_next' => $reached[$to]];
            } else {
                $out[$from] = ['rate' => (float) $defaults[$from] / 100, 'estimate' => true, 'sample' => $n, 'reached_next' => $reached[$to]];
            }
        }

        return $this->memo[$key] = $out;
    }

    /** How many of a station's records are needed from MIC to list ONE property, for a scope's rates (the walk back from Active to the MIC load). */
    public function perListing(User $agent, string $scope, CarbonInterface $day, string $track = 'sales'): array
    {
        $rates = $this->rates($agent, $scope, $day, $track);
        $need = 1.0;
        $estimate = false;
        foreach (array_reverse(array_keys(self::DEFAULT_RATES)) as $from) {
            $need /= $rates[$from]['rate'];
            $estimate = $estimate || $rates[$from]['estimate'];
        }

        return ['need' => $need, 'estimate' => $estimate];
    }

    /** The agent's Listings (Active) target for a month: their own monthly target, else the agency default monthly one, else the settings' standard. */
    public function listingsFor(User $agent, string $period, string $track = 'sales'): int
    {
        $own = $this->targets->monthlyFor($agent, $period)['listings'];
        if ($own > 0) {
            return $own;
        }
        $settings = SettingsService::forUser($agent, $track);
        $default = (int) ($settings->defaultMonthlyTargets()['listings'] ?? 0);

        return $default > 0 ? $default : $settings->targetListingsPerMonth();
    }

    /** Working days of the agent's month (the same days the daily engine counts: calendar, holidays, leave). */
    public function workingDays(User $agent, CarbonInterface $day, string $track = 'sales'): int
    {
        $key = 'wd|' . $agent->id . '|' . CarbonImmutable::instance($day)->format('Y-m') . '|' . $track;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        $d = CarbonImmutable::instance($day)->startOfMonth();
        $n = 0;
        for ($end = $d->endOfMonth(); $d->lte($end); $d = $d->addDay()) {
            $this->workdays->isWorkday($agent, $d, $track) && $n++;
        }

        return $this->memo[$key] = $n;
    }

    /**
     * The targets of a scope for the month of $day: the Listings figure, the derived chain, the overrides and the hand-set MIC intake.
     *
     * @return array{scope:string,agents:int,period:string,working_days:int,listings:int,mic_per_day:float,mic_per_month:float,stations:array<string,array<string,mixed>>}
     */
    public function forScope(User $agent, string $scope, CarbonInterface $day, string $track = 'sales'): array
    {
        $key = 'fig|' . $agent->id . '|' . $scope . '|' . $day->toDateString() . '|' . $track;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        $agencyId = (int) ($agent->effectiveAgencyId() ?: 0);
        $settings = SettingsService::forAgency($agencyId, $track);
        $period = CarbonImmutable::instance($day)->format('Y-m');
        $people = $this->people($agent, $scope);
        $users = $people === [] ? collect() : User::query()->withoutGlobalScopes()->whereIn('id', array_column($people, 'id'))->get();
        $agents = max(1, $users->count());

        $listings = 0;
        $micMonth = 0;
        $exact = $users->count() <= 40;   // each person's month target walks the month's days (holidays, leave, pauses): beyond 40 people the viewer's own month stands in
        foreach ($users as $u) {
            $listings += $this->listingsFor($u, $period, $track);
            $exact && $micMonth += $this->workdays->monthTarget($u, $day, $track);
        }
        if (! $exact) {
            $micMonth = $this->workdays->monthTarget($agent, $day, $track) * $users->count();
        }
        $wd = max(1, $this->workingDays($agent, $day, $track));
        $rates = $this->rates($agent, $scope, $day, $track);
        $overrides = $settings->stepOverrides();

        // walk back from Active
        $derived = [];
        $estimate = [];
        $derived['active'] = (float) $listings;
        $estimate['active'] = false;
        $steps = array_keys(self::DEFAULT_RATES);
        for ($i = count($steps) - 1; $i >= 0; $i--) {
            $from = $steps[$i];
            $to = self::CHAIN[$i + 1];
            $derived[$from] = $derived[$to] / $rates[$from]['rate'];
            $estimate[$from] = $estimate[$to] || $rates[$from]['estimate'];
        }

        $out = [];
        foreach (self::CHAIN as $i => $st) {
            $source = 'derived';
            $month = $derived[$st];
            if ($st === 'loaded') {
                $source = 'set';                       // MIC intake per day is the agency's own hand-set number (the weekday targets)
                $month = (float) $micMonth;
            } elseif (isset($overrides[$st])) {
                $source = 'override';
                $month = $overrides[$st] * $agents;
            } elseif (($rm = $this->recipeScope($users, $agent, $st, $day, $track, $exact)) !== null) {
                $source = 'recipe';        // the agency's recipe task target for the step is its hand-set target: it wins over the derived figure
                $month = $rm;
            }
            $next = self::CHAIN[$i + 1] ?? null;
            $out[$st] = [
                'month' => round($month, 1), 'day' => round($month / $wd, 1), 'derived_month' => round($derived[$st], 1), 'derived_day' => round($derived[$st] / $wd, 1),
                'source' => $source, 'estimate' => $source === 'derived' ? (bool) $estimate[$st] : false,
                'rate_next' => $next !== null ? (int) round($rates[$st]['rate'] * 100) : null, 'rate_estimate' => $next !== null ? (bool) $rates[$st]['estimate'] : false,
                'reached' => $next !== null ? (int) $rates[$st]['sample'] : (int) $rates['draft']['reached_next'],
            ];
        }

        return $this->memo[$key] = [
            'scope' => $scope, 'agents' => $users->count(), 'period' => $period, 'working_days' => $wd, 'listings' => $listings,
            'mic_per_day' => round($derived['loaded'] / $wd, 1), 'mic_per_month' => round($derived['loaded'], 1), 'stations' => $out,
        ];
    }

    /** The one station without a tile (Belt v2): its row is the step from the signed result to Draft, named for what it is. */
    public const NO_TILE_LABELS = ['won' => 'Won → Draft'];

    /** The station's name on the Targets screen: the TILE name (the belt config's short name), so the two can never drift; a station without a tile gets its step name. */
    public static function stationName(string $station): string
    {
        $s = BeltService::station($station);
        if ($s !== null && empty($s['no_tile'])) {
            return (string) $s['short'];
        }

        return self::NO_TILE_LABELS[$station] ?? (string) ($s['label'] ?? BeltFunnelService::STATIONS[$station][0]);
    }

    /**
     * The panel beside the targets (23.29.4): ONE dense row per belt station for a scope. Every figure is a whole number (properties are counted: derived figures round UP), and there
     * is ONE "should be this month" per station (override > recipe task target > worked out; for Stock loaded the worked-out need, with the hand-set intake shown as "set N/day").
     * "est." sits on a station's own conversion rate only where the standard (seed) rate was used.
     *
     * @return array{rows:list<array<string,mixed>>,figures:array<string,mixed>,window_days:int,min_sample:int,line:array<string,mixed>,overrides:array<string,float>}
     */
    public function panel(User $agent, string $scope, CarbonInterface $day, string $track = 'sales'): array
    {
        $agencyId = (int) ($agent->effectiveAgencyId() ?: 0);
        $settings = SettingsService::forAgency($agencyId, $track);
        $fig = $this->forScope($agent, $scope, $day, $track);
        $wd = max(1, (int) $fig['working_days']);
        $ids = array_column($this->people($agent, $scope), 'id');
        $byStage = $ids === [] ? [] : DB::table('rts_prospects')->where('agency_id', $agencyId)->where('track', $track)->whereIn('user_id', $ids)
            ->whereNull('deleted_at')->whereNull('closed_at')->whereNull('released_at')->whereNull('parked_at')->selectRaw('stage, COUNT(*) as n')->groupBy('stage')->pluck('n', 'stage')->all();
        $overrides = $settings->stepOverrides();
        $rows = [];
        foreach (self::CHAIN as $st) {
            $now = 0;
            foreach (BeltFunnelService::STATIONS[$st][2] as $stage) {
                $now += (int) ($byStage[$stage] ?? 0);
            }
            $f = $fig['stations'][$st];
            $should = $st === 'loaded' ? $f['derived_month'] : $f['month'];
            $rows[] = [
                'station' => $st, 'label' => self::stationName($st), 'now' => $now, 'reached' => (int) $f['reached'],
                'rate_next' => $f['rate_next'], 'rate_estimate' => (bool) $f['rate_estimate'],
                'should_month' => (int) ceil($should - 1e-9), 'should_day' => (int) ceil($should / $wd - 1e-9),
                'source' => $f['source'], 'override' => $overrides[$st] ?? null,
                'set_day' => $st === 'loaded' ? (int) ceil($f['month'] / $wd - 1e-9) : null,   // the hand-set MIC intake per day (the weekday targets), read-only here
            ];
        }

        return ['rows' => $rows, 'figures' => $fig, 'window_days' => $settings->targetWindowDays(), 'min_sample' => $settings->targetMinSample(), 'line' => $this->perListingLine($agent, $day, $track), 'overrides' => $overrides];
    }

    /**
     * The month figure of the recipe task target(s) of a station for one person: a daily target x the month's working days, a weekly one x working days / 5, a monthly one as it
     * stands. Null when the station has no task or the agency set no target for it (blank = derived).
     */
    public function recipeMonth(User $user, string $station, CarbonInterface $day, string $track = 'sales'): ?float
    {
        $keys = (array) (BeltService::station($station)['tasks'] ?? []);
        if ($keys === [] || $station === 'loaded') {
            return null;
        }
        $key = 'rm|' . $user->id . '|' . $station . '|' . CarbonImmutable::instance($day)->format('Y-m-d') . '|' . $track;
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }
        $d = CarbonImmutable::instance($day);
        $tasks = (array) ($this->recipe->forAgent($user, $track, $d->isToday() ? now() : $d->endOfDay())['tasks'] ?? []);
        $rows = array_filter($tasks, fn ($t) => in_array($t['key'], $keys, true) && (int) $t['target'] > 0);
        $wd = $this->workingDays($user, $day, $track);
        $month = 0.0;
        foreach ($rows as $t) {
            $month += match ($t['unit']) {
                'day' => $t['target'] * $wd,
                'week' => $t['target'] * $wd / 5,
                'month' => (float) $t['target'],
                default => 0.0,
            };
        }

        return $this->memo[$key] = $month > 0 ? $month : null;
    }

    /** The recipe month figure of a scope: the sum over its people (beyond 60 people the viewer's own figure x the people). Null when nobody has one. */
    private function recipeScope($users, User $agent, string $station, CarbonInterface $day, string $track, bool $exact): ?float
    {
        if ($users->isEmpty()) {
            return null;
        }
        if ($users->count() > 60) {
            $one = $this->recipeMonth($agent, $station, $day, $track);

            return $one === null ? null : $one * $users->count();
        }
        $sum = 0.0;
        foreach ($users as $u) {
            $sum += $this->recipeMonth($u, $station, $day, $track) ?? 0.0;
        }

        return $sum > 0 ? $sum : null;
    }

    /** The month target of ONE station for a scope (override wins, else derived; Loaded = the hand-set intake). Null for a station with no target (Advertised). */
    public function stationTarget(User $agent, string $scope, string $station, CarbonInterface $day, string $track = 'sales'): ?array
    {
        return $this->forScope($agent, $scope, $day, $track)['stations'][$station] ?? null;
    }

    /**
     * "Should be on X" for the summary bar: the station's month target for the agent, pro-rated by the share of the month's working days that have passed
     * (today included). Null when the step is not on the belt chain.
     */
    public function shouldBeToday(User $agent, string $summaryKey, CarbonInterface $day, string $track = 'sales'): ?float
    {
        $station = self::SUMMARY_STATION[$summaryKey] ?? null;
        if ($station === null) {
            return null;
        }
        $t = $this->stationTarget($agent, 'me', $station, $day, $track);
        if ($t === null || $t['month'] <= 0) {
            return null;
        }
        $d = CarbonImmutable::instance($day)->startOfDay();
        $total = 0;
        $passed = 0;
        for ($x = $d->startOfMonth(); $x->month === $d->month; $x = $x->addDay()) {
            if ($this->workdays->isWorkday($agent, $x, $track)) {
                $total++;
                $x->lte($d) && $passed++;
            }
        }

        return $total > 0 ? round($t['month'] * $passed / $total, 1) : null;
    }

    /**
     * The one line on the Road to Sold page: "To list 1 property: you need X from MIC · branch avg Y · agency avg Z".
     *
     * @return array{me:int,branch:?int,agency:int,estimate:bool,text:string}
     */
    public function perListingLine(User $agent, CarbonInterface $day, string $track = 'sales'): array
    {
        $me = $this->perListing($agent, 'me', $day, $track);
        $branch = $agent->branch_id ? $this->perListing($agent, 'branch', $day, $track) : null;
        $agency = $this->perListing($agent, 'agency', $day, $track);
        $n = fn (float $v): int => (int) ceil(round($v, 6));
        $est = $me['estimate'] || ($branch['estimate'] ?? false) || $agency['estimate'];
        $plain = 'To list 1 property: you need ' . $n($me['need']) . ' from MIC'
            . ($branch !== null ? ' · branch avg ' . $n($branch['need']) : '') . ' · agency avg ' . $n($agency['need']);

        return ['me' => $n($me['need']), 'branch' => $branch !== null ? $n($branch['need']) : null, 'agency' => $n($agency['need']), 'estimate' => $est, 'plain' => $plain, 'text' => $plain . ($est ? ' (estimate)' : '')];
    }
}
