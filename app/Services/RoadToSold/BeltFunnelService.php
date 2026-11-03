<?php

namespace App\Services\RoadToSold;

use App\Models\User;
use App\Services\Performance\Period;
use Illuminate\Support\Facades\DB;

/**
 * Road to Sold v4 (road-to-sold.md 23.12): the belt funnel and its off-ramps.
 *
 * THE FUNNEL: Loaded -> Claimed -> Owner -> Contacted -> Booked -> Prepared -> Held -> Won -> Draft -> Active -> Advertised. A property COUNTS at a station for a period
 * when a `placed` / `stage` row of its history (`rts_prospect_events`) that reaches that station - or crosses it, skipping through to a later one - is dated inside the
 * period; once per property per agent per station. Conversion = reached at the next station / reached at this one. Nothing is stored: every figure is read from the history.
 *
 * THE OFF-RAMPS: every `park`, `release` and `close` row of the period is an off-ramp at the station the record was at, with the reason written on it (the agency's own wording).
 * "Not now" (undecided) is counted and shown but is a follow-up, not a loss, so it is left out of the loss rate the training flag reads. Draft / Active blockers are DERIVED from
 * the property (BlockerService) and counted as they stand now.
 *
 * WHO SEES WHAT: an agent sees their own funnel next to the branch's and the agency's (figures only for the others, never another agent's rows); a branch manager also sees
 * every agent of their branch; an admin with the agency breadth sees every agent. The agent rows come from VisibleAgents (the query layer), never from a hidden link.
 *
 * THE TRAINING FLAG: an agent's loss rate at a station (properties off-ramped there / properties that reached it, in the agency's window) at least
 * `flag_offramp_excess_pct` percent above the rate of their branch colleagues (the agency's other agents when the branch has too few properties through that station
 * to compare) - and only when the agent has at least `flag_min_sample` properties through the station. It names the station and the top reason, nothing else about the person.
 */
class BeltFunnelService
{
    /** Belt stations, in order: key => [label, short, the stage keys that count as being there]. */
    public const STATIONS = [
        'loaded'     => ['Stock loaded',         'Loaded',     ['found']],
        'claimed'    => ['Claimed',              'Claimed',    ['claimed']],
        'owner'      => ['Owner found',          'Owner',      ['owner_found']],
        'contacted'  => ['Contacted',            'Contacted',  ['contacted']],
        'booked'     => ['Evaluation booked',    'Booked',     ['appointment_set']],
        'prepared'   => ['Presentation prepared', 'Prepared',  ['presentation_prepared']],
        'held'       => ['Evaluation held',      'Held',       ['evaluation_done']],
        'won'        => ['Result: won',          'Won',        ['mandate_signed']],
        'draft'      => ['Draft',                'Draft',      ['draft']],
        'active'     => ['Active',               'Active',     ['active']],
        'advertised' => ['Advertised',           'Advertised', ['listing_live', 'advertising']],
    ];

    /** Stations where a property can be lost and the flag can fire (the others are forward moves or derived blockers). */
    public const FLAGGABLE = ['claimed', 'owner', 'contacted', 'booked', 'held'];

    /** The reason wording that means "follow up", not "lost". */
    private const TEMPORARY = ['Not now'];

