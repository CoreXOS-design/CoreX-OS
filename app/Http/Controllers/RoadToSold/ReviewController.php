<?php

namespace App\Http\Controllers\RoadToSold;

use App\Http\Controllers\Controller;
use App\Models\RoadToSold\RtsTaskCompletion;
use App\Models\User;
use App\Services\RoadToSold\CompletionListService;
use App\Services\RoadToSold\TaskCompletionService;
use App\Services\RoadToSold\TaskReviewService;
use App\Services\RoadToSold\Track;
use App\Services\RoadToSold\VisibleAgents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * AT-454 v2 phase 6 - the manager's review screen: the team's task completions, the ones waiting for approval first
 * (road-to-sold.md sections 10.2, 10.4 "Team completions / review queue").
 *
 * Gate: `road_to_sold.review_completions` on every route. Breadth is the viewer's `road_to_sold.view` (branch manager = their branch,
 * admin = the agency), applied in the query by TaskCompletionService::visibleTo(); a completion outside it is a 404, never a 403, so
 * a guessed id tells a manager nothing. Approving and sending back go through TaskReviewService (locked, audited, never your own work).
 * Archive and restore of a captured task use the existing captures routes (same breadth rule), so there is one rule, not two.
 */
class ReviewController extends Controller
{
    /** What the list can be sorted by; the default is the first (when, newest first). */
    public const SORTS = ['when' => 'When', 'agent' => 'Agent', 'task' => 'Task', 'points' => 'Points'];

    public function __construct(
        private readonly CompletionListService $list,
        private readonly TaskCompletionService $completions,
        private readonly TaskReviewService $review,
    ) {
    }

    public function index(Request $request): View
    {
        $viewer = $request->user();
        $track  = Track::fromRequest($request);

        // Nothing chosen = the work waiting for a decision. "any" is the explicit way to see everything.
        $state = $request->query('state');
        $state = $state === null ? RtsTaskCompletion::STATE_PROVISIONAL : ($state === 'any' ? '' : (string) $state);
        $filters = [
            'q' => (string) $request->query('q', ''), 'sort' => $request->query('sort'), 'dir' => $request->query('dir'),
            'task' => $request->query('task'), 'step' => $request->query('step'), 'state' => $state,
            'by' => $request->query('by'), 'source' => $request->query('source'), 'status' => $request->query('status'),
            'from' => $request->query('from'), 'to' => $request->query('to'), 'page' => $request->query('page'),
            'agent' => $request->query('agent'),
        ];
        $rows = $this->list->page($viewer, null, $filters, $track);

        $base = $this->completions->visibleTo($viewer)->where('rts_task_completions.track', $track);

        return view('road-to-sold.review.index', [
            'rows'     => $rows,
            'filters'  => $filters + ['state_raw' => $request->query('state')],
            'links'    => $this->list->recordLinks($rows),
            'choices'  => $this->list->filterChoices((int) $viewer->effectiveAgencyId(), $track),
            'agents'   => VisibleAgents::for($viewer),
            'waiting'  => (clone $base)->where('rts_task_completions.state', RtsTaskCompletion::STATE_PROVISIONAL)->count(),
            'returned' => (clone $base)->where('rts_task_completions.state', RtsTaskCompletion::STATE_RETURNED)->count(),
            'track'    => $track,
            'review'   => $this->review,
            'viewer'   => $viewer,
        ]);
    }

    public function show(Request $request, int $completion): View
    {
        $viewer = $request->user();
        $row = $this->completions->visibleTo($viewer)->withTrashed()
            ->with(['task', 'step', 'agent:id,name', 'recordedBy:id,name', 'records'])->whereKey($completion)->firstOrFail();

        $page = new \Illuminate\Pagination\LengthAwarePaginator([$row], 1, 1);
        $names = User::query()->withoutGlobalScopes()->whereIn('id', collect($row->override_audit ?? [])->pluck('by')->push($row->reviewed_by_user_id)->filter()->unique())->pluck('name', 'id');

        return view('road-to-sold.review.show', [
            'c'      => $row,
            'links'  => $this->list->recordLinks($page)[$row->id] ?? [],
            'names'  => $names,
            'review' => $this->review,
            'viewer' => $viewer,
            'track'  => $row->track,
        ]);
    }

    public function approve(Request $request, int $completion): RedirectResponse
    {
        $this->review->approve($request->user(), $completion);

        return back()->with('status', 'Approved. It now counts toward the agent\'s targets.');
    }

    public function sendBack(Request $request, int $completion): RedirectResponse
    {
        $this->review->sendBack($request->user(), $completion, $request->input('comment'));

        return back()->with('status', 'Sent back to the agent with your comment. It does not count until you approve it again.');
    }
}
