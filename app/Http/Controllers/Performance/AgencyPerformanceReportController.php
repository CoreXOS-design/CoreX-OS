<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Performance\AgencyPerformanceReportService;
use App\Services\Performance\BuyerActivityService;
use App\Services\Performance\Period;
use App\Services\Performance\PeriodResolver;
use App\Services\Performance\PerformanceDrilldownService;
use App\Services\Performance\PerformanceScope;
use App\Services\Performance\PeriodComparison;
use App\Services\Performance\ReportPeriodComparator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AT-366 — Agency Performance & ROI report. Renders the scope + period selector
 * and the agent → branch → company rollup over the registered metric providers,
 * plus branch/agent drill-down (AT-366-D) and the period-scoped buyer-activity
 * view (AT-366-E). Every metric reconciles agent → branch → company.
 */
class AgencyPerformanceReportController extends Controller
{
    public function index(Request $request, PeriodResolver $periods, AgencyPerformanceReportService $service, BuyerActivityService $buyers, ReportPeriodComparator $comparator)
    {
        $user     = $request->user();
        $agencyId = $user?->effectiveAgencyId();
        abort_if(!$agencyId, 403, 'No agency context for the performance report.');

        [$period, $preset] = $this->resolvePeriod($request, $periods);

        $branchId = $request->filled('branch_id') ? (int) $request->query('branch_id') : null;
        $userId   = $request->filled('user_id') ? (int) $request->query('user_id') : null;

        $scope  = new PerformanceScope((int) $agencyId, $branchId, $userId);
        $report = $service->build($scope, $period);

        // AT-366-E — company-level buyer-activity summary (period-scoped).
        $buyerActivity = $buyers->rollup($scope, $period);

        // 2026-08-19 (Johan, period-comparison) — resolved ADDITIVELY: $report and
        // $buyerActivity above are completely untouched either way, so "comparison
        // off" is byte-identical to before this feature existed. See
        // .ai/specs/at366-period-comparison.md.
        [$comparePeriod, $compareMode, $compareError] = $this->resolveComparisonPeriod($request, $periods, $period);

        $comparison = null;
        $buyerComparison = null;
        $comparisonMeta = null;
        if ($comparePeriod !== null) {
            $prevReport = $service->build($scope, $comparePeriod);
            $comparison = $comparator->build($report, $prevReport);

            $prevBuyer = $buyers->rollup($scope, $comparePeriod);
            $buyerComparison = $this->compareBuyerRollup($buyerActivity, $prevBuyer);

            $comparisonMeta = [
                'period'         => $comparePeriod->toArray(),
                'mode'           => $compareMode,
                'unequal_length' => $period->lengthInDays() !== $comparePeriod->lengthInDays(),
                'period_days'    => $period->lengthInDays(),
                'comparison_days' => $comparePeriod->lengthInDays(),
                // 2026-08-19 (Johan, Phase 2) — "every delta reads as '+R124k vs
                // previous period'... the reader must never have to work out what
                // it's being compared to." One phrase, built once, used everywhere
                // a delta renders (hero line, tiles, table captions) so it can
                // never drift between surfaces.
                'phrase' => match ($compareMode) {
                    'previous'       => 'vs previous period',
                    'same_last_year' => 'vs same period last year',
                    'custom'         => 'vs ' . $comparePeriod->label,
                    default          => 'vs comparison period',
                },
            ];
        } elseif ($compareError) {
            session()->flash('compare_error', $compareError);
        }

        return view('performance.agency-report.index', [
            'report' => $report,
            'buyer'  => [
                'metrics'   => $buyerActivity['metrics'],
                'aggregate' => $buyerActivity['company'],
            ],
            'preset' => $preset,
            'presets' => PeriodResolver::PRESETS,
            'compareMode'      => $compareMode,
            'compareModes'     => PeriodResolver::COMPARE_MODES,
            'comparison'       => $comparison,
            'buyerComparison'  => $buyerComparison,
            'comparisonMeta'   => $comparisonMeta,
        ]);
    }

    /**
     * 2026-08-19 (Johan, period-comparison) — mirrors resolvePeriod()'s
     * fail-soft shape: an invalid custom comparison range falls back to
     * comparison OFF (never a 500, never a broken page) with the error
     * flashed for the header to show. Returns [?Period, string mode, ?string error].
     */
    private function resolveComparisonPeriod(Request $request, PeriodResolver $periods, Period $period): array
    {
        $mode = (string) $request->query('compare', 'off');
        if (!in_array($mode, PeriodResolver::COMPARE_MODES, true)) {
            $mode = 'off';
        }

        try {
            $comparePeriod = $periods->resolveComparison($mode, $period, $request->query('compare_start'), $request->query('compare_end'));
        } catch (\InvalidArgumentException $e) {
            return [null, 'off', $e->getMessage()];
        }

        return [$comparePeriod, $mode, null];
    }

