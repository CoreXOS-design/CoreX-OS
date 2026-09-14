<?php

declare(strict_types=1);

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Compliance\WhistleblowComplaint;
use App\Models\FicaSubmission;
use App\Services\Compliance\ApprovalQueueCounts;
use App\Services\Docuperfect\EsignApprovalService;
use Illuminate\Support\Facades\Auth;

/**
 * Approvals hub — one place to look, three separately labelled and separately counted groups
 * (FICA, documents awaiting release, compliance reports), each linking to its own queue. Never a
 * merged list. Spec esign-compliance-approval-gate.md §8.3.
 */
class ApprovalsHubController extends Controller
{
    public function __construct(
        private ApprovalQueueCounts $counts,
        private EsignApprovalService $esign,
    ) {}

    public function index()
    {
        $user     = Auth::user();
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        $counts   = $this->counts->forUser($user);

        // FICA — the two stations FicaController shows, scoped exactly the same way.
        $ficaRo = collect();
        $ficaCo = collect();
        if ($agencyId > 0 && $user->isComplianceOfficer($agencyId)) {
            $ficaRo = FicaSubmission::where('status', 'agent_approved')->visibleTo($user)
                ->with(['contact', 'requestedBy'])->latest('updated_at')->limit(5)->get();
        }
        if ($agencyId > 0 && $user->isPrimaryComplianceOfficer($agencyId)) {
            $ficaCo = FicaSubmission::where('status', 'referred_to_co')->visibleTo($user)
                ->with(['contact', 'requestedBy'])->latest('updated_at')->limit(5)->get();
        }

        // Documents awaiting release — only meaningful to an e-sign officer.
        $esignItems = $counts['esign'] > 0 ? $this->esign->queueQuery($user)->limit(5)->get() : collect();

        // Compliance reports.
        $wbItems = collect();
        if ($agencyId > 0 && $this->counts->whistleblowMayDecide($user, $agencyId)) {
            $wbItems = WhistleblowComplaint::where('status', 'pending_approval')->visibleTo($user)
                ->with('reporter')->latest('created_at')->limit(5)->get();
        }

        return view('corex.approvals.index', compact('counts', 'ficaRo', 'ficaCo', 'esignItems', 'wbItems'));
    }
}