    public static function stationOfStage(?string $stage): ?string
    {
        foreach (self::STATIONS as $key => [, , $stages]) {
            if (in_array($stage, $stages, true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * The raw figures of every agent of the agency for a period: user id => [reached => [station => n], off => [station => [reason => n]], lost => [station => n]].
     *
     * @return array<int,array{reached:array<string,int>,off:array<string,array<string,int>>,lost:array<string,int>}>
     */
    public function figures(int $agencyId, Period $period, string $track = 'sales', ?array $userIds = null): array
    {
        $from = $period->start->toDateTimeString();
        $to = $period->end->toDateTimeString();
        $reached = [];
        $moves = DB::table('rts_prospect_events')->where('agency_id', $agencyId)->where('track', $track)->whereIn('kind', ['placed', 'stage'])
            ->when($userIds !== null, fn ($q) => $q->whereIn('user_id', array_map('intval', $userIds)))
            ->whereBetween('occurred_at', [$from, $to])->get(['prospect_id', 'user_id', 'kind', 'from_stage', 'to_stage']);
        foreach ($moves as $m) {
            foreach ($this->stationsReached($m->kind, $m->from_stage, $m->to_stage) as $st) {
                $reached[(int) $m->user_id][$st][(int) $m->prospect_id] = true;
            }
        }

        $off = [];
        $lost = [];
        $outs = DB::table('rts_prospect_events')->where('agency_id', $agencyId)->where('track', $track)->whereIn('kind', ['park', 'release', 'close'])
            ->when($userIds !== null, fn ($q) => $q->whereIn('user_id', array_map('intval', $userIds)))
            ->whereBetween('occurred_at', [$from, $to])->get(['prospect_id', 'user_id', 'kind', 'to_stage', 'note']);
        foreach ($outs as $o) {
            $st = self::stationOfStage($o->to_stage);
            if ($st === null) {
                continue;
            }
            $reason = trim((string) $o->note) !== '' ? trim((string) $o->note) : ($o->kind === 'close' ? 'Closed' : ($o->kind === 'release' ? 'Given back' : 'No reason given'));
            $off[(int) $o->user_id][$st][$reason][(int) $o->prospect_id] = true;
            if (! in_array($reason, self::TEMPORARY, true)) {
                $lost[(int) $o->user_id][$st][(int) $o->prospect_id] = true;
            }
        }

        $out = [];
        foreach (array_unique(array_merge(array_keys($reached), array_keys($off))) as $uid) {
            $out[$uid] = ['reached' => [], 'off' => [], 'lost' => []];
            foreach ($reached[$uid] ?? [] as $st => $ids) {
                $out[$uid]['reached'][$st] = count($ids);
            }
            foreach ($off[$uid] ?? [] as $st => $reasons) {
                foreach ($reasons as $reason => $ids) {
                    $out[$uid]['off'][$st][$reason] = count($ids);
                }
            }
            foreach ($lost[$uid] ?? [] as $st => $ids) {
                $out[$uid]['lost'][$st] = count($ids);
            }
        }

        return $out;
    }

    /**
     * The properties that reached $station (one station, or ANY of several) in the period, for these agents only: prospect id => when (the first time in the period). The SAME rule `figures()` counts with
     * (stationsReached), so a tile's "moved on today" and the list behind it can never disagree with the funnel (road-to-sold.md 23.24).
     *
     * @param  int[]  $userIds
     * @return array<int,string>
     */
    public function reachedProspects(int $agencyId, Period $period, string $track, array $userIds, string|array $station): array
    {
        $stations = array_values(array_filter((array) $station, fn ($k) => isset(self::STATIONS[$k])));
        if ($userIds === [] || $stations === []) {
            return [];
        }
        $found = [];
        $q = DB::table('rts_prospect_events')->where('agency_id', $agencyId)->where('track', $track)->whereIn('kind', ['placed', 'stage'])
            ->whereIn('user_id', array_map('intval', $userIds))
            ->whereBetween('occurred_at', [$period->start->toDateTimeString(), $period->end->toDateTimeString()])->orderBy('occurred_at');
        foreach ($q->get(['prospect_id', 'kind', 'from_stage', 'to_stage', 'occurred_at']) as $e) {
            if (! isset($found[(int) $e->prospect_id]) && array_intersect($stations, $this->stationsReached($e->kind, $e->from_stage, $e->to_stage)) !== []) {
                $found[(int) $e->prospect_id] = (string) $e->occurred_at;
            }
        }

        return $found;
    }

    /** The station after $station on the belt (null at the end). */
    public static function nextStation(string $station): ?string
    {
        $keys = array_keys(self::STATIONS);
        $i = array_search($station, $keys, true);

        return $i === false ? null : ($keys[$i + 1] ?? null);
    }

    /** @return list<string> the stations one history row reaches or crosses (a "placed" row reaches only the station it was placed at) */
    private function stationsReached(string $kind, ?string $from, ?string $to): array
    {
        if ($to === null) {
            return [];
        }
        if ($kind === 'placed' || $from === null) {
            // a property that came onto the belt at a later station (a claim straight from Market Intelligence starts at Claimed) was on the stations before it too:
            // it counts there as well, so "to next" can never read above 100% because MIC claims outnumber loaded stock (road-to-sold.md 23.12)
            $hi = ProspectStages::order($to);
            if (self::stationOfStage($to) === null) {
                return [];
            }
            $out = [];
            foreach (self::STATIONS as $key => [, , $stages]) {
                foreach ($stages as $stage) {
                    if (ProspectStages::order($stage) <= $hi) {
                        $out[] = $key;
                        break;
                    }
                }
            }

            return $out;
        }
        $lo = ProspectStages::order($from);
        $hi = ProspectStages::order($to);
        $out = [];
        foreach (self::STATIONS as $key => [, , $stages]) {
            foreach ($stages as $stage) {
                $o = ProspectStages::order($stage);
                if ($o > $lo && $o <= $hi) {
                    $out[] = $key;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * One funnel from a set of agents' figures.
     *
     * @param  int[]  $agentIds
     * @param  array<int,array>  $figures
     * @return array{stations:list<array>,reasons:array<string,array<string,int>>}
     */
    public function funnelOf(array $agentIds, array $figures): array
    {
        $reached = array_fill_keys(array_keys(self::STATIONS), 0);
        $lost = array_fill_keys(array_keys(self::STATIONS), 0);
        $reasons = [];
        foreach ($agentIds as $id) {
            $f = $figures[$id] ?? null;
            if (! $f) {
                continue;
            }
            foreach ($f['reached'] as $st => $n) {
                $reached[$st] += $n;
            }
            foreach ($f['lost'] as $st => $n) {
                $lost[$st] += $n;
            }
            foreach ($f['off'] as $st => $rs) {
                foreach ($rs as $reason => $n) {
                    $reasons[$st][$reason] = ($reasons[$st][$reason] ?? 0) + $n;
                }
            }
        }
        $keys = array_keys(self::STATIONS);
        $rows = [];
        foreach ($keys as $i => $key) {
            $next = $keys[$i + 1] ?? null;
            $rows[] = [
                'key' => $key, 'label' => self::STATIONS[$key][0], 'short' => self::STATIONS[$key][1], 'reached' => $reached[$key],
                // never above 100%: more at the next station than here happens only when they got here in an earlier period (they reached this station before the dates
                // asked for), so the share is shown as 100% and the page says why
                'to_next_pct' => ($next && $reached[$key] > 0) ? min(100.0, round($reached[$next] / $reached[$key] * 100, 1)) : null,
                'to_next_capped' => $next && $reached[$key] > 0 && $reached[$next] > $reached[$key],
                'lost' => $lost[$key], 'lost_pct' => $reached[$key] > 0 ? round($lost[$key] / $reached[$key] * 100, 1) : null,
                'reasons' => collect($reasons[$key] ?? [])->sortDesc()->all(),
            ];
        }

        return ['stations' => $rows, 'reasons' => $reasons];
    }

    /**
     * The funnel screen's data for one viewer and one period (and optionally a second one to compare against).
     *
     * @return array<string,mixed>
     */
    public function forViewer(User $viewer, Period $period, ?Period $compare = null, string $track = 'sales'): array
    {
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);
        $users = User::query()->withoutGlobalScopes()->where('agency_id', $agencyId)->get(['id', 'name', 'branch_id'])->keyBy('id');
        $me = (int) $viewer->ownershipUserId();
        $myBranch = (int) ($users[$me]->branch_id ?? $viewer->effectiveBranchId() ?? 0);
        $branchIds = $users->filter(fn ($u) => $myBranch > 0 && (int) $u->branch_id === $myBranch)->keys()->all();
        $allIds = $users->keys()->all();

        $build = function (Period $p) use ($agencyId, $track, $me, $branchIds, $allIds) {
            $fig = $this->figures($agencyId, $p, $track);

            return ['figures' => $fig, 'me' => $this->funnelOf([$me], $fig), 'branch' => $this->funnelOf($branchIds, $fig), 'agency' => $this->funnelOf($allIds, $fig)];
        };
        $main = $build($period);
        $level = Scope::level($viewer);
        $agentRows = [];
        if (in_array($level, ['branch', 'all'], true)) {
            foreach (VisibleAgents::for($viewer) as $agent) {
                $agentRows[] = ['id' => (int) $agent->id, 'name' => (string) $agent->name, 'branch' => (string) ($agent->branch?->name ?? ''), 'funnel' => $this->funnelOf([(int) $agent->id], $main['figures'])];
            }
        }
        $other = $compare ? $build($compare) : null;

        return [
            'period' => $period, 'compare' => $compare, 'level' => $level, 'stations' => array_map(fn ($k) => ['key' => $k, 'label' => self::STATIONS[$k][0], 'short' => self::STATIONS[$k][1]], array_keys(self::STATIONS)),
            'me' => $main['me'], 'branch' => $main['branch'], 'agency' => $main['agency'], 'agents' => $agentRows,
            'compare_me' => $other['me'] ?? null, 'compare_branch' => $other['branch'] ?? null, 'compare_agency' => $other['agency'] ?? null,
            'blockers' => $this->blockerCounts($viewer, $level),
        ];
    }

    /**
     * The properties behind one figure (road-to-sold.md 23.12 "one drill-down per figure"): kind "reached" = the properties that reached $station in the period, kind "lost" =
     * the ones off-ramped there (optionally one $reason). $scope = me | branch | agency picks whose work. Rows are only ever the viewer's own and, for a manager, the
     * agents they may see (VisibleAgents) - a colleague's properties are never listed to an agent, who gets the figure only ($allowed = false).
     *
     * @return array{allowed:bool,title:string,rows:list<array<string,mixed>>}
     */
    public function drill(User $viewer, Period $period, string $station, string $kind, ?string $reason, string $scope, string $track = 'sales'): array
    {
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);
        $label = self::STATIONS[$station][0] ?? $station;
        $title = ($kind === 'lost' ? 'Lost at ' . $label . ($reason ? ': ' . $reason : '') : 'Reached ' . $label);
        $me = (int) $viewer->ownershipUserId();
        $users = User::query()->withoutGlobalScopes()->where('agency_id', $agencyId)->get(['id', 'name', 'branch_id'])->keyBy('id');
        $myBranch = (int) ($users[$me]->branch_id ?? $viewer->effectiveBranchId() ?? 0);
        $scoped = match ($scope) {
            'branch' => $users->filter(fn ($u) => $myBranch > 0 && (int) $u->branch_id === $myBranch)->keys()->all(),
            'agency' => $users->keys()->all(),
            default  => [$me],
        };
        // the breadth decides whose PROPERTIES may be listed (never a hidden link): own work always; the branch for a branch-wide viewer; the agency for an agency-wide one
        $level = Scope::level($viewer);
        $allowed = $scope === 'me' || ($scope === 'branch' && in_array($level, ['branch', 'all'], true)) || ($scope === 'agency' && $level === 'all');
        $ids = $allowed ? array_values(array_unique(array_map('intval', $scoped))) : [];
        if (! isset(self::STATIONS[$station]) || $ids === []) {
            return ['allowed' => $allowed, 'title' => $title, 'rows' => []];
        }
        $from = $period->start->toDateTimeString();
        $to = $period->end->toDateTimeString();
        $found = [];   // prospect id => [when, reason, user]
        if ($kind === 'lost') {
            $q = DB::table('rts_prospect_events')->where('agency_id', $agencyId)->where('track', $track)->whereIn('kind', ['park', 'release', 'close'])
                ->whereIn('user_id', $ids)->whereBetween('occurred_at', [$from, $to])->orderBy('occurred_at');
            foreach ($q->get(['prospect_id', 'user_id', 'to_stage', 'note', 'occurred_at', 'kind']) as $e) {
                if (self::stationOfStage($e->to_stage) !== $station) {
                    continue;
                }
                $why = trim((string) $e->note) !== '' ? trim((string) $e->note) : ($e->kind === 'close' ? 'Closed' : ($e->kind === 'release' ? 'Given back' : 'No reason given'));
                if ($reason !== null && $reason !== '' && $why !== $reason) {
                    continue;
                }
                if (($reason === null || $reason === '') && in_array($why, self::TEMPORARY, true)) {
                    continue;   // the loss figure leaves "Not now" out; the same list does
                }
                $found[(int) $e->prospect_id] = ['when' => $e->occurred_at, 'reason' => $why, 'user' => (int) $e->user_id];
            }
        } else {
            $q = DB::table('rts_prospect_events')->where('agency_id', $agencyId)->where('track', $track)->whereIn('kind', ['placed', 'stage'])
                ->whereIn('user_id', $ids)->whereBetween('occurred_at', [$from, $to])->orderBy('occurred_at');
            foreach ($q->get(['prospect_id', 'user_id', 'kind', 'from_stage', 'to_stage', 'occurred_at']) as $e) {
                if (in_array($station, $this->stationsReached($e->kind, $e->from_stage, $e->to_stage), true) && ! isset($found[(int) $e->prospect_id])) {
                    $found[(int) $e->prospect_id] = ['when' => $e->occurred_at, 'reason' => null, 'user' => (int) $e->user_id];
                }
            }
        }
        if ($found === []) {
            return ['allowed' => $allowed, 'title' => $title, 'rows' => []];
        }
        $props = \App\Models\RoadToSold\RtsProspect::withoutGlobalScopes()->withTrashed()->whereIn('id', array_keys($found))
            ->with(['trackedProperty' => fn ($t) => $t->withoutGlobalScopes()])->get()->keyBy('id');
        $rows = [];
        foreach ($found as $pid => $f) {
            $p = $props[$pid] ?? null;
            $rows[] = [
                'prospect_id' => $pid, 'address' => $p?->trackedProperty ? ($p->trackedProperty->displayAddress() ?: 'Property #' . $p->tracked_property_id) : 'Property #' . $pid,
                'agent' => (string) ($users[$f['user']]->name ?? ''), 'when' => $f['when'], 'reason' => $f['reason'],
                'now' => $p ? ($p->released_at ? 'Given back' : ($p->closed_at ? 'Closed' : ($p->parked_at ? 'Set aside' : ProspectStages::label((string) $p->stage)))) : '-',
            ];
        }
        usort($rows, fn ($a, $b) => strcmp((string) $b['when'], (string) $a['when']));

        return ['allowed' => $allowed, 'title' => $title, 'rows' => $rows];
    }

    /** The derived Draft / Active blockers as they stand now, for the viewer's breadth (own / branch / agency): station => reason label => count. @return array<string,array<string,int>> */
    public function blockerCounts(User $viewer, ?string $level = null): array
    {
        $svc = app(BlockerService::class);
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);
        $q = \App\Models\RoadToSold\RtsProspect::query()->visibleTo($viewer)->owned()->whereIn('rts_prospects.stage', [ProspectStages::DRAFT, ProspectStages::ACTIVE]);
        $labels = \App\Models\RoadToSold\RtsParkReason::hasStationColumns()
            ? \App\Models\RoadToSold\RtsParkReason::withoutGlobalScopes()->where('agency_id', $agencyId)->where('kind', 'blocker')->pluck('label', 'key')
            : collect();
        $out = [];
        foreach ($q->limit(500)->get() as $p) {
            $b = $svc->firstBlocker($p);
            if ($b) {
                $label = $labels[$b['key']] ?? collect(ParkReasonService::stationDefaults())->firstWhere('key', $b['key'])['label'] ?? $b['key'];
                $out[$b['station']][$label] = ($out[$b['station']][$label] ?? 0) + 1;
            }
        }

        return $out;
    }

    /**
     * The training flags for the agency's agents: agent id => ["offramp_<station>" => flag]. A flag: pct = the agent's loss rate, median = the rate it is read against, num / den,
     * severity (how far above, 0..), compare_label, text, top_reason. See the class note for the rule.
     *
     * @param  array<int,array>  $figures
     * @param  int[]  $agentIds
     * @return array<int,array<string,array<string,mixed>>>
     */
    public function flags(int $agencyId, array $figures, array $agentIds, SettingsService $settings): array
    {
        $min = $settings->flagMinSample();
        $excess = $settings->flagOffRampExcessPct();
        $users = User::query()->withoutGlobalScopes()->where('agency_id', $agencyId)->get(['id', 'name', 'branch_id'])->keyBy('id');
        $out = [];
        foreach ($agentIds as $id) {
            $mine = $figures[$id] ?? null;
            $branch = (int) ($users[$id]->branch_id ?? 0);
            if (! $mine) {
                continue;
            }
            foreach (self::FLAGGABLE as $st) {
                $den = (int) ($mine['reached'][$st] ?? 0);
                $num = (int) ($mine['lost'][$st] ?? 0);
                if ($den < $min || $num <= 0) {
                    continue;
                }
                $rate = $num / $den * 100;
                $mates = array_values(array_filter($agentIds, fn ($o) => $o !== $id && $branch > 0 && (int) ($users[$o]->branch_id ?? 0) === $branch));
                $against = $this->rateOf($mates, $figures, $st);
                $label = 'branch';
                if ($against === null || $against['den'] < $min) {
                    $against = $this->rateOf(array_values(array_filter($agentIds, fn ($o) => $o !== $id)), $figures, $st);
                    $label = 'agency';
                }
                if ($against === null || $against['den'] < $min || $against['rate'] <= 0) {
                    continue;
                }
                if ($rate < $against['rate'] * (1 + $excess / 100)) {
                    continue;
                }
                $top = collect($mine['off'][$st] ?? [])->reject(fn ($n, $reason) => in_array($reason, self::TEMPORARY, true))->sortDesc()->keys()->first();
                $name = (string) ($users[$id]->name ?? 'This agent');
                $out[$id]['offramp_' . $st] = [
                    'pct' => round($rate, 1), 'median' => round($against['rate'], 1), 'num' => $num, 'den' => $den,
                    'severity' => round(min(5.0, $rate / $against['rate'] - 1), 4), 'compare_label' => $label . ' rate', 'station' => $st, 'top_reason' => $top,
                    'text' => $name . ' loses ' . round($rate) . '% of properties at ' . self::STATIONS[$st][1] . ' (' . $label . ' ' . round($against['rate']) . '%)' . ($top ? ', mostly: ' . $top : ''),
                ];
            }
        }

        return $out;
    }

    /** @param int[] $ids @param array<int,array> $figures @return array{rate:float,den:int}|null */
    private function rateOf(array $ids, array $figures, string $station): ?array
    {
        $den = 0;
        $num = 0;
        foreach ($ids as $id) {
            $den += (int) ($figures[$id]['reached'][$station] ?? 0);
            $num += (int) ($figures[$id]['lost'][$station] ?? 0);
        }

        return $den > 0 ? ['rate' => $num / $den * 100, 'den' => $den] : null;
    }
}
