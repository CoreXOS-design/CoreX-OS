<?php

namespace App\Http\Controllers\RoadToSold;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\RoadToSold\RtsAgentLevel;
use App\Models\RoadToSold\RtsLevel;
use App\Models\RoadToSold\RtsLevelSignoff;
use App\Services\RoadToSold\AgentLevelService;
use App\Services\RoadToSold\Scope;
use App\Services\RoadToSold\Track;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * AT-454 v2 phase 4 - the manager's level sign-off screen (road-to-sold.md sections 8, 10.2, 10.4).
 *
 * Gate: `road_to_sold.signoff_levels` on every route. Breadth is the viewer's `road_to_sold.view` (branch manager = their branch,
 * admin = the agency), applied in the query by RtsAgentLevel::visibleTo(); an agent outside it is a 404, never a 403, so a guessed id
 * tells a manager nothing. Approving and sending back go through AgentLevelService (locked, appended to the ledger, never your own
 * level). Owners and principals have no level row, so they cannot appear here.
 */
class SignoffController extends Controller
{
    /** What the list can be sorted by; the default is the first (waiting longest first). */
    public const SORTS = ['requested' => 'Waiting since', 'agent' => 'Agent', 'level' => 'Level', 'started' => 'Started the level'];
    public const STATUSES = ['awaiting_signoff' => 'Waiting for sign-off', 'active' => 'Working on it', 'signed_off' => 'Top level reached', 'any' => 'Any'];
    public const ARCHIVED = ['active' => 'Not archived', 'archived' => 'Archived', 'all' => 'All'];

    public function __construct(private readonly AgentLevelService $levels, private readonly \App\Services\RoadToSold\FunnelService $funnel)
    {
    }

