<?php

namespace App\Services\RoadToSold;

use App\Models\RoadToSold\RtsOutcomeDefinition;
use App\Models\User;
use App\Services\Performance\Period;
use App\Services\Performance\PeriodComparison;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AT-454 - the Road to Sold report: one row per agent, one column per figure, for a period, optionally
 * beside a second period. Spec: .ai/specs/road-to-sold.md section 10.6.
 *
 * Figures (each one drills to its rows through DrilldownService, which counts the same way):
 *   target            the call target over the period (WorkdayService - holidays and leave carry none)
 *   worked            list items worked toward the target
 *   attempts / conversations / evaluations_booked / each outcome / dropped   from the call log
 *   added_worked      items worked on top of the target ("added" via Add to my day)
 *   missed            items missed or rolled over
 *
 * The caller has already restricted $agents to agents the viewer may see.
 */
class ReportService
{
    /** key => [label, direction-of-good] for the fixed columns, in display order. */
    public const FIXED = [
        'target'            => ['Target', 'neutral'],
        'worked'            => ['Worked', 'higher_is_better'],
        'attempts'          => ['Attempts', 'higher_is_better'],
        'conversations'     => ['Conversations', 'higher_is_better'],
    ];
    public const TAIL = [
        'evaluations_booked' => ['Evaluations booked', 'higher_is_better'],
        'added_worked'      => ['Extra over target', 'higher_is_better'],
        'missed'            => ['Missed / rolled over', 'lower_is_better'],
        'dropped'           => ['Dropped after no-answers', 'lower_is_better'],
    ];

    public function __construct(private readonly WorkdayService $workdays)
    {
    }

    /**
     * @param  \Illuminate\Support\Collection<int, User>  $agents  (id, name, agency_id, branch_id, role, ...)
     * @return array{columns:array<int,array<string,mixed>>, rows:array<int,array<string,mixed>>, totals:array<string,mixed>, compared:bool}
     */
    public function build(int $agencyId, $agents, Period $period, ?Period $compare, string $track = 'sales'): array
    {
        // Spec 11.1: an assistant is not a target-carrying row - their calls are already the agent's (rts_contact_outcomes.user_id).
        $agents = $agents->reject(fn ($u) => (bool) ($u->is_assistant ?? false))->values();
        $ids = $agents->pluck('id')->map(fn ($v) => (int) $v)->all();
        $current = $this->figures($agencyId, $agents, $period, $track);
        $previous = $compare ? $this->figures($agencyId, $agents, $compare, $track) : null;

        $columns = $this->columns($agencyId, $period, $compare, $ids, $track);

        $rows = [];
        foreach ($agents as $a) {
            $rows[] = [
                'agent_id' => (int) $a->id,
                'name'     => $a->name,
                'branch_id' => $a->branch_id ? (int) $a->branch_id : null,
                'cells'    => $this->cells($columns, $current[$a->id] ?? [], $previous[$a->id] ?? null),
            ];
        }

        $sum = fn (array $set) => collect($set)->reduce(function ($acc, $fig) {
            foreach ($fig as $k => $v) {
                $acc[$k] = ($acc[$k] ?? 0) + $v;
            }

            return $acc;
        }, []);
        $totals = [
            'agent_id' => null, 'name' => 'Total',
            'cells' => $this->cells($columns, $sum($current), $previous ? $sum($previous) : null),
        ];

        return ['columns' => $columns, 'rows' => $rows, 'totals' => $totals, 'compared' => $compare !== null];
    }

    /** @return array<int, array{key:string,label:string,metric:string,direction:string,group:string}> */
    private function columns(int $agencyId, Period $period, ?Period $compare, array $agentIds, string $track): array
    {
        $cols = [];
        foreach (self::FIXED as $k => [$label, $dir]) {
            $cols[] = ['key' => $k, 'label' => $label, 'metric' => $k, 'direction' => $dir, 'group' => 'figures'];
        }

        // Outcomes: the agency's current list in its own order, then any other outcome that was logged in the window.
        $defs = RtsOutcomeDefinition::query()->where('agency_id', $agencyId)->where('track', $track)->orderBy('sort_order')->orderBy('id')->get(['key', 'label']);
        $seen = [];
        foreach ($defs as $d) {
            $seen[$d->key] = $d->label;
        }
        $windows = array_filter([$period, $compare]);
        foreach ($windows as $w) {
            $logged = DB::table('rts_contact_outcomes')->where('agency_id', $agencyId)->where('track', $track)->whereNull('deleted_at')
                ->whereIn('user_id', $agentIds ?: [0])->whereBetween('occurred_at', [$w->start->toDateTimeString(), $w->end->toDateTimeString()])
                ->groupBy('outcome_key')->selectRaw('outcome_key, max(outcome_label) as label')->get();
            foreach ($logged as $l) {
                $seen[$l->outcome_key] ??= $l->label;
            }
        }
        foreach ($seen as $key => $label) {
            $cols[] = ['key' => 'outcome:' . $key, 'label' => $label, 'metric' => 'outcome:' . $key, 'direction' => 'neutral', 'group' => 'outcomes'];
        }

        foreach (self::TAIL as $k => [$label, $dir]) {
            $cols[] = ['key' => $k, 'label' => $label, 'metric' => $k, 'direction' => $dir, 'group' => 'figures'];
        }

        return $cols;
    }

