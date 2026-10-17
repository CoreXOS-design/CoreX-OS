<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AI\AINarrativeCache;
use App\Models\AI\AiSpendGuardEvent;
use App\Models\Agency;
use App\Services\AI\AICostAggregator;
use App\Services\AI\AiSpendGuard;
use App\Services\AdTemplates\AiTemplateBuilderService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * MIC Phase B2 — AI usage / cost dashboard.
 *
 * Surfaces the monthly Anthropic spend per agency, narrative-type breakdown,
 * daily burn, top consumers (agencies), and — since AT-456 — the AI Spend
 * Safety table: each agency's rolling-window spend, Active/Alerted/Paused
 * status, and the "Turn AI back on" control. The thresholds themselves live in
 * Dev Settings → AI Spend Safety. Owner-only (route middleware).
 *
 * Spec: .ai/specs/mic-complete-spec.md §4.8, .ai/specs/at456-ai-tools-scheduler-and-spend-safety.md §A.5.
 */
class AiUsageController extends Controller
{
    public function __construct(
        private readonly AICostAggregator $aggregator,
        private readonly AiSpendGuard $spendGuard,
        private readonly AiTemplateBuilderService $templateBuilder,
    ) {
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

        // ── Daily burn for the month (from the unified ledger) ──
        $dailyBurn = DB::table('ai_usage_events')
            ->selectRaw('DATE(occurred_at) AS day, SUM(cost_zar) AS cost_zar_sum, COUNT(*) AS generations')
            ->whereBetween('occurred_at', [$monthStart, $monthEnd])
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($r) => [
                'day'         => (string) $r->day,
                'cost_zar'    => (float) $r->cost_zar_sum,
                'generations' => (int) $r->generations,
            ])
            ->all();

        // ── By source (mic_narrative, mobile_voice, image_analysis, …) ──
        $bySource = $this->aggregator->monthlyCostBySource(null, $monthCarbon);

        // ── Top consumers (agencies) — across every AI surface ──
        $topAgencies = DB::table('ai_usage_events as c')
            ->leftJoin('agencies as a', 'a.id', '=', 'c.agency_id')
            ->selectRaw('c.agency_id, a.name AS agency_name, SUM(c.cost_zar) AS cost_zar_sum, COUNT(*) AS generations')
            ->whereBetween('c.occurred_at', [$monthStart, $monthEnd])
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

        // ── AI Spend Safety — one row per agency (AT-456 §A.5) ──
        $agencies = $this->agencyRows($request, $monthStart, $monthEnd);

        // The system bucket: AI no agency owns (e.g. the Article Library).
        $system = [
            'spend_zar' => $this->spendGuard->windowSpend(null),
            'status'    => $this->spendGuard->status(null),
        ];

        // ── Active cache footprint ──
        $cacheStats = [
            'active_rows'    => AINarrativeCache::query()->whereNull('deleted_at')->count(),
            'soft_deleted'   => AINarrativeCache::query()->withTrashed()->whereNotNull('deleted_at')->count(),
            'expired_active' => AINarrativeCache::query()->whereNull('deleted_at')
                                  ->where('expires_at', '<=', now())->count(),
        ];

