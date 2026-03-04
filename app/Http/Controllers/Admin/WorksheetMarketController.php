<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PerformanceSetting;
use App\Models\Worksheet;
use App\Models\Deal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class WorksheetMarketController extends Controller
{
    public function index(Request $request)
    {
        $u = Auth::user();
        abort_unless($u?->hasPermission('edit_worksheet'), 403);

        $period = $request->query('period', now()->format('Y-m'));

        
        $branchId = $request->query('branch_id');
$agents = DB::table('users')
            ->whereIn('role', ['agent','branch_manager'])
            ->whereNotNull('branch_id')
            ->select('id','name','email','branch_id','role')
            ->orderBy('branch_id')
            ->orderBy('name')
            ->get();

        $worksheets = Worksheet::where('period', $period)->get()->keyBy('user_id');

        $branches = DB::table('branches')->select('id','name')->get()->keyBy('id');


          // Market averages per branch from Deal Register (schema: deals.period, property_value, total_commission)
          $vatDivisor = 1 + (float) PerformanceSetting::get('vat_rate', 15) / 100;

          $mb = Deal::query()
              ->where('period', $period)
              ->whereNotNull('branch_id')
              ->selectRaw('branch_id, COUNT(*) as deals_count')
              ->selectRaw('AVG(property_value) as avg_sale_price_inc_vat')
              ->selectRaw('AVG(property_value / ?) as avg_sale_price_ex_vat', [$vatDivisor])
              ->selectRaw('AVG((total_commission / ? / NULLIF(property_value,0))*100.0) as effective_commission_percent_ex_vat', [$vatDivisor])
              ->groupBy('branch_id')
              ->get()
              ->keyBy(fn($r) => (string) $r->branch_id)
              ->map(fn($r) => [
                  'deals_count' => (int) $r->deals_count,
                  'avg_sale_price_inc_vat' => (float) $r->avg_sale_price_inc_vat,
                  'avg_sale_price_ex_vat' => (float) $r->avg_sale_price_ex_vat,
                  'effective_commission_percent_ex_vat' => (float) $r->effective_commission_percent_ex_vat,
              ])->all();
        // ---- Admin: per-branch market averages (uses Deal Register + branch-local market) ----
        $avgWindow = (string) $request->get('avg_window', 'period'); // period|3m|6m|all

        // Stage filters: read input values (checkboxes may be absent on GET).
                $hasStageParams =
            $request->has('st_pending') ||
            $request->has('st_granted') ||
            $request->has('st_registered');

        $stageFilter = $hasStageParams
            ? [
                'pending'    => $request->has('st_pending'),
                'granted'    => $request->has('st_granted'),
                'registered' => $request->has('st_registered'),
              ]
            : [
                'pending'    => true,
                'granted'    => true,
                'registered' => true,
              ];
$periodStart = \Carbon\Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $periodEnd   = \Carbon\Carbon::createFromFormat('Y-m', $period)->endOfMonth();

        if ($avgWindow === '3m') {
            $dateFrom = (clone $periodStart)->subMonthsNoOverflow(2)->startOfMonth();
            $dateTo   = (clone $periodEnd);
        } elseif ($avgWindow === '6m') {
            $dateFrom = (clone $periodStart)->subMonthsNoOverflow(5)->startOfMonth();
            $dateTo   = (clone $periodEnd);
        } elseif ($avgWindow === 'all') {
            $dateFrom = null; $dateTo = null;
        } else {
            $dateFrom = (clone $periodStart);
            $dateTo   = (clone $periodEnd);
        }

        $branchMarket = []; // [branch_id => metrics]
        // ---- Per-agent market stats per branch (same logic as BM Worksheet Market) ----
        // agentMarketByBranch[branch_id][user_id] = metrics
        $agentMarketByBranch = [];

        foreach ($branches as $bid => $b) {
            $bid = (int)$bid;

            $dq = Deal::query()->where('branch_id', $bid);

            // window filter via deal_date
            if ($dateFrom && $dateTo) {
                $dq->whereBetween('deal_date', [$dateFrom->toDateString(), $dateTo->toDateString()]);
            }

            // stage filter mapped to real columns
            $dq->where(function($w) use ($stageFilter) {
                $didAny = false;

                if (!empty($stageFilter['registered'])) {
                    $w->orWhereNotNull('registration_date');
                    $didAny = true;
                }
                if (!empty($stageFilter['granted'])) {
                    $w->orWhereNotNull('granted_at');
                    $didAny = true;
                }
                if (!empty($stageFilter['pending'])) {
                    $w->orWhere(function($p) {
                        $p->where(function($x){
                            $x->whereNull('granted_at')->whereNull('registration_date');
                        })->orWhere('accepted_status', '=', 'P');
                    });
                    $didAny = true;
                }

                if (!$didAny) $w->whereRaw('1=0');
            });

            $dealIds = $dq->pluck('id')->all();
            if (empty($dealIds)) { continue; }

            // Distinct agent+deal (so listing+selling doesn't double count)
            $rows = \DB::table('deal_user')
                ->join('deals', 'deals.id', '=', 'deal_user.deal_id')
                ->where('deals.accepted_status', '!=', 'D')
                ->whereIn('deal_user.deal_id', $dealIds)
                ->selectRaw('deal_user.user_id as user_id, deal_user.deal_id as deal_id')
                ->selectRaw('CAST(deals.property_value AS REAL) as property_value')
                ->selectRaw('CAST(deals.total_commission AS REAL) as total_commission')
                ->distinct()
                ->get();

            $sumPrice = [];     // [uid => sum property_value]
            $sumPct   = [];     // [uid => sum deal_comm_pct]
            $dealSet  = [];     // [uid => [deal_id => true]]

            foreach ($rows as $r) {
                $uid = (int)$r->user_id;
                $did = (int)$r->deal_id;
                $pv  = (float)($r->property_value ?? 0);
                $tc  = (float)($r->total_commission ?? 0);

                if ($pv <= 0) continue;

                // EX VAT commission, same as BM
                $commEx = $tc / $vatDivisor;
                $pct = ($commEx / $pv) * 100.0;

                $sumPrice[$uid] = ($sumPrice[$uid] ?? 0.0) + $pv;
                $sumPct[$uid]   = ($sumPct[$uid]   ?? 0.0) + $pct;

                if (!isset($dealSet[$uid])) $dealSet[$uid] = [];
                $dealSet[$uid][$did] = true;
            }

            foreach ($dealSet as $uid => $set) {
                $count = count($set);
                $avgInc = $count ? (($sumPrice[$uid] ?? 0.0) / $count) : 0.0;
                $avgEx  = $avgInc ? ($avgInc / $vatDivisor) : 0.0;
                $avgPct = $count ? (($sumPct[$uid] ?? 0.0) / $count) : 0.0;

                if (!isset($agentMarketByBranch[$bid])) $agentMarketByBranch[$bid] = [];
                $agentMarketByBranch[$bid][(int)$uid] = [
                    'deals_count' => $count,
                    'avg_sale_price_inc_vat' => $avgInc,
                    'avg_sale_price_ex_vat' => $avgEx,
                    'effective_commission_percent_ex_vat' => $avgPct,
                ];
            }
        }
        // -------------------------------------------------------------------
        foreach ($branches as $bid => $b) {
            $q = Deal::query()->where('branch_id', (int)$bid);

            // window filter via deal_date
            if ($dateFrom && $dateTo) {
                $q->whereBetween('deal_date', [$dateFrom->toDateString(), $dateTo->toDateString()]);
            }

            // stage filter mapped to your real columns:
            // pending: accepted_status 'P' (or anything not granted/registered)
            // granted: granted_at not null
            // registered: registration_date not null
            $q->where(function($w) use ($stageFilter) {
                $didAny = false;
                if (!empty($stageFilter['registered'])) {
                    $w->orWhereNotNull('registration_date');
                    $didAny = true;
                }
                if (!empty($stageFilter['granted'])) {
                    $w->orWhereNotNull('granted_at');
                    $didAny = true;
                }
                if (!empty($stageFilter['pending'])) {
                    // if accepted_status exists, treat 'P' as pending; otherwise fall back to "not granted and not registered"
                    $w->orWhere(function($p) {
                        $p->where(function($x){
                            $x->whereNull('granted_at')->whereNull('registration_date');
                        })->orWhere('accepted_status', '=', 'P');
                    });
                    $didAny = true;
                }
                if (!$didAny) {
                    $w->whereRaw('1=0');
                }
            });

            $deals = $q->get(['property_value','total_commission']);
            $count = $deals->count();

            $sumPriceInc = (float) $deals->sum('property_value');
            $sumCommInc  = (float) $deals->sum('total_commission');

            $avgPriceInc = $count ? ($sumPriceInc / $count) : 0.0;

            $avgPriceEx = $avgPriceInc ? ($avgPriceInc / $vatDivisor) : 0.0;

            $sumPriceEx = $sumPriceInc ? ($sumPriceInc / $vatDivisor) : 0.0;
            $sumCommEx  = $sumCommInc  ? ($sumCommInc  / $vatDivisor) : 0.0;

            $effPctEx = ($sumPriceInc > 0) ? (($sumCommEx / $sumPriceInc) * 100.0) : 0.0;

            $branchMarket[(int)$bid] = [
                'deals_count' => $count,
                'avg_sale_price_inc_vat' => $avgPriceInc,
                'avg_sale_price_ex_vat' => $avgPriceEx,
                'effective_commission_percent_ex_vat' => $effPctEx,
            ];
        }

        return view('admin.worksheet_market', [
            'agentMarketByBranch' => $agentMarketByBranch,

'period' => $period,
            'agents' => $agents,
            'worksheets' => $worksheets,
                          'branches' => $branches,
              'mb' => $mb,
'avgWindow' => $avgWindow ?? 'period',
            'stageFilter' => $stageFilter ?? ['pending'=>true,'granted'=>true,'registered'=>true],
            'dateFrom' => (isset($dateFrom) && $dateFrom) ? (is_object($dateFrom) ? $dateFrom->toDateString() : Carbon::parse($dateFrom)->toDateString()) : null,
            'dateTo' => (isset($dateTo) && $dateTo) ? (is_object($dateTo) ? $dateTo->toDateString() : Carbon::parse($dateTo)->toDateString()) : null,
            'branchMarket' => $branchMarket ?? [],
                  ]);
    }

    public function store(Request $request)
{
    $u = Auth::user();
    abort_unless($u?->hasPermission('edit_worksheet'), 403);

    $data = $request->validate([
        'period' => ['required', 'string', 'max:7', 'regex:/^\d{4}-\d{2}$/'],

        // Branch to save (admin can choose; BM is scoped)
        'branch_id' => ['nullable','integer'],

        // Avg Sale Price override (Admin/BM style)
        'avg' => ['array'],
        'avg.*' => ['nullable','numeric','min:0'],

        // Commission % override (Ex VAT)
        'comm' => ['array'],
        'comm.*' => ['nullable','numeric','min:0','max:100'],

        // Lock flag (checkbox)
        'lock' => ['array'],
        'lock.*' => ['nullable'],
    ]);

    $period = $data['period'];
    $branchId = isset($data['branch_id']) && $data['branch_id'] !== '' ? (int)$data['branch_id'] : null;

    // Allowed users: agents + branch managers, optionally filtered by branch
    $allowedUsers = DB::table('users')
        ->whereIn('role', ['agent','branch_manager'])
        ->whereNotNull('branch_id')
        ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
        ->select('id')
        ->get()
        ->keyBy('id');

    // Union of all ids posted
    $ids = [];
    foreach (['avg','comm','lock'] as $k) {
        foreach (array_keys($data[$k] ?? []) as $id) { $ids[(int)$id] = true; }
    }

    foreach (array_keys($ids) as $userId) {
        if (!$allowedUsers->has($userId)) continue;

        $avg = $data['avg'][$userId] ?? null;
        $avg = ($avg === '' ? null : $avg);

        $comm = $data['comm'][$userId] ?? null;
        $comm = ($comm === '' ? null : $comm);

        $locked = !empty($data['lock'][$userId]) ? 1 : 0;

        Worksheet::updateOrCreate(
            ['user_id' => $userId, 'period' => $period],
            [
                'user_id' => $userId,
                'period' => $period,
                'avg_sale_price_admin' => $avg,
                'commission_percent_admin' => $comm,
                'commission_percent_locked' => $locked,
            ]
        );
    }

    return redirect()
        ->route('admin.worksheet-market', array_filter([
            'period' => $period,
            'branch_id' => $branchId,
            'avg_window' => $request->query('avg_window', $request->get('avg_window')),
            'st_pending' => $request->query('st_pending', $request->get('st_pending', 1)),
            'st_granted' => $request->query('st_granted', $request->get('st_granted', 1)),
            'st_registered' => $request->query('st_registered', $request->get('st_registered', 1)),
        ], fn($v) => $v !== null))
        ->with('status', 'Market overrides saved for ' . $period);
}
}