    /** The 6 buyer-activity metrics get the identical treatment as the 13 company metrics, without needing ReportPeriodComparator's branch/agent/deal_status merge logic. */
    private function compareBuyerRollup(array $current, array $previous): array
    {
        $out = ['company' => [], 'branches' => [], 'agents' => []];
        foreach (BuyerActivityService::METRICS as $m) {
            $out['company'][$m['key']] = PeriodComparison::compute(
                (float) ($current['company'][$m['key']] ?? 0),
                (float) ($previous['company'][$m['key']] ?? 0),
                $m['direction'],
            );
        }

        $branchKeys = array_unique(array_merge(array_keys($current['branches']), array_keys($previous['branches'])));
        foreach ($branchKeys as $key) {
            $curB  = $current['branches'][$key]['metrics']  ?? [];
            $prevB = $previous['branches'][$key]['metrics'] ?? [];
            $row = [];
            foreach (BuyerActivityService::METRICS as $m) {
                $row[$m['key']] = PeriodComparison::compute((float) ($curB[$m['key']] ?? 0), (float) ($prevB[$m['key']] ?? 0), $m['direction']);
            }
            $out['branches'][$key] = [
                'label'   => $current['branches'][$key]['label'] ?? $previous['branches'][$key]['label'] ?? (string) $key,
                'metrics' => $row,
            ];
        }

        $prevAgentsByUser = [];
        foreach ($previous['agents'] as $a) {
            $prevAgentsByUser[$a['user_id']] = $a;
        }
        foreach ($current['agents'] as $a) {
            $prevA = $prevAgentsByUser[$a['user_id']] ?? ['metrics' => []];
            $row = [];
            foreach (BuyerActivityService::METRICS as $m) {
                $row[$m['key']] = PeriodComparison::compute((float) ($a['metrics'][$m['key']] ?? 0), (float) ($prevA['metrics'][$m['key']] ?? 0), $m['direction']);
            }
            $out['agents'][] = ['user_id' => $a['user_id'], 'name' => $a['name'], 'metrics' => $row];
        }

        return $out;
    }

    /**
     * AT-366-D — one branch's drill-down: the branch's rolled-up metrics with
     * prior-period trend + the agents attributed to it (point-in-time). The
     * {branch} segment is a numeric branch id or the 'unassigned' sentinel.
     */
    public function branch(Request $request, string $branch, PeriodResolver $periods, AgencyPerformanceReportService $service, BuyerActivityService $buyers)
    {
        $actor    = $request->user();
        $agencyId = $actor?->effectiveAgencyId();
        abort_if(!$agencyId, 403, 'No agency context for the performance report.');
        abort_unless($branch === 'unassigned' || ctype_digit($branch), 404);

        [$period, $preset] = $this->resolvePeriod($request, $periods);

        $report = $service->branchJourney((int) $agencyId, $branch, $period);
        abort_if($report['branch'] === null, 404);

        // AT-366-E — this branch's buyer-activity summary, pulled from the same
        // company rollup so it reconciles with the company total.
        $buyerActivity = $buyers->rollup(new PerformanceScope((int) $agencyId), $period);

        return view('performance.agency-report.branch', [
            'report'  => $report,
            'buyer'   => [
                'metrics'   => $buyerActivity['metrics'],
                'aggregate' => $buyerActivity['branches'][$branch]['metrics'] ?? null,
            ],
            'preset'  => $preset,
            'presets' => PeriodResolver::PRESETS,
        ]);
    }

    /**
     * AT-366-C — one agent's journey: every metric for the period paired with its
     * prior-period value (trend). Agency-scoped; owners / out-of-agency 404.
     */
    public function agent(Request $request, User $user, PeriodResolver $periods, AgencyPerformanceReportService $service, BuyerActivityService $buyers)
    {
        $actor    = $request->user();
        $agencyId = $actor?->effectiveAgencyId();
        abort_if(!$agencyId, 403, 'No agency context for the performance report.');
        abort_unless((int) $user->agency_id === (int) $agencyId, 404);

        [$period, $preset] = $this->resolvePeriod($request, $periods);

        $journey = $service->agentJourney((int) $agencyId, (int) $user->id, $period);
        abort_if($journey['agent'] === null, 404);

        // AT-366-E — the agent's full period-scoped buyer-activity picture (Q7).
        $buyerActivity = $buyers->agentDetail((int) $agencyId, (int) $user->id, $period);

        return view('performance.agency-report.agent', [
            'journey' => $journey,
            'buyer'   => $buyerActivity,
            'preset'  => $preset,
            'presets' => PeriodResolver::PRESETS,
        ]);
    }

