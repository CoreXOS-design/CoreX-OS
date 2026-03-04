<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\MonthlyTargetGoal;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MonthlyGoalController extends Controller
{
    private function ensureAccess(): void
    {
        abort_unless(auth()->user()?->hasPermission('manage_targets'), 403);
    }

    private function ensureActive(): void
    {
        $u = auth()->user();
        abort_unless($u && (int)($u->is_active ?? 0) === 1, 403);
    }

    public function index(Request $request)
    {
        $this->ensureAccess();
        $this->ensureActive();

        $auth = auth()->user();

        $period = (string)($request->get('period') ?: Carbon::now()->format('Y-m'));
        if (!preg_match('/^\d{4}\-\d{2}$/', $period)) {
            $period = Carbon::now()->format('Y-m');
        }

        // Branch scope (Admin can choose; BM locked to own branch)
        $branchId = (int)($request->get('branch_id') ?: 0);
        if ($auth->isEffectiveBranchManager()) {
            $branchId = (int)($auth->branch_id ?? 0);
        }

        $branchNames = Branch::query()->pluck('name', 'id')->all();

        // Current saved goals
        $companyGoal = MonthlyTargetGoal::query()
            ->where('period', $period)
            ->whereNull('user_id')
            ->whereNull('branch_id')
            ->orderByDesc('id')
            ->first();

        $branchGoal = null;
        if ($branchId > 0) {
            $branchGoal = MonthlyTargetGoal::query()
                ->where('period', $period)
                ->whereNull('user_id')
                ->where('branch_id', $branchId)
                ->orderByDesc('id')
                ->first();
        }

        // Rollups from existing per-user targets table
        $branchRollups = DB::table('targets')
            ->selectRaw('period, branch_id,
                COUNT(DISTINCT user_id) as agents_with_targets,
                COALESCE(SUM(listings_target),0) as listings_target_sum,
                COALESCE(SUM(deals_target),0) as deals_target_sum,
                COALESCE(SUM(value_target),0) as value_target_sum
            ')
            ->where('period', $period)
            ->groupBy('period', 'branch_id')
            ->orderBy('branch_id')
            ->get()
            ->map(function ($r) use ($branchNames) {
                $bid = (int)($r->branch_id ?? 0);
                return [
                    'branch_id' => $bid,
                    'branch_name' => $branchNames[$bid] ?? ('Branch #' . $bid),
                    'agents_with_targets' => (int)$r->agents_with_targets,
                    'listings_target_sum' => (int)$r->listings_target_sum,
                    'deals_target_sum' => (int)$r->deals_target_sum,
                    'value_target_sum' => (float)$r->value_target_sum,
                ];
            })
            ->all();

        $companyRollup = [
            'agents_with_targets' => 0,
            'listings_target_sum' => 0,
            'deals_target_sum' => 0,
            'value_target_sum' => 0.0,
        ];

        foreach ($branchRollups as $b) {
            $companyRollup['agents_with_targets'] += (int)$b['agents_with_targets'];
            $companyRollup['listings_target_sum'] += (int)$b['listings_target_sum'];
            $companyRollup['deals_target_sum'] += (int)$b['deals_target_sum'];
            $companyRollup['value_target_sum'] += (float)$b['value_target_sum'];
        }

        return view('admin.targets.monthly-goals', [
            'period' => $period,
            'branchId' => $branchId,
            'branchNames' => $branchNames,

            'companyGoal' => $companyGoal,
            'branchGoal' => $branchGoal,

            'branchRollups' => $branchRollups,
            'companyRollup' => $companyRollup,

            'isAdmin' => $auth->isEffectiveAdmin(),
            'isBM' => $auth->isEffectiveBranchManager(),
        ]);
    }

    public function save(Request $request)
    {
        $this->ensureAccess();
        $this->ensureActive();

        $auth = auth()->user();

        $period = (string)$request->input('period');
        if (!preg_match('/^\d{4}\-\d{2}$/', $period)) {
            return back()->withErrors('Invalid period.')->withInput();
        }

        $scope = strtolower(trim((string)($request->input('scope') ?? 'company'))); // company|branch

        // Scope rules:
        // - Admin can save company or any branch
        // - BM can only save branch goal for their branch
        $branchId = (int)($request->input('branch_id') ?? 0);
        if ($auth->isEffectiveBranchManager()) {
            $branchId = (int)($auth->branch_id ?? 0);
            $scope = 'branch';
        }

        $listings = (int)($request->input('listings_target') ?? 0);
        $deals    = (int)($request->input('deals_target') ?? 0);
        $value    = (float)($request->input('value_target') ?? 0);

        if ($listings < 0) $listings = 0;
        if ($deals < 0) $deals = 0;
        if ($value < 0) $value = 0;

        DB::transaction(function () use ($auth, $period, $scope, $branchId, $listings, $deals, $value) {

            if ($scope === 'branch') {
                abort_unless($branchId > 0, 422);

                MonthlyTargetGoal::updateOrCreate(
                    ['period' => $period, 'user_id' => null, 'branch_id' => $branchId],
                    [
                        'listings_target' => $listings,
                        'deals_target' => $deals,
                        'value_target' => $value,
                        'created_by' => $auth?->id,
                        'updated_by' => $auth?->id,
                    ]
                );

                return;
            }

            // company/global
            MonthlyTargetGoal::updateOrCreate(
                ['period' => $period, 'user_id' => null, 'branch_id' => null],
                [
                    'listings_target' => $listings,
                    'deals_target' => $deals,
                    'value_target' => $value,
                    'created_by' => $auth?->id,
                    'updated_by' => $auth?->id,
                ]
            );
        });

        return redirect()->route('admin.monthly-goals', [
            'period' => $period,
            'branch_id' => $auth->isEffectiveBranchManager() ? (int)($auth->branch_id ?? 0) : $branchId,
        ])->with('status', 'Monthly goal saved.');
    }

}
