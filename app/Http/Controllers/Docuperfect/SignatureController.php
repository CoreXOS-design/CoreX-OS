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

        // Determine template type for dynamic party labels (buyer/seller vs tenant/landlord)
        $templateType = $docTemplate?->template_type ?? 'rentals';

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
            'templateType' => $templateType,
        ]);
    }

    /**
     * Save parties for a document's signature template.
     */
    public function saveParties(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        // Determine template type for party role labels
        $templateType = $document->template?->template_type ?? 'rentals';
        $isSales = $templateType === 'sales';

        // Party role labels differ by template type
        $partyOneRole = $isSales ? 'buyer' : 'tenant';
        $partyTwoRole = $isSales ? 'seller' : 'landlord';

        $partyOneNotRequired = $request->boolean("{$partyOneRole}_not_required");
        $partyTwoNotRequired = $request->boolean("{$partyTwoRole}_not_required");

        // Build validation rules — only validate active parties
        $rules = [
            'agent_name' => 'required|string|max:255',
            'agent_email' => 'required|email|max:255',
            "{$partyOneRole}_not_required" => 'nullable|boolean',
            "{$partyTwoRole}_not_required" => 'nullable|boolean',
        ];

        if (!$partyOneNotRequired) {
            $rules["{$partyOneRole}_name"] = 'required|string|max:255';
            $rules["{$partyOneRole}_email"] = 'required|email|max:255';
            $rules["{$partyOneRole}_id_number"] = 'required|string|max:20';
            $rules["add_{$partyOneRole}_witness"] = 'nullable|boolean';
            $rules["{$partyOneRole}_witness_name"] = "required_if:add_{$partyOneRole}_witness,1|nullable|string|max:255";
            $rules["{$partyOneRole}_witness_email"] = "required_if:add_{$partyOneRole}_witness,1|nullable|email|max:255";
        }

        if (!$partyTwoNotRequired) {
            $rules["{$partyTwoRole}_name"] = 'required|string|max:255';
            $rules["{$partyTwoRole}_email"] = 'required|email|max:255';
            $rules["{$partyTwoRole}_id_number"] = 'required|string|max:20';
            $rules["add_{$partyTwoRole}_witness"] = 'nullable|boolean';
            $rules["{$partyTwoRole}_witness_name"] = "required_if:add_{$partyTwoRole}_witness,1|nullable|string|max:255";
            $rules["{$partyTwoRole}_witness_email"] = "required_if:add_{$partyTwoRole}_witness,1|nullable|email|max:255";
        }

        $request->validate($rules);

        // Build parties array — only include active parties
        $parties = [
            ['role' => 'agent', 'name' => $request->agent_name, 'email' => $request->agent_email, 'id_number' => null],
        ];

        $signingOrder = ['agent'];

        if (!$partyOneNotRequired) {
            $parties[] = [
                'role' => $partyOneRole,
                'name' => $request->input("{$partyOneRole}_name"),
                'email' => $request->input("{$partyOneRole}_email"),
                'id_number' => $request->input("{$partyOneRole}_id_number"),
            ];
            $signingOrder[] = $partyOneRole;

            if ($request->boolean("add_{$partyOneRole}_witness")) {
                $parties[] = [
                    'role' => "{$partyOneRole}_witness",
                    'name' => $request->input("{$partyOneRole}_witness_name"),
                    'email' => $request->input("{$partyOneRole}_witness_email"),
                    'id_number' => null,
                ];
            }
        }

        if (!$partyTwoNotRequired) {
            $parties[] = [
                'role' => $partyTwoRole,
                'name' => $request->input("{$partyTwoRole}_name"),
                'email' => $request->input("{$partyTwoRole}_email"),
                'id_number' => $request->input("{$partyTwoRole}_id_number"),
            ];
            $signingOrder[] = $partyTwoRole;

            if ($request->boolean("add_{$partyTwoRole}_witness")) {
                $parties[] = [
                    'role' => "{$partyTwoRole}_witness",
                    'name' => $request->input("{$partyTwoRole}_witness_name"),
                    'email' => $request->input("{$partyTwoRole}_witness_email"),
                    'id_number' => null,
                ];
            }
        }

        // All core (non-witness) roles
        $coreRoles = ['agent', 'tenant', 'landlord', 'buyer', 'seller'];

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
        $activeRoles = collect($parties)->pluck('role')->intersect($coreRoles)->all();

        foreach ($parties as $party) {
            // Only create requests for core signing roles
            if (!in_array($party['role'], $coreRoles)) {
                continue;
            }

            $existing = $template->requests()
                ->where('party_role', $party['role'])
                ->first();

            if ($existing) {
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
        $removableRoles = array_diff($coreRoles, ['agent']);
        $template->requests()
            ->whereIn('party_role', $removableRoles)
            ->whereNotIn('party_role', $activeRoles)
            ->delete();

        // Remove markers assigned to parties that are no longer active
        $template->markers()
            ->whereIn('assigned_party', $removableRoles)
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
            'signature_data' => 'nullable|string',
            'text_value' => 'nullable|string|max:1000',
            'signature_type' => 'nullable|string|in:drawn,typed',
        ]);

        // At least one of signature_data or text_value must be provided
        if (!$request->input('signature_data') && !$request->input('text_value')) {
            return response()->json(['ok' => false, 'error' => 'Signature data or text value required.'], 422);
        }

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
            $request->input('text_value'),
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

        // Determine next party from signing order
        $signingOrder = $template->signing_order_json ?? ['agent'];
        $nextPartyRole = null;
        foreach ($signingOrder as $role) {
            if ($role !== 'agent') {
                $nextPartyRole = $role;
                break;
            }
        }

        // Set status based on the next party
        $statusMap = [
            'tenant' => SignatureTemplate::STATUS_AWAITING_TENANT,
            'landlord' => SignatureTemplate::STATUS_AWAITING_LANDLORD,
            'buyer' => SignatureTemplate::STATUS_AWAITING_BUYER,
            'seller' => SignatureTemplate::STATUS_AWAITING_SELLER,
        ];
        $nextStatus = $nextPartyRole ? ($statusMap[$nextPartyRole] ?? SignatureTemplate::STATUS_SIGNING) : SignatureTemplate::STATUS_COMPLETED;
        $template->update(['status' => $nextStatus]);

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

        // Determine next party label for the success message
        $nextPartyLabel = $nextPartyRole ? ucfirst($nextPartyRole) : 'the next party';

        return redirect()->route('docuperfect.signatures.sendConfirmation', $document)
            ->with('success', "You have signed all your markers. Now send to {$nextPartyLabel}.");
    }

    /**
     * Show the send confirmation page (before sending to next party).
     */
    public function sendConfirmation(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();
        $parties = $template->parties_json ?? [];
        $signingOrder = $template->signing_order_json ?? [];

        // Find the first non-agent party (tenant/landlord for rentals, buyer/seller for sales)
        $nextPartyRole = null;
        foreach ($signingOrder as $role) {
            if ($role !== 'agent') {
                $nextPartyRole = $role;
                break;
            }
        }
        $nextParty = $nextPartyRole ? collect($parties)->firstWhere('role', $nextPartyRole) : null;

        return view('docuperfect.signatures.send-confirmation', [
            'document' => $document,
            'template' => $template,
            'tenant' => $nextParty, // keep 'tenant' key for backward compat with existing view
            'nextParty' => $nextParty,
            'nextPartyRole' => $nextPartyRole,
            'user' => $user,
        ]);
    }

    // ──────────────────────────────────────────────
    // Send + reminders
    // ──────────────────────────────────────────────

    /**
     * Send document for signature (handles initial send OR agent-complete → next party send).
     */
    public function sendForSignature(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();
        $templateType = $document->template?->template_type ?? 'rentals';
        $isSales = $templateType === 'sales';

        // If template is awaiting a party, send to that party
        $awaitingStatuses = [
            SignatureTemplate::STATUS_AWAITING_TENANT,
            SignatureTemplate::STATUS_AWAITING_LANDLORD,
            SignatureTemplate::STATUS_AWAITING_BUYER,
            SignatureTemplate::STATUS_AWAITING_SELLER,
        ];

        if (in_array($template->status, $awaitingStatuses)) {
            $currentRole = $template->currentPartyRole();
            $partyRequest = $currentRole
                ? $template->requests()->where('party_role', $currentRole)->first()
                : null;

            if ($partyRequest && $partyRequest->status === SignatureRequest::STATUS_WAITING) {
                if ($request->filled('message')) {
                    $partyRequest->update(['message' => $request->input('message')]);
                }
                $this->signatureService->sendSigningRequest($partyRequest);
            }

            $dashboardRoute = $isSales ? 'docuperfect.sales' : 'docuperfect.rental';
            $partyLabel = $currentRole ? ucfirst($currentRole) : 'next party';

            return redirect()->route($dashboardRoute)
                ->with('status', "Document sent to {$partyLabel} for signing.");
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

        return response()->file(Storage::disk('local')->path($path));
    }

    /**
     * Agent uploads a signed document on behalf of a party.
     */
    public function uploadOnBehalf(Request $request, Document $document, SignatureRequest $signingRequest)
    {
        $this->authorizeDocument($request->user(), $document);

        $request->validate([
            'files'          => 'required|array|min:1',
            'files.*'        => 'file|mimes:pdf,jpg,jpeg,png|max:20480',
            'receive_method' => 'required|in:whatsapp,email,in_person',
        ]);

        // Store uploaded files
        $paths = [];
        foreach ($request->file('files') as $file) {
            $paths[] = $file->store("docuperfect/wet-ink-uploads/{$signingRequest->id}", 'local');
        }

        $signingRequest->update([
            'signing_method'      => 'wet_ink',
            'wet_ink_upload_path' => json_encode($paths),
            'wet_ink_status'      => SignatureRequest::WET_INK_UPLOADED_PENDING_REVIEW,
        ]);

        $template = $signingRequest->template;

        SignatureAuditLog::log(
            $template,
            SignatureAuditLog::ACTION_WET_INK_UPLOADED,
            SignatureAuditLog::ACTOR_USER,
            $request->user()->name,
            $request->user()->email,
            $request->user()->id,
            $signingRequest->id,
            $request->ip(),
            $request->userAgent(),
            [
                'uploaded_on_behalf' => true,
                'receive_method' => $request->input('receive_method'),
                'file_count' => count($paths),
            ],
        );

        // Auto-approve: skip the wet-ink review step, approve immediately
        if ($request->boolean('auto_approve')) {
            $this->signatureService->approveUploadOnBehalf($signingRequest, $request->user());

            $templateType = $document->template?->template_type ?? 'rentals';
            $dashboardRoute = $templateType === 'sales' ? 'docuperfect.sales' : 'docuperfect.rental';

            return redirect()->route($dashboardRoute)
                ->with('status', 'Uploaded and approved for ' . $signingRequest->signer_name . '. Signing advanced.');
        }

        return redirect()->route('docuperfect.signatures.wetInkReview', [
            'document' => $document->id,
            'signingRequest' => $signingRequest->id,
        ])->with('status', 'Document uploaded on behalf of ' . $signingRequest->signer_name . '. Please review.');
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
            ? "Wet ink approved for {$signingRequest->signer_name}. Signing flow advanced."
            : "Rejection sent to {$signingRequest->signer_name} with instructions to re-sign.";

        // Redirect to the appropriate dashboard based on template type
        $templateType = $document->template?->template_type ?? 'rentals';
        $dashboardRoute = $templateType === 'sales' ? 'docuperfect.sales' : 'docuperfect.rental';

        return redirect()->route($dashboardRoute)
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
            $templateType = $document->template?->template_type ?? 'rentals';
            $dashboardRoute = $templateType === 'sales' ? 'docuperfect.sales' : 'docuperfect.rental';
            return redirect()->route($dashboardRoute)
                ->with('error', 'This document is not pending approval.');
        }

        $result = $this->signatureService->approveAndAdvance($template);

        $templateType = $document->template?->template_type ?? 'rentals';
        $dashboardRoute = $templateType === 'sales' ? 'docuperfect.sales' : 'docuperfect.rental';

        if ($result['action'] === 'sent') {
            $nextName = $result['next_name'] ?? ucfirst($result['next_party']);
            return redirect()->route($dashboardRoute)
                ->with('status', "Approved. Document sent to {$nextName} ({$result['next_party']}) for signing.");
        }

        return redirect()->route($dashboardRoute)
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

        $path = Storage::disk('local')->path($flattenedPages[$pageNum]);
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
        $this->authorizeDocument($request->user(), $document);

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