    /**
     * AT-366 (cc1 frontend #8) — whole-company printable: the full report, chrome-free,
     * with a company header. Same agency-scoped build() as index(); print-optimised view.
     */
    public function print(Request $request, PeriodResolver $periods, AgencyPerformanceReportService $service, BuyerActivityService $buyers)
    {
        $user     = $request->user();
        $agencyId = $user?->effectiveAgencyId();
        abort_if(!$agencyId, 403, 'No agency context for the performance report.');

        [$period, $preset] = $this->resolvePeriod($request, $periods);

        $scope  = new PerformanceScope((int) $agencyId, null, null);
        $report = $service->build($scope, $period);
        $buyerActivity = $buyers->rollup($scope, $period);

        return view('performance.agency-report.print-company', [
            'report' => $report,
            'buyer'  => ['metrics' => $buyerActivity['metrics'], 'aggregate' => $buyerActivity['company']],
            'agency' => \App\Models\Agency::withoutGlobalScopes()->find((int) $agencyId),
            'preset' => $preset,
        ]);
    }

    /**
     * AT-366 (cc1 frontend #8) — single-agent printable to hand an agent their own
     * figures. Agency-scoped exactly like agent(); out-of-agency users 404.
     */
    public function agentPrint(Request $request, User $user, PeriodResolver $periods, AgencyPerformanceReportService $service, BuyerActivityService $buyers)
    {
        $actor    = $request->user();
        $agencyId = $actor?->effectiveAgencyId();
        abort_if(!$agencyId, 403, 'No agency context for the performance report.');
        abort_unless((int) $user->agency_id === (int) $agencyId, 404);

        [$period, $preset] = $this->resolvePeriod($request, $periods);

        $journey = $service->agentJourney((int) $agencyId, (int) $user->id, $period);
        abort_if($journey['agent'] === null, 404);

        return view('performance.agency-report.print-agent', [
            'journey' => $journey,
            'agency'  => \App\Models\Agency::withoutGlobalScopes()->find((int) $agencyId),
            'preset'  => $preset,
        ]);
    }

    /** @return array{0: Period, 1: string} resolved period + effective preset */
    /**
     * AT-366 §B (#9) — read-only drilldown rows behind a clicked figure.
     * GET /corex/performance/agency-report/drilldown?metric=&level=&id=&period=&start=&end=&status=
     * Returns {title,total,columns,rows} per the frontend contract. Strictly agency-scoped.
     */
    public function drilldown(Request $request, PeriodResolver $periods, PerformanceDrilldownService $drill)
    {
        $agencyId = (int) ($request->user()?->effectiveAgencyId() ?? 0);
        abort_if(!$agencyId, 403, 'No agency context.');

        // Accept BOTH the short contract aliases AND every provider/tile key shown on the report.
        $map = [
            // short aliases
            'deals' => 'deals', 'contacts' => 'contacts_created', 'properties' => 'properties_created',
            'fica' => 'fica_submissions', 'buyers' => 'buyers_added', 'viewings' => 'viewings',
            // provider keys (tiles + columns)
            'mic_claims' => 'mic_claims', 'contacts_created' => 'contacts_created', 'properties_created' => 'properties_created',
            'presentations_created' => 'presentations_created', 'portal_views' => 'portal_views',
            'buyers_added' => 'buyers_added', 'appointments' => 'appointments', 'outreach_messages' => 'outreach_messages',
            'fica_submissions' => 'fica_submissions',
            'deals_created' => 'deals', 'deals_registered' => 'deals', 'commission_gross_ex_vat' => 'commission',
        ];
        $metricIn = (string) $request->query('metric', '');
        $metric   = $map[$metricIn] ?? $metricIn; // accept the short alias OR the full metric/tile key
        abort_unless(in_array($metric, PerformanceDrilldownService::METRICS, true), 422, 'Unknown metric.');

        $status = $request->filled('status') ? (string) $request->query('status') : null;
        abort_if($status !== null && !in_array($status, ['pending', 'granted', 'registered', 'declined', 'all'], true), 422, 'Unknown status.');
        // The "deals registered" tile/column is the deals list forced to the registered bucket.
        if ($metricIn === 'deals_registered') $status = 'registered';

        $level = (string) $request->query('level', 'company');
        $id    = $request->filled('id') ? (int) $request->query('id') : null;
        $agentId = null; $branchId = null;
        if ($level === 'agent') {
            abort_unless($id && DB::table('users')->where('id', $id)->where('agency_id', $agencyId)->exists(), 404, 'Agent not in agency.');
            $agentId = $id;
        } elseif ($level === 'branch' && $id !== null) {
            abort_unless(DB::table('branches')->where('id', $id)->where('agency_id', $agencyId)->exists(), 404, 'Branch not in agency.');
            $branchId = $id;
        }

        [$period] = $this->resolvePeriod($request, $periods);
        $cohort   = $drill->cohort($agencyId, $branchId, $agentId);
        $res      = $drill->rows($metric, $cohort, $period, $agencyId, $metric === 'deals' ? $status : null);

        return response()->json([
            'title'   => $this->drilldownTitle($metric, $status, $level, $id, $period, $res['count']),
            'total'   => $res['count'],
            'columns' => $this->drilldownColumns($metric),
            'rows'    => array_map(fn ($r) => $this->drilldownRow($metric, $r), $res['rows']),
        ]);
    }

