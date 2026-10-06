<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Platform\AgencyTimeline;
use App\Models\Platform\AgencyTimelineEvent;
use App\Models\Platform\AgencyTimelineItem;
use App\Models\User;
use App\Services\Platform\AgencyTimelineService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * System Developer → Agency Timeline (owner-only, no permission key — see spec §5).
 * Spec: .ai/specs/agency-timeline-and-platform-esign.md §7.4
 *
 * Platform-owned data; every action ALSO aborts unless the actor is an owner.
 */
class AgencyTimelineController extends Controller
{
    public function __construct(private AgencyTimelineService $svc)
    {
    }

    private function owner(Request $r): User
    {
        $u = $r->user();
        abort_unless($u && $u->isOwnerRole(), 403, 'This area is restricted to System Owners.');

        return $u;
    }

    private function itemOf(AgencyTimeline $timeline, int $itemId): AgencyTimelineItem
    {
        return AgencyTimelineItem::withTrashed()->where('timeline_id', $timeline->id)->findOrFail($itemId);
    }

    // ── Index ──────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $this->owner($request);
        $today = now()->startOfDay();
        // B-L8: a mangled date in the URL is a validation message, never a 500.
        $bad = validator($request->only('start_from', 'start_to'), ['start_from' => 'nullable|date_format:Y-m-d', 'start_to' => 'nullable|date_format:Y-m-d'])->errors();
        if ($bad->any()) {
            $request->query->remove('start_from');   // ignore the unreadable range instead of failing the whole list
            $request->query->remove('start_to');
            $request->merge(['start_from' => null, 'start_to' => null]);
            session()->now('warning', 'The start-date range was not a valid date, so it was ignored.');
        }

        $agencies = Agency::query()
            ->when($request->boolean('hide_demo'), fn ($q) => $q
                ->where(fn ($d) => $d->where('is_demo', false)->orWhereNull('is_demo'))
                ->where(fn ($a) => $a->where('is_active', true)->orWhereNull('is_active')))
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%' . $request->string('q') . '%'))
            ->get();
        $timelines = AgencyTimeline::whereIn('agency_id', $agencies->pluck('id'))->get()->keyBy('agency_id');
        $items = AgencyTimelineItem::whereIn('timeline_id', $timelines->pluck('id'))->where('kind', 'milestone')->get()->groupBy('timeline_id');

        $rows = $agencies->map(function (Agency $a) use ($timelines, $items, $today) {
            $tl = $timelines->get($a->id);
            if (!$tl) {
                return ['agency' => $a, 'timeline' => null, 'status' => 'not_started', 'done' => 0, 'total' => 0,
                        'next' => null, 'overdue' => 0, 'start' => null, 'planned' => null, 'expected' => null, 'slip' => 0];
            }
            $ms = $items->get($tl->id, collect());
            $open = $ms->where('status', 'pending')->filter(fn ($m) => $m->due_date)->sortBy('due_date');
            $gl = $this->svc->goLive($tl, $today);

            return [
                'agency' => $a, 'timeline' => $tl, 'status' => $tl->status,
                'done' => $ms->where('status', 'done')->count(), 'total' => $ms->count(),
                'next' => $open->first(),
                'overdue' => $ms->filter(fn ($m) => $this->svc->state($m, $today) === 'overdue')->count(),
                'start' => $tl->start_date, 'planned' => $gl['planned'], 'expected' => $gl['expected'], 'slip' => $gl['slip_days'],
            ];
        });

        $kpis = [
            'total'   => $rows->count(),
            'running' => $rows->where('status', 'running')->count(),
            'live'    => $rows->where('status', 'live')->count(),
            'overdue' => $rows->where('overdue', '>', 0)->count(),
        ];

        $status = (string) $request->get('status', '');
        $archivedRows = null;
        if ($status === 'archived') {
            $archivedRows = AgencyTimeline::onlyTrashed()->with('agency')->orderByDesc('deleted_at')->get();
            $rows = collect();
        }
        // Start-date range (only timelines that HAVE a start date can match).
        $from = $request->filled('start_from') ? Carbon::parse($request->get('start_from'))->startOfDay() : null;
        $to = $request->filled('start_to') ? Carbon::parse($request->get('start_to'))->startOfDay() : null;
        if ($from || $to) {
            $rows = $rows->filter(fn ($r) => $r['start'] && (!$from || $r['start']->gte($from)) && (!$to || $r['start']->lte($to)));
        }
        if (in_array($status, ['not_started', 'running', 'live', 'paused'], true)) {
            $rows = $rows->where('status', $status);
        }

