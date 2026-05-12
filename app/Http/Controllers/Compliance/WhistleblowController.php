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
            ->with(['reporter', 'approvedBy', 'subjects']);

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
     * Standalone filing form page.
     */
    public function create(Request $request)
    {
        $user = Auth::user();

        // Pre-fill from property if linked
        $property = null;
        if ($request->filled('property_id')) {
            $property = \App\Models\Property::withoutGlobalScopes()->find($request->property_id);
        }

        // Agency properties for picker
        $properties = \App\Models\Property::withoutGlobalScopes()
            ->where('agency_id', $user->effectiveAgencyId() ?? $user->agency_id)
            ->whereNotNull('address')
            ->orderBy('address')
            ->select('id', 'address', 'suburb', 'title')
            ->limit(200)
            ->get();

        $idempotencyToken = (string) \Illuminate\Support\Str::uuid();

        return view('compliance.whistleblow.create', compact('property', 'properties', 'idempotencyToken'));
    }

    /**
     * Store — from standalone form OR property page modal.
     */
    public function store(Request $request)
    {
        $request->validate([
            'tier'                          => 'required|in:tier_1,tier_2,tier_3',
            'property_address'              => 'required_without:property_id|nullable|string|max:255',
            'property_id'                   => 'nullable|integer|exists:properties,id',
            'seller_statement'              => 'nullable|string|max:5000',
            'agent_notes'                   => 'nullable|string|max:5000',
            'subjects'                      => 'required|array|min:1|max:10',
            'subjects.*.agency_name'        => 'required|string|max:255',
            'subjects.*.practitioner_name'  => 'nullable|string|max:255',
            'subjects.*.portal_url'         => 'required|url|max:500',
            'subjects.*.portal_source'      => 'required|in:p24,pp,other',
            'screenshot'                    => 'nullable|file|image|max:5120',
            'evidence_files'                => 'nullable|array|max:5',
            'evidence_files.*'              => 'file|max:10240',
        ]);

        $user = Auth::user();

        // Idempotency: check token
        $token = $request->input('_idempotency_token');
        if ($token && session('wb_last_token') === $token) {
            $lastId = session('wb_last_complaint_id');
            if ($lastId) {
                $ref = 'HFC-WB-' . $lastId;
                if ($request->expectsJson()) {
                    return response()->json(['ok' => true, 'complaint_id' => $lastId, 'reference' => $ref]);
                }
                return redirect()->route('compliance.whistleblow.index')->with('success', "Report already submitted. Reference: {$ref}");
            }
        }

        // Derive property_address from property if linked
        $propertyAddress = $request->property_address;
        if ($request->property_id) {
            $prop = \App\Models\Property::withoutGlobalScopes()->find($request->property_id);
            if ($prop) {
                $propertyAddress = $prop->address ?? $prop->title ?? $propertyAddress;
            }
        }

        $complaint = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $user, $propertyAddress) {
            // Build complaint data
            $data = [
                'agency_id'        => $user->effectiveAgencyId(),
                'branch_id'        => $user->branch_id,
                'tier'             => $request->tier,
                'property_address' => $propertyAddress,
                'property_id'      => $request->property_id,
                'agent_notes'      => $request->agent_notes,
                'subjects'         => $request->subjects,
            ];

            if ($request->tier === 'tier_1') {
                $data['seller_statement'] = $request->seller_statement ?: $request->agent_notes;
            }

            $complaint = $this->service->createDraft($data, $user);

            // Handle screenshot upload (modal shortcut)
            if ($request->hasFile('screenshot')) {
                $file = $request->file('screenshot');
                $path = $file->store("whistleblow/evidence/{$user->id}", 'local');
                $this->service->attachEvidence($complaint, 'screenshot', storage_path('app/' . $path),
                    $file->getClientOriginalName(), $file->getMimeType(), $file->getSize(), 'Screenshot uploaded with report', $user);
            }

            // Handle multiple evidence files (standalone form)
            if ($request->hasFile('evidence_files')) {
                foreach ($request->file('evidence_files') as $file) {
                    $path = $file->store("whistleblow/evidence/{$user->id}", 'local');
                    $isImage = str_starts_with($file->getMimeType(), 'image/');
                    $this->service->attachEvidence($complaint, $isImage ? 'screenshot' : 'document_upload',
                        storage_path('app/' . $path), $file->getClientOriginalName(), $file->getMimeType(), $file->getSize(), 'Evidence file uploaded with report', $user);
                }
            }

            $complaint = $this->service->submit($complaint, $user);
            return $complaint;
        });

        // Store idempotency token
        if ($token) {
            session(['wb_last_token' => $token, 'wb_last_complaint_id' => $complaint->id]);
        }

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
        $complaint->load(['reporter', 'approvedBy', 'rejectedBy', 'evidence', 'sellerContact', 'subjects', 'emailLogs.sentBy']);
        $auditLog = $complaint->auditLog()->with('user')->orderBy('created_at')->get();
        $agency = \App\Models\Agency::withoutGlobalScopes()->find($complaint->agency_id);

        $user = Auth::user();
        $isApprover = $this->canApprove($complaint, $user);

        return view('compliance.whistleblow.show', compact('complaint', 'auditLog', 'isApprover', 'agency'));
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
