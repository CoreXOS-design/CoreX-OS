<?php

namespace App\Http\Controllers\BM;

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
            $start = $today->copy()->subMonthsNoOverflow($months[$range])->addDay();
            $end   = $today->copy()->endOfDay();
            return [$range, $start, $end, null];
        }

        $start = $today->copy()->subDays(6);
        $end   = $today->copy()->endOfDay();
        return [$range, $start, $end, null];
    }

    public function index(Request $request)
    {
        $u = $request->user();
        abort_unless($u && $u->hasPermission('daily_activity.view'), 403);

        [$range, $start, $end, $month] = $this->rangeFromRequest($request);

        $branchId = (int)($u->effectiveBranchId() ?? ($u->branch_id ?? 0));
        // Owner without a branch: fall back to first available branch
        if ($branchId === 0 && $u->isOwnerRole()) {
            $branchId = (int) DB::table('branches')->orderBy('id')->value('id');
        }
        abort_unless($branchId > 0, 403);

        // Definitions visible to this branch: global + branch definitions for this branch, enabled
        $defs = DB::table('activity_definitions')
            ->where('is_enabled', 1)
            ->where(function ($q) use ($branchId) {
                $q->where('scope', 'system')
                  ->orWhere(function ($qq) use ($branchId) {
                      $qq->where('scope', 'branch')->where('branch_id', $branchId);
                  });
            })
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id','name','weight']);

        $defIds = $defs->pluck('id')->map(fn($v)=>(int)$v)->all();

        $agg = DB::table('daily_activity_entries as e')
            ->selectRaw('e.activity_definition_id as def_id, SUM(e.value) as total_count')
            ->where('e.branch_id', $branchId)
            ->whereBetween('e.activity_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('e.activity_definition_id', $defIds)
            ->groupBy('e.activity_definition_id')
            ->get()
            ->keyBy('def_id');

        $items = [];
        $grandCount = 0;
        $grandPoints = 0.0;

        foreach ($defs as $d) {
            $count = (int)($agg[$d->id]->total_count ?? 0);
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

        foreach ($items as &$it) {
            $it['pct_points'] = $grandPoints > 0 ? ($it['points'] / $grandPoints) * 100.0 : 0.0;
        }
        unset($it);

        $branchName = DB::table('branches')->where('id', $branchId)->value('name');

        return view('bm.daily-summary.index', [
            'range'=>$range,'month'=>$month,'start'=>$start,'end'=>$end,
            'items'=>$items,'grandCount'=>$grandCount,'grandPoints'=>$grandPoints,
            'branchId'=>$branchId,'branchName'=>$branchName,
        ]);
    }

    public function activity(Request $request, int $definition)
    {
        $u = $request->user();
        abort_unless($u && $u->hasPermission('daily_activity.view'), 403);

        [$range, $start, $end, $month] = $this->rangeFromRequest($request);

        $branchId = (int)($u->effectiveBranchId() ?? ($u->branch_id ?? 0));
        abort_unless($branchId > 0, 403);

        $def = DB::table('activity_definitions')
            ->where('id', $definition)
            ->where('is_enabled', 1)
            ->where(function ($q) use ($branchId) {
                $q->where('scope', 'system')
                  ->orWhere(function ($qq) use ($branchId) {
                      $qq->where('scope', 'branch')->where('branch_id', $branchId);
                  });
            })
            ->first(['id','name','weight']);

        abort_unless($def, 404);

        // Agent totals for this activity in this branch + range
        $rows = DB::table('daily_activity_entries as e')
            ->join('users as u', 'u.id', '=', 'e.user_id')
            ->selectRaw('e.user_id, u.name, SUM(e.value) as total_count')
            ->where('e.branch_id', $branchId)
            ->where('e.activity_definition_id', (int)$def->id)
            ->whereBetween('e.activity_date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('e.user_id', 'u.name')
            ->orderByRaw('SUM(e.value) DESC')
            ->get();

        $items = [];
        $totalCount = 0;
        $totalPoints = 0.0;

        foreach ($rows as $r) {
            $count = (int)$r->total_count;
            $points = $count * (float)$def->weight;
            $items[] = [
                'user_id' => (int)$r->user_id,
                'name' => (string)$r->name,
                'count' => $count,
                'points' => $points,
            ];
            $totalCount += $count;
            $totalPoints += $points;
        }

        $branchName = DB::table('branches')->where('id', $branchId)->value('name');

        return view('bm.daily-summary.activity', [
            'range'=>$range,'month'=>$month,'start'=>$start,'end'=>$end,
            'def'=>$def,'items'=>$items,'totalCount'=>$totalCount,'totalPoints'=>$totalPoints,
            'branchId'=>$branchId,'branchName'=>$branchName,
        ]);
    }

    public function agent(Request $request, int $definition, int $user)
    {
        $u = $request->user();
        abort_unless($u && $u->hasPermission('daily_activity.view'), 403);

        [$range, $start, $end, $month] = $this->rangeFromRequest($request);

        $branchId = (int)($u->effectiveBranchId() ?? ($u->branch_id ?? 0));
        if ($branchId === 0 && $u->isOwnerRole()) {
            $branchId = (int) DB::table('branches')->orderBy('id')->value('id');
        }
        abort_unless($branchId > 0, 403);

        $def = DB::table('activity_definitions')
            ->where('id', $definition)
            ->where('is_enabled', 1)
            ->where(function ($q) use ($branchId) {
                $q->where('scope', 'system')
                  ->orWhere(function ($qq) use ($branchId) {
                      $qq->where('scope', 'branch')->where('branch_id', $branchId);
                  });
            })
            ->first(['id','name','weight']);

        abort_unless($def, 404);

        // Ensure agent belongs to this branch (hard guard)
        $agentName = DB::table('users')->where('id', $user)->where('branch_id', $branchId)->value('name');
        abort_unless($agentName, 404);

        $entries = DB::table('daily_activity_entries as e')
            ->select(['e.activity_date','e.value'])
            ->where('e.branch_id', $branchId)
            ->where('e.user_id', $user)
            ->where('e.activity_definition_id', (int)$def->id)
            ->whereBetween('e.activity_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('e.activity_date', 'desc')
            ->get();

        $rows = [];
        $totalCount = 0;
        $totalPoints = 0.0;

        foreach ($entries as $e) {
            $count = (int)$e->value;
            $points = $count * (float)$def->weight;
            $rows[] = ['date'=>(string)$e->activity_date,'count'=>$count,'points'=>$points];
            $totalCount += $count;
            $totalPoints += $points;
        }

        $branchName = DB::table('branches')->where('id', $branchId)->value('name');

        return view('bm.daily-summary.agent', [
            'range'=>$range,'month'=>$month,'start'=>$start,'end'=>$end,
            'def'=>$def,'rows'=>$rows,'totalCount'=>$totalCount,'totalPoints'=>$totalPoints,
            'branchId'=>$branchId,'branchName'=>$branchName,
            'agentId'=>$user,'agentName'=>$agentName,
        ]);
    }
}
