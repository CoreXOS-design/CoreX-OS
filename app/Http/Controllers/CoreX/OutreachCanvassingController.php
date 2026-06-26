<?php

declare(strict_types=1);

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Services\SellerOutreach\OutreachActivityFeedService;
use App\Services\SellerOutreach\WhatsappOutreachSummaryService;
use Illuminate\Http\Request;

/**
 * Part 4 — unified "Outreach & Canvassing" board.
 *
 * One page, two tabs:
 *   - Activity Feed  (new) — every outreach/canvassing action over the dormant-no-
 *     more agent_activity_events backbone, SOURCE-TAGGED mic_prospect / direct_contact
 *     / comms_tile, counted SEPARATELY (never blended), total = visible sum.
 *   - Consent Funnel — the existing AT-91 WhatsApp matrix, retained as-is (reuses
 *     its service + its extracted board partial).
 *
 * Gate: reuses the AT-91 permission (outreach.summary.view) — this board is a
 * superset surface for the same audience and literally embeds the AT-91 board.
 * Activity-feed row visibility: agents see their own actions; team-viewers
 * (mic.view_team / prospecting_setup.manage, owners via permission bypass) see the
 * whole agency and may filter by agent.
 */
class OutreachCanvassingController extends Controller
{
    public function index(
        Request $request,
        OutreachActivityFeedService $feedService,
        WhatsappOutreachSummaryService $summaryService,
    ) {
        $user = $request->user();
        abort_unless($user?->hasPermission('outreach.summary.view') === true, 403);

        $agencyId = (int) ($user->effectiveAgencyId() ?? $user->agency_id ?? 0);
        abort_if($agencyId <= 0, 404);

        $canSeeTeam = $user->hasPermission('mic.view_team')
            || $user->hasPermission('prospecting_setup.manage');

        $filters = [
            'days'   => (int) $request->integer('days', 90),
            'source' => $request->query('source'),
        ];
        if (! $canSeeTeam) {
            // Agents only ever see their own canvassing activity.
            $filters['user_id'] = (int) $user->id;
        } elseif (is_numeric($request->query('agent_id'))) {
            $filters['user_id'] = (int) $request->query('agent_id');
        }

        $feed = $feedService->feed($agencyId, $filters);

        // Tab 2 — the AT-91 consent-funnel board, untouched.
        $board = $summaryService->board();

        return view('corex.outreach-canvassing.index', [
            'feed'         => $feed,
            'rows'         => $board['rows'],
            'totals'       => $board['totals'],
            'hasAwaiting'  => $board['has_awaiting'],
            'canSeeTeam'   => $canSeeTeam,
            'activeTab'    => in_array($request->query('tab'), ['activity', 'consent'], true)
                ? $request->query('tab')
                : 'activity',
            'sourceLabels' => OutreachActivityFeedService::SOURCE_LABELS,
            'filterDays'   => $filters['days'],
            'filterSource' => $feed['source'],
        ]);
    }
}
