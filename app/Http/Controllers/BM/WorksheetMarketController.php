<?php

namespace App\Http\Controllers\BM;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Deal;
use App\Models\Worksheet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WorksheetMarketController extends Controller
{
    public function index(Request $request)
    {
        $bm = Auth::user();
        $period = $request->get('period', now()->format('Y-m'));

        $agents = User::query()
            ->whereIn('role', ['agent','branch_manager'])
            ->when($bm->branch_id, fn($q) => $q->where('branch_id', $bm->branch_id))
            ->orderBy('name')
            ->get();

        $worksheets = Worksheet::query()
            ->where('period', $period)
            ->whereIn('user_id', $agents->pluck('id'))
            ->get()
            ->keyBy('user_id');


        // ---- Deal Register market averages (branch + selected window + selected stages) ----
        $branchId = (int)($bm->effectiveBranchId() ?? ($bm->branch_id ?? 0));

        $stageFilter = [
            'pending'    => $request->boolean('st_pending', true),
            'granted'    => $request->boolean('st_granted', true),
            'registered' => $request->boolean('st_registered', true),
        ];

        // period = selected month only (default)
        // 3m/6m = rolling months ending in selected period
        // all = all time
        $avgWindow = (string) $request->get('avg_window', 'period');
        $avgWindow = in_array($avgWindow, ['period','3m','6m','all'], true) ? $avgWindow : 'period';

        $dateFrom = null;
        $dateTo = null;

        if ($avgWindow !== 'all') {
            $dtTo = \Carbon\Carbon::createFromFormat('Y-m', $period)->endOfMonth();
            $dateTo = $dtTo->toDateString();

            if ($avgWindow === 'period') {
                $dtFrom = \Carbon\Carbon::createFromFormat('Y-m', $period)->startOfMonth();
                $dateFrom = $dtFrom->toDateString();
            } elseif ($avgWindow === '3m') {
                $dtFrom = (clone $dtTo)->subMonthsNoOverflow(2)->startOfMonth();
                $dateFrom = $dtFrom->toDateString();
            } elseif ($avgWindow === '6m') {
                $dtFrom = (clone $dtTo)->subMonthsNoOverflow(5)->startOfMonth();
                $dateFrom = $dtFrom->toDateString();
            }
        }

        $marketAverages = [];
        if ($branchId > 0) {
            $marketAverages = Deal::marketAveragesForBranch(
                $branchId,
                $avgWindow === 'all' ? 'all' : $period,
                $stageFilter,
                $dateFrom,
                $dateTo
            );
        }
        // ---------------------------------------------------------------

        return view('bm.worksheet_market', [
            'period' => $period,
            'agents' => $agents,
            'worksheets' => $worksheets,
            'stageFilter' => $stageFilter,
            'avgWindow' => $avgWindow,
            'avgWindowFrom' => $dateFrom,
            'avgWindowTo' => $dateTo,
            'marketAverages' => $marketAverages,
        ]);
    }

    public function save(Request $request)
    {
        $bm = Auth::user();
        $data = $request->validate([
            'period' => ['required', 'string', 'max:7', 'regex:/^\d{4}-\d{2}$/'],
            'avg' => ['array'],
            'avg.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $period = $data['period'];

        $agents = User::query()
            ->whereIn('role', ['agent','branch_manager'])
            ->when($bm->branch_id, fn($q) => $q->where('branch_id', $bm->branch_id))
            ->get()
            ->keyBy('id');

        foreach (($data['avg'] ?? []) as $userId => $avg) {
            $userId = (int)$userId;
            if (!$agents->has($userId)) continue;

            Worksheet::updateOrCreate(
                ['user_id' => $userId, 'period' => $period],
                [
                    'user_id' => $userId,
                    'period' => $period,
                    'avg_sale_price_admin' => $avg,
                ]
            );
        }

        return redirect()
            ->route('bm.worksheet.market', ['period' => $period])
            ->with('status', 'Market Avg Prices saved for ' . $period);
    }
}
