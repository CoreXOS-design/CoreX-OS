<?php

namespace App\Http\Controllers\RoadToSold;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Services\Performance\Period;
use App\Services\Performance\PeriodResolver;
use App\Services\RoadToSold\CallListService;
use App\Services\RoadToSold\DrilldownService;
use App\Services\RoadToSold\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AT-454 - the rows behind a Road to Sold figure (summary bar and report), and (later) the report itself.
 * Spec: .ai/specs/road-to-sold.md section 10.6. The agents a viewer may look at come from
 * CallListService::viewableAgents() (own / branch / agency breadth); an agent outside it is a 404.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly CallListService $list,
        private readonly DrilldownService $drill,
        private readonly PeriodResolver $periods,
        private readonly ReportService $report,
    ) {
    }

    public const PER_PAGE = 50;

    /** GET /road-to-sold/report */
    public function index(Request $request)
    {
        $data = $this->build($request);
        $perPage = self::PER_PAGE;
        $page = max(1, (int) $request->query('page', 1));
        $rows = collect($data['rows']);
        $paged = new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, [
            'path' => $request->url(), 'query' => $request->query(),
        ]);

        $q = array_filter([
            'period' => $data['preset'], 'start' => $request->query('start'), 'end' => $request->query('end'),
            'compare' => $data['compareMode'] !== 'off' ? $data['compareMode'] : null,
            'compare_start' => $request->query('compare_start'), 'compare_end' => $request->query('compare_end'),
            'branch_id' => $request->query('branch_id'), 'q' => $request->query('q'),
        ], fn ($v) => $v !== null && $v !== '');

        return view('road-to-sold.report.index', $data + [
            'paged'         => $paged,
            'drilldownBase' => route('road-to-sold.drilldown') . '?' . http_build_query($q),
            'presets'       => PeriodResolver::PRESETS,
            'compareModes'  => PeriodResolver::COMPARE_MODES,
            'branches'      => $this->branchChoices($request),
            'scopeLabel'    => match (\App\Services\RoadToSold\Scope::level($request->user())) {
                'own' => 'Your figures', 'branch' => 'Your branch', default => 'Whole agency',
            },
            'filters'       => ['q' => (string) $request->query('q', ''), 'branch_id' => (string) $request->query('branch_id', ''),
                                'sort' => $data['sort'], 'dir' => $data['dir']],
        ]);
    }

    /** GET /road-to-sold/report/print - the same figures, print layout. */
    public function print(Request $request)
    {
        return view('road-to-sold.report.print', $this->build($request) + ['forPdf' => false, 'generatedAt' => now()]);
    }

    /** GET /road-to-sold/report/pdf */
    public function pdf(Request $request)
    {
        $data = $this->build($request);
        $pdf = Pdf::loadView('road-to-sold.report.print', $data + ['forPdf' => true, 'generatedAt' => now()])->setPaper('a4', 'landscape');
        $pdf->setOption('isRemoteEnabled', false);

        return $pdf->download('road-to-sold-' . ReportService::slug($data['period']->label) . '-' . now()->format('Y-m-d') . '.pdf');
    }

    /** GET /road-to-sold/report/xlsx */
    public function xlsx(Request $request): StreamedResponse
    {
        $data = $this->build($request);
        $columns = $data['columns'];
        $compared = $data['compared'];
        $name = 'road-to-sold-' . ReportService::slug($data['period']->label) . '-' . now()->format('Y-m-d') . '.xlsx';

        return new StreamedResponse(function () use ($data, $columns, $compared) {
            $tmp = tempnam(sys_get_temp_dir(), 'rtsrep');
            $w = new XlsxWriter();
            $w->openToFile($tmp);

            $head = ['Agent'];
            foreach ($columns as $c) {
                $head[] = $c['label'];
                if ($compared) {
                    $head[] = $c['label'] . ' (' . $data['compare']->label . ')';
                }
            }
            $w->addRow(Row::fromValues($head));

            foreach (array_merge($data['rows'], [$data['totals']]) as $r) {
                $line = [$this->cell($r['name'])];
                foreach ($columns as $c) {
                    $line[] = $r['cells'][$c['key']]['value'];
                    if ($compared) {
                        $line[] = $r['cells'][$c['key']]['previous'];
                    }
                }
                $w->addRow(Row::fromValues($line));
            }
            $w->close();
            readfile($tmp);
            @unlink($tmp);
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control'       => 'private, no-store',
        ]);
    }

    /** Spreadsheet text cells never start with a formula character. */
    private function cell(?string $v): string
    {
        $v = (string) $v;

        return $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $v : $v;
    }

    /**
     * Everything the report, print, PDF and XLSX share: the viewer's cohort (narrowed by agent / branch / name),
     * the period and comparison, the figures, sorted. Exports carry exactly what the page shows.
     *
     * @return array<string,mixed>
     */
    protected function build(Request $request): array
    {
        $viewer = $request->user();
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);
        abort_if($agencyId <= 0, 403, 'Switch into an agency to open the Road to Sold report.');

        [$period, $preset] = $this->resolvePeriod($request);
        [$compare, $compareMeta, $compareMode] = $this->resolveComparison($request, $period);

        $ids = $this->cohortIds($request);
        $agents = User::query()->where('agency_id', $agencyId)->whereIn('id', $ids ?: [0])->orderBy('name')->get();

        $built = $this->report->build($agencyId, $agents, $period, $compare);

        $sort = (string) $request->query('sort', 'name');
        $valid = array_merge(['name'], array_column($built['columns'], 'key'));
        $sort = in_array($sort, $valid, true) ? $sort : 'name';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';
        $rows = collect($built['rows']);
        $rows = $sort === 'name'
            ? $rows->sortBy(fn ($r) => mb_strtolower($r['name']), SORT_REGULAR, $dir === 'desc')
            : $rows->sortBy(fn ($r) => [$r['cells'][$sort]['value'] ?? 0, mb_strtolower($r['name'])], SORT_REGULAR, $dir === 'desc');

        return [
            'columns'     => $built['columns'],
            'rows'        => $rows->values()->all(),
            'totals'      => $built['totals'],
            'compared'    => $built['compared'],
            'period'      => $period,
            'preset'      => $preset,
            'compare'     => $compare,
            'compareMode' => $compareMode,
            'comparisonMeta' => $compareMeta,
            'sort'        => $sort,
            'dir'         => $dir,
            'agentCount'  => $agents->count(),
        ];
    }

    /** @return array{0:Period,1:string} falls back to this month, with the reason flashed */
    private function resolvePeriod(Request $request): array
    {
        $preset = (string) $request->query('period', 'this_month');
        if (! in_array($preset, PeriodResolver::PRESETS, true)) {
            $preset = 'this_month';
        }
        try {
            return [$this->periods->resolve($preset, $request->query('start'), $request->query('end')), $preset];
        } catch (InvalidArgumentException $e) {
            session()->flash('period_error', $e->getMessage());

            return [$this->periods->resolve('this_month'), 'this_month'];
        }
    }

    /** @return array{0:?Period,1:?array,2:string} an invalid custom range fails soft to "off" with the message flashed */
    private function resolveComparison(Request $request, Period $period): array
    {
        $mode = (string) $request->query('compare', 'off');
        if (! in_array($mode, PeriodResolver::COMPARE_MODES, true)) {
            $mode = 'off';
        }
        try {
            $compare = $this->periods->resolveComparison($mode, $period, $request->query('compare_start'), $request->query('compare_end'));
        } catch (InvalidArgumentException $e) {
            session()->flash('compare_error', $e->getMessage());

            return [null, null, 'off'];
        }
        if ($compare === null) {
            return [null, null, $mode];
        }

        return [$compare, [
            'period'          => $compare->toArray(),
            'mode'            => $mode,
            'unequal_length'  => $period->lengthInDays() !== $compare->lengthInDays(),
            'period_days'     => $period->lengthInDays(),
            'comparison_days' => $compare->lengthInDays(),
            'phrase'          => match ($mode) {
                'previous'       => 'vs previous period',
                'same_last_year' => 'vs same period last year',
                'custom'         => 'vs ' . $compare->label,
                default          => 'vs comparison period',
            },
        ], $mode];
    }

    /** @return \Illuminate\Support\Collection<int, Branch> the branches of the agents this viewer may see (only worth showing when more than one) */
    private function branchChoices(Request $request)
    {
        $branchIds = $this->list->viewableAgents($request->user())->pluck('branch_id')->filter()->unique()->all();

        return count($branchIds) > 1 ? Branch::query()->whereIn('id', $branchIds)->orderBy('name')->get(['id', 'name']) : collect();
    }

    /** GET /road-to-sold/drilldown - the shared drill-down envelope, JSON. */
    public function drilldown(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);
        abort_if($agencyId <= 0, 403);

        $metric = (string) $request->query('metric', '');
        if (! $this->drill->supports($metric)) {
            return response()->json(['message' => 'Unknown figure.'], 422);
        }

        try {
            $period = $this->period($request);
            if ($request->query('source') === 'compare') {
                $mode = (string) $request->query('compare', 'off');
                if (! in_array($mode, PeriodResolver::COMPARE_MODES, true) || $mode === 'off') {
                    return response()->json(['message' => 'There is no comparison period to show.'], 422);
                }
                $period = $this->periods->resolveComparison($mode, $period, $request->query('compare_start'), $request->query('compare_end'));
            }
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $agentIds = $this->cohortIds($request);

        return response()->json($this->drill->rows($metric, $agentIds, $period, $agencyId));
    }

    /**
     * The agent ids this request covers: one agent (?agent_id, must be in the viewer's scope - else 404),
     * otherwise everyone the viewer may see, optionally narrowed to a branch.
     *
     * @return int[]
     */
    protected function cohortIds(Request $request): array
    {
        $agents = $this->list->viewableAgents($request->user());

        if ($request->filled('agent_id')) {
            $id = (int) $request->query('agent_id');
            abort_unless($agents->contains('id', $id), 404);

            return [$id];
        }
        if ($request->filled('branch_id')) {
            $agents = $agents->where('branch_id', (int) $request->query('branch_id'));
        }
        if ($request->filled('q')) {
            $needle = mb_strtolower(trim((string) $request->query('q')));
            $agents = $agents->filter(fn ($a) => str_contains(mb_strtolower((string) $a->name), $needle));
        }

        return $agents->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /** @throws InvalidArgumentException */
    protected function period(Request $request): Period
    {
        $preset = (string) $request->query('period', 'this_month');
        if (! in_array($preset, PeriodResolver::PRESETS, true)) {
            throw new InvalidArgumentException('Unknown period.');
        }

        return $this->periods->resolve($preset, $request->query('start'), $request->query('end'));
    }
}
