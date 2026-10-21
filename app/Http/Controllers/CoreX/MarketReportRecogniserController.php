<?php

declare(strict_types=1);

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\DevSetting;
use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportLearnedLayout;
use App\Services\MarketReports\MarketReportReprocessor;
use App\Services\MarketReports\ReportReadSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * CoreX admin side of the report recogniser: the cross-agency "Unrecognised &
 * AI-read reports" queue and the learned-layouts screen. Spec: mic-complete-spec §8.9.
 *
 * Owner roles only — the same gate as the parser accuracy dashboard (route
 * permission mic.upload_reports + isOwnerRole() here, 403 otherwise). Cross-agency by
 * design, so every report query is explicitly un-scoped; nothing here is ever shown
 * to an agency user. Nothing is hard-deleted: "retire" and "resolve" are markers.
 */
class MarketReportRecogniserController extends Controller
{
    private const PER_PAGE = 25;

    private const QUEUE_SORTS = [
        'uploaded'   => 'market_reports.created_at',
        'file'       => 'market_reports.file_name',
        'agency'     => 'agencies.name',
        'publisher'  => 'market_reports.read_publisher',
        'confidence' => 'market_reports.ai_read_confidence',
        'figures'    => 'market_reports.data_points_count',
    ];

    private const LAYOUT_SORTS = [
        'last_used' => 'last_used_at',
        'name'      => 'name',
        'publisher' => 'publisher',
        'used'      => 'times_used',
        'learned'   => 'created_at',
    ];

    public function __construct(
        private readonly MarketReportReprocessor $reprocessor,
    ) {}

    // ── queue ────────────────────────────────────────────────────────────