    /**
     * @param  array<int, array<string,mixed>>  $columns
     * @param  array<string,int|float>  $cur
     * @param  array<string,int|float>|null  $prev
     */
    private function cells(array $columns, array $cur, ?array $prev): array
    {
        $out = [];
        foreach ($columns as $c) {
            $v = (float) ($cur[$c['key']] ?? 0);
            $out[$c['key']] = [
                'value'   => (int) $v,
                'compare' => $prev === null ? null : PeriodComparison::compute($v, (float) ($prev[$c['key']] ?? 0), $c['direction']),
                'previous' => $prev === null ? null : (int) ($prev[$c['key']] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Every figure for every agent over one period: agentId => [key => number].
     *
     * @return array<int, array<string,int>>
     */
    private function figures(int $agencyId, $agents, Period $period, string $track): array
    {
        $ids = $agents->pluck('id')->map(fn ($v) => (int) $v)->all();
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = [];
        }
        if ($ids === [] || $agencyId <= 0) {
            return $out;
        }

        // The call log.
        $log = DB::table('rts_contact_outcomes')
            ->where('agency_id', $agencyId)->where('track', $track)->whereNull('deleted_at')
            ->whereIn('user_id', $ids)->whereBetween('occurred_at', [$period->start->toDateTimeString(), $period->end->toDateTimeString()])
            ->groupBy('user_id', 'outcome_key', 'kind')
            ->selectRaw('user_id, outcome_key, kind, count(*) as n, sum(valuation_booked) as vb, sum(dropped) as dr')
            ->get();
        foreach ($log as $r) {
            $u = (int) $r->user_id;
            $out[$u]['outcome:' . $r->outcome_key] = ($out[$u]['outcome:' . $r->outcome_key] ?? 0) + (int) $r->n;
            $kindKey = $r->kind === 'conversation' ? 'conversations' : 'attempts';
            $out[$u][$kindKey] = ($out[$u][$kindKey] ?? 0) + (int) $r->n;
            $out[$u]['evaluations_booked'] = ($out[$u]['evaluations_booked'] ?? 0) + (int) $r->vb;
            $out[$u]['dropped'] = ($out[$u]['dropped'] ?? 0) + (int) $r->dr;
        }

        // The day's list.
        $items = DB::table('rts_list_items')
            ->where('agency_id', $agencyId)->where('track', $track)->whereNull('deleted_at')
            ->whereIn('user_id', $ids)->whereBetween('list_date', [$period->start->toDateString(), $period->end->toDateString()])
            ->groupBy('user_id', 'origin', 'status')->selectRaw('user_id, origin, status, count(*) as n')->get();
        foreach ($items as $r) {
            $u = (int) $r->user_id;
            if ($r->origin === 'added') {
                if ($r->status === 'worked') {
                    $out[$u]['added_worked'] = ($out[$u]['added_worked'] ?? 0) + (int) $r->n;
                }
                continue;
            }
            if ($r->origin === 'follow_up') {      // counted apart from the day's new intake (road-to-sold.md 22.3)
                if ($r->status === 'worked') {
                    $out[$u]['followups_worked'] = ($out[$u]['followups_worked'] ?? 0) + (int) $r->n;
                }
                continue;
            }
            if ($r->status === 'worked') {
                $out[$u]['worked'] = ($out[$u]['worked'] ?? 0) + (int) $r->n;
            } elseif (in_array($r->status, ['missed', 'rolled'], true)) {
                $out[$u]['missed'] = ($out[$u]['missed'] ?? 0) + (int) $r->n;
            }
        }

        // The target.
        foreach ($agents as $agent) {
            $sum = 0;
            for ($d = $period->start->startOfDay(); $d->lte($period->end); $d = $d->addDay()) {
                $sum += $this->workdays->targetFor($agent, $d, $track);
            }
            $out[(int) $agent->id]['target'] = $sum;
        }

        return $out;
    }

    /** A filename-safe slug for downloads. */
    public static function slug(string $label): string
    {
        return Str::slug($label) ?: 'report';
    }
}