        return view('admin.ai-usage.index', [
            'month'          => $monthCarbon->format('Y-m'),
            'monthLabel'     => $monthCarbon->format('F Y'),
            'totalZar'       => $totalZar,
            'tokens'         => $tokens,
            'cacheHitRate30' => $cacheHitRate30,
            'dailyBurn'      => $dailyBurn,
            'bySource'       => $bySource,
            'topAgencies'    => $topAgencies,
            'agencies'       => $agencies,
            'system'         => $system,
            'guard'          => $this->spendGuard->settings(),
            'filters'        => [
                'search' => (string) $request->query('search', ''),
                'status' => $this->statusFilter($request),
                'sort'   => $this->sortColumn($request),
                'direction' => $this->sortDir($request),
            ],
            'cacheStats'     => $cacheStats,
        ]);
    }

    /**
     * Drill-down for one agency: where the AI spend comes from (by source/surface)
     * and who drove it (by user), plus the recent call log. Read-only.
     */
    public function agency(Request $request, Agency $agency): View
    {
        $this->authorizeAgency($agency);

        $month       = $request->query('month');
        $monthCarbon = $month
            ? Carbon::createFromFormat('Y-m', (string) $month)->startOfMonth()
            : Carbon::now()->startOfMonth();
        $monthStart = $monthCarbon->copy()->startOfMonth();
        $monthEnd   = $monthCarbon->copy()->endOfMonth();

        // Raw ledger query (bypasses any model scope) scoped to this agency + month.
        // Columns are fully qualified — `users` (joined below) also has agency_id,
        // so an unqualified where('agency_id') is ambiguous.
        $base = fn () => DB::table('ai_usage_events')
            ->where('ai_usage_events.agency_id', $agency->id)
            ->whereBetween('ai_usage_events.occurred_at', [$monthStart, $monthEnd]);

        $totals = $base()
            ->selectRaw('COALESCE(SUM(cost_zar),0) AS cost, COALESCE(SUM(input_tokens),0) AS inp, COALESCE(SUM(output_tokens),0) AS outp, COUNT(*) AS gens')
            ->first();

        // ── WHERE it comes from (by source) ──
        $bySource = $base()
            ->selectRaw('source, SUM(cost_zar) AS cost, COUNT(*) AS gens')
            ->groupBy('source')->orderByDesc('cost')->get()
            ->map(fn ($r) => ['source' => (string) $r->source, 'cost' => (float) $r->cost, 'gens' => (int) $r->gens])
            ->all();

        // ── WHO it comes from (by user) ──
        $byUser = $base()
            ->leftJoin('users', 'users.id', '=', 'ai_usage_events.user_id')
            ->selectRaw('ai_usage_events.user_id, users.name AS uname, SUM(ai_usage_events.cost_zar) AS cost, COUNT(*) AS gens')
            ->groupBy('ai_usage_events.user_id', 'users.name')->orderByDesc('cost')->get()
            ->map(fn ($r) => ['name' => $r->uname ?? 'System / unattributed', 'cost' => (float) $r->cost, 'gens' => (int) $r->gens])
            ->all();

        // ── Recent calls (latest 100) ──
        $recent = $base()
            ->leftJoin('users', 'users.id', '=', 'ai_usage_events.user_id')
            ->select(
                'ai_usage_events.occurred_at', 'users.name AS uname', 'ai_usage_events.source',
                'ai_usage_events.model', 'ai_usage_events.input_tokens', 'ai_usage_events.output_tokens',
                'ai_usage_events.cost_zar', 'ai_usage_events.surface_ref', 'ai_usage_events.cache_hit', 'ai_usage_events.fallback'
            )
            ->orderByDesc('ai_usage_events.occurred_at')->limit(100)->get()
            ->map(fn ($r) => [
                'occurred_at' => $r->occurred_at,
                'user'        => $r->uname ?? '—',
                'source'      => (string) $r->source,
                'model'       => (string) $r->model,
                'input'       => (int) $r->input_tokens,
                'output'      => (int) $r->output_tokens,
                'cost'        => (float) $r->cost_zar,
                'surface'     => $r->surface_ref,
                'cache_hit'   => (bool) $r->cache_hit,
                'fallback'    => (bool) $r->fallback,
            ])->all();

        // ── AI Spend Safety: live status + alert/pause/resume history (AT-456 §A.5) ──
        $guardHistory = AiSpendGuardEvent::query()
            ->with('user:id,name')
            ->where('agency_id', $agency->id)
            ->whereIn('type', [AiSpendGuardEvent::TYPE_ALERT, AiSpendGuardEvent::TYPE_PAUSE, AiSpendGuardEvent::TYPE_RESUME, AiSpendGuardEvent::TYPE_TEMPLATE_LIMIT])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return view('admin.ai-usage.agency', [
            'guardSnapshot' => $this->spendGuard->snapshot($agency),
            'guard'         => $this->spendGuard->settings(),
            'guardHistory'  => $guardHistory,
            // AT-456 Part B — this month's AI template allowance + how many AI templates are saved.
            'templateUsage' => $this->templateBuilder->usage((int) $agency->id),
            'templatesSaved' => (int) DB::table('property_ad_templates')->where('agency_id', $agency->id)->where('ai_generated', true)->count(),
            'agency'     => $agency,
            'month'      => $monthCarbon->format('Y-m'),
            'monthLabel' => $monthCarbon->format('F Y'),
            'totals'     => [
                'cost' => (float) ($totals->cost ?? 0),
                'inp'  => (int) ($totals->inp ?? 0),
                'outp' => (int) ($totals->outp ?? 0),
                'gens' => (int) ($totals->gens ?? 0),
            ],
            'bySource'   => $bySource,
            'byUser'     => $byUser,
            'recent'     => $recent,
        ]);
    }

    /**
     * "Turn AI back on" for one agency (AT-456 §A.2). Owner-only at the route.
     * Audited by AiSpendGuard::resume() (who, when, spend at the time).
     */
    public function resume(Request $request, Agency $agency): RedirectResponse
    {
        $this->authorizeAgency($agency);

        if (!$this->spendGuard->isPaused((int) $agency->id)) {
            return back()->with('status', "AI is already on for {$agency->name}.");
        }

        $this->spendGuard->resume((int) $agency->id, $request->user());

        return back()->with('status', "AI is back on for {$agency->name}. Spend is counted afresh from now.");
    }

    /**
     * Set an agency's monthly AI template generation limit (AT-456 Part B, spec §B.4).
     * Owner-only at the route. Takes effect on the agency's very next attempt. Audited in
     * ai_spend_guard_events as a `template_limit` row (who, old → new).
     */
    public function updateTemplateLimit(Request $request, Agency $agency): RedirectResponse
    {
        $this->authorizeAgency($agency);

        $data = $request->validate([
            'limit' => ['required', 'integer', 'min:0', 'max:1000'],
        ], [
            'limit.required' => 'Enter a whole number from 0 to 1000.',
            'limit.integer'  => 'Enter a whole number from 0 to 1000.',
            'limit.min'      => 'Enter a whole number from 0 to 1000.',
            'limit.max'      => 'Enter a whole number from 0 to 1000.',
        ]);

        $new = (int) $data['limit'];
        $old = $this->templateBuilder->limitFor((int) $agency->id);

        if ($new === $old) {
            return back()->with('status', "{$agency->name}'s template limit is already {$new} a month.");
        }

        DB::transaction(function () use ($agency, $new, $old, $request) {
            DB::table('agencies')->where('id', $agency->id)->update(['ai_template_monthly_limit' => $new]);

            AiSpendGuardEvent::create([
                'agency_id'   => $agency->id,
                'type'        => AiSpendGuardEvent::TYPE_TEMPLATE_LIMIT,
                'spend_zar'   => 0,
                'user_id'     => $request->user()->id,
                'details'     => ['old' => $old, 'new' => $new],
                'occurred_at' => Carbon::now(),
            ]);
        });

        return back()->with('status', "{$agency->name} can now make {$new} AI template generation" . ($new === 1 ? '' : 's') . ' a month (was ' . $old . ').');
    }

    /**
     * "Turn AI back on" for the platform-wide system bucket (AI no agency owns).
     */
    public function resumeSystem(Request $request): RedirectResponse
    {
        if (!$this->spendGuard->isPaused(null)) {
            return back()->with('status', 'Platform AI is already on.');
        }

        $this->spendGuard->resume(null, $request->user());

        return back()->with('status', 'Platform AI is back on. Spend is counted afresh from now.');
    }

    /** Sortable columns of the per-agency table → row key. Default: 24h spend, highest first. */
    private const SORTS = [
        'name'        => 'name',
        'spend_24h'   => 'spend_24h',
        'spend_month' => 'spend_month',
        'status'      => 'status',
        'template_gens'   => 'template_used',
        'templates_saved' => 'templates_saved',
    ];

    private const STATUSES = [
        AiSpendGuard::STATUS_ACTIVE,
        AiSpendGuard::STATUS_ALERTED,
        AiSpendGuard::STATUS_PAUSED,
    ];

    private const PER_PAGE = 25;

    /**
     * The per-agency AI Spend Safety table: search, status filter, sort on
     * every column, 25 per page. Agencies are few and two of the columns are
     * computed per agency (the window floor differs after a resume), so rows
     * are built in PHP and paged as a collection.
     */
    private function agencyRows(Request $request, Carbon $monthStart, Carbon $monthEnd): LengthAwarePaginator
    {
        $search = trim((string) $request->query('search', ''));
        $status = $this->statusFilter($request);
        $sort   = $this->sortColumn($request);
        $dir    = $this->sortDir($request);

        $monthSpend = DB::table('ai_usage_events')
            ->whereNotNull('agency_id')
            ->whereBetween('occurred_at', [$monthStart, $monthEnd])
            ->groupBy('agency_id')
            ->selectRaw('agency_id, SUM(cost_zar) AS cost')
            ->pluck('cost', 'agency_id');

        // AT-456 Part B — this calendar month's AI template generations (the window the limit
        // counts) and every AI-made template an agency has saved (archived ones included: it
        // measures what the AI made for them, not what is currently on show).
        $templateUsed  = $this->templateBuilder->usedThisMonthByAgency();
        $templateSaved = DB::table('property_ad_templates')
            ->where('ai_generated', true)
            ->groupBy('agency_id')
            ->selectRaw('agency_id, COUNT(*) AS n')
            ->pluck('n', 'agency_id');

        $rows = Agency::query()
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%' . $search . '%'))
            ->get()
            ->map(function (Agency $a) use ($monthSpend, $templateUsed, $templateSaved) {
                $snap = $this->spendGuard->snapshot($a);

                return [
                    'id'            => $a->id,
                    'name'          => (string) $a->name,
                    'spend_24h'     => $snap['spend_zar'],
                    'since_resume'  => $snap['since_resume'],
                    'window_start'  => $snap['window_start'],
                    'spend_month'   => (float) ($monthSpend[$a->id] ?? 0),
                    'template_used'  => (int) ($templateUsed[$a->id] ?? 0),
                    'template_limit' => $a->ai_template_monthly_limit === null ? AiTemplateBuilderService::DEFAULT_LIMIT : (int) $a->ai_template_monthly_limit,
                    'templates_saved' => (int) ($templateSaved[$a->id] ?? 0),
                    'status'        => $snap['status'],
                    'paused_at'     => $a->ai_paused_at,
                    'paused_reason' => $a->ai_paused_reason,
                ];
            })
            ->when($status !== 'all', fn ($c) => $c->where('status', $status))
            ->sortBy(
                fn ($r) => match ($sort) {
                    'status' => array_search($r['status'], self::STATUSES, true),
                    'name'   => mb_strtolower($r['name']),
                    default  => $r[self::SORTS[$sort]],
                },
                SORT_REGULAR,
                $dir === 'desc',
            )
            ->values();

        $page = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $rows->forPage($page, self::PER_PAGE)->values(),
            $rows->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }

    private function statusFilter(Request $request): string
    {
        $status = (string) $request->query('status', 'all');

        return in_array($status, self::STATUSES, true) ? $status : 'all';
    }

    private function sortColumn(Request $request): string
    {
        $sort = (string) $request->query('sort', 'spend_24h');

        return array_key_exists($sort, self::SORTS) ? $sort : 'spend_24h';
    }

    private function sortDir(Request $request): string
    {
        // Name reads naturally A→Z; every money/status column reads worst-first.
        $default = $this->sortColumn($request) === 'name' ? 'asc' : 'desc';
        $dir     = (string) $request->query('direction', $default);

        return in_array($dir, ['asc', 'desc'], true) ? $dir : $default;
    }

    /**
     * `mic.view_ai_costs` (like `manage_performance_settings` / `agency_api.manage`)
     * is grantable to an ordinary agency admin, not just platform owners, and
     * Agency itself carries no tenant scope — so route-model binding won't 404 a
     * foreign agency the way it does for child tables, and the underlying
     * ai_usage_events query is scoped to whatever $agency->id the URL supplies.
     * Mirrors AgencyApiKeyController::authorizeAgency() / CompanySettingsController::
     * authorizeAgency() / AgencyController::authorizeAgencyScope().
     */
    private function authorizeAgency(Agency $agency): void
    {
        $user = auth()->user();
        if (!$user) {
            abort(403);
        }
        if ($user->isOwnerRole()) {
            return;
        }
        if ((int) $user->effectiveAgencyId() !== (int) $agency->id) {
            abort(403, 'You can only view AI usage for your own agency.');
        }
    }
}
