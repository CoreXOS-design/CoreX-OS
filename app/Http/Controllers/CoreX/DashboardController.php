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

        // daily_activity_entries has agency_id but no automatic tenant scope on
        // this raw query-builder path. user_id is self-scoped here, but the
        // agency_id filter is added as defense in depth, matching every other
        // daily_activity_entries call site fixed in this pass.
        $agencyId = $user->effectiveAgencyId();

        // M6.5 — achievement-total filter.
        $mtdPoints = (int) DB::table('daily_activity_entries as e')
            ->join('activity_definitions as d', 'd.id', '=', 'e.activity_definition_id')
            ->where('e.user_id', $user->id)
            ->where('e.period', $period)
            ->whereIn('e.activity_definition_id', $defIds)
            ->whereIn('e.point_state', \App\Models\DailyActivityEntry::ACHIEVEMENT_TOTAL_STATES)
            ->whereIn('e.source', \App\Models\DailyActivityEntry::ACHIEVEMENT_TOTAL_SOURCES)
            ->when($agencyId, function ($q) use ($agencyId) {
                $q->where('e.agency_id', $agencyId);
            })
            ->sum(DB::raw('e.value * d.weight'));

        $monthlyTarget = (int) (DB::table('targets')
            ->where('user_id', $user->id)
            ->where('period', $period)
            ->value('points_target') ?? 0);

        // Candidate documents needing authorisation (shared queue for full-status users)
        $candidateService = new CandidatePractitionerService();
        $candidateDocs = collect();

        if ($candidateService->canAuthorise($user)) {
            // SignatureTemplate has no BelongsToAgency trait — agency_id lives on
            // the parent Document, so scope via the document relation.
            $candidateDocs = SignatureTemplate::with(['document', 'creator'])
                ->whereHas('document', fn ($q) => $q->where('agency_id', $user->effectiveAgencyId()))
                ->where('is_candidate_flow', true)
                ->whereIn('status', [
                    SignatureTemplate::STATUS_AWAITING_SUPERVISOR,
                    SignatureTemplate::STATUS_AWAITING_SUPERVISOR_FINAL,
                ])
                ->orderBy('created_at', 'desc')
                ->get();
        }

        return view('corex.dashboard', [
            'mtdPoints'     => $mtdPoints,
            'monthlyTarget' => $monthlyTarget,
            'period'        => $period,
            'candidateDocs' => $candidateDocs,
        ]);
    }
}
