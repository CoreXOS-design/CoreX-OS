<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Compliance\WhistleblowComplaint;
use App\Services\Compliance\WhistleblowComplaintService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WhistleblowController extends Controller
{
    public function __construct(
        private WhistleblowComplaintService $service
    ) {}

    /**
     * Queue / index page — all complaints visible to the user.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $query = WhistleblowComplaint::query()
            ->with(['reporter', 'approvedBy']);

        // Scope: approvers see all agency complaints, agents see only their own
        $canViewAll = $user->hasPermission('compliance.whistleblow.view_all_agency');
        if (!$canViewAll) {
            $query->where('reported_by_user_id', $user->id);
        }

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('tier')) {
            $query->where('tier', $request->tier);
        }

        // Default sort: pending_approval first, then by created_at desc
        $query->orderByRaw("FIELD(status, 'pending_approval') DESC")
              ->orderBy('created_at', 'desc');

        $complaints = $query->paginate(25);

        return view('compliance.whistleblow.index', compact('complaints'));
    }

    /**
     * Store — from property page modal. Creates draft + attaches evidence + submits.
     */
    public function store(Request $request)
    {
        $request->validate([
            'tier'                 => 'required|in:tier_1,tier_2,tier_3',
            'subject_agency_name'  => 'required|string|max:255',
            'property_address'     => 'required|string|max:255',
            'property_portal_url'  => 'nullable|url|max:500',
            'portal_source'        => 'nullable|in:p24,pp,other',
            'agent_notes'          => 'nullable|string|max:5000',
            'seller_consents_to_named_complaint' => 'nullable|boolean',
            'screenshot'           => 'nullable|file|image|max:5120',
            'property_id'         => 'nullable|integer|exists:properties,id',
        ]);

        $user = Auth::user();

        // Build complaint data
        $data = [
            'agency_id'            => $user->effectiveAgencyId(),
            'branch_id'            => $user->branch_id,
            'tier'                 => $request->tier,
            'subject_agency_name'  => $request->subject_agency_name,
            'property_address'     => $request->property_address,
            'property_portal_url'  => $request->property_portal_url,
            'portal_source'        => $request->portal_source,
            'agent_notes'          => $request->agent_notes,
            'property_id'          => $request->property_id,
            'seller_consents_to_named_complaint' => (bool) $request->seller_consents_to_named_complaint,
        ];

        // Tier 1 needs seller_statement from agent_notes
        if ($request->tier === 'tier_1') {
            $data['seller_statement'] = $request->agent_notes;
        }

        $complaint = $this->service->createDraft($data, $user);

        // Handle screenshot upload
        if ($request->hasFile('screenshot')) {
            $file = $request->file('screenshot');
            $path = $file->store("whistleblow/evidence/{$user->id}", 'local');
            $fullPath = storage_path('app/' . $path);

            $this->service->attachEvidence(
                $complaint,
                'screenshot',
                $fullPath,
                $file->getClientOriginalName(),
                $file->getMimeType(),
                $file->getSize(),
                'Screenshot uploaded with report',
                $user
            );
        }

        // Auto-submit (skip draft — agent's report modal goes straight to pending)
        $complaint = $this->service->submit($complaint, $user);

        $reference = 'HFC-WB-' . $complaint->id;

        if ($request->expectsJson()) {
            return response()->json([
                'ok'           => true,
                'complaint_id' => $complaint->id,
                'reference'    => $reference,
            ]);
        }

        return redirect()->back()->with('success', "Report submitted for approval. Reference: {$reference}");
    }

    /**
     * Detail / review page.
     */
    public function show(WhistleblowComplaint $complaint)
    {
        $complaint->load(['reporter', 'approvedBy', 'rejectedBy', 'evidence', 'sellerContact']);
        $auditLog = $complaint->auditLog()->with('user')->orderBy('created_at')->get();

        $user = Auth::user();
        $isApprover = $this->canApprove($complaint, $user);

        return view('compliance.whistleblow.show', compact('complaint', 'auditLog', 'isApprover'));
    }

    /**
     * Approve a complaint — triggers PDF + email.
     */
    public function approve(Request $request, WhistleblowComplaint $complaint)
    {
        $this->service->approve(
            $complaint,
            Auth::user(),
            $request->input('notes')
        );

        return redirect()->route('compliance.whistleblow.index')
            ->with('success', 'Complaint approved and submitted to PPRA.');
    }

    /**
     * Reject a complaint.
     */
    public function reject(Request $request, WhistleblowComplaint $complaint)
    {
        $request->validate(['reason' => 'required|string|max:2000']);

        $this->service->reject(
            $complaint,
            Auth::user(),
            $request->reason
        );

        return redirect()->route('compliance.whistleblow.index')
            ->with('success', 'Complaint rejected.');
    }

    /**
     * Request changes on a complaint.
     */
    public function requestChanges(Request $request, WhistleblowComplaint $complaint)
    {
        $request->validate(['notes' => 'required|string|max:2000']);

        $this->service->requestChanges(
            $complaint,
            Auth::user(),
            $request->notes
        );

        return redirect()->route('compliance.whistleblow.index')
            ->with('success', 'Changes requested — agent has been notified.');
    }

    /**
     * Download the lawyer review pack (ZIP with 3 PDFs + cover email + README).
     */
    public function lawyerReviewPack(Request $request)
    {
        $zipPath = $this->service->generateLawyerReviewPack(Auth::user());
        $filename = 'whistleblow-lawyer-review-pack-' . now()->format('Y-m-d') . '.zip';

        return response()->download($zipPath, $filename)->deleteFileAfterSend(true);
    }

    /**
     * Check if user can approve complaints for this agency.
     */
    private function canApprove(WhistleblowComplaint $complaint, $user): bool
    {
        if (!$user->hasPermission('compliance.whistleblow.approve')) {
            return false;
        }

        $agency = \App\Models\Agency::withoutGlobalScopes()->find($complaint->agency_id);
        $approverIds = $agency->whistleblow_approver_user_ids ?? [];

        if (!empty($approverIds)) {
            return in_array($user->id, $approverIds);
        }

        return in_array($user->role ?? 'agent', ['admin', 'branch_manager', 'super_admin']);
    }
}
