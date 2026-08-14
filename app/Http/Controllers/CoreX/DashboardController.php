<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\SignatureTemplate;
use App\Services\CandidatePractitionerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $period = now()->format('Y-m');

        // MTD points: sum(value * weight) for enabled global definitions
        $defIds = DB::table('activity_definitions')
            ->where('is_enabled', 1)
            ->where('scope', 'system')
            ->pluck('id');

        // M6.5 — achievement-total filter.
        $mtdPoints = (int) DB::table('daily_activity_entries as e')
            ->join('activity_definitions as d', 'd.id', '=', 'e.activity_definition_id')
            ->where('e.user_id', $user->id)
            ->where('e.period', $period)
            ->whereIn('e.activity_definition_id', $defIds)
            ->whereIn('e.point_state', \App\Models\DailyActivityEntry::ACHIEVEMENT_TOTAL_STATES)
            ->whereIn('e.source', \App\Models\DailyActivityEntry::ACHIEVEMENT_TOTAL_SOURCES)
            ->sum(DB::raw('e.value * d.weight'));

        $monthlyTarget = (int) (DB::table('targets')
            ->where('user_id', $user->id)
            ->where('period', $period)
            ->value('points_target') ?? 0);

        // Candidate documents needing authorisation (shared queue for full-status users)
        $candidateService = new CandidatePractitionerService();
        $candidateDocs = collect();

        // Supervised candidates' IN-PROGRESS documents (read-only walk-through surface).
        // AT-352b (greenlit): a full-status authoriser can open a supervised candidate's live
        // document — read-only, via the view-live mirror — BEFORE it reaches their authorisation
        // turn, so they can walk the party through it. Additive: separate from (and does NOT
        // disturb) the "needs authorisation" queue above. Deliberately EXCLUDES the two
        // AWAITING_SUPERVISOR(_FINAL) statuses (those already surface in $candidateDocs) and all
        // terminal/pre-send statuses. Agency-scoped via the candidate (creator) so an authoriser
        // only ever sees their own agency's candidates — mirrors getEligibleAuthorisers' scope.
        $candidateInProgressDocs = collect();

        // BRANCH-SCOPED authorisation surface (confirmed model, 2026-08-03): a viewer sees exactly
        // the candidate documents they are eligible to authorise —
        //   - agency admins (admin/super_admin/principal/owner): agency-wide,
        //   - Branch Managers / full-status practitioners: their branch(es) only.
        // This mirrors getEligibleAuthorisers so the dashboard queue matches the in-app notification
        // pool (never showing a branch agent another branch's candidate docs). A null agency, or a
        // non-admin with no authorising branch, resolves to nothing rather than every agency's.
        $agencyId = $user->effectiveAgencyId();
        if ($candidateService->canAuthorise($user) && $agencyId) {
            if ($candidateService->isAgencyAdmin($user)) {
                $creatorScope = function ($q) use ($agencyId) {
                    $q->where('agency_id', $agencyId)
                        ->orWhereHas('branch', fn ($b) => $b->where('agency_id', $agencyId));
                };
            } else {
                // -1 sentinel → matches no branch when the viewer manages none (empty result).
                $branchIds = $candidateService->authorisingBranchIds($user) ?: [-1];
                $creatorScope = function ($q) use ($branchIds) {
                    $q->whereIn('branch_id', $branchIds);
                };
            }

            $candidateDocs = SignatureTemplate::with(['document', 'creator'])
                ->where('is_candidate_flow', true)
                ->whereIn('status', [
                    SignatureTemplate::STATUS_AWAITING_SUPERVISOR,
                    SignatureTemplate::STATUS_AWAITING_SUPERVISOR_FINAL,
                ])
                ->whereHas('creator', $creatorScope)
                ->orderBy('created_at', 'desc')
                ->get();

            $candidateInProgressDocs = SignatureTemplate::with(['document', 'creator'])
                ->where('is_candidate_flow', true)
                ->whereIn('status', [
                    SignatureTemplate::STATUS_SIGNING,
                    SignatureTemplate::STATUS_AWAITING_TENANT,
                    SignatureTemplate::STATUS_AWAITING_LANDLORD,
                    SignatureTemplate::STATUS_AWAITING_BUYER,
                    SignatureTemplate::STATUS_AWAITING_SELLER,
                    SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL,
                    SignatureTemplate::STATUS_RETURNED_TO_CANDIDATE,
                    SignatureTemplate::STATUS_AWAITING_DEFERRED,
                    SignatureTemplate::STATUS_AMENDMENT_REVIEW,
                    SignatureTemplate::STATUS_AMENDMENT_INITIALING,
                    SignatureTemplate::STATUS_PARTIAL,
                ])
                ->whereHas('creator', $creatorScope)
                ->orderBy('created_at', 'desc')
                ->get();
        }

        return view('corex.dashboard', [
            'mtdPoints'                => $mtdPoints,
            'monthlyTarget'            => $monthlyTarget,
            'period'                   => $period,
            'candidateDocs'            => $candidateDocs,
            'candidateInProgressDocs'  => $candidateInProgressDocs,
        ]);
    }
}
