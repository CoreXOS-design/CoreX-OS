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
        $rows = $milestones->map(function ($m) use ($svc, $timeline, $goLive, $today, $firstOpen) {
            $state = $svc->state($m, $today);

            return [
                'title'   => $svc->mergeText($m->title, $timeline, $goLive),
                'html'    => PlainDocRenderer::render($svc->mergeText($m->body, $timeline, $goLive)),
                'due'     => $m->due_date,
                'state'   => $state === 'upcoming' && $firstOpen && $m->id === $firstOpen->id ? 'next' : $state,
                'late'    => $svc->daysOverdue($m, $today),
                'done_on' => $m->status === 'done' ? $m->completed_at : null,
                'live'    => $m->is_go_live,
            ];
        });

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
        ];

        return response()->view('public.agency-timeline.show', $payload)
            ->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
