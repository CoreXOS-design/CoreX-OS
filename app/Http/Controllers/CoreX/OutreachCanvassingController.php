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
 * Activity-feed row visibility (AT-380): per-role own/branch/all scope, set in
 * Role Manager (outreach_canvassing.view) — replaces the old hardcoded
 * own-vs-everyone binary (mic.view_team / prospecting_setup.manage), which had
 * no branch tier at all. 'branch'/'all' scopes may still drill down via
 * ?agent_id=.
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

        $scope = \App\Services\PermissionService::outreachCanvassingScope($user);
        $canSeeTeam = in_array($scope, ['branch', 'all'], true);

        $filters = [
            'days'   => (int) $request->integer('days', 90),
            'source' => $request->query('source'),
        ];
        if ($scope === 'branch') {
            $branchId = $user->effectiveBranchId();
            if ($branchId) {
                $filters['user_ids'] = \App\Models\User::withoutGlobalScopes()
                    ->where('agency_id', $agencyId)
                    ->where('branch_id', $branchId)
                    ->pluck('id')->all();
            } else {
                // No single branch (e.g. branches.view_all with no branch_id) —
                // "branch" IS "all" for this user, mirroring ProspectingListing
                // ::scopeVisibleTo() and CalendarEvent's same carve-out.
                $canSeeTeam = $canSeeTeam || $user->hasPermission('branches.view_all');
                if (! $user->hasPermission('branches.view_all')) {
                    $filters['user_id'] = (int) $user->id;
                }
            }
        } elseif ($scope !== 'all') {
            // 'own' (or no scope row yet — safe default).
            $filters['user_id'] = (int) $user->id;
        }
        // Both 'branch' (with a resolvable branch) and 'all' may drill down to
        // one teammate's activity via the agent filter.
        if ($canSeeTeam && is_numeric($request->query('agent_id'))) {
            $filters['user_id'] = (int) $request->query('agent_id');
            unset($filters['user_ids']);
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
