<?php

namespace App\Http\Controllers\Admin;

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

        // Definitions visible company-wide: global + all branch scopes, enabled
        $defs = DB::table('activity_definitions')
            ->where('is_enabled', 1)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id','name','weight','scope','branch_id']);

        $defIds = $defs->pluck('id')->map(fn($v)=>(int)$v)->all();

        // daily_activity_entries has agency_id but no automatic tenant scope on
        // this raw query-builder path (Eloquent's BelongsToAgency global scope
        // does not apply to DB::table()). Without this filter, a company-wide
        // "all definitions" aggregate would sum every agency's activity into
        // one company's totals. Owner-role admins with no active switcher
        // override see everything, mirroring AgencyScope's read bypass.
        $agencyId = $u->effectiveAgencyId();

        // M6.5 — achievement-total filter (confirmed/overridden + manual/auto_*).
        $agg = DB::table('daily_activity_entries as e')
            ->selectRaw('e.activity_definition_id as def_id, SUM(e.value) as total_count')
            ->whereBetween('e.activity_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('e.activity_definition_id', $defIds)
            ->whereIn('e.point_state', \App\Models\DailyActivityEntry::ACHIEVEMENT_TOTAL_STATES)
            ->whereIn('e.source', \App\Models\DailyActivityEntry::ACHIEVEMENT_TOTAL_SOURCES)
            ->when($agencyId, function ($q) use ($agencyId) {
                $q->where('e.agency_id', $agencyId);
            })
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
                'scope' => (string)$d->scope,
                'branch_id' => $d->branch_id !== null ? (int)$d->branch_id : null,
            ];

            $grandCount += $count;
            $grandPoints += $points;
        }

        foreach ($items as &$it) {
            $it['pct_points'] = $grandPoints > 0 ? ($it['points'] / $grandPoints) * 100.0 : 0.0;
        }
        unset($it);

        return view('admin.daily-summary.index', [
            'range'=>$range,'month'=>$month,'start'=>$start,'end'=>$end,
            'items'=>$items,'grandCount'=>$grandCount,'grandPoints'=>$grandPoints,
        ]);
    }

    public function activity(Request $request, int $definition)
    {
        $u = $request->user();
        abort_unless($u && $u->hasPermission('daily_activity.view'), 403);

        [$range, $start, $end, $month] = $this->rangeFromRequest($request);

        $def = DB::table('activity_definitions')
            ->where('id', $definition)
            ->where('is_enabled', 1)
            ->first(['id','name','weight']);

        abort_unless($def, 404);

        // daily_activity_entries has agency_id but no automatic tenant scope on
        // this raw query-builder path. Without the filters below, this leaked
        // two ways: (1) the aggregate would sum every agency's activity for
        // this definition, and (2) the unscoped `leftJoin('branches as b', ...)`
        // would resolve branch NAMES belonging to other agencies (branches.name
        // is not itself agency-guarded by a raw join). Restrict the branches
        // join and the entries themselves to the caller's own agency.
        $agencyId = $u->effectiveAgencyId();

        // Branch totals for this activity (include null branch -> "Unassigned")
        // M6.5 — achievement-total filter.
        $rows = DB::table('daily_activity_entries as e')
            ->leftJoin('branches as b', function ($join) use ($agencyId) {
                $join->on('b.id', '=', 'e.branch_id');
                if ($agencyId) {
                    $join->where('b.agency_id', '=', $agencyId);
                }
            })
            ->selectRaw('e.branch_id as branch_id, COALESCE(b.name, "Unassigned") as branch_name, SUM(e.value) as total_count')
            ->where('e.activity_definition_id', (int)$def->id)
            ->whereBetween('e.activity_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('e.point_state', \App\Models\DailyActivityEntry::ACHIEVEMENT_TOTAL_STATES)
            ->whereIn('e.source', \App\Models\DailyActivityEntry::ACHIEVEMENT_TOTAL_SOURCES)
            ->when($agencyId, function ($q) use ($agencyId) {
                $q->where('e.agency_id', $agencyId);
            })
            ->groupBy('e.branch_id', 'b.name')
            ->orderByRaw('SUM(e.value) DESC')
            ->get();

        $items = [];
        $totalCount = 0;
        $totalPoints = 0.0;

        foreach ($rows as $r) {
            $count = (int)$r->total_count;
            $points = $count * (float)$def->weight;
            $items[] = [
                'branch_id' => $r->branch_id !== null ? (int)$r->branch_id : 0,
                'branch_name' => (string)$r->branch_name,
                'count' => $count,
                'points' => $points,
            ];
            $totalCount += $count;
            $totalPoints += $points;
        }

        return view('admin.daily-summary.activity', [
            'range'=>$range,'month'=>$month,'start'=>$start,'end'=>$end,
            'def'=>$def,'items'=>$items,'totalCount'=>$totalCount,'totalPoints'=>$totalPoints,
        ]);
    }

    public function branch(Request $request, int $definition, int $branch)
    {
        $u = $request->user();
        abort_unless($u && $u->hasPermission('daily_activity.view'), 403);

        [$range, $start, $end, $month] = $this->rangeFromRequest($request);

        $def = DB::table('activity_definitions')
            ->where('id', $definition)
            ->where('is_enabled', 1)
            ->first(['id','name','weight']);

        abort_unless($def, 404);

        // daily_activity_entries has agency_id but no automatic tenant scope on
        // this raw query-builder path (Eloquent's BelongsToAgency global scope
        // does not apply to DB::table()). Without this filter, an admin could
        // pass another agency's branch id and pull its agent activity totals,
        // and the unscoped branches name lookup below would leak that
        // agency's branch name too. Mirrors index()/activity()/agent() above.
        $agencyId = $u->effectiveAgencyId();

        // branch=0 means "Unassigned"
        $branchName = $branch > 0
            ? DB::table('branches')->where('id', $branch)
                ->when($agencyId, function ($q) use ($agencyId) {
                    $q->where('agency_id', $agencyId);
                })
                ->value('name')
            : 'Unassigned';

        // Agent totals for this activity in this branch
        // M6.5 — achievement-total filter.
        $q = DB::table('daily_activity_entries as e')
            ->join('users as u', 'u.id', '=', 'e.user_id')
            ->selectRaw('e.user_id, u.name, SUM(e.value) as total_count')
            ->where('e.activity_definition_id', (int)$def->id)
            ->whereBetween('e.activity_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('e.point_state', \App\Models\DailyActivityEntry::ACHIEVEMENT_TOTAL_STATES)
            ->whereIn('e.source', \App\Models\DailyActivityEntry::ACHIEVEMENT_TOTAL_SOURCES)
            ->when($agencyId, function ($q) use ($agencyId) {
                $q->where('e.agency_id', $agencyId);
            });

        if ($branch > 0) $q->where('e.branch_id', $branch);
        else $q->whereNull('e.branch_id');

        $rows = $q->groupBy('e.user_id', 'u.name')
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

        return view('admin.daily-summary.branch', [
            'range'=>$range,'month'=>$month,'start'=>$start,'end'=>$end,
            'def'=>$def,'items'=>$items,'totalCount'=>$totalCount,'totalPoints'=>$totalPoints,
            'branchId'=>$branch,'branchName'=>$branchName,
        ]);
    }

    public function agent(Request $request, int $definition, int $branch, int $user)
    {
        $u = $request->user();
        abort_unless($u && $u->hasPermission('daily_activity.view'), 403);

        [$range, $start, $end, $month] = $this->rangeFromRequest($request);

        $def = DB::table('activity_definitions')
            ->where('id', $definition)
            ->where('is_enabled', 1)
            ->first(['id','name','weight']);

        abort_unless($def, 404);

        // daily_activity_entries has no model-level agency scope, and this route
        // takes raw branch/user ids, so without an explicit tenancy check any
        // admin with daily_activity.view could enumerate another agency's
        // branch/user ids to read its activity/points data. Resolve the branch's
        // agency through the scoped model (Branch uses BelongsToAgency) and
        // require it to match the caller's own effective agency.
        if ($branch > 0) {
            $branchModel = \App\Models\Branch::find($branch);
            abort_unless($branchModel, 404);
            abort_unless(
                (int) $branchModel->agency_id === (int) $u->effectiveAgencyId() || $u->isOwnerRole(),
                404
            );
        }

        $branchName = $branch > 0
            ? (DB::table('branches')->where('id', $branch)->value('name') ?: ('Branch #' . $branch))
            : 'Unassigned';

        $agentName = DB::table('users')->where('id', $user)->value('name');
        abort_unless($agentName, 404);

        // Also verify the target user actually belongs to the caller's agency —
        // the branch check above covers branch>0, but a foreign-tenant user id
        // paired with branch=0 ("Unassigned") would otherwise slip through.
        $agentAgencyId = DB::table('users')->where('id', $user)->value('agency_id');
        abort_unless(
            (int) $agentAgencyId === (int) $u->effectiveAgencyId() || $u->isOwnerRole(),
            404
        );

        $q = DB::table('daily_activity_entries as e')
            ->select(['e.activity_date','e.value'])
            ->where('e.activity_definition_id', (int)$def->id)
            ->where('e.user_id', $user)
            ->whereBetween('e.activity_date', [$start->toDateString(), $end->toDateString()]);

        if ($branch > 0) $q->where('e.branch_id', $branch);
        else $q->whereNull('e.branch_id');

        $entries = $q->orderBy('e.activity_date', 'desc')->get();

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

        return view('admin.daily-summary.agent', [
            'range'=>$range,'month'=>$month,'start'=>$start,'end'=>$end,
            'def'=>$def,'rows'=>$rows,'totalCount'=>$totalCount,'totalPoints'=>$totalPoints,
            'branchId'=>$branch,'branchName'=>$branchName,
            'agentId'=>$user,'agentName'=>$agentName,
        ]);
    }
}