    public function queue(Request $request): View
    {
        $this->authoriseOwner($request);

        $sort = array_key_exists((string) $request->query('sort'), self::QUEUE_SORTS) ? (string) $request->query('sort') : 'uploaded';
        $dir  = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $status = in_array($request->query('status'), ['open', 'resolved', 'all'], true) ? (string) $request->query('status') : 'open';
        $how    = in_array($request->query('how'), ['ai', 'unread', 'held', 'all'], true) ? (string) $request->query('how') : 'all';

        $base = MarketReport::query()
            ->withoutGlobalScopes()
            ->whereNull('market_reports.deleted_at')
            ->whereIn('market_reports.read_method', [MarketReport::READ_AI, MarketReport::READ_UNREAD]);

        $query = (clone $base)
            ->leftJoin('agencies', 'agencies.id', '=', 'market_reports.agency_id')
            ->leftJoin('users', 'users.id', '=', 'market_reports.uploaded_by_user_id')
            ->select('market_reports.*', 'agencies.name as agency_name', 'users.name as uploader_name', 'users.email as uploader_email')
            ->selectSub(
                MarketReport::query()->withoutGlobalScopes()->from('market_reports as mrc')
                    ->selectRaw('count(*)')
                    ->whereColumn('mrc.layout_case_id', 'market_reports.layout_case_id')
                    ->whereNull('mrc.deleted_at'),
                'case_reports'
            );

        if ($status === 'open') {
            $query->whereNull('market_reports.resolved_at');
        } elseif ($status === 'resolved') {
            $query->whereNotNull('market_reports.resolved_at');
        }
        if ($how === 'held') {
            // Queued without an AI read: a cap was reached, or the same layout is already with CoreX.
            $query->where('market_reports.read_method', MarketReport::READ_UNREAD)
                ->whereIn('market_reports.spot_check_results->reason_code', ['ai_monthly_cap', 'ai_platform_daily_cap', 'layout_case_open']);
        } elseif ($how !== 'all') {
            $query->where('market_reports.read_method', $how);
        }
        if ($request->filled('agency')) {
            $query->where('market_reports.agency_id', (int) $request->query('agency'));
        }
        if ($request->filled('publisher')) {
            $query->where('market_reports.read_publisher', (string) $request->query('publisher'));
        }
        if ($request->filled('from')) {
            $query->whereDate('market_reports.created_at', '>=', (string) $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('market_reports.created_at', '<=', (string) $request->query('to'));
        }
        if ($request->filled('q')) {
            $like = '%' . trim((string) $request->query('q')) . '%';
            $query->where(function ($q) use ($like) {
                $q->where('market_reports.file_name', 'like', $like)
                    ->orWhere('market_reports.read_publisher', 'like', $like)
                    ->orWhere('agencies.name', 'like', $like)
                    ->orWhere('users.name', 'like', $like)
                    ->orWhere('users.email', 'like', $like);
            });
        }

        $reports = $query->orderBy(self::QUEUE_SORTS[$sort], $dir)->orderByDesc('market_reports.id')
            ->paginate(self::PER_PAGE)->withQueryString();

        $agencyIds = (clone $base)->distinct()->pluck('agency_id');
        $agencies = Agency::query()->withoutGlobalScopes()->whereIn('id', $agencyIds)->orderBy('name')->get(['id', 'name']);
        $publishers = (clone $base)->whereNotNull('read_publisher')->distinct()->orderBy('read_publisher')->pluck('read_publisher');

        $counts = [
            'open_ai'     => (clone $base)->whereNull('resolved_at')->where('read_method', MarketReport::READ_AI)->count(),
            'open_unread' => (clone $base)->whereNull('resolved_at')->where('read_method', MarketReport::READ_UNREAD)->count(),
            'resolved'    => (clone $base)->whereNotNull('resolved_at')->count(),
        ];

        return view('corex.market-intelligence.reports.recogniser.queue', [
            'reports' => $reports, 'agencies' => $agencies, 'publishers' => $publishers, 'counts' => $counts,
            'filters' => ['q' => (string) $request->query('q', ''), 'status' => $status, 'how' => $how,
                'agency' => (string) $request->query('agency', ''), 'publisher' => (string) $request->query('publisher', ''),
                'from' => (string) $request->query('from', ''), 'to' => (string) $request->query('to', ''), 'sort' => $sort, 'dir' => $dir],
        ]);
    }

    public function download(Request $request, int $id)
    {
        $this->authoriseOwner($request);
        $report = $this->find($id);
        if (empty($report->file_path) || !Storage::disk('local')->exists($report->file_path)) {
            return redirect()->back()->with('error', 'The stored PDF is missing from disk.');
        }

        return Storage::disk('local')->download($report->file_path, $report->file_name ?: 'report.pdf');
    }

    public function rerun(Request $request, int $id): RedirectResponse
    {
        $this->authoriseOwner($request);
        $report = $this->find($id);
        $force = $request->boolean('force_new_ai');

        // A CoreX owner retrying: skips the "same layout already queued" block (the caps still apply).
        // deferAi: if the report needs the paid AI read, that runs on a queue worker (never inside this request).
        if (!$this->reprocessor->rerun($report, $force, true, true, true, (int) $request->user()->id)) {
            return redirect()->back()->with('error', 'The stored PDF is missing from disk — it cannot be re-run.');
        }
        $report->refresh();

        if (in_array($report->parse_status, [MarketReport::PARSE_PENDING, MarketReport::PARSE_PARSING], true)) {
            return redirect()->back()->with('status', 'Re-run started: the report is being read in the background. You will get a message here when it is finished.');
        }

        // Finished inline (no paid read was needed, or the queue runs inline): a read by AI has already closed the layout
        // case the same way an approval does (ParseMarketReportJob::closeCaseAfterOwnerRerun) and told the owner.
        return redirect()->back()->with('status', 'Re-run finished: ' . ($report->readBadge() ?? 'read by a built-in parser') . '.');
    }

    public function resolve(Request $request, int $id): RedirectResponse
    {
        $this->authoriseOwner($request);
        $this->find($id)->forceFill(['resolved_at' => now(), 'resolved_by_user_id' => $request->user()->id])->save();

        return redirect()->back()->with('status', 'Marked resolved.');
    }

    public function reopen(Request $request, int $id): RedirectResponse
    {
        $this->authoriseOwner($request);
        $this->find($id)->forceFill(['resolved_at' => null, 'resolved_by_user_id' => null])->save();

        return redirect()->back()->with('status', 'Reopened.');
    }

    // ── learned layouts ──────────────────────────────────────────────────

    public function layouts(Request $request, ReportReadSettings $settings): View
    {
        $this->authoriseOwner($request);

        $sort = array_key_exists((string) $request->query('sort'), self::LAYOUT_SORTS) ? (string) $request->query('sort') : 'last_used';
        $dir  = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $status = in_array($request->query('status'), ['learned', 'approved', 'retired', 'all'], true) ? (string) $request->query('status') : 'all';

        $query = MarketReportLearnedLayout::query();
        if ($status === 'retired') {
            $query->onlyTrashed();
        } elseif ($status === 'all') {
            $query->withTrashed();
        } else {
            $query->where('status', $status);
        }
        if ($request->filled('q')) {
            $like = '%' . trim((string) $request->query('q')) . '%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('publisher', 'like', $like));
        }

        $layouts = $query->orderBy(self::LAYOUT_SORTS[$sort], $dir)->orderByDesc('id')->paginate(self::PER_PAGE)->withQueryString();

        return view('corex.market-intelligence.reports.recogniser.layouts', [
            'layouts' => $layouts,
            'filters' => ['q' => (string) $request->query('q', ''), 'status' => $status, 'sort' => $sort, 'dir' => $dir],
            'system'  => $settings->systemValues(),
        ]);
    }

    public function layoutApprove(Request $request, int $id): RedirectResponse
    {
        $this->authoriseOwner($request);
        $layout = MarketReportLearnedLayout::query()->findOrFail($id);
        $layout->forceFill([
            'status' => MarketReportLearnedLayout::STATUS_APPROVED, 'approved_at' => now(), 'approved_by_user_id' => $request->user()->id,
        ])->save();

        // Johan's ruling 20 Oct: approving a layout resets the affected agencies' AI counters, re-runs their
        // queued reports on it (no AI) and tells the agents. A case can hold many reports and each is a full
        // re-parse, so it runs on a queue worker; the owner gets a message here with the outcome.
        \App\Jobs\MarketReports\ResolveLayoutCasesJob::dispatch((int) $layout->id, (int) $request->user()->id);

        return redirect()->back()->with('status', 'Layout approved. Its queued reports are being re-run in the background; their agents are told as each one is imported, and you will get a message here when it is done.');
    }

    public function layoutRename(Request $request, int $id): RedirectResponse
    {
        $this->authoriseOwner($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:160']]);
        MarketReportLearnedLayout::query()->findOrFail($id)->forceFill(['name' => trim($data['name'])])->save();

        return redirect()->back()->with('status', 'Layout renamed.');
    }

    public function layoutRetire(Request $request, int $id): RedirectResponse
    {
        $this->authoriseOwner($request);
        $layout = MarketReportLearnedLayout::query()->findOrFail($id);
        $layout->forceFill(['archived_by_user_id' => $request->user()->id])->save();
        $layout->delete(); // soft delete — a retired layout is never used again, never hard-deleted

        return redirect()->back()->with('status', 'Layout retired. Reports in that layout will go to the AI again.');
    }

    public function layoutRestore(Request $request, int $id): RedirectResponse
    {
        $this->authoriseOwner($request);
        $layout = MarketReportLearnedLayout::withTrashed()->findOrFail($id);
        $layout->restore();
        $layout->forceFill(['archived_by_user_id' => null])->save();

        return redirect()->back()->with('status', 'Layout restored.');
    }

    public function settingsUpdate(Request $request): RedirectResponse
    {
        $this->authoriseOwner($request);
        $data = $request->validate([
            ReportReadSettings::KEY_MAX_PAGES      => ['required', 'integer', 'min:1', 'max:100'],
            ReportReadSettings::KEY_MIN_CONFIDENCE => ['required', 'numeric', 'min:0', 'max:1'],
            ReportReadSettings::KEY_LAYOUT_MATCH   => ['required', 'numeric', 'min:0.5', 'max:1'],
            ReportReadSettings::KEY_SELFTEST_MIN   => ['required', 'numeric', 'min:0.5', 'max:1'],
            ReportReadSettings::KEY_MONTHLY_CAP    => ['required', 'integer', 'min:0', 'max:100'],
            ReportReadSettings::KEY_PLATFORM_DAILY_CAP => ['required', 'integer', 'min:0', 'max:500'],
        ]);
        foreach ($data as $key => $value) {
            DevSetting::set($key, (string) $value);
        }

        return redirect()->back()->with('status', 'Report reading settings saved.');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function authoriseOwner(Request $request): void
    {
        abort_unless($request->user()?->isOwnerRole(), 403);
    }

    private function find(int $id): MarketReport
    {
        return MarketReport::query()->withoutGlobalScopes()->findOrFail($id);
    }
}