    public function index(Request $request): View
    {
        $viewer = $request->user();
        $track  = Track::fromRequest($request);
        $status = $request->query('status');
        $status = $status === null ? 'awaiting_signoff' : (array_key_exists((string) $status, self::STATUSES) ? (string) $status : 'awaiting_signoff');
        $sort   = array_key_exists((string) $request->query('sort'), self::SORTS) ? (string) $request->query('sort') : 'requested';
        $dir    = $request->query('dir') === 'desc' ? 'desc' : ($request->query('dir') === 'asc' ? 'asc' : null);
        $archived = in_array($request->query('archived'), ['archived', 'all'], true) ? $request->query('archived') : 'active';
        $q      = trim((string) $request->query('q', ''));
        $f = ['q' => $q, 'status' => $status, 'sort' => $sort, 'dir' => $dir, 'level' => $request->query('level'), 'branch' => $request->query('branch'), 'archived' => $archived];

        $base = RtsAgentLevel::query()->visibleTo($viewer)->where('rts_agent_levels.track', $track);
        $counts = [
            'awaiting' => (clone $base)->where('status', RtsAgentLevel::STATUS_AWAITING)->count(),
            'active'   => (clone $base)->where('status', RtsAgentLevel::STATUS_ACTIVE)->count(),
        ];

        $rows = (clone $base)
            ->join('users as u', 'u.id', '=', 'rts_agent_levels.user_id')
            ->join('rts_levels as lv', 'lv.id', '=', 'rts_agent_levels.level_id')
            ->select('rts_agent_levels.*')
            ->with(['agent:id,name,branch_id', 'level']);
        if ($archived === 'archived') {
            $rows->onlyTrashed();
        } elseif ($archived === 'all') {
            $rows->withTrashed();
        }
        if ($status !== 'any') {
            $rows->where('rts_agent_levels.status', $status);
        }
        if ($q !== '') {
            $rows->where('u.name', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%');
        }
        if ($request->filled('level')) {
            $rows->where('rts_agent_levels.level_id', (int) $request->query('level'));
        }
        if ($request->filled('branch') && Scope::level($viewer) === 'all') {
            $rows->where('u.branch_id', (int) $request->query('branch'));
        }
        match ($sort) {
            'agent'   => $rows->orderBy('u.name', $dir ?? 'asc'),
            'level'   => $rows->orderBy('lv.number', $dir ?? 'asc')->orderBy('u.name'),
            'started' => $rows->orderBy('rts_agent_levels.started_at', $dir ?? 'desc'),
            default   => $rows->orderByRaw('rts_agent_levels.requested_signoff_at IS NULL')->orderBy('rts_agent_levels.requested_signoff_at', $dir ?? 'asc')->orderBy('u.name'),
        };
        $page = $rows->paginate(25)->withQueryString();

        $progress = [];
        foreach ($page->items() as $row) {
            if ($row->agent) {
                $progress[$row->id] = $this->levels->progress($row->agent, $track);
            }
        }

        return view('road-to-sold.signoffs.index', [
            'rows' => $page, 'filters' => $f, 'progress' => $progress, 'counts' => $counts, 'track' => $track, 'viewer' => $viewer,
            'levels' => RtsLevel::query()->where('track', $track)->orderBy('number')->get(['id', 'number', 'name']),
            'branches' => Scope::level($viewer) === 'all' ? Branch::query()->orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }

    public function show(Request $request, int $agentLevel): View
    {
        $viewer = $request->user();
        $row = RtsAgentLevel::query()->visibleTo($viewer)->withTrashed()->with(['agent', 'level'])->whereKey($agentLevel)->firstOrFail();
        $progress = $this->levels->progress($row->agent, $row->track);

        $ledger = RtsLevelSignoff::query()->visibleTo($viewer)->where('track', $row->track)->where('user_id', $row->user_id)
            ->with(['decidedBy:id,name', 'fromLevel', 'toLevel'])->orderByDesc('decided_at')->orderByDesc('id')->get();

        // The agent's funnel and flags beside the decision (spec 8.4), for the managers who may see the funnel at all.
        $funnel = null;
        if ($viewer->hasPermission('road_to_sold.view_funnel')) {
            $settings = \App\Services\RoadToSold\SettingsService::forAgency((int) $row->agency_id, $row->track);
            $period = $this->funnel->windowPeriod($settings->flagWindowDays());
            $snap = $this->funnel->snapshot((int) $row->agency_id, $period, $row->track, $settings);
            $funnel = ['period' => $period, 'ratios' => $snap['computed']['ratios'][$row->user_id] ?? [], 'medians' => $snap['medians'], 'flags' => $snap['flags'][$row->user_id] ?? [], 'days' => $settings->flagWindowDays()];
        }

        return view('road-to-sold.signoffs.show', ['funnel' => $funnel,
            'row' => $row, 'progress' => $progress, 'ledger' => $ledger, 'viewer' => $viewer, 'track' => $row->track,
            'own' => in_array((int) $row->user_id, array_map('intval', $viewer->dataIdentityIds()), true),
        ]);
    }

    public function approve(Request $request, int $agentLevel): RedirectResponse
    {
        $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);
        $this->levels->approve($request->user(), $agentLevel, $request->input('comment'));

        return redirect()->route('road-to-sold.signoffs.index')->with('status', 'Signed off. The agent has moved up and has been told.');
    }

    public function sendBack(Request $request, int $agentLevel): RedirectResponse
    {
        $this->levels->sendBack($request->user(), $agentLevel, $request->input('comment'));

        return redirect()->route('road-to-sold.signoffs.index')->with('status', 'Sent back with your comment. Their weeks start counting again from today.');
    }

    public function archive(Request $request, int $agentLevel): RedirectResponse
    {
        $row = RtsAgentLevel::query()->visibleTo($request->user())->whereKey($agentLevel)->firstOrFail();
        $row->delete();

        return back()->with('status', 'Archived. This agent is no longer tracked on levels until you restore them.');
    }

    public function restore(Request $request, int $agentLevel): RedirectResponse
    {
        $row = RtsAgentLevel::query()->visibleTo($request->user())->onlyTrashed()->whereKey($agentLevel)->firstOrFail();
        $row->restore();

        return back()->with('status', 'Restored.');
    }
}
