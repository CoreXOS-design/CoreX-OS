<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Platform\AgencyTimeline;
use App\Models\Platform\AgencyTimelineItem;
use App\Services\Platform\AgencyTimelineService;
use App\Services\Platform\PlainDocRenderer;
use Illuminate\Http\Request;

/**
 * The shareable, read-only, no-login agency timeline. Spec §7.5.
 *
 * Resolves exactly ONE record by token and hands the view a whitelisted,
 * pre-rendered payload — never models — so no user, email, contract or hidden
 * item can leak. Unknown and disabled tokens get the SAME neutral page so tokens
 * cannot be probed.
 */
class AgencyTimelinePublicController extends Controller
{
    /** /agency-timeline/{agency-name}/{token} — the name is cosmetic; only the token authorises. */
    public function showNamed(Request $request, AgencyTimelineService $svc, string $slug, string $token)
    {
        return $this->show($request, $token, $svc);
    }

    /**
     * The agency ticks (or un-ticks) one of ITS OWN steps from the public link.
     *
     * No login, so the guard rails are strict: the token authorises the timeline; the step must belong to it,
     * be shown publicly and be flagged agency_can_complete by CoreX; the timeline must be running; and an agency
     * can only undo a tick the agency itself made (never one CoreX or the system made). Every change is logged
     * in the timeline history as "by the agency".
     */
    public function complete(Request $request, string $token, int $item, AgencyTimelineService $svc)
    {
        $timeline = strlen($token) === 48
            ? AgencyTimeline::with('agency')->where('token', $token)->where('public_link_enabled', true)->first()
            : null;
        abort_unless($timeline, 404);
        abort_unless($timeline->status === AgencyTimeline::STATUS_RUNNING, 403);

        $data = $request->validate(['status' => 'required|in:done,pending']);
        $step = AgencyTimelineItem::where('timeline_id', $timeline->id)->where('kind', 'milestone')->where('is_public', true)->findOrFail($item);

        if ($data['status'] === 'done') {
            abort_unless($step->agency_can_complete && $step->status === 'pending', 403);
            $svc->setStatus($step, 'done', null, 'agency');
            $msg = 'Thank you — "' . $step->title . '" is marked as completed.';
        } else {
            abort_unless($step->status === 'done' && $step->completed_source === 'agency', 403);
            $svc->setStatus($step, 'pending', null, 'agency');
            $msg = '"' . $step->title . '" is back on your list.';
        }

        return redirect($timeline->publicUrl())->with('tl_ok', $svc->mergeText($msg, $timeline));
    }

    public function show(Request $request, string $token, AgencyTimelineService $svc)
    {
        $timeline = strlen($token) === 48
            ? AgencyTimeline::with('agency')->where('token', $token)->where('public_link_enabled', true)->first()
            : null;

        if (!$timeline) {
            return response()->view('public.agency-timeline.inactive', [], 404)
                ->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow');
        }

        $svc->syncAgreement($timeline);
        $today = now()->startOfDay();
        $goLive = $svc->goLive($timeline, $today);
        $items = AgencyTimelineItem::where('timeline_id', $timeline->id)->where('is_public', true)->orderBy('sort_order')->orderBy('id')->get();

        $blocks = $items->where('kind', 'block')->map(fn ($b) => [
            'title' => $svc->mergeText($b->title, $timeline, $goLive),
            'html'  => PlainDocRenderer::render($svc->mergeText($b->body, $timeline, $goLive)),
        ])->values();

        $milestones = $items->where('kind', 'milestone')->sortBy([['due_date', 'asc'], ['sort_order', 'asc']])->values();
        $firstOpen = $milestones->first(fn ($m) => $m->status === 'pending');
        $running = $timeline->status === AgencyTimeline::STATUS_RUNNING;
        $rows = $milestones->map(function ($m) use ($svc, $timeline, $goLive, $today, $firstOpen, $running, $token) {
            $state = $svc->state($m, $today);

            return [
                'action'  => url('/agency-timeline/' . $token . '/steps/' . $m->id),
                'yours'   => (bool) $m->agency_can_complete,
                'can_complete' => $running && $m->agency_can_complete && $m->status === 'pending',
                'can_undo'     => $running && $m->status === 'done' && $m->completed_source === 'agency',
                'title'   => $svc->mergeText($m->title, $timeline, $goLive),
                'html'    => PlainDocRenderer::render($svc->mergeText($m->body, $timeline, $goLive)),
                'due'     => $m->due_date,
                'state'   => $state === 'upcoming' && $firstOpen && $m->id === $firstOpen->id ? 'next' : $state,
                'late'    => $svc->daysOverdue($m, $today),
                'done_on' => $m->status === 'done' ? $m->completed_at : null,
                'live'    => $m->is_go_live,
            ];
        });

        $rows = $rows->values()->map(fn ($r, $i) => $r + ['key' => $i]);
        // Which step the interactive timeline opens on: the first overdue one, else the next up, else the last done.
        $selected = $rows->first(fn ($r) => $r['state'] === 'overdue')['key']
            ?? $rows->first(fn ($r) => $r['state'] === 'next')['key']
            ?? max(0, $rows->count() - 1);
        // Where the "Today" pin sits: before the first step that is not in the past.
        $todayAt = $rows->first(fn ($r) => $r['due'] && $r['due']->gte($today))['key'] ?? $rows->count();

        $counted = $milestones->whereIn('status', ['pending', 'done']);
        $payload = [
            'agencyName' => $timeline->agency?->name ?? 'Your agency',
            'logoUrl'    => $timeline->agency?->logo_path ? asset('storage/' . $timeline->agency->logo_path) : null,
            'blocks'     => $blocks,
            'rows'       => $rows,
            'planned'    => $goLive['planned'],
            'expected'   => $goLive['expected'],
            'slip'       => $goLive['slip_days'],
            'isLive'     => $timeline->status === AgencyTimeline::STATUS_LIVE,
            'percent'    => $counted->count() ? (int) round($counted->where('status', 'done')->count() / $counted->count() * 100) : 0,
            'today'      => $today,
            'selected'   => $selected,
            'todayAt'    => $todayAt,
            'doneCount'  => $counted->where('status', 'done')->count(),
            'totalCount' => $counted->count(),
            'daysToLive' => $goLive['expected'] ? (int) $today->diffInDays($goLive['expected']->copy()->startOfDay(), false) : null,
        ];

        return response()->view('public.agency-timeline.show', $payload)
            ->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