    private function drilldownColumns(string $metric): array
    {
        return match ($metric) {
            'deals' => [
                ['key' => 'address', 'label' => 'Property', 'align' => 'left'],
                ['key' => 'price', 'label' => 'Price', 'align' => 'right', 'format' => 'currency'],
                ['key' => 'commission', 'label' => 'Commission', 'align' => 'right', 'format' => 'currency'],
                ['key' => 'status', 'label' => 'Status', 'align' => 'left', 'format' => 'badge'],
            ],
            'contacts_created' => [
                ['key' => 'name', 'label' => 'Contact', 'align' => 'left'],
                ['key' => 'created', 'label' => 'Created', 'align' => 'left', 'format' => 'date'],
            ],
            'properties_created' => [
                ['key' => 'address', 'label' => 'Property', 'align' => 'left'],
                ['key' => 'status', 'label' => 'Status', 'align' => 'left', 'format' => 'badge'],
                ['key' => 'listed', 'label' => 'Listed', 'align' => 'left', 'format' => 'date'],
            ],
            'fica_submissions' => [
                ['key' => 'contact', 'label' => 'Contact', 'align' => 'left'],
                ['key' => 'status', 'label' => 'Status', 'align' => 'left', 'format' => 'badge'],
                ['key' => 'created', 'label' => 'Created', 'align' => 'left', 'format' => 'date'],
            ],
            'buyers_added' => [
                ['key' => 'name', 'label' => 'Buyer', 'align' => 'left'],
                ['key' => 'entered', 'label' => 'Added', 'align' => 'left', 'format' => 'date'],
            ],
            'viewings' => [
                ['key' => 'title', 'label' => 'Viewing', 'align' => 'left'],
                ['key' => 'date', 'label' => 'Date', 'align' => 'left', 'format' => 'date'],
            ],
            'mic_claims' => [
                ['key' => 'address', 'label' => 'Property', 'align' => 'left'],
                ['key' => 'status', 'label' => 'Status', 'align' => 'left', 'format' => 'badge'],
                ['key' => 'claimed', 'label' => 'Claimed', 'align' => 'left', 'format' => 'date'],
            ],
            'presentations_created' => [
                ['key' => 'title', 'label' => 'Presentation', 'align' => 'left'],
                ['key' => 'status', 'label' => 'Status', 'align' => 'left', 'format' => 'badge'],
                ['key' => 'created', 'label' => 'Created', 'align' => 'left', 'format' => 'date'],
            ],
            'portal_views' => [
                ['key' => 'title', 'label' => 'Listing', 'align' => 'left'],
                ['key' => 'views', 'label' => 'Views', 'align' => 'right'],
            ],
            'appointments' => [
                ['key' => 'title', 'label' => 'Appointment', 'align' => 'left'],
                ['key' => 'category', 'label' => 'Type', 'align' => 'left', 'format' => 'badge'],
                ['key' => 'date', 'label' => 'Date', 'align' => 'left', 'format' => 'date'],
            ],
            'outreach_messages' => [
                ['key' => 'contact', 'label' => 'Contact', 'align' => 'left'],
                ['key' => 'channel', 'label' => 'Channel', 'align' => 'left', 'format' => 'badge'],
                ['key' => 'sent', 'label' => 'Sent', 'align' => 'left', 'format' => 'date'],
            ],
            'commission' => [
                ['key' => 'address', 'label' => 'Deal', 'align' => 'left'],
                // 2026-08 (company-share refinement) — a joint deal has one row per agent
                // (per deal_money_lines line); without this column two rows for the same
                // deal read as a duplicate. Now each row is visibly a different agent's cut.
                ['key' => 'agent', 'label' => 'Agent', 'align' => 'left'],
                ['key' => 'commission', 'label' => 'Agent share (ex-VAT)', 'align' => 'right', 'format' => 'currency'],
                ['key' => 'company_commission', 'label' => 'Company share (ex-VAT)', 'align' => 'right', 'format' => 'currency'],
            ],
            default => [],
        };
    }

