<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Target;
use App\Models\User;
use App\Models\Worksheet;
use App\Models\Deal;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TargetsManageController extends Controller
{
    private function role(): string
    {
        $u = auth()->user();
        return strtolower(trim((string)($u->role ?? '')));
    }

    private function isAdmin(): bool
    {
        $u = auth()->user();
        if (!$u) return false;
        return $this->role() === 'admin' || (bool)($u->is_admin ?? 0) === true;
    }

    private function isBM(): bool
    {
        return $this->role() === 'branch_manager';
    }

    private function ensureActive(): void
    {
        $u = auth()->user();
        abort_unless($u && (int)($u->is_active ?? 0) === 1, 403);
    }

    // Derived targets (bottom-up): return deals_required + value_required (no money shown to admin/BM)
    private function deriveFromWorksheet(?Worksheet $w): array
    {
        if (!$w) return ['deals'=>0,'value'=>0];

        $need = (float)$w->personal_net_target + (float)$w->business_net_target + (float)$w->want_net_target;

        $avgSale = max(0.0, (float)$w->avg_sale_price);
        $commPct = max(0.0, (float)$w->commission_percent) / 100.0;
        $splitPct = max(0.0, (float)$w->agent_split_percent) / 100.0;
        $payePct = max(0.0, (float)$w->paye_percent) / 100.0;

        // crude net per deal estimate (v1): commission * split * (1 - paye)
        $netPerDeal = $avgSale * $commPct * $splitPct * (1.0 - $payePct);
        if ($netPerDeal <= 0.0) return ['deals'=>0,'value'=>0];

        $deals = (int)ceil($need / $netPerDeal);
        if ($deals < 0) $deals = 0;
        $value = (float)$deals * $avgSale;

        return ['deals'=>$deals,'value'=>$value];
    }

    public function index(Request $request)
    {
        abort_unless($this->isAdmin() || $this->isBM(), 403);
        $this->ensureActive();

        $auth = auth()->user();

        $period = (string)($request->get('period') ?: Carbon::now()->format('Y-m'));
        if (!preg_match('/^\d{4}\-\d{2}$/', $period)) $period = Carbon::now()->format('Y-m');

        $branchId = (int)($request->get('branch_id') ?: 0);
        if ($this->isBM()) {
            $branchId = (int)($auth->branch_id ?? 0);
        }

        $branchNames = Branch::query()->pluck('name','id')->all();

        $agents = User::query()
            ->where('role','agent')
            ->where('is_active',1)
            ->when($branchId > 0, fn($q)=>$q->where('branch_id',$branchId))
            ->orderBy('branch_id')
            ->orderBy('name')
            ->get();

        // Latest worksheet for that period per user (worksheets are period specific already)
        $ws = Worksheet::query()
            ->where('period',$period)
            ->whereIn('user_id',$agents->pluck('id'))
            ->get()
            ->keyBy('user_id');

        // Overrides from targets table
        $targets = Target::query()
            ->where('period',$period)
            ->whereIn('user_id',$agents->pluck('id'))
            ->get()
            ->keyBy('user_id');

        // Actuals from deals for the period
        $userIds = $agents->pluck('id')->all();
        $actuals = collect();
        if (count($userIds) > 0) {
            $rows = DB::table('deal_user')
                ->join('deals','deals.id','=','deal_user.deal_id')
                ->where('deals.period',$period)
                ->whereIn('deal_user.user_id',$userIds)
                ->selectRaw('
                    deal_user.user_id as user_id,
                    COUNT(DISTINCT deal_user.deal_id) as deals_actual,
                    COALESCE(SUM(DISTINCT deals.property_value),0) as value_actual
                ')
                ->groupBy('deal_user.user_id')
                ->get();
            $actuals = collect($rows)->keyBy('user_id');
        }

        // Build rows
        $rows = [];
        foreach ($agents as $a) {
            $derived = $this->deriveFromWorksheet($ws[$a->id] ?? null);

            $ov = $targets[$a->id] ?? null;
            $ovDeals = (int)($ov->deals_target ?? 0);
            $ovValue = (float)($ov->value_target ?? 0);

            $effDeals = max($derived['deals'], $ovDeals);
            $effValue = max($derived['value'], $ovValue);

            $act = $actuals[$a->id] ?? null;
            $actDeals = (int)($act->deals_actual ?? 0);
            $actValue = (float)($act->value_actual ?? 0);

            $rows[] = [
                'branch_id' => (int)($a->branch_id ?? 0),
                'agent' => $a,
                'derived_deals' => $derived['deals'],
                'derived_value' => $derived['value'],
                'override_deals' => $ovDeals,
                'override_value' => $ovValue,
                'effective_deals' => $effDeals,
                'effective_value' => $effValue,
                'actual_deals' => $actDeals,
                'actual_value' => $actValue,
            ];
        }

        // Group by branch for totals
        $byBranch = collect($rows)->groupBy('branch_id')->map(function($items){
            return [
                'rows' => $items->values()->all(),
                'totals' => [
                    'derived_deals' => (int)$items->sum('derived_deals'),
                    'override_deals' => (int)$items->sum('override_deals'),
                    'effective_deals' => (int)$items->sum('effective_deals'),
                    'actual_deals' => (int)$items->sum('actual_deals'),
                    'derived_value' => (float)$items->sum('derived_value'),
                    'override_value' => (float)$items->sum('override_value'),
                    'effective_value' => (float)$items->sum('effective_value'),
                    'actual_value' => (float)$items->sum('actual_value'),
                ],
            ];
        });

        return view('admin.targets.manage', [
            'period' => $period,
            'branchId' => $branchId,
            'branchNames' => $branchNames,
            'byBranch' => $byBranch,
            'isAdmin' => $this->isAdmin(),
            'isBM' => $this->isBM(),
        ]);
    }

    public function save(Request $request)
    {
        abort_unless($this->isAdmin() || $this->isBM(), 403);
        $this->ensureActive();

        $auth = auth()->user();

        $period = (string)$request->input('period');
        if (!preg_match('/^\d{4}\-\d{2}$/', $period)) {
            return back()->withErrors('Invalid period.')->withInput();
        }

        $rows = $request->input('rows', []);
        if (!is_array($rows)) $rows = [];

        DB::transaction(function() use ($rows, $period, $auth) {
            foreach ($rows as $userId => $r) {
                $userId = (int)$userId;
                if ($userId <= 0) continue;
                if (!is_array($r)) $r = [];

                $u = User::find($userId);
                if (!$u) continue;
                if ((string)$u->role !== 'agent') continue;

                // BM limited to branch
                if (strtolower((string)$auth->role) === 'branch_manager') {
                    if ((int)($u->branch_id ?? 0) !== (int)($auth->branch_id ?? 0)) continue;
                }

                $deals = isset($r['deals_target']) ? (int)$r['deals_target'] : 0;
                if ($deals < 0) $deals = 0;

                $value = isset($r['value_target']) ? (float)$r['value_target'] : 0;
                if ($value < 0) $value = 0;

                Target::updateOrCreate(
                    ['period'=>$period,'user_id'=>$userId],
                    [
                        'branch_id' => $u->branch_id,
                        'deals_target' => $deals,
                        'value_target' => $value,
                        'updated_by' => $auth->id,
                    ] + (Target::where('period',$period)->where('user_id',$userId)->exists() ? [] : ['created_by'=>$auth->id])
                );
            }
        });

        return back()->with('status','Overrides saved.');
    }
}
