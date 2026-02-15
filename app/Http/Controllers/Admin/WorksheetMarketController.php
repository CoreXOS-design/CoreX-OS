<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
        if (!in_array($u->effectiveRole(), ['admin','branch_manager'], true)) {
            abort(403);
        }

        $period = $request->query('period', now()->format('Y-m'));

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
          $mb = Deal::query()
              ->where('period', $period)
              ->whereNotNull('branch_id')
              ->selectRaw('branch_id, COUNT(*) as deals_count')
              ->selectRaw('AVG(property_value) as avg_sale_price_inc_vat')
              ->selectRaw('AVG(property_value/1.15) as avg_sale_price_ex_vat')
              ->selectRaw('AVG((total_commission / NULLIF(property_value/1.15,0))*100.0) as effective_commission_percent_ex_vat')
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
        $stageFilter = [
            'pending'    => (bool) $request->get('st_pending', 1),
            'granted'    => (bool) $request->get('st_granted', 1),
            'registered' => (bool) $request->get('st_registered', 1),
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

            // SA VAT 15%
            $vatDiv = 1.15;
            $avgPriceEx = $avgPriceInc ? ($avgPriceInc / $vatDiv) : 0.0;

            $sumPriceEx = $sumPriceInc ? ($sumPriceInc / $vatDiv) : 0.0;
            $sumCommEx  = $sumCommInc  ? ($sumCommInc  / $vatDiv) : 0.0;

            $effPctEx = ($sumPriceEx > 0) ? (($sumCommEx / $sumPriceEx) * 100.0) : 0.0;

            $branchMarket[(int)$bid] = [
                'deals_count' => $count,
                'avg_sale_price_inc_vat' => $avgPriceInc,
                'avg_sale_price_ex_vat' => $avgPriceEx,
                'effective_commission_percent_ex_vat' => $effPctEx,
            ];
        }


          // ---- Deal Register market averages (admin, per-branch) ----
          $avgWindow = (string) $request->get('avg_window', 'period');
          $stageFilter = [
              'pending'    => (bool) $request->get('st_pending', 1),
              'granted'    => (bool) $request->get('st_granted', 1),
              'registered' => (bool) $request->get('st_registered', 1),
          ];

          $dateFrom = null; $dateTo = null;
          if (preg_match('/^\d{4}-\d{2}$/', $period)) {
              $start = \Carbon\Carbon::createFromFormat('Y-m', $period)->startOfMonth();
              $end = (clone $start)->endOfMonth();

              if ($avgWindow === 'period') { $dateFrom = $start->toDateString(); $dateTo = $end->toDateString(); }
              elseif ($avgWindow === '3m') { $dateFrom = (clone $start)->subMonthsNoOverflow(2)->startOfMonth()->toDateString(); $dateTo = $end->toDateString(); }
              elseif ($avgWindow === '6m') { $dateFrom = (clone $start)->subMonthsNoOverflow(5)->startOfMonth()->toDateString(); $dateTo = $end->toDateString(); }
              elseif ($avgWindow === 'all') { $dateFrom = null; $dateTo = null; }
          }

          $stages = [];
          if (!empty($stageFilter['pending'])) $stages[] = 'pending';
          if (!empty($stageFilter['granted'])) $stages[] = 'granted';
          if (!empty($stageFilter['registered'])) $stages[] = 'registered';

          $marketByBranch = [];
          foreach ($branches as $bid => $b) {
              $q = \App\Models\Deal::query()->where('branch_id', (int)$bid);

              if (!empty($stages)) $q->whereIn('status', $stages);

              if ($dateFrom && $dateTo) {
                  $q->where(function($qq) use ($dateFrom, $dateTo) {
                      $qq->whereBetween('registered_at', [$dateFrom, $dateTo])
                         ->orWhereBetween('granted_at', [$dateFrom, $dateTo])
                         ->orWhereBetween('created_at', [$dateFrom, $dateTo]);
                  });
              }

              $deals = $q->get();
              $count = $deals->count();

              $avgInc = $count ? (float) $deals->avg(function($d){ return (float)($d->sale_price_inc_vat ?? $d->sale_price ?? 0); }) : 0.0;
              $avgEx  = $count ? (float) $deals->avg(function($d){ return (float)($d->sale_price_ex_vat ?? 0); }) : 0.0;

              $effPct = 0.0;
              if ($count) {
                  $sum = 0.0; $n = 0;
                  foreach ($deals as $d) {
                      $p = $d->effective_commission_percent_ex_vat ?? null;
                      if ($p === null) {
                          $saleEx = (float)($d->sale_price_ex_vat ?? 0);
                          $commEx = (float)($d->commission_ex_vat ?? 0);
                          if ($saleEx > 0 && $commEx > 0) $p = ($commEx / $saleEx) * 100.0;
                      }
                      if ($p !== null) { $sum += (float)$p; $n++; }
                  }
                  $effPct = $n ? ($sum / $n) : 0.0;
              }

              $marketByBranch[$bid] = [
                  'deals_count' => $count,
                  'avg_sale_price_inc_vat' => $avgInc,
                  'avg_sale_price_ex_vat' => $avgEx,
                  'effective_commission_percent_ex_vat' => $effPct,
              ];
          }
        return view('admin.worksheet_market', [
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
        if (!in_array($u->effectiveRole(), ['admin','branch_manager'], true)) {
            abort(403);
        }

        $data = $request->validate([
            'period' => ['required', 'string', 'max:7', 'regex:/^\d{4}-\d{2}$/'],
            'avg' => ['array'],
            'avg.*' => ['nullable','numeric','min:0'],
        ]);

        $period = $data['period'];

        foreach (($data['avg'] ?? []) as $userId => $avgPrice) {
              $userId = (int)$userId;
              if (!$allowedUsers->has($userId)) { continue; }
              Worksheet::updateOrCreate(
                ['user_id' => $userId, 'period' => $period],
                ['avg_sale_price_admin' => $avgPrice]
            );
        }

        return redirect()
            ->route('admin.worksheet-market', ['period' => $period])
            ->with('status', 'Saved planned average sale prices for ' . $period);
    }
}
