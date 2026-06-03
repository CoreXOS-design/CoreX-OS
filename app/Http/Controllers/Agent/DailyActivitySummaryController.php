<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DailyActivitySummaryController extends Controller
{
    private function rangeFromRequest(Request $request): array
    {
        $tz = config('app.timezone') ?: 'UTC';
        $today = Carbon::now($tz)->startOfDay();

        $range = (string)($request->query('range') ?: '7d');
        $allowed = ['7d','month','3m','6m','12m'];
        if (!in_array($range, $allowed, true)) $range = '7d';

        if ($range === 'month') {
            $month = (string)($request->query('month') ?: $today->format('Y-m'));
            if (!preg_match('/^\d{4}\-\d{2}$/', $month)) $month = $today->format('Y-m');
            $start = Carbon::createFromFormat('Y-m', $month, $tz)->startOfMonth();
            $end   = (clone $start)->endOfMonth();
            return [$range, $start, $end, $month];
        }

        $months = ['3m'=>3,'6m'=>6,'12m'=>12];
        if (isset($months[$range])) {
            $start = $today->copy()->subMonthsNoOverflow($months[$range])->addDay(); // inclusive-ish window
            $end   = $today->copy()->endOfDay();
            return [$range, $start, $end, null];
        }

        // 7d default
        $start = $today->copy()->subDays(6);
        $end   = $today->copy()->endOfDay();
        return [$range, $start, $end, null];
    }

    public function index(Request $request)
    {
        $u = $request->user();
        abort_unless($u && $u->hasPermission('daily_activity.view'), 403);
        abort_unless((int)($u->is_active ?? 0) === 1, 403);

        [$range, $start, $end, $month] = $this->rangeFromRequest($request);

        // Effective definitions visible to this user: global + (their branch-specific), enabled only.
        $branchId = (int)($u->branch_id ?? 0);

        $defs = DB::table('activity_definitions')
            ->where('is_enabled', 1)
            ->where(function ($q) use ($branchId) {
                $q->where('scope', 'system');
                if ($branchId) {
                    $q->orWhere(function ($qq) use ($branchId) {
                        $qq->where('scope', 'branch')->where('branch_id', $branchId);
                    });
                }
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id','name','weight','scope','branch_id','scoring_mode']);

        $defIds = $defs->pluck('id')->map(fn($v)=>(int)$v)->all();

        // M6.5 — achievement total: confirmed/overridden + manual/auto_calendar/
        // auto_instant only. Provisional + revoked + auto_other excluded.
        $rows = DB::table('daily_activity_entries as e')
            ->selectRaw('e.activity_definition_id as def_id, SUM(e.value) as total_count')
            ->where('e.user_id', $u->id)
            ->whereBetween('e.activity_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('e.activity_definition_id', $defIds)
            ->whereIn('e.point_state', \App\Models\DailyActivityEntry::ACHIEVEMENT_TOTAL_STATES)
            ->whereIn('e.source', \App\Models\DailyActivityEntry::ACHIEVEMENT_TOTAL_SOURCES)
            ->groupBy('e.activity_definition_id')
            ->get()
            ->keyBy('def_id');

        $items = [];
        $grandPoints = 0.0;
        $grandCount = 0;

        foreach ($defs as $d) {
            $count = (int)($rows[$d->id]->total_count ?? 0);
            $points = $count * (float)$d->weight;
            $items[] = [
                'id' => (int)$d->id,
                'name' => (string)$d->name,
                'count' => $count,
                'weight' => (float)$d->weight,
                'points' => $points,
            ];
            $grandCount += $count;
            $grandPoints += $points;
        }

        // Percentages (by points)
        foreach ($items as &$it) {
            $it['pct_points'] = $grandPoints > 0 ? ($it['points'] / $grandPoints) * 100.0 : 0.0;
            $it['pct_count']  = $grandCount > 0 ? ($it['count'] / $grandCount) * 100.0 : 0.0;
        }
        unset($it);

        return view('agent.daily-summary.index', [
            'range' => $range,
            'month' => $month,
            'start' => $start,
            'end'   => $end,
            'items' => $items,
            'grandCount' => $grandCount,
            'grandPoints' => $grandPoints,
        ]);
    }

    public function activity(Request $request, int $definition)
    {
        $u = $request->user();
        abort_unless($u && $u->hasPermission('daily_activity.view'), 403);
        abort_unless((int)($u->is_active ?? 0) === 1, 403);

        [$range, $start, $end, $month] = $this->rangeFromRequest($request);

        $branchId = (int)($u->branch_id ?? 0);

        // Ensure definition is visible to this user (global or their branch), enabled
        $def = DB::table('activity_definitions')
            ->where('id', $definition)
            ->where('is_enabled', 1)
            ->where(function ($q) use ($branchId) {
                $q->where('scope', 'system');
                if ($branchId) {
                    $q->orWhere(function ($qq) use ($branchId) {
                        $qq->where('scope', 'branch')->where('branch_id', $branchId);
                    });
                }
            })
            ->first(['id','name','weight']);

        abort_unless($def, 404);

        $entries = DB::table('daily_activity_entries as e')
            ->select(['e.activity_date','e.value'])
            ->where('e.user_id', $u->id)
            ->where('e.activity_definition_id', (int)$def->id)
            ->whereBetween('e.activity_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('e.activity_date', 'desc')
            ->get();

        $totalCount = 0;
        $totalPoints = 0.0;
        $rows = [];

        foreach ($entries as $e) {
            $count = (int)$e->value;
            $points = $count * (float)$def->weight;
            $rows[] = [
                'date' => (string)$e->activity_date,
                'count' => $count,
                'points' => $points,
            ];
            $totalCount += $count;
            $totalPoints += $points;
        }

        return view('agent.daily-summary.activity', [
            'range' => $range,
            'month' => $month,
            'start' => $start,
            'end' => $end,
            'def' => $def,
            'rows' => $rows,
            'totalCount' => $totalCount,
            'totalPoints' => $totalPoints,
        ]);
    }

}
