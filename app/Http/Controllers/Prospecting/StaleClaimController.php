<?php

declare(strict_types=1);

namespace App\Http\Controllers\Prospecting;

use App\Http\Controllers\Controller;
use App\Models\ProspectingClaim;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\Prospecting\ProspectingClaimService;
use App\Services\Prospecting\ProspectingConfigurationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * MIC funnel phase 2 (Johan 2026-08-13) — BM/admin stale-claim review + reassignment.
 *
 * ANTI-POACHING RULE (Johan's call): agents never grab stale stock from each other. When a
 * pitched/claimed property goes stale (unworked past the agency's claim_release_days) the BRANCH
 * MANAGER / ADMIN makes the move-or-keep decision here — reassign to another agent (losing agent is
 * warned) or keep with the current agent (timer reset). Also hosts the agent's "release with reason"
 * (no address could be established) so others/BM see WHY.
 *
 * Own controller (not the shared MarketIntelligenceController) to avoid collisions on the shared tree.
 */
class StaleClaimController extends Controller
{
    /** BM/admin oversight list — warned + stale claims for the agency. */
    public function index(Request $request, ProspectingConfigurationService $config)
    {
        $user = $request->user();
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        $thresholds = $config->getSuggestedActionThresholds($agencyId);
        $warnDays = (int) $thresholds->claim_warn_days;
        $releaseDays = (int) $thresholds->claim_release_days;

        // Active, unworked claims at or past the WARN line — the BM sees warned + fully-stale together.
        $rows = ProspectingClaim::query()
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            ->whereNull('released_at')
            ->get()
            ->filter(fn ($c) => $c->staleAgeDays() >= $warnDays)
            ->sortByDesc(fn ($c) => $c->staleAgeDays())
            ->values();

        $userNames = User::whereIn('id', $rows->pluck('user_id')->filter()->unique())->pluck('name', 'id');
        $addresses = DB::table('prospecting_listings')
            ->whereIn('id', $rows->pluck('prospecting_listing_id')->filter()->unique())
            ->pluck('address', 'id');

        $items = $rows->map(fn ($c) => [
            'claim'      => $c,
            'agent_name' => $userNames[$c->user_id] ?? 'Unassigned',
            'address'    => (string) ($addresses[$c->prospecting_listing_id] ?? '') ?: 'Property #' . ($c->property_id ?? $c->prospecting_listing_id),
            'days'       => $c->staleAgeDays(),
            'is_stale'   => $c->isStaleForReview($releaseDays),  // past release line = ready for move-or-keep
        ]);

        // Agents in the agency to reassign TO.
        $agents = User::where('agency_id', $agencyId)->orderBy('name')->get(['id', 'name']);

        return view('corex.market-intelligence.stale-review', [
            'items'       => $items,
            'agents'      => $agents,
            'warnDays'    => $warnDays,
            'releaseDays' => $releaseDays,
        ]);
    }

    /** Reassign a stale claim to another agent (BM/admin). The losing agent is warned. */
    public function reassign(Request $request, int $claim, ProspectingClaimService $claims, NotificationDispatcher $dispatcher)
    {
        $user = $request->user();
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        $data = $request->validate(['new_agent_id' => ['required', 'integer']]);

        $model = ProspectingClaim::where('id', $claim)->where('agency_id', $agencyId)->firstOrFail();
        $newAgent = User::where('id', (int) $data['new_agent_id'])->where('agency_id', $agencyId)->firstOrFail();

        [$updated, $oldUserId] = $claims->reassignClaim($model->id, $newAgent->id, (int) $user->id);

        // Warn the losing agent.
        $losing = User::find($oldUserId);
        if ($losing && $oldUserId !== $newAgent->id) {
            $label = (string) DB::table('prospecting_listings')->where('id', $updated->prospecting_listing_id)->value('address') ?: 'a property';
            $dispatcher->fire($losing, 'prospecting.claim_reassigned', $updated, [
                'title'            => "{$label} — reassigned",
                'body'            => "Your manager reassigned this property to {$newAgent->name} after it went stale.",
                'subject_label'    => $label,
                'action_url'       => route('market-intelligence.work', [], false),
                'severity'         => 'warning',
                'threshold_hit_at' => now(),
            ]);
        }

        return back()->with('status', 'Reassigned to ' . $newAgent->name . '.');
    }

    /** Keep the claim with its current agent (BM/admin) — resets the staleness timer. */
    public function keep(Request $request, int $claim, ProspectingClaimService $claims)
    {
        $user = $request->user();
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        $model = ProspectingClaim::where('id', $claim)->where('agency_id', $agencyId)->firstOrFail();
        $claims->keepClaim($model->id, (int) $user->id);
        return back()->with('status', 'Kept with the current agent — timer reset.');
    }

    /**
     * Agent releases their claim back to MIC because no address could be established. Structured
     * reason 'no_address' so other agents / the BM see WHY on the pool. Owner or manager only.
     */
    public function releaseNoAddress(Request $request, int $claim, ProspectingClaimService $claims)
    {
        $user = $request->user();
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        $model = ProspectingClaim::where('id', $claim)->where('agency_id', $agencyId)->firstOrFail();

        $isOwner = (int) $model->user_id === (int) $user->id;
        $isManager = $user->hasPermission('prospecting_setup.manage');
        abort_unless($isOwner || $isManager, 403);

        $claims->releaseClaim($model->id, (int) $user->id, 'No address could be established', 'no_address');

        return back()->with('status', 'Released back to MIC — no address could be established.');
    }
}
