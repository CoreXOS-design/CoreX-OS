<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AI\AINarrativeCache;
use App\Models\Agency;
use App\Services\AI\AICostAggregator;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * MIC Phase B2 — AI usage / cost dashboard.
 *
 * Surfaces the monthly Anthropic spend per agency, narrative-type breakdown,
 * daily burn, top consumers (agencies), and the per-agency budget control
 * form. Read-only for users with `mic.view_ai_costs`; budget edits require
 * `super_admin` role.
 *
 * Spec: .ai/specs/mic-complete-spec.md §4.8.
 */
class AiUsageController extends Controller
{
    public function __construct(private readonly AICostAggregator $aggregator)
    {
        // Middleware is applied at the route level (see routes/web.php):
        // `auth` via the wrapping group, `permission:mic.view_ai_costs` per route.
        // Laravel 11/12 controllers no longer expose $this->middleware().
    }

    public function index(Request $request): View
    {
        $month = $request->query('month');
        $monthCarbon = $month
            ? Carbon::createFromFormat('Y-m', (string) $month)->startOfMonth()
            : Carbon::now()->startOfMonth();

        $monthStart = $monthCarbon->copy()->startOfMonth();
        $monthEnd   = $monthCarbon->copy()->endOfMonth();

        // ── Hero: month-to-date global spend ──
        $totalZar       = $this->aggregator->monthlyCostZar(null, $monthCarbon);
        $tokens         = $this->aggregator->totalTokensThisMonth(null);
        $cacheHitRate30 = $this->aggregator->cacheHitRate(30);

        // ── Daily burn for the month ──
        $dailyBurn = DB::table('ai_narrative_cache')
            ->selectRaw('DATE(generated_at) AS day, SUM(cost_zar) AS cost_zar_sum, COUNT(*) AS generations')
            ->whereBetween('generated_at', [$monthStart, $monthEnd])
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($r) => [
                'day'         => (string) $r->day,
                'cost_zar'    => (float) $r->cost_zar_sum,
                'generations' => (int) $r->generations,
            ])
            ->all();

        // ── By narrative type ──
        $byType = $this->aggregator->monthlyCostByNarrativeType(null, $monthCarbon);

        // ── Top consumers (agencies) ──
        $topAgencies = DB::table('ai_narrative_cache as c')
            ->leftJoin('agencies as a', 'a.id', '=', 'c.agency_id')
            ->selectRaw('c.agency_id, a.name AS agency_name, SUM(c.cost_zar) AS cost_zar_sum, COUNT(*) AS generations')
            ->whereBetween('c.generated_at', [$monthStart, $monthEnd])
            ->groupBy('c.agency_id', 'a.name')
            ->orderByDesc('cost_zar_sum')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'agency_id'   => $r->agency_id !== null ? (int) $r->agency_id : null,
                'agency_name' => $r->agency_name ?? '(global)',
                'cost_zar'    => (float) $r->cost_zar_sum,
                'generations' => (int) $r->generations,
            ])
            ->all();

        // ── Agencies + budget status (for the management form) ──
        $agencies = Agency::query()
            ->orderBy('name')
            ->get()
            ->map(function (Agency $a) use ($monthCarbon) {
                return [
                    'id'                => $a->id,
                    'name'              => $a->name,
                    'budget_zar'        => (float) ($a->ai_monthly_budget_zar ?? 0),
                    'used_zar'          => $a->aiBudgetUsedZar($monthCarbon),
                    'used_pct'          => $a->aiBudgetUsedPct($monthCarbon),
                    'status'            => $a->aiBudgetStatus($monthCarbon),
                    'warning_pct'       => (int) ($a->ai_budget_warning_pct ?? 80),
                    'hard_cap_pct'      => (int) ($a->ai_budget_hard_cap_pct ?? 110),
                    'overage_allowed'   => (bool) $a->ai_budget_overage_allowed,
                    'last_warned_at'    => $a->ai_budget_last_warned_at,
                    'last_hard_stopped' => $a->ai_budget_last_hard_stopped_at,
                ];
            })
            ->all();

        // ── Active cache footprint ──
        $cacheStats = [
            'active_rows'    => AINarrativeCache::query()->whereNull('deleted_at')->count(),
            'soft_deleted'   => AINarrativeCache::query()->withTrashed()->whereNotNull('deleted_at')->count(),
            'expired_active' => AINarrativeCache::query()->whereNull('deleted_at')
                                  ->where('expires_at', '<=', now())->count(),
        ];

        $canEditBudgets = $request->user()?->role === 'super_admin';

        return view('admin.ai-usage.index', [
            'month'          => $monthCarbon->format('Y-m'),
            'monthLabel'     => $monthCarbon->format('F Y'),
            'totalZar'       => $totalZar,
            'tokens'         => $tokens,
            'cacheHitRate30' => $cacheHitRate30,
            'dailyBurn'      => $dailyBurn,
            'byType'         => $byType,
            'topAgencies'    => $topAgencies,
            'agencies'       => $agencies,
            'cacheStats'     => $cacheStats,
            'canEditBudgets' => $canEditBudgets,
        ]);
    }

    /**
     * Update an agency's AI budget. super_admin only.
     */
    public function updateBudget(Request $request, Agency $agency): RedirectResponse
    {
        if ($request->user()?->role !== 'super_admin') {
            abort(403, 'AI budget edits are restricted to super_admin.');
        }

        $validated = $request->validate([
            'ai_monthly_budget_zar'     => ['required', 'numeric', 'min:0', 'max:1000000'],
            'ai_budget_warning_pct'     => ['required', 'integer', 'min:0', 'max:100'],
            'ai_budget_hard_cap_pct'    => ['required', 'integer', 'min:50', 'max:200'],
            'ai_budget_overage_allowed' => ['sometimes', 'boolean'],
        ]);

        $agency->update([
            'ai_monthly_budget_zar'     => $validated['ai_monthly_budget_zar'],
            'ai_budget_warning_pct'     => $validated['ai_budget_warning_pct'],
            'ai_budget_hard_cap_pct'    => $validated['ai_budget_hard_cap_pct'],
            'ai_budget_overage_allowed' => (bool) ($validated['ai_budget_overage_allowed'] ?? false),
        ]);

        return redirect()->route('admin.ai-usage.index')
            ->with('status', "Budget updated for {$agency->name}.");
    }
}