        $sort = (string) $request->get('sort', 'agency');
        $dir = $request->get('dir') === 'desc' ? 'desc' : 'asc';
        $rows = match ($sort) {
            'go_live'  => $rows->sortBy(fn ($r) => $r['expected']?->timestamp ?? PHP_INT_MAX, SORT_REGULAR, $dir === 'desc'),
            'start'    => $rows->sortBy(fn ($r) => $r['start']?->timestamp ?? PHP_INT_MAX, SORT_REGULAR, $dir === 'desc'),
            'overdue'  => $rows->sortBy('overdue', SORT_REGULAR, $dir === 'desc'),
            default    => $rows->sortBy(fn ($r) => mb_strtolower($r['agency']->name), SORT_REGULAR, $dir === 'desc'),
        };

        $page = LengthAwarePaginator::resolveCurrentPage();
        $paged = new LengthAwarePaginator($rows->forPage($page, 25)->values(), $rows->count(), 25, $page, [
            'path' => $request->url(), 'query' => $request->query(),
        ]);

        return view('admin.agency-timelines.index', ['rows' => $paged, 'sort' => $sort, 'dir' => $dir, 'status' => $status, 'kpis' => $kpis, 'archivedRows' => $archivedRows]);
    }

    // ── Start ──────────────────────────────────────────────────────────────

    public function startForm(Request $request, Agency $agency)
    {
        $this->owner($request);
        if ($existing = AgencyTimeline::where('agency_id', $agency->id)->first()) {
            return redirect()->route('admin.agency-timelines.show', $existing)->with('warning', 'This agency already has a timeline.');
        }
        // A timeline can't start in the past: a past (or missing) date, including the
        // agency's older creation date, falls back to today.
        $today = now()->startOfDay();
        try {
            $start = Carbon::createFromFormat('Y-m-d', (string) $request->get('start_date', ''))->startOfDay();
        } catch (\Throwable $e) {
            $start = $agency->created_at ? Carbon::parse($agency->created_at)->startOfDay() : $today;   // missing/garbled ?start_date → default
        }
        if ($start->lt($today)) {
            $start = $today;
        }

        return view('admin.agency-timelines.start', [
            'agency' => $agency, 'start' => $start, 'preview' => $this->svc->previewDates($start),
        ]);
    }

    public function start(Request $request, Agency $agency)
    {
        $user = $this->owner($request);
        $data = $request->validate([
            'start_date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'dates'      => 'nullable|array',
            'dates.*'    => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ], [
            'start_date.date_format'    => 'Enter the start date as a calendar date.',
            'dates.*.date_format'       => 'Enter each step date as a calendar date.',
            'start_date.after_or_equal' => 'The start date cannot be in the past.',
            'dates.*.after_or_equal'    => 'A step date cannot be before the start date.',
        ]);
        $validIds = \App\Models\Platform\AgencyTimelineDefaultItem::where('kind', 'milestone')->pluck('id')->all();
        $overrides = collect($data['dates'] ?? [])->filter()->only($validIds)
            ->map(fn ($d) => Carbon::parse($d)->toDateString())->all();

        try {
            $timeline = $this->svc->start($agency, Carbon::parse($data['start_date']), $user->id, $overrides);
        } catch (\DomainException $e) {
            $existing = AgencyTimeline::where('agency_id', $agency->id)->first();

            return redirect()->route('admin.agency-timelines.show', $existing)->with('warning', $e->getMessage());
        }

        return redirect()->route('admin.agency-timelines.show', $timeline)->with('success', 'Timeline started for ' . $agency->name . '.');
    }

    // ── Detail ─────────────────────────────────────────────────────────────

    public function show(Request $request, AgencyTimeline $timeline)
    {
        $this->owner($request);
        $today = now()->startOfDay();
        // No agreement sync here: reading a page must not write (B-M1) or undo a manual reopen (B-L1).
        $showArchived = $request->boolean('archived');

        $all = AgencyTimelineItem::withTrashed()->where('timeline_id', $timeline->id)->orderBy('sort_order')->orderBy('id')->get();
        $live = $all->whereNull('deleted_at');
        $goLive = $this->svc->goLive($timeline, $today);
        $milestones = $live->where('kind', 'milestone')->sortBy([['due_date', 'asc'], ['sort_order', 'asc']])->values();

        return view('admin.agency-timelines.show', [
            'timeline'   => $timeline->load('agency'),
            'blocks'     => $live->where('kind', 'block')->values(),
            'milestones' => $milestones,
            'archived'   => $showArchived ? $all->whereNotNull('deleted_at')->values() : collect(),
            'archivedCount' => $all->whereNotNull('deleted_at')->count(),
            'goLive'     => $goLive,
            'svc'        => $this->svc,
            'today'      => $today,
            'events'     => AgencyTimelineEvent::where('timeline_id', $timeline->id)->orderByDesc('id')->limit(100)->get(),
            'actors'     => User::withoutGlobalScopes()->whereIn('id', AgencyTimelineEvent::where('timeline_id', $timeline->id)->pluck('actor_user_id')->filter()->unique())->pluck('name', 'id'),
            'agreementDocs' => $this->platformDocuments($timeline),
            // The agency's latest Subscription Agreement (web document) — status + re-issue on the agreement card (spec §11.14).
            'agreementWebdoc' => \App\Models\PlatformEsign\Document::where('agency_id', $timeline->agency_id)->where('source', 'webdoc')->with('signers')->orderByDesc('id')->first(),
            'tab'        => $request->get('tab') === 'history' ? 'history' : 'plan',
        ]);
    }

    /** Platform E-Sign documents for the agreement picker: ONLY this agency's Subscription Agreements, newest first (B-L3). */
    private function platformDocuments(AgencyTimeline $timeline): \Illuminate\Support\Collection
    {
        return $this->svc->linkableAgreements($timeline)
            ->orderByDesc('id')->limit(100)->get(['id', 'title', 'status', 'completed_at', 'agency_id'])
            ->map(fn ($d) => (object) ['id' => $d->id, 'name' => $d->title, 'status' => $d->status, 'completed_at' => $d->completed_at]);
    }

    public function agreement(Request $request, AgencyTimeline $timeline)
    {
        $user = $this->owner($request);
        $data = $request->validate(['document_id' => 'nullable|integer']);
        $id = $data['document_id'] ?? null;
        if ($id) {
            // B-L3: only this agency's own Subscription Agreement can be the agreement on this timeline.
            abort_unless($this->svc->linkableAgreements($timeline)->whereKey($id)->exists(), 422, 'That document is not this agency\'s Subscription Agreement.');
        }
        $this->svc->linkAgreement($timeline, $id ? (int) $id : null, $user->id);

        return back()->with('success', $id ? 'Agreement linked.' : 'Agreement unlinked.');
    }

    // ── Changes (each one recorded by the service) ─────────────────────────

    public function storeItem(Request $request, AgencyTimeline $timeline)
    {
        $user = $this->owner($request);
        $data = $request->validate([
            'kind'      => 'required|in:block,milestone',
            'title'     => 'required|string|max:255',
            'body'      => 'nullable|string|max:5000',
            'due_date'  => 'nullable|date_format:Y-m-d|required_if:kind,milestone',
            'is_public' => 'nullable|boolean',
        ]);
        $data['is_public'] = $request->boolean('is_public', true);
        $data['agency_can_complete'] = $request->boolean('agency_can_complete');
        $this->svc->addCustomItem($timeline, $data, $user->id);

        return back()->with('success', 'Added.');
    }

    public function updateItem(Request $request, AgencyTimeline $timeline, int $item)
    {
        $user = $this->owner($request);
        $item = $this->itemOf($timeline, $item);
        if ($item->trashed()) {
            return back()->with('warning', 'That step is archived. Restore it before editing.');
        }
        $data = $request->validate([
            'title'    => 'required|string|max:255',
            'body'     => 'nullable|string|max:5000',
            'due_date' => 'nullable|date_format:Y-m-d',
        ]);
        $data['is_public'] = $request->boolean('is_public');
        $data['agency_can_complete'] = $request->boolean('agency_can_complete');
        $this->svc->updateItem($item, $data, $user->id);

        return back()->with('success', 'Saved.');
    }

    public function status(Request $request, AgencyTimeline $timeline, int $item)
    {
        $user = $this->owner($request);
        $data = $request->validate(['status' => 'required|in:pending,done,skipped']);
        $step = $this->itemOf($timeline, $item);
        if ($step->trashed()) {
            return back()->with('warning', 'That step is archived. Restore it before changing its status.');
        }
        $this->svc->setStatus($step, $data['status'], $user->id);

        return back();
    }

    public function move(Request $request, AgencyTimeline $timeline, int $item)
    {
        $user = $this->owner($request);
        $data = $request->validate(['direction' => 'required|in:up,down']);
        $step = $this->itemOf($timeline, $item);
        if ($step->trashed()) {
            return back()->with('warning', 'That step is archived. Restore it before moving it.');
        }
        $this->svc->move($step, $data['direction'], $user->id);

        return back();
    }

    public function archiveItem(Request $request, AgencyTimeline $timeline, int $item)
    {
        $user = $this->owner($request);
        $step = $this->itemOf($timeline, $item);
        if ($step->trashed()) {
            return back()->with('warning', 'That step is already archived.');
        }
        $this->svc->archiveItem($step, $user->id);

        return back()->with('success', 'Archived. You can restore it from "Show archived".');
    }

    public function restoreItem(Request $request, AgencyTimeline $timeline, int $item)
    {
        $user = $this->owner($request);
        $step = $this->itemOf($timeline, $item);
        if (!$step->trashed()) {
            return back()->with('warning', 'That step is not archived.');
        }
        $this->svc->restoreItem($step, $user->id);

        return back()->with('success', 'Restored.');
    }

    public function startDate(Request $request, AgencyTimeline $timeline)
    {
        $user = $this->owner($request);
        $data = $request->validate(['start_date' => 'required|date_format:Y-m-d', 'confirm_past' => 'nullable|boolean'], [
            'start_date.date_format' => 'Enter the start date as a calendar date.',
        ]);
        $new = Carbon::createFromFormat('Y-m-d', $data['start_date'])->startOfDay();
        // B-L8: moving the start into the past is allowed (backdating a take-on) but only on purpose —
        // more than a day back needs the "yes, backdate" tick so a mistyped year cannot silently shift every step.
        if ($new->lt(now()->startOfDay()->subDay()) && !$new->isSameDay($timeline->start_date) && !$request->boolean('confirm_past')) {
            return back()->withErrors(['start_date' => 'That start date is in the past. Tick "This date is in the past on purpose" to confirm.'])->withInput();
        }
        $this->svc->changeStartDate($timeline, $new, $request->boolean('shift'), $user->id);

        return back()->with('success', 'Start date updated.');
    }

    public function resetDates(Request $request, AgencyTimeline $timeline)
    {
        $user = $this->owner($request);
        $n = $this->svc->resetDates($timeline, $user->id);

        return back()->with('success', "Reset {$n} dates to the defaults.");
    }

    public function link(Request $request, AgencyTimeline $timeline)
    {
        $user = $this->owner($request);
        $data = $request->validate(['action' => 'required|in:enable,disable,regenerate']);
        match ($data['action']) {
            'enable'     => $this->svc->setLinkEnabled($timeline, true, $user->id),
            'disable'    => $this->svc->setLinkEnabled($timeline, false, $user->id),
            'regenerate' => $this->svc->regenerateToken($timeline, $user->id),
        };

        return back()->with('success', 'Public link updated.');
    }

    public function archive(Request $request, AgencyTimeline $timeline)
    {
        $user = $this->owner($request);
        $this->svc->archiveTimeline($timeline, $user->id);

        return redirect()->route('admin.agency-timelines.index')->with('success', 'Timeline archived. Its public link is offline; you can restore it from the Archived filter.');
    }

    public function restore(Request $request, int $timeline)
    {
        $user = $this->owner($request);
        $tl = AgencyTimeline::onlyTrashed()->findOrFail($timeline);
        try {
            $this->svc->restoreTimeline($tl, $user->id);
        } catch (\DomainException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return redirect()->route('admin.agency-timelines.show', $tl)->with('success', 'Timeline restored.');
    }

    public function lifecycle(Request $request, AgencyTimeline $timeline)
    {
        $user = $this->owner($request);
        $data = $request->validate(['action' => 'required|in:live,pause,resume']);
        match ($data['action']) {
            'live'   => $this->svc->markLive($timeline, $user->id),
            'pause'  => $this->svc->setPaused($timeline, true, $user->id),
            'resume' => $this->svc->setPaused($timeline, false, $user->id),
        };

        return back()->with('success', 'Timeline updated.');
    }
}
