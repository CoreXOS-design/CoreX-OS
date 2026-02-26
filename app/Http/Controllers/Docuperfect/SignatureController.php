<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\LeaseRecord;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureMarker;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\WetInkInspection;
use App\Services\Docuperfect\DocumentFlattener;
use App\Services\Docuperfect\SignaturePdfService;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SignatureController extends Controller
{
    protected SignatureService $signatureService;

    public function __construct(SignatureService $signatureService)
    {
        $this->signatureService = $signatureService;
    }

    // ──────────────────────────────────────────────
    // Rental Dashboard (fully implemented)
    // ──────────────────────────────────────────────

    /**
     * Show the rental documents dashboard grouped by status.
     */
    public function rentalDashboard(Request $request)
    {
        $user = $request->user();
        $data = $this->signatureService->getRentalDashboardData($user);

        return view('docuperfect.rental.dashboard', [
            'groups' => $data['groups'],
            'signatureTemplates' => $data['signatureTemplates'],
            'fieldStatus' => $data['fieldStatus'],
            'counts' => $data['counts'],
            'upcomingRenewals' => $data['upcomingRenewals'],
            'expiredLeases' => $data['expiredLeases'],
            'activeLeases' => $data['activeLeases'],
            'activeLeaseCount' => $data['activeLeaseCount'],
            'lastUpdate' => $data['lastUpdate'] ?? '',
            'user' => $user,
        ]);
    }

    // ──────────────────────────────────────────────
    // Signature setup
    // ──────────────────────────────────────────────

    /**
     * Show signature setup page for a document.
     */
    public function setup(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        // Field completion gate
        $validation = $this->signatureService->validateFieldCompletion($document);
        if (!$validation['valid']) {
            $filled = count(($document->fields_json ?? []));
            $total = $filled + count($validation['missing']);
            return redirect()->back()->with('error',
                "Complete all document fields before setting up signatures. {$filled}/{$total} fields completed."
            )->with('missing_fields', $validation['missing']);
        }

        // Get or create signature template
        $template = SignatureTemplate::firstOrCreate(
            ['document_id' => $document->id],
            [
                'status' => SignatureTemplate::STATUS_DRAFT,
                'created_by' => $user->id,
                'signing_order_json' => ['agent', 'tenant', 'landlord'],
            ]
        );

        // Auto-convert template signature zones to markers (idempotent)
        if ($template->isDraft()) {
            $this->signatureService->convertZonesToMarkers($template);
        }

        // Load existing markers (including any just created from zones)
        $markers = $template->markers()->orderBy('page_number')->orderBy('sort_order')->get();

        // Build page image URLs from the document's parent template
        $docTemplate = $document->template;
        $pageImages = [];
        if ($docTemplate) {
            for ($n = 0; $n < $docTemplate->page_count; $n++) {
                $pageImages[] = route('docuperfect.page.image', ['id' => $docTemplate->id, 'page' => $n]);
            }
        }

        // Determine which step to show
        $parties = $template->parties_json ?? [];
        $step = !empty($parties) ? 2 : 1;

        // If step query param is provided, allow going back to step 1
        if ($request->query('step') === '1') {
            $step = 1;
        }

        return view('docuperfect.signatures.setup', [
            'document' => $document,
            'template' => $template,
            'sigTemplate' => $template,
            'markers' => $markers,
            'parties' => $parties,
            'pageImages' => $pageImages,
            'pageCount' => $docTemplate ? $docTemplate->page_count : 0,
            'step' => $step,
            'user' => $user,
        ]);
    }

    /**
     * Save parties for a document's signature template.
     */
    public function saveParties(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        $tenantNotRequired = $request->boolean('tenant_not_required');
        $landlordNotRequired = $request->boolean('landlord_not_required');

        // Build validation rules — only validate active parties
        $rules = [
            'agent_name' => 'required|string|max:255',
            'agent_email' => 'required|email|max:255',
            'tenant_not_required' => 'nullable|boolean',
            'landlord_not_required' => 'nullable|boolean',
        ];

        if (!$tenantNotRequired) {
            $rules['tenant_name'] = 'required|string|max:255';
            $rules['tenant_email'] = 'required|email|max:255';
            $rules['tenant_id_number'] = 'nullable|string|max:20';
            $rules['add_tenant_witness'] = 'nullable|boolean';
            $rules['tenant_witness_name'] = 'required_if:add_tenant_witness,1|nullable|string|max:255';
            $rules['tenant_witness_email'] = 'required_if:add_tenant_witness,1|nullable|email|max:255';
        }

        if (!$landlordNotRequired) {
            $rules['landlord_name'] = 'required|string|max:255';
            $rules['landlord_email'] = 'required|email|max:255';
            $rules['landlord_id_number'] = 'nullable|string|max:20';
            $rules['add_landlord_witness'] = 'nullable|boolean';
            $rules['landlord_witness_name'] = 'required_if:add_landlord_witness,1|nullable|string|max:255';
            $rules['landlord_witness_email'] = 'required_if:add_landlord_witness,1|nullable|email|max:255';
        }

        $request->validate($rules);

        // Build parties array — only include active parties
        $parties = [
            ['role' => 'agent', 'name' => $request->agent_name, 'email' => $request->agent_email, 'id_number' => null],
        ];

        $signingOrder = ['agent'];

        if (!$tenantNotRequired) {
            $parties[] = ['role' => 'tenant', 'name' => $request->tenant_name, 'email' => $request->tenant_email, 'id_number' => $request->tenant_id_number];
            $signingOrder[] = 'tenant';

            if ($request->boolean('add_tenant_witness')) {
                $parties[] = ['role' => 'tenant_witness', 'name' => $request->tenant_witness_name, 'email' => $request->tenant_witness_email, 'id_number' => null];
            }
        }

        if (!$landlordNotRequired) {
            $parties[] = ['role' => 'landlord', 'name' => $request->landlord_name, 'email' => $request->landlord_email, 'id_number' => $request->landlord_id_number];
            $signingOrder[] = 'landlord';

            if ($request->boolean('add_landlord_witness')) {
                $parties[] = ['role' => 'landlord_witness', 'name' => $request->landlord_witness_name, 'email' => $request->landlord_witness_email, 'id_number' => null];
            }
        }

        // Get or create template
        $template = SignatureTemplate::firstOrCreate(
            ['document_id' => $document->id],
            [
                'status' => SignatureTemplate::STATUS_DRAFT,
                'created_by' => $user->id,
                'signing_order_json' => $signingOrder,
            ]
        );

        // Generate document hash
        $hash = $this->signatureService->generateDocumentHash($document);

        $template->update([
            'parties_json' => $parties,
            'signing_order_json' => $signingOrder,
            'document_hash' => $hash,
        ]);

        // Create signing requests for active core parties only
        $activeRoles = collect($parties)->pluck('role')->intersect(['agent', 'tenant', 'landlord'])->all();

        foreach ($parties as $party) {
            // Only create requests for core signing roles
            if (!in_array($party['role'], ['agent', 'tenant', 'landlord'])) {
                continue;
            }

            $existing = $template->requests()
                ->where('party_role', $party['role'])
                ->first();

            if ($existing) {
                // Update existing request if details changed
                $existing->update([
                    'signer_name' => $party['name'],
                    'signer_email' => $party['email'],
                    'signer_id_number' => $party['id_number'] ?? null,
                ]);
            } else {
                $this->signatureService->createSigningRequest(
                    $template,
                    $party['role'],
                    $party['name'],
                    $party['email'],
                    $party['id_number'] ?? null,
                    sentBy: $user,
                );
            }
        }

        // Remove signing requests for parties that are no longer active
        $template->requests()
            ->whereIn('party_role', ['tenant', 'landlord'])
            ->whereNotIn('party_role', $activeRoles)
            ->delete();

        // Remove markers assigned to parties that are no longer active
        $template->markers()
            ->whereIn('assigned_party', ['tenant', 'landlord'])
            ->whereNotIn('assigned_party', $activeRoles)
            ->delete();

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('docuperfect.signatures.setup', $document)
            ->with('status', 'Parties saved. Now place signature markers on the document.');
    }

    /**
     * Save markers (JSON API).
     */
    public function saveMarkers(Request $request, Document $document)
    {
        $this->authorizeDocument($request->user(), $document);

        $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();

        // Build allowed parties from the template's active parties
        $allowedParties = collect($template->parties_json ?? [])
            ->pluck('role')
            ->intersect(['agent', 'tenant', 'landlord'])
            ->implode(',');

        $request->validate([
            'markers' => 'required|array',
            'markers.*.page_number' => 'required|integer|min:1',
            'markers.*.x_position' => 'required|numeric|min:0|max:100',
            'markers.*.y_position' => 'required|numeric|min:0|max:100',
            'markers.*.width' => 'required|numeric|min:0|max:100',
            'markers.*.height' => 'required|numeric|min:0|max:100',
            'markers.*.type' => 'required|string|in:signature,initial,date,text',
            'markers.*.assigned_party' => 'required|string|in:' . $allowedParties,
            'markers.*.label' => 'nullable|string|max:255',
        ]);

        try {
            $count = $this->signatureService->saveMarkers($template, $request->input('markers'));
        } catch (\LogicException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'count' => $count]);
    }

    /**
     * Update markers (same as save — PUT variant).
     */
    public function updateMarkers(Request $request, Document $document)
    {
        return $this->saveMarkers($request, $document);
    }

    // ──────────────────────────────────────────────
    // Internal signing
    // ──────────────────────────────────────────────

    /**
     * Show the internal signing page (for agent).
     */
    public function sign(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();

        // Must have markers placed
        if ($template->markers()->count() === 0) {
            return redirect()->route('docuperfect.signatures.setup', $document)
                ->with('error', 'Place signature markers before signing.');
        }

        // Get all markers with their signatures
        $allMarkers = $template->markers()
            ->with(['signatures' => fn($q) => $q->select('id', 'signature_marker_id', 'signature_data', 'signature_type', 'signed_at')])
            ->orderBy('page_number')
            ->orderBy('sort_order')
            ->get();

        // Agent markers count
        $agentMarkers = $allMarkers->where('assigned_party', 'agent');
        $signedCount = $agentMarkers->filter(fn($m) => $m->signatures->isNotEmpty())->count();
        $totalAgent = $agentMarkers->count();

        // Build page image URLs
        $docTemplate = $document->template;
        $pageImages = [];
        if ($docTemplate) {
            for ($n = 0; $n < $docTemplate->page_count; $n++) {
                $pageImages[] = route('docuperfect.page.image', ['id' => $docTemplate->id, 'page' => $n]);
            }
        }

        return view('docuperfect.signatures.sign', [
            'document' => $document,
            'template' => $template,
            'allMarkers' => $allMarkers,
            'signedCount' => $signedCount,
            'totalAgent' => $totalAgent,
            'allAgentSigned' => $signedCount >= $totalAgent && $totalAgent > 0,
            'pageImages' => $pageImages,
            'pageCount' => $docTemplate ? $docTemplate->page_count : 0,
            'user' => $user,
        ]);
    }

    /**
     * Capture a signature on a marker (internal).
     */
    public function captureSignature(Request $request, Document $document, SignatureMarker $marker)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        $request->validate([
            'signature_data' => 'required|string',
            'signature_type' => 'nullable|string|in:drawn,typed',
        ]);

        // Verify marker belongs to this document's template
        $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();
        if ((int) $marker->signature_template_id !== (int) $template->id) {
            abort(403);
        }

        // Verify marker is assigned to agent
        if ($marker->assigned_party !== 'agent') {
            abort(403, 'This marker is not assigned to you.');
        }

        // Verify marker hasn't already been signed
        if ($marker->signatures()->exists()) {
            return response()->json(['ok' => false, 'error' => 'Already signed'], 409);
        }

        $signingRequest = SignatureRequest::where('signature_template_id', $template->id)
            ->where('signer_email', $user->email)
            ->first();

        $signature = $this->signatureService->captureSignature(
            $marker,
            $request->input('signature_data'),
            $user->name,
            $user->email,
            $request->ip(),
            $request->userAgent(),
            $signingRequest,
            $user,
            $request->input('signature_type', 'drawn'),
        );

        // Check if all agent markers are now signed
        $allAgentSigned = $this->signatureService->isPartyComplete($template, 'agent');
        $signedCount = $template->signatures()
            ->whereHas('marker', fn($q) => $q->where('assigned_party', 'agent'))
            ->count();
        $totalAgent = $template->markers()
            ->where('assigned_party', 'agent')
            ->where('required', true)
            ->count();

        return response()->json([
            'ok' => true,
            'signature_id' => $signature->id,
            'all_agent_signed' => $allAgentSigned,
            'signed_count' => $signedCount,
            'total_agent' => $totalAgent,
        ]);
    }

    /**
     * Complete internal signing for a document.
     */
    public function signComplete(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        $template = SignatureTemplate::where('document_id', $document->id)
            ->with(['document.template', 'markers.signatures'])
            ->firstOrFail();

        // Verify all agent markers signed
        if (!$this->signatureService->isPartyComplete($template, 'agent')) {
            return redirect()->back()->with('error', 'Sign all your markers before completing.');
        }

        // FLATTEN: Bake field values + agent signatures into page images
        // From this point forward, external signers see flattened images only.
        $flattener = app(DocumentFlattener::class);
        $flattener->flattenFields($template);

        // Now flatten all agent signatures onto the already-flattened field images
        $agentMarkers = $template->markers->where('assigned_party', 'agent');
        foreach ($agentMarkers as $marker) {
            $sig = $marker->signatures->first();
            if ($sig) {
                $template->refresh(); // reload flattened_pages_json after each flatten
                $flattener->flattenSignature($template, $marker, $sig);
            }
        }

        // Mark agent request as completed
        $agentRequest = $template->requests()
            ->where('party_role', 'agent')
            ->where('status', '!=', SignatureRequest::STATUS_COMPLETED)
            ->first();

        if ($agentRequest) {
            $agentRequest->update([
                'status' => SignatureRequest::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
        }

        // Update template status
        $template->update(['status' => SignatureTemplate::STATUS_AWAITING_TENANT]);

        SignatureAuditLog::log(
            $template,
            SignatureAuditLog::ACTION_COMPLETED,
            SignatureAuditLog::ACTOR_USER,
            $user->name,
            $user->email,
            $user->id,
            $agentRequest?->id,
            $request->ip(),
            $request->userAgent(),
            [
                'phase' => 'agent_signing',
                'total_agent_signatures' => $template->signatures()
                    ->whereHas('marker', fn($q) => $q->where('assigned_party', 'agent'))
                    ->count(),
            ],
        );

        return redirect()->route('docuperfect.signatures.sendConfirmation', $document)
            ->with('success', 'You have signed all your markers. Now send to the tenant.');
    }

    /**
     * Show the send-to-tenant confirmation page.
     */
    public function sendConfirmation(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();
        $parties = $template->parties_json ?? [];

        // Find tenant details from parties
        $tenant = collect($parties)->firstWhere('role', 'tenant');

        return view('docuperfect.signatures.send-confirmation', [
            'document' => $document,
            'template' => $template,
            'tenant' => $tenant,
            'user' => $user,
        ]);
    }

    // ──────────────────────────────────────────────
    // Send + reminders
    // ──────────────────────────────────────────────

    /**
     * Send document for signature (handles initial send OR agent-complete → tenant send).
     */
    public function sendForSignature(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();

        // If template is awaiting_tenant, send to the tenant
        if ($template->status === SignatureTemplate::STATUS_AWAITING_TENANT) {
            $tenantRequest = $template->requests()
                ->where('party_role', 'tenant')
                ->first();

            if ($tenantRequest && $tenantRequest->status === SignatureRequest::STATUS_WAITING) {
                // Update message if provided
                if ($request->filled('message')) {
                    $tenantRequest->update(['message' => $request->input('message')]);
                }
                $this->signatureService->sendSigningRequest($tenantRequest);
            }

            return redirect()->route('docuperfect.rental')
                ->with('status', 'Document sent to tenant for signing.');
        }

        // Otherwise, initial send flow (draft/ready → signing)
        $validation = $this->signatureService->validateFieldCompletion($document);
        if (!$validation['valid']) {
            return redirect()->back()->withErrors([
                'fields' => 'Missing required fields: ' . implode(', ', $validation['missing']),
            ]);
        }

        try {
            $this->signatureService->sendForSigning($template, $user);
        } catch (\LogicException $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()->back()->with('status', 'Document sent for signing.');
    }

    /**
     * Send a manual reminder to a signer.
     */
    public function sendReminder(Request $request, Document $document, SignatureRequest $signatureRequest)
    {
        $this->authorizeDocument($request->user(), $document);

        if (in_array($signatureRequest->status, [
            SignatureRequest::STATUS_COMPLETED,
            SignatureRequest::STATUS_EXPIRED,
            SignatureRequest::STATUS_DECLINED,
        ])) {
            return redirect()->back()->with('error', 'Cannot send reminder — request is already ' . $signatureRequest->status . '.');
        }

        $this->signatureService->sendManualReminder($signatureRequest, $request->user());

        return redirect()->back()->with('status', "Reminder sent to {$signatureRequest->signer_name}.");
    }

    // ──────────────────────────────────────────────
    // Audit & download
    // ──────────────────────────────────────────────

    /**
     * Show audit trail for a document's signatures.
     */
    public function audit(Request $request, Document $document)
    {
        $this->authorizeDocument($request->user(), $document);

        $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();
        $template->loadMissing(['requests', 'markers', 'signatures', 'creator']);

        $logs = $template->auditLogs()
            ->orderBy('created_at', 'desc')
            ->get();

        $progress = $template->partyProgress();

        return view('docuperfect.signatures.audit-log', [
            'document' => $document,
            'template' => $template,
            'logs' => $logs,
            'progress' => $progress,
            'user' => $request->user(),
        ]);
    }

    /**
     * Download signed document.
     */
    public function download(Request $request, Document $document)
    {
        $this->authorizeDocument($request->user(), $document);

        $template = SignatureTemplate::where('document_id', $document->id)
            ->where('status', SignatureTemplate::STATUS_COMPLETED)
            ->firstOrFail();

        if (!$template->signed_pdf_path) {
            return redirect()->back()->with('error', 'Signed PDF has not been generated yet.');
        }

        $pdfPath = storage_path("app/{$template->signed_pdf_path}");

        if (!file_exists($pdfPath)) {
            return redirect()->back()->with('error', 'Signed PDF file not found.');
        }

        $filename = "Signed - {$document->name}.pdf";

        return response()->download($pdfPath, $filename);
    }

    // ──────────────────────────────────────────────
    // Leases
    // ──────────────────────────────────────────────

    /**
     * List lease records.
     */
    public function leases(Request $request)
    {
        $user = $request->user();

        $leases = LeaseRecord::visibleTo($user)
            ->with(['document', 'signatureTemplate'])
            ->orderByDesc('lease_end_date')
            ->paginate(20);

        return view('docuperfect.signatures.placeholder', [
            'title' => 'Lease Records',
            'leases' => $leases,
        ]);
    }

    // ──────────────────────────────────────────────
    // Wet ink inspection
    // ──────────────────────────────────────────────

    /**
     * Show wet ink inspection page for a signing request.
     */
    public function wetInkReview(Request $request, Document $document, SignatureRequest $signingRequest)
    {
        $this->authorizeDocument($request->user(), $document);

        $template = $signingRequest->template;

        // Get this party's required markers
        $markers = $template->markers()
            ->where('assigned_party', $signingRequest->party_role)
            ->where('required', true)
            ->orderBy('page_number')
            ->orderBy('sort_order')
            ->get();

        // Get uploaded files
        $uploadPaths = [];
        if ($signingRequest->wet_ink_upload_path) {
            $decoded = json_decode($signingRequest->wet_ink_upload_path, true);
            $uploadPaths = is_array($decoded) ? $decoded : [$signingRequest->wet_ink_upload_path];
        }

        // Build file info with URLs
        $uploadFiles = [];
        foreach ($uploadPaths as $path) {
            $uploadFiles[] = [
                'path' => $path,
                'name' => basename($path),
                'extension' => pathinfo($path, PATHINFO_EXTENSION),
                'exists' => Storage::disk('local')->exists($path),
            ];
        }

        return view('docuperfect.signatures.wet-ink-review', [
            'document' => $document,
            'signingRequest' => $signingRequest,
            'template' => $template,
            'markers' => $markers,
            'uploadFiles' => $uploadFiles,
            'previousInspections' => $signingRequest->inspections()->with('inspector')->latest()->get(),
            'user' => $request->user(),
        ]);
    }

    /**
     * Serve a wet ink uploaded file for review.
     */
    public function wetInkFile(Request $request, Document $document, SignatureRequest $signingRequest, $fileIndex)
    {
        $this->authorizeDocument($request->user(), $document);

        $uploadPaths = [];
        if ($signingRequest->wet_ink_upload_path) {
            $decoded = json_decode($signingRequest->wet_ink_upload_path, true);
            $uploadPaths = is_array($decoded) ? $decoded : [$signingRequest->wet_ink_upload_path];
        }

        $index = (int) $fileIndex;
        if (!isset($uploadPaths[$index])) {
            abort(404);
        }

        $path = $uploadPaths[$index];
        if (!Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return response()->file(storage_path("app/{$path}"));
    }

    /**
     * Process wet ink approval/rejection decision.
     */
    public function wetInkDecision(Request $request, Document $document, SignatureRequest $signingRequest)
    {
        $this->authorizeDocument($request->user(), $document);

        $request->validate([
            'checklist' => 'required|array',
            'checklist.*.marker_id' => 'required|integer',
            'checklist.*.status' => 'required|in:present,missing,unclear',
            'result' => 'required|in:approved,rejected',
            'notes' => 'nullable|string|max:2000',
            'rejection_note' => 'required_if:result,rejected|nullable|string|max:2000',
        ]);

        $result = $request->input('result');
        $notes = $result === 'rejected'
            ? $request->input('rejection_note')
            : $request->input('notes');

        $this->signatureService->submitInspection(
            $signingRequest,
            $request->user(),
            $result,
            $request->input('checklist'),
            $notes,
        );

        $message = $result === 'approved'
            ? "Wet ink document approved for {$signingRequest->signer_name}."
            : "Rejection sent to {$signingRequest->signer_name} with instructions to re-sign.";

        return redirect()->route('docuperfect.rental')
            ->with('status', $message);
    }

    // ──────────────────────────────────────────────
    // Agent approval gate
    // ──────────────────────────────────────────────

    /**
     * Show the agent review page for a completed party's signatures.
     */
    public function review(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();

        if ($template->status !== SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL) {
            return redirect()->route('docuperfect.rental')
                ->with('error', 'This document is not pending approval.');
        }

        $template->loadMissing(['requests', 'markers.signatures', 'signatures']);

        // Find the most recently completed non-agent request
        $completedRequest = $template->requests
            ->where('status', SignatureRequest::STATUS_COMPLETED)
            ->where('party_role', '!=', 'agent')
            ->sortByDesc('completed_at')
            ->first();

        // Determine the next party
        $order = $template->signing_order_json ?? ['agent', 'tenant', 'landlord'];
        $completedParties = $template->requests
            ->where('status', SignatureRequest::STATUS_COMPLETED)
            ->pluck('party_role')
            ->toArray();

        $nextParty = null;
        foreach ($order as $party) {
            if ($party !== 'agent' && !in_array($party, $completedParties)) {
                $nextParty = $party;
                break;
            }
        }

        // Get progress for the completed party
        $progress = $template->partyProgress();

        // Build page image URLs — use flattened images when available
        $docTemplate = $document->template;
        $flattenedPages = $template->flattened_pages_json ?? [];
        $hasFlattened = !empty($flattenedPages);
        $pageImages = [];
        $pageCount = $docTemplate ? $docTemplate->page_count : 0;

        for ($n = 0; $n < $pageCount; $n++) {
            if ($hasFlattened && isset($flattenedPages[$n])) {
                $pageImages[] = route('docuperfect.signatures.flattenedPage', ['templateId' => $template->id, 'page' => $n]);
            } elseif ($docTemplate) {
                $pageImages[] = route('docuperfect.page.image', ['id' => $docTemplate->id, 'page' => $n]);
            }
        }

        // Get all markers with signatures for display
        $allMarkers = $template->markers()
            ->with('signatures')
            ->orderBy('page_number')
            ->orderBy('sort_order')
            ->get();

        return view('docuperfect.signatures.review', [
            'document' => $document,
            'template' => $template,
            'completedRequest' => $completedRequest,
            'nextParty' => $nextParty,
            'progress' => $progress,
            'pageImages' => $pageImages,
            'pageCount' => $pageCount,
            'allMarkers' => $allMarkers,
            'hasFlattened' => $hasFlattened,
            'user' => $user,
        ]);
    }

    /**
     * Approve and advance to the next party (or complete the document).
     */
    public function approveAndAdvance(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();

        if ($template->status !== SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL) {
            return redirect()->route('docuperfect.rental')
                ->with('error', 'This document is not pending approval.');
        }

        $result = $this->signatureService->approveAndAdvance($template);

        if ($result['action'] === 'sent') {
            $nextName = $result['next_name'] ?? ucfirst($result['next_party']);
            return redirect()->route('docuperfect.rental')
                ->with('status', "Approved. Document sent to {$nextName} ({$result['next_party']}) for signing.");
        }

        return redirect()->route('docuperfect.rental')
            ->with('status', 'All signatures approved. Document completed!');
    }

    /**
     * Status check endpoint for dashboard polling.
     */
    public function statusCheck(Request $request)
    {
        $user = $request->user();

        $pendingApproval = SignatureTemplate::where('status', SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL)
            ->visibleTo($user)
            ->count();

        $lastUpdate = SignatureTemplate::visibleTo($user)
            ->max('updated_at');

        return response()->json([
            'pending_approval_count' => $pendingApproval,
            'last_update' => $lastUpdate,
        ]);
    }

    // ──────────────────────────────────────────────
    // Flattened page image serving
    // ──────────────────────────────────────────────

    /**
     * Serve a flattened page image for authenticated users.
     */
    public function flattenedPageImage(Request $request, $templateId, $page)
    {
        $template = SignatureTemplate::findOrFail($templateId);
        $this->authorizeDocument($request->user(), $template->document);

        $flattenedPages = $template->flattened_pages_json ?? [];
        $pageNum = (int) $page;

        if (!isset($flattenedPages[$pageNum])) {
            abort(404, 'Flattened page not found.');
        }

        $path = storage_path('app/' . $flattenedPages[$pageNum]);
        if (!file_exists($path)) {
            abort(404, 'Flattened page file not found.');
        }

        return response()->file($path, [
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    // ──────────────────────────────────────────────
    // Authorization helper
    // ──────────────────────────────────────────────

    private function authorizeDocument($user, Document $document): void
    {
        if ($user->isAdmin()) {
            return;
        }

        if ($user->isBranchManager()) {
            if ($document->branch_id !== $user->effectiveBranchId()) {
                abort(403);
            }
            return;
        }

        if ((int) $document->owner_id !== (int) $user->id) {
            abort(403);
        }
    }

    // ──────────────────────────────────────────────
    // Document Rejection / Redo
    // ──────────────────────────────────────────────

    public function reject(Request $request, $id)
    {
        $request->validate([
            'rejection_reason' => 'required|string|min:5',
            'action' => 'required|in:archive,revise',
        ]);

        $document = Document::findOrFail($id);
        $this->authorizeDocument($request, $document);

        $template = $document->signatureTemplate;

        if (!$template) {
            return back()->with('error', 'No signature template found for this document.');
        }

        // 1. Mark as rejected
        $template->update([
            'status' => SignatureTemplate::STATUS_REJECTED,
            'rejected_at' => now(),
            'rejection_reason' => $request->rejection_reason,
            'rejected_by' => auth()->id(),
        ]);

        // 2. Invalidate all pending signing requests
        $template->requests()
            ->whereIn('status', ['waiting', 'pending', 'viewed', 'partially_signed'])
            ->update([
                'status' => 'expired',
            ]);

        // 3. Log in audit trail
        SignatureAuditLog::log(
            $template,
            'document_rejected',
            SignatureAuditLog::ACTOR_USER,
            auth()->user()->name,
            auth()->user()->email,
            auth()->id(),
            null,
            $request->ip(),
            $request->userAgent(),
            [
                'reason' => $request->rejection_reason,
                'action' => $request->action,
            ]
        );

        // 4. If "Create revised version" — clone the document
        if ($request->action === 'revise') {
            $newDocument = $this->cloneDocumentForRevision($document);

            return redirect()->route('docuperfect.documents.edit', $newDocument->id)
                ->with('status', 'Document rejected. A revised copy has been created for editing.');
        }

        // 5. Archive action — just leave it rejected
        return redirect()->route('rental.signatures')
            ->with('status', 'Document rejected and archived.');
    }

    private function cloneDocumentForRevision(Document $original): Document
    {
        $new = $original->replicate(['archived_at']);
        $new->name = $original->name . ' (Revised)';
        $new->created_at = now();
        $new->save();

        // Copy page images if they exist
        $originalDir = "docuperfect/documents/{$original->id}/pages";
        $newDir = "docuperfect/documents/{$new->id}/pages";

        if (Storage::disk('local')->exists($originalDir)) {
            foreach (Storage::disk('local')->files($originalDir) as $file) {
                $filename = basename($file);
                Storage::disk('local')->copy($file, "{$newDir}/{$filename}");
            }
        }

        // Do NOT copy: signature_templates, signing_requests, signatures
        // The new document starts fresh for signing

        return $new;
    }
}
