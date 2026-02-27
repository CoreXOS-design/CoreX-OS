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
use App\Models\User;
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

        // HARD BLOCK: Sales templates cannot use electronic signatures
        $isSalesTemplate = $docTemplate && $docTemplate->template_type === 'sales';

        // Determine which step to show
        $parties = $template->parties_json ?? [];
        $step = !empty($parties) ? 2 : 1;

        // If step query param is provided, allow going back to step 1
        if ($request->query('step') === '1') {
            $step = 1;
        }

        // Candidate co-signing: detect candidate status and find eligible co-signers
        $isCandidate = $user->isCandidate();
        $branchManager = null;
        $fullStatusAgents = collect();
        if ($isCandidate && $user->branch_id) {
            $branchManager = User::where('branch_id', $user->branch_id)
                ->where('role', 'branch_manager')
                ->where('is_active', true)
                ->first();

            $fullStatusAgents = User::where('branch_id', $user->branch_id)
                ->where('is_active', true)
                ->where('id', '!=', $user->id)
                ->where(function ($q) {
                    $q->where('role', 'branch_manager')
                      ->orWhere(function ($q2) {
                          $q2->whereNotNull('designation')
                             ->where('designation', 'not like', '%Candidate%');
                      });
                })
                ->orderByRaw("CASE WHEN role = 'branch_manager' THEN 0 ELSE 1 END")
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role', 'designation']);
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
            'isSalesTemplate' => $isSalesTemplate,
            'isCandidate' => $isCandidate,
            'branchManager' => $branchManager,
            'fullStatusAgents' => $fullStatusAgents,
            'cosignMode' => $template->cosign_mode,
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

        $hasCosigner = $request->boolean('has_cosigner');

        // Build validation rules — only validate active parties
        $rules = [
            'agent_name' => 'required|string|max:255',
            'agent_email' => 'required|email|max:255',
            'tenant_not_required' => 'nullable|boolean',
            'landlord_not_required' => 'nullable|boolean',
            'has_cosigner' => 'nullable|boolean',
        ];

        if ($hasCosigner) {
            $rules['cosigner_user_id'] = 'required|integer|exists:users,id';
            $rules['cosign_mode'] = 'required|in:together,sequential';
        }

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
        $cosignMode = null;

        // Add cosigner (Full Status/BM) if candidate agent has one
        if ($hasCosigner) {
            $cosigner = User::findOrFail($request->cosigner_user_id);
            $parties[] = ['role' => 'cosigner', 'name' => $cosigner->name, 'email' => $cosigner->email, 'id_number' => null];
            $signingOrder[] = 'cosigner';
            $cosignMode = $request->input('cosign_mode');
        }

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
            'cosign_mode' => $cosignMode,
            'document_hash' => $hash,
        ]);

        // Create signing requests for active core parties only (agent, cosigner, tenant, landlord)
        $coreRoles = ['agent', 'cosigner', 'tenant', 'landlord'];
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
            ->whereIn('party_role', ['cosigner', 'tenant', 'landlord'])
            ->whereNotIn('party_role', $activeRoles)
            ->delete();

        // Remove markers assigned to parties that are no longer active
        $template->markers()
            ->whereIn('assigned_party', ['cosigner', 'tenant', 'landlord'])
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

        // HARD BLOCK: Sales templates cannot have electronic signature markers
        $docTemplate = $document->template;
        if ($docTemplate && $docTemplate->template_type === 'sales') {
            return response()->json([
                'ok' => false,
                'error' => 'Electronic signature markers are not permitted on sales documents. Sales documents must use wet-ink signing only.',
            ], 403);
        }

        $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();

        // Build allowed parties from the template's active parties
        $allowedParties = collect($template->parties_json ?? [])
            ->pluck('role')
            ->intersect(['agent', 'cosigner', 'tenant', 'landlord'])
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
     * Show the internal signing page (for agent or cosigner).
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

        // Determine if this user is the agent or the cosigner
        $parties = $template->parties_json ?? [];
        $cosignerParty = collect($parties)->firstWhere('role', 'cosigner');
        $isCosigner = $cosignerParty && $cosignerParty['email'] === $user->email;
        $signerRole = $isCosigner ? 'cosigner' : 'agent';

        // Get all markers with their signatures
        $allMarkers = $template->markers()
            ->with(['signatures' => fn($q) => $q->select('id', 'signature_marker_id', 'signature_data', 'signature_type', 'signed_at')])
            ->orderBy('page_number')
            ->orderBy('sort_order')
            ->get();

        // Current signer's markers count
        $signerMarkers = $allMarkers->where('assigned_party', $signerRole);
        $signedCount = $signerMarkers->filter(fn($m) => $m->signatures->isNotEmpty())->count();
        $totalSigner = $signerMarkers->count();

        // Build page image URLs — use flattened images when available (for cosigner in sequential mode)
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

        return view('docuperfect.signatures.sign', [
            'document' => $document,
            'template' => $template,
            'allMarkers' => $allMarkers,
            'signedCount' => $signedCount,
            'totalAgent' => $totalSigner,
            'allAgentSigned' => $signedCount >= $totalSigner && $totalSigner > 0,
            'pageImages' => $pageImages,
            'pageCount' => $pageCount,
            'user' => $user,
            'signerRole' => $signerRole,
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

        // Verify marker is assigned to agent or cosigner (internal signers)
        $parties = $template->parties_json ?? [];
        $cosignerParty = collect($parties)->firstWhere('role', 'cosigner');
        $isCosigner = $cosignerParty && $cosignerParty['email'] === $user->email;
        $expectedRole = $isCosigner ? 'cosigner' : 'agent';

        if ($marker->assigned_party !== $expectedRole) {
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

        // Check if all markers for this signer are now signed
        $allAgentSigned = $this->signatureService->isPartyComplete($template, $expectedRole);
        $signedCount = $template->signatures()
            ->whereHas('marker', fn($q) => $q->where('assigned_party', $expectedRole))
            ->count();
        $totalAgent = $template->markers()
            ->where('assigned_party', $expectedRole)
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
     * Complete internal signing for a document (agent or cosigner).
     */
    public function signComplete(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        $template = SignatureTemplate::where('document_id', $document->id)
            ->with(['document.template', 'markers.signatures'])
            ->firstOrFail();

        // Determine if this user is the agent or the cosigner
        $parties = $template->parties_json ?? [];
        $cosignerParty = collect($parties)->firstWhere('role', 'cosigner');
        $isCosigner = $cosignerParty && $cosignerParty['email'] === $user->email;
        $signerRole = $isCosigner ? 'cosigner' : 'agent';
        $cosignMode = $template->cosign_mode; // 'together', 'sequential', or null

        // Verify all markers for this signer are signed
        if (!$this->signatureService->isPartyComplete($template, $signerRole)) {
            return redirect()->back()->with('error', 'Sign all your markers before completing.');
        }

        // FLATTEN: Bake field values + signer signatures into page images
        $flattener = app(DocumentFlattener::class);

        // Only flatten fields on the first signer (agent), not again for cosigner
        if ($signerRole === 'agent') {
            $flattener->flattenFields($template);
        }

        // Flatten this signer's signatures onto the page images
        $signerMarkers = $template->markers->where('assigned_party', $signerRole);
        foreach ($signerMarkers as $marker) {
            $sig = $marker->signatures->first();
            if ($sig) {
                $template->refresh();
                $flattener->flattenSignature($template, $marker, $sig);
            }
        }

        // Mark this signer's request as completed
        $signerRequest = $template->requests()
            ->where('party_role', $signerRole)
            ->where('status', '!=', SignatureRequest::STATUS_COMPLETED)
            ->first();

        if ($signerRequest) {
            $signerRequest->update([
                'status' => SignatureRequest::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
        }

        SignatureAuditLog::log(
            $template,
            SignatureAuditLog::ACTION_COMPLETED,
            SignatureAuditLog::ACTOR_USER,
            $user->name,
            $user->email,
            $user->id,
            $signerRequest?->id,
            $request->ip(),
            $request->userAgent(),
            [
                'phase' => $signerRole . '_signing',
                'total_signatures' => $template->signatures()
                    ->whereHas('marker', fn($q) => $q->where('assigned_party', $signerRole))
                    ->count(),
            ],
        );

        // --- Determine next step based on cosign mode ---

        // No cosigner: standard flow (advance to tenant)
        if (!$cosignMode) {
            $template->update(['status' => SignatureTemplate::STATUS_AWAITING_TENANT]);
            return redirect()->route('docuperfect.signatures.sendConfirmation', $document)
                ->with('success', 'You have signed all your markers. Now send to the tenant.');
        }

        // TOGETHER mode: both agent and cosigner sign in parallel
        if ($cosignMode === 'together') {
            $agentDone = $this->signatureService->isPartyComplete($template, 'agent');
            $cosignerDone = $this->signatureService->isPartyComplete($template, 'cosigner');

            // Check if agent request is completed
            $agentRequestCompleted = $template->requests()
                ->where('party_role', 'agent')
                ->where('status', SignatureRequest::STATUS_COMPLETED)
                ->exists();
            $cosignerRequestCompleted = $template->requests()
                ->where('party_role', 'cosigner')
                ->where('status', SignatureRequest::STATUS_COMPLETED)
                ->exists();

            if ($agentDone && $cosignerDone && $agentRequestCompleted && $cosignerRequestCompleted) {
                // Both done — advance to external parties
                $template->update(['status' => SignatureTemplate::STATUS_AWAITING_TENANT]);
                return redirect()->route('docuperfect.signatures.sendConfirmation', $document)
                    ->with('success', 'Both agent and co-signer have signed. Now send to the next party.');
            }

            // One is still pending — stay in signing status
            $template->update(['status' => SignatureTemplate::STATUS_SIGNING]);
            $otherRole = $signerRole === 'agent' ? 'co-signer' : 'agent';
            return redirect()->route('docuperfect.signatures.sign', $document)
                ->with('success', "You have signed all your markers. Waiting for the {$otherRole} to complete.");
        }

        // SEQUENTIAL mode: agent signs first, then cosigner
        if ($cosignMode === 'sequential') {
            if ($signerRole === 'agent') {
                // Agent done — advance to cosigner
                $template->update(['status' => SignatureTemplate::STATUS_AWAITING_COSIGNER]);

                // Send notification to cosigner
                $cosignerRequest = $template->requests()
                    ->where('party_role', 'cosigner')
                    ->first();
                if ($cosignerRequest && $cosignerRequest->status === SignatureRequest::STATUS_WAITING) {
                    $this->signatureService->sendSigningRequest($cosignerRequest);
                }

                return redirect()->route('docuperfect.rental')
                    ->with('success', 'You have signed all your markers. Document sent to co-signer for review.');
            }

            // Cosigner done — advance to external parties
            $template->update(['status' => SignatureTemplate::STATUS_AWAITING_TENANT]);
            return redirect()->route('docuperfect.signatures.sendConfirmation', $document)
                ->with('success', 'Co-signing complete. Now send to the next party.');
        }

        // Fallback: standard flow
        $template->update(['status' => SignatureTemplate::STATUS_AWAITING_TENANT]);
        return redirect()->route('docuperfect.signatures.sendConfirmation', $document)
            ->with('success', 'You have signed all your markers. Now send to the tenant.');
    }

    /**
     * Show the send-to-next-party confirmation page.
     */
    public function sendConfirmation(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();
        $parties = $template->parties_json ?? [];

        // Find the next external party (first non-agent, non-cosigner who hasn't completed)
        $internalRoles = ['agent', 'cosigner'];
        $completedParties = $template->requests()
            ->where('status', SignatureRequest::STATUS_COMPLETED)
            ->pluck('party_role')
            ->toArray();

        $order = $template->signing_order_json ?? ['agent', 'tenant', 'landlord'];
        $nextPartyRole = null;
        foreach ($order as $party) {
            if (!in_array($party, $internalRoles) && !in_array($party, $completedParties)) {
                $nextPartyRole = $party;
                break;
            }
        }

        $tenant = $nextPartyRole
            ? collect($parties)->firstWhere('role', $nextPartyRole)
            : collect($parties)->firstWhere('role', 'tenant');

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

        // If template is awaiting an external party, send to the next one
        if (in_array($template->status, [
            SignatureTemplate::STATUS_AWAITING_TENANT,
            SignatureTemplate::STATUS_AWAITING_LANDLORD,
        ])) {
            // Find the next external party in signing order
            $internalRoles = ['agent', 'cosigner'];
            $order = $template->signing_order_json ?? ['agent', 'tenant', 'landlord'];
            $completedParties = $template->requests()
                ->where('status', SignatureRequest::STATUS_COMPLETED)
                ->pluck('party_role')
                ->toArray();

            $nextPartyRole = null;
            foreach ($order as $party) {
                if (!in_array($party, $internalRoles) && !in_array($party, $completedParties)) {
                    $nextPartyRole = $party;
                    break;
                }
            }

            if ($nextPartyRole) {
                $nextRequest = $template->requests()
                    ->where('party_role', $nextPartyRole)
                    ->first();

                if ($nextRequest && $nextRequest->status === SignatureRequest::STATUS_WAITING) {
                    if ($request->filled('message')) {
                        $nextRequest->update(['message' => $request->input('message')]);
                    }
                    $this->signatureService->sendSigningRequest($nextRequest);
                }
            }

            $partyLabel = $nextPartyRole ? ucfirst(str_replace('_', ' ', $nextPartyRole)) : 'next party';
            return redirect()->route('docuperfect.rental')
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
    // Agent upload on behalf
    // ──────────────────────────────────────────────

    /**
     * Upload wet ink document on behalf of a party (agent received via email/WhatsApp/in-person).
     */
    public function uploadOnBehalf(Request $request, Document $document, SignatureRequest $signingRequest)
    {
        $this->authorizeDocument($request->user(), $document);

        if ($signingRequest->status === SignatureRequest::STATUS_COMPLETED) {
            return redirect()->back()->with('error', 'This party has already completed signing.');
        }

        $request->validate([
            'files' => 'required|array|min:1',
            'files.*' => 'file|mimes:pdf,jpg,jpeg,png,heic|max:10240',
            'upload_method' => 'required|in:email,whatsapp,in_person',
        ]);

        $paths = [];
        foreach ($request->file('files') as $file) {
            $path = $file->store("docuperfect/wet-ink-uploads/{$signingRequest->id}", 'local');
            $paths[] = $path;
        }

        // On re-upload after rejection, replace all files
        $existingPaths = [];
        if ($signingRequest->wet_ink_status !== SignatureRequest::WET_INK_REJECTED) {
            $existingPaths = $signingRequest->wet_ink_upload_path
                ? (json_decode($signingRequest->wet_ink_upload_path, true) ?: [])
                : [];
        }

        $allPaths = array_merge($existingPaths, $paths);

        $signingRequest->update([
            'signing_method' => 'wet_ink',
            'wet_ink_upload_path' => json_encode($allPaths),
            'wet_ink_upload_method' => $request->input('upload_method'),
            'wet_ink_status' => SignatureRequest::WET_INK_UPLOADED_PENDING_REVIEW,
        ]);

        SignatureAuditLog::log(
            $signingRequest->template,
            SignatureAuditLog::ACTION_WET_INK_UPLOADED,
            SignatureAuditLog::ACTOR_USER,
            $request->user()->name,
            $request->user()->email,
            $request->user()->id,
            $signingRequest->id,
            $request->ip(),
            $request->userAgent(),
            [
                'upload_method' => $request->input('upload_method'),
                'file_count' => count($paths),
                'on_behalf_of' => $signingRequest->signer_name,
            ],
        );

        return redirect()->route('docuperfect.signatures.wetInkReview', [
            'document' => $document->id,
            'signingRequest' => $signingRequest->id,
        ])->with('status', "Document uploaded on behalf of {$signingRequest->signer_name}. Review it now.");
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

        // Find the most recently completed non-internal request
        $internalRoles = ['agent', 'cosigner'];
        $completedRequest = $template->requests
            ->where('status', SignatureRequest::STATUS_COMPLETED)
            ->whereNotIn('party_role', $internalRoles)
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
            if (!in_array($party, $internalRoles) && !in_array($party, $completedParties)) {
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
