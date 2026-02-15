<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\DailyActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AgentDailyController extends Controller
{
    private function isAgentStrict(): bool
    {
        $u = auth()->user();
        if (!$u) return false;
        $role = strtolower(trim((string)(method_exists($u, "effectiveRole") ? $u->effectiveRole() : ($u->role ?? ""))));
        return $role === "agent";
    }

    public function index(Request $request)
    {
        abort_unless($this->isAgentStrict(), 403);

        $auth = auth()->user();
        abort_unless((int)($auth->is_active ?? 0) === 1, 403);

        $month = (string)($request->get('month') ?: Carbon::now()->format('Y-m'));
        if (!preg_match('/^\d{4}\-\d{2}$/', $month)) $month = Carbon::now()->format('Y-m');

        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end   = (clone $start)->endOfMonth();

        $branchId = (int)($auth->branch_id ?? 0);

        // Global defaults
        $global = DB::table('activity_columns')
            ->select('key','label','group','default_enabled','sort_order')
            ->orderBy('sort_order')
            ->get()
            ->map(fn($r)=>[
                'key'=>(string)$r->key,
                'label'=>(string)$r->label,
                'group'=>$r->group !== null ? (string)$r->group : null,
                'enabled'=>((int)$r->default_enabled===1),
                'sort_order'=>(int)$r->sort_order
            ])->keyBy('key');

        $effective = $global->values();

        // Branch overrides
        if ($branchId > 0) {
            $ov = DB::table('branch_activity_columns')->where('branch_id',$branchId)->get()->keyBy('key');
            if ($ov->count() > 0) {
                $effective = $global->map(function($g) use ($ov) {
                    $k = $g['key'];
                    if (isset($ov[$k])) {
                        $g['enabled'] = ((int)$ov[$k]->is_enabled===1);
                        if ($ov[$k]->sort_order !== null) $g['sort_order']=(int)$ov[$k]->sort_order;
                    }
                    return $g;
                })->sortBy('sort_order')->values();
            }
        }

        $dailyCols = $effective->filter(fn($c)=> (bool)$c['enabled'])->values();

        $rows = DailyActivity::query()
            ->where('user_id',$auth->id)
            ->whereBetween('activity_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy('activity_date');

        $days = [];
        $cur = $start->copy();
        while ($cur <= $end) {
            $d = $cur->toDateString();
            $days[] = ['date'=>$d, 'dow'=>$cur->format('D'), 'row'=>$rows[$d] ?? null];
            $cur->addDay();
        }

        return view('agent.daily.index', compact('month','days','dailyCols'));
    }

    public function save(Request $request)
    {
        abort_unless($this->isAgentStrict(), 403);

        $auth = auth()->user();
        abort_unless((int)($auth->is_active ?? 0) === 1, 403);

        $month = (string)$request->input('month');
        if (!preg_match('/^\d{4}\-\d{2}$/', $month)) return back()->withErrors('Invalid month.')->withInput();

        $payload = $request->input('daily', []);
        if (!is_array($payload)) $payload = [];

        $branchId = (int)($auth->branch_id ?? 0);

        $global = DB::table('activity_columns')
            ->select('key','default_enabled','sort_order')
            ->orderBy('sort_order')
            ->get()
            ->map(fn($r)=>['key'=>(string)$r->key,'enabled'=>((int)$r->default_enabled===1),'sort_order'=>(int)$r->sort_order])
            ->keyBy('key');

        $effective = $global->values();

        if ($branchId > 0) {
            $ov = DB::table('branch_activity_columns')->where('branch_id',$branchId)->get()->keyBy('key');
            if ($ov->count() > 0) {
                $effective = $global->map(function($g) use ($ov) {
                    $k = $g['key'];
                    if (isset($ov[$k])) {
                        $g['enabled'] = ((int)$ov[$k]->is_enabled===1);
                        if ($ov[$k]->sort_order !== null) $g['sort_order']=(int)$ov[$k]->sort_order;
                    }
                    return $g;
                })->sortBy('sort_order')->values();
            }
        }

        $cols = $effective->filter(fn($c)=> (bool)$c['enabled'])->pluck('key')->values()->all();

        DB::transaction(function() use ($payload,$cols,$month,$auth) {
            foreach ($payload as $date => $row) {
                if (!preg_match('/^\d{4}\-\d{2}\-\d{2}$/', (string)$date)) continue;
                if (substr((string)$date,0,7) !== $month) continue;
                if (!is_array($row)) $row = [];

                $save = [];
                foreach ($cols as $k) {
                    $v = isset($row[$k]) ? (int)$row[$k] : 0;
                    if ($v < 0) $v = 0;
                    $save[$k] = $v;
                }

                DailyActivity::updateOrCreate(
                    ['activity_date'=>$date,'user_id'=>$auth->id],
                    array_merge(['period'=>$month,'branch_id'=>$auth->branch_id], $save)
                );
            }
        });

        return redirect()->route('agent.daily', ['month'=>$month])->with('status','Daily activity saved.');
    }
}