    private function drilldownRow(string $metric, array $r): array
    {
        return match ($metric) {
            'deals' => ['address' => $r['address'] ?? ('Deal ' . ($r['ref'] ?? $r['id'])), 'price' => $r['price'], 'commission' => $r['commission'], 'status' => ucfirst((string) $r['status']), 'href' => $r['url']],
            'contacts_created' => ['name' => $r['name'], 'created' => $r['created_at'], 'href' => $r['url']],
            'properties_created' => ['address' => $r['address'], 'status' => ucfirst((string) $r['status']), 'listed' => $r['listed_date'], 'href' => $r['url']],
            'fica_submissions' => ['contact' => $r['contact_name'], 'status' => ucfirst((string) $r['status']), 'created' => $r['created_at'], 'href' => $r['url']],
            'buyers_added' => ['name' => $r['name'], 'entered' => $r['entered_at'], 'href' => $r['url']],
            'viewings' => ['title' => $r['title'], 'date' => $r['event_date'], 'href' => $r['url']],
            'mic_claims' => ['address' => $r['address'] ?? ('Claim #' . $r['id']), 'status' => ucfirst((string) ($r['status'] ?? '')), 'claimed' => $r['claimed_at'], 'href' => $r['url']],
            'presentations_created' => ['title' => $r['title'], 'status' => ucfirst((string) ($r['status'] ?? '')), 'created' => $r['created_at'], 'href' => $r['url']],
            'portal_views' => ['title' => $r['title'], 'views' => $r['views'], 'href' => $r['url']],
            'appointments' => ['title' => $r['title'], 'category' => ucfirst(str_replace('_', ' ', (string) ($r['category'] ?? ''))), 'date' => $r['event_date'], 'href' => $r['url']],
            'outreach_messages' => ['contact' => $r['contact'], 'channel' => ucfirst((string) ($r['channel'] ?? '')), 'sent' => $r['sent_at'], 'href' => $r['url']],
            'commission' => ['address' => $r['address'], 'agent' => $r['agent'] ?? '—', 'commission' => $r['commission'], 'company_commission' => $r['company_commission'] ?? 0, 'href' => $r['url']],
            default => $r,
        };
    }

    private function drilldownTitle(string $metric, ?string $status, string $level, ?int $id, Period $period, int $total): string
    {
        $noun = ['deals' => 'deals', 'contacts_created' => 'contacts', 'properties_created' => 'properties', 'fica_submissions' => 'FICA submissions', 'buyers_added' => 'buyers', 'viewings' => 'viewings', 'mic_claims' => 'MIC claims', 'presentations_created' => 'presentations', 'portal_views' => 'viewed listings', 'appointments' => 'appointments', 'outreach_messages' => 'outreach messages', 'commission' => 'commission lines'][$metric] ?? $metric;
        $statusWord = ($metric === 'deals' && $status && $status !== 'all') ? ($status . ' ') : '';
        $who = 'Company';
        if ($level === 'agent' && $id) {
            $who = (string) (DB::table('users')->where('id', $id)->value('name') ?? 'Agent');
        } elseif ($level === 'branch' && $id) {
            $who = (string) (DB::table('branches')->where('id', $id)->value('name') ?? 'Branch');
        }

        return trim("{$total} {$statusWord}{$noun}") . " — {$who} · " . $period->label;
    }

    private function resolvePeriod(Request $request, PeriodResolver $periods): array
    {
        $preset = (string) $request->query('period', 'this_month');
        if (!in_array($preset, PeriodResolver::PRESETS, true)) {
            $preset = 'this_month';
        }

        try {
            $period = $periods->resolve($preset, $request->query('start'), $request->query('end'));
        } catch (\InvalidArgumentException $e) {
            $preset = 'this_month';
            $period = $periods->resolve('this_month');
            session()->flash('period_error', $e->getMessage());
        }

        return [$period, $preset];
    }
}
