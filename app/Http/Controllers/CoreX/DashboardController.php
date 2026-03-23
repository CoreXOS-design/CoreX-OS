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
            ->where('scope', 'global')
            ->pluck('id');

        $mtdPoints = (int) DB::table('daily_activity_entries as e')
            ->join('activity_definitions as d', 'd.id', '=', 'e.activity_definition_id')
            ->where('e.user_id', $user->id)
            ->where('e.period', $period)
            ->whereIn('e.activity_definition_id', $defIds)
            ->sum(DB::raw('e.value * d.weight'));

        $monthlyTarget = (int) (DB::table('targets')
            ->where('user_id', $user->id)
            ->where('period', $period)
            ->value('points_target') ?? 0);

        // Candidate documents needing authorisation (shared queue for full-status users)
        $candidateService = new CandidatePractitionerService();
        $candidateDocs = collect();

        if ($candidateService->canAuthorise($user)) {
            $candidateDocs = SignatureTemplate::with(['document', 'creator'])
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
