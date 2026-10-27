<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ComplianceGateChange;
use App\Services\Compliance\ComplianceGateSettingsService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AT-509 — Dev Settings → Agency compliance switches (.ai/specs/property-compliance-gate-settings.md §7.3).
 *
 * The system owner's cross-agency view of every agency's Compliance Switches and the permanent history of
 * every change (who, when, IP, disclaimer accepted). Read-only by design. Cross-agency on purpose, so every
 * query names its tables directly — no AgencyScope (which would silently narrow to the switched-to agency).
 * owner_only on the route, and re-checked here like the other Dev Settings pages.
 */
class ComplianceSwitchesAuditController extends Controller
{
    private const SORTS_AGENCIES = ['name', 'last_change', 'off_count'];
    private const SORTS_HISTORY  = ['date' => 'c.id', 'agency' => 'agency_name', 'gate' => 'c.gate_label'];

    public function __construct(private ComplianceGateSettingsService $gates)
    {
    }

    public function index(Request $request): View
    {
        $this->authorizeOwner($request);

        $q = trim((string) $request->query('q', ''));
        $off = (string) $request->query('off', '');
        $gate = (string) $request->query('gate', '');
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));
        $sort = in_array($request->query('sort'), self::SORTS_AGENCIES, true) ? $request->query('sort') : 'name';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        $agencies = DB::table('agencies')
            ->whereNull('deleted_at')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('trading_name', 'like', "%{$q}%")))
            ->get(['id', 'name', 'trading_name']);

        $rows = $agencies->map(function ($a) {
            $offGates = $this->gates->switchedOffGates((int) $a->id);
            $last = $this->gates->lastChangeFor((int) $a->id);
            $lastOff = ComplianceGateChange::where('agency_id', $a->id)
                ->where('action', ComplianceGateChange::ACTION_OFF)
                ->orderByDesc('id')
                ->first();

            return (object) [
                'id'          => (int) $a->id,
                'name'        => $a->trading_name ?: $a->name,
                'master_on'   => $this->gates->masterEnabled((int) $a->id),
                'off'         => array_values($offGates),
                'off_keys'    => array_keys($offGates),
                'last'        => $last,
                'last_off'    => $lastOff,
            ];
        });

        $rows = $rows
            ->when($off === 'any', fn ($c) => $c->filter(fn ($r) => $r->off !== []))
            ->when($off === 'none', fn ($c) => $c->filter(fn ($r) => $r->off === []))
            ->when($gate !== '', fn ($c) => $c->filter(fn ($r) => in_array($gate, $r->off_keys, true)))
            ->when($from, fn ($c) => $c->filter(fn ($r) => $r->last && $r->last->created_at->gte($from)))
            ->when($to, fn ($c) => $c->filter(fn ($r) => $r->last && $r->last->created_at->lte($to->copy()->endOfDay())));

        $rows = match ($sort) {
            'last_change' => $rows->sortBy(fn ($r) => $r->last?->id ?? 0, SORT_REGULAR, $dir === 'desc'),
            'off_count'   => $rows->sortBy(fn ($r) => count($r->off), SORT_REGULAR, $dir === 'desc'),
            default       => $rows->sortBy(fn ($r) => mb_strtolower((string) $r->name), SORT_REGULAR, $dir === 'desc'),
        };

        $perPage = 25;
        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator(
            $rows->values()->forPage($page, $perPage),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('admin.dev-settings.compliance-switches.index', [
            'rows'        => $paginator,
            'gateOptions' => $this->gateOptions(),
            'filters'     => compact('q', 'off', 'gate', 'sort', 'dir') + ['from' => $from?->toDateString(), 'to' => $to?->toDateString()],
        ]);
    }

    public function history(Request $request): View
    {
        $this->authorizeOwner($request);

        $sort = array_key_exists((string) $request->query('sort'), self::SORTS_HISTORY) ? (string) $request->query('sort') : 'date';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $changes = $this->historyQuery($request)
            ->orderBy(self::SORTS_HISTORY[$sort], $dir)
            ->orderByDesc('c.id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.dev-settings.compliance-switches.history', [
            'changes'       => $changes,
            'gateOptions'   => $this->gateOptions(),
            'agencyOptions' => DB::table('agencies')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'trading_name']),
            'filters'       => [
                'q'         => (string) $request->query('q', ''),
                'agency_id' => (string) $request->query('agency_id', ''),
                'gate'      => (string) $request->query('gate', ''),
                'action'    => (string) $request->query('action', ''),
                'from'      => $this->date($request->query('from'))?->toDateString(),
                'to'        => $this->date($request->query('to'))?->toDateString(),
                'sort'      => $sort,
                'dir'       => $dir,
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorizeOwner($request);

        $query = $this->historyQuery($request)->orderByDesc('c.id');
        $tz = config('app.timezone');

        return response()->streamDownload(function () use ($query, $tz) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Time', 'Agency', 'Check', 'Action', 'Name', 'Email', 'IP address', 'Browser', 'Disclaimer version', 'Disclaimer accepted at', 'Disclaimer text']);
            foreach ($query->cursor() as $c) {
                $at = Carbon::parse($c->created_at)->timezone($tz);
                fputcsv($out, array_map([$this, 'csvCell'], [
                    $at->format('Y-m-d'), $at->format('H:i:s'), $c->agency_name, $c->gate_label,
                    $c->action === ComplianceGateChange::ACTION_OFF ? 'Switched off' : 'Switched on',
                    $c->user_name, $c->user_email, $c->ip_address, $c->user_agent, $c->disclaimer_version,
                    $c->disclaimer_accepted_at ? Carbon::parse($c->disclaimer_accepted_at)->timezone($tz)->format('Y-m-d H:i:s') : '',
                    $c->disclaimer_text,
                ]));
            }
            fclose($out);
        }, 'compliance-switch-history-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
    }

    private function historyQuery(Request $request): \Illuminate\Database\Query\Builder
    {
        $q = trim((string) $request->query('q', ''));
        $agencyId = (int) $request->query('agency_id', 0);
        $gate = (string) $request->query('gate', '');
        $action = (string) $request->query('action', '');
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));

        return DB::table('compliance_gate_changes as c')
            ->leftJoin('agencies as a', 'a.id', '=', 'c.agency_id')
            ->select('c.*', DB::raw('COALESCE(NULLIF(a.trading_name, \'\'), a.name) as agency_name'))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x
                ->where('a.name', 'like', "%{$q}%")
                ->orWhere('a.trading_name', 'like', "%{$q}%")
                ->orWhere('c.user_name', 'like', "%{$q}%")
                ->orWhere('c.user_email', 'like', "%{$q}%")
                ->orWhere('c.ip_address', 'like', "%{$q}%")))
            ->when($agencyId > 0, fn ($w) => $w->where('c.agency_id', $agencyId))
            ->when($gate !== '', fn ($w) => $w->where('c.gate_key', $gate))
            ->when(in_array($action, [ComplianceGateChange::ACTION_OFF, ComplianceGateChange::ACTION_ON], true), fn ($w) => $w->where('c.action', $action))
            ->when($from, fn ($w) => $w->where('c.created_at', '>=', $from->copy()->startOfDay()))
            ->when($to, fn ($w) => $w->where('c.created_at', '<=', $to->copy()->endOfDay()));
    }

    /** Every check that has ever been switched, plus the fixed ones, for the Gate filter. */
    private function gateOptions(): array
    {
        $options = [
            ComplianceGateSettingsService::MASTER  => 'All compliance checks',
        ];

        foreach (DB::table('compliance_gate_changes')->select('gate_key', DB::raw('MAX(gate_label) as gate_label'))->groupBy('gate_key')->get() as $g) {
            $options[$g->gate_key] ??= $g->gate_label;
        }

        return $options;
    }

    /**
     * Agency and user names are typed by people; a cell starting with = + - @ would run as a formula when the
     * export is opened in a spreadsheet. Prefix those with a quote so they stay plain text.
     */
    private function csvCell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value, config('app.timezone'))->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function authorizeOwner(Request $request): void
    {
        abort_unless($request->user()?->isOwnerRole(), 403);
    }
}
