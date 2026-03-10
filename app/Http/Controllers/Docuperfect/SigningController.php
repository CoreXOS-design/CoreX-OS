<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureMarker;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Services\Docuperfect\DocumentFlattener;
use App\Services\Docuperfect\SignatureService;
use App\Services\WebTemplateFieldPartyMap;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SigningController extends Controller
{
    protected SignatureService $signatureService;

    public function __construct(SignatureService $signatureService)
    {
        $this->signatureService = $signatureService;
    }

    /**
     * Show the external signing page (no auth — token-based).
     */
    public function show(Request $request, $token)
    {
        $signingRequest = SignatureRequest::where('token', $token)
            ->with(['template.document', 'template.markers.signatures', 'template.creator'])
            ->firstOrFail();

        // Expired
        if ($signingRequest->isExpired()) {
            return view('docuperfect.signatures.external.expired', [
                'request' => $signingRequest,
            ]);
        }

        // Already completed
        if ($signingRequest->status === SignatureRequest::STATUS_COMPLETED) {
            return view('docuperfect.signatures.external.already-completed', [
                'request' => $signingRequest,
            ]);
        }

        // Declined
        if ($signingRequest->status === SignatureRequest::STATUS_DECLINED) {
            return view('docuperfect.signatures.external.expired', [
                'request' => $signingRequest,
                'declined' => true,
            ]);
        }

        // Mark as viewed if pending (first view)
        if ($signingRequest->status === SignatureRequest::STATUS_PENDING) {
            $signingRequest->update([
                'status' => SignatureRequest::STATUS_VIEWED,
                'viewed_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            SignatureAuditLog::log(
                $signingRequest->template,
                SignatureAuditLog::ACTION_VIEWED,
                SignatureAuditLog::ACTOR_SIGNER,
                $signingRequest->signer_name,
                $signingRequest->signer_email,
                requestId: $signingRequest->id,
                ip: $request->ip(),
                ua: $request->userAgent(),
            );
        }

        // Identity verification gate — only if signer has an ID number on file
        if (!session("signing_verified_{$token}") && !empty($signingRequest->signer_id_number)) {
            return view('docuperfect.signatures.external.verify', [
                'request' => $signingRequest,
            ]);
        }

        $template = $signingRequest->template;
        $document = $template->document;

        // Get this party's markers
        $myMarkers = $template->markers()
            ->with('signatures')
            ->where('assigned_party', $signingRequest->party_role)
            ->orderBy('page_number')
            ->orderBy('sort_order')
            ->get();

        $signedCount = $myMarkers->filter(fn($m) => $m->signatures->isNotEmpty())->count();
        $totalMarkers = $myMarkers->where('required', true)->count();

        // Get all markers for display (other parties' markers shown as context)
        $allMarkers = $template->markers()
            ->with('signatures')
            ->orderBy('page_number')
            ->orderBy('sort_order')
            ->get();

        // Detect web template rendering
        $docTemplate = $document->template;
        $isWebTemplate = $docTemplate && $docTemplate->render_type === 'web' && $docTemplate->blade_view;
        $webTemplateHtml = '';
        $editableFields = [];

        if ($isWebTemplate) {
            $webTemplateData = $document->web_template_data ?? [];

            if (!empty($webTemplateData['merged_html'])) {
                // Pack document — use pre-rendered merged HTML
                $webTemplateHtml = $webTemplateData['merged_html'];
            } else {
                // Single template — render blade view normally
                $viewData = $webTemplateData;
                $fullHtml = view($docTemplate->blade_view, $viewData)->render();
                $styles = '';
                preg_match_all('/<style[^>]*>.*?<\/style>/si', $fullHtml, $styleMatches);
                if (!empty($styleMatches[0])) {
                    $styles = implode("\n", $styleMatches[0]);
                }
                if (preg_match('/<body[^>]*>(.*)<\/body>/si', $fullHtml, $bodyMatch)) {
                    $webTemplateHtml = trim($styles . "\n" . $bodyMatch[1]);
                } else {
                    $webTemplateHtml = trim($styles . "\n" . $fullHtml);
                }
            }

            // Determine which fields this signer can edit
            $editableFields = WebTemplateFieldPartyMap::getEditableFields($signingRequest->party_role);
        }

        // Build page image URLs — use flattened images when available (PDF path)
        $flattenedPages = $template->flattened_pages_json ?? [];
        $hasFlattened = !empty($flattenedPages);
        $pageImages = [];
        $pageCount = !empty($flattenedPages) ? count($flattenedPages) : ($docTemplate ? $docTemplate->page_count : 0);

        if (!$isWebTemplate) {
            for ($n = 0; $n < $pageCount; $n++) {
                if ($hasFlattened && isset($flattenedPages[$n])) {
                    $pageImages[] = route('signatures.external.flattenedPage', ['token' => $token, 'page' => $n]);
                } elseif ($docTemplate) {
                    $pageImages[] = route('docuperfect.page.image', ['id' => $docTemplate->id, 'page' => $n]);
                }
            }
        }

        // Check if wet ink upload is pending review
        $wetInkPendingReview = $signingRequest->signing_method === 'wet_ink'
            && $signingRequest->wet_ink_status === SignatureRequest::WET_INK_UPLOADED_PENDING_REVIEW;

        // Check if wet ink was rejected (needs re-upload)
        $wetInkRejected = $signingRequest->wet_ink_status === SignatureRequest::WET_INK_REJECTED;

        return view('docuperfect.signatures.external.sign', [
            'request' => $signingRequest,
            'template' => $template,
            'document' => $document,
            'allMarkers' => $allMarkers,
            'myMarkers' => $myMarkers,
            'signedCount' => $signedCount,
            'totalMarkers' => $totalMarkers,
            'pageImages' => $pageImages,
            'pageCount' => $pageCount,
            'wetInkPendingReview' => $wetInkPendingReview,
            'wetInkRejected' => $wetInkRejected,
            'hasFlattened' => $hasFlattened,
            'isWebTemplate' => $isWebTemplate,
            'webTemplateHtml' => $webTemplateHtml,
            'editableFields' => $editableFields,
            'token' => $token,
        ]);
    }

    /**
     * Verify signer identity (ID/passport number only).
     */
    public function verify(Request $request, $token)
    {
        $signingRequest = SignatureRequest::where('token', $token)
            ->with('template')
            ->firstOrFail();

        if ($signingRequest->isExpired()) {
            return redirect()->route('signatures.external', $token);
        }

        $request->validate([
            'id_number' => 'required|string|min:3|max:20',
        ]);

        // Normalized, case-insensitive comparison
        $submittedId = strtolower(trim($request->id_number));
        $expectedId = strtolower(trim($signingRequest->signer_id_number));

        if ($submittedId !== $expectedId) {
            SignatureAuditLog::log(
                $signingRequest->template,
                'identity_verification_failed',
                SignatureAuditLog::ACTOR_SIGNER,
                $signingRequest->signer_name,
                $signingRequest->signer_email,
                requestId: $signingRequest->id,
                ip: $request->ip(),
                ua: $request->userAgent(),
                metadata: ['id_match' => false],
            );

            return redirect()->back()
                ->withInput()
                ->with('error', 'The ID number does not match our records. Please try again.');
        }

        // Store verification in session
        session(["signing_verified_{$token}" => true]);

        SignatureAuditLog::log(
            $signingRequest->template,
            'identity_verified',
            SignatureAuditLog::ACTOR_SIGNER,
            $signingRequest->signer_name,
            $signingRequest->signer_email,
            requestId: $signingRequest->id,
            ip: $request->ip(),
            ua: $request->userAgent(),
        );

        return redirect()->route('signatures.external', ['token' => $token]);
    }

    /**
     * Choose signing method (electronic or wet ink).
     */
    public function chooseMethod(Request $request, $token)
    {
        $signingRequest = SignatureRequest::where('token', $token)->firstOrFail();

        if ($signingRequest->isExpired()) {
            return response()->json(['ok' => false, 'error' => 'Signing link has expired.'], 410);
        }

        $request->validate([
            'method' => 'required|in:electronic,wet_ink',
        ]);

        $signingRequest->update([
            'signing_method' => $request->method,
        ]);

        if ($request->method === 'wet_ink') {
            $signingRequest->update([
                'wet_ink_status' => SignatureRequest::WET_INK_PENDING_UPLOAD,
            ]);
        }

        return response()->json(['ok' => true, 'method' => $request->method]);
    }

    /**
     * Capture a signature on a specific marker (external).
     */
    public function capture(Request $request, $token, SignatureMarker $marker)
    {
        $signingRequest = SignatureRequest::where('token', $token)
            ->with('template')
            ->firstOrFail();

        if ($signingRequest->isExpired()) {
            return response()->json(['ok' => false, 'error' => 'Signing link has expired.'], 410);
        }

        // Verify session
        if (!session("signing_verified_{$token}")) {
            return response()->json(['ok' => false, 'error' => 'Identity not verified.'], 403);
        }

        // Verify marker belongs to this party
        if ($marker->assigned_party !== $signingRequest->party_role) {
            return response()->json(['ok' => false, 'error' => 'This marker is not assigned to you.'], 403);
        }

        // Soft hash check — log warning but don't block signing
        // Hash is recalculated before sending to each external party
        if (!$this->signatureService->verifyDocumentHash($signingRequest->template)) {
            \Log::warning('Document hash mismatch during signing', [
                'template_id' => $signingRequest->template->id,
                'signer' => $signingRequest->signer_name,
                'party_role' => $signingRequest->party_role,
            ]);
        }

        $request->validate([
            'signature_data' => 'nullable|string',
            'text_value' => 'nullable|string|max:1000',
            'signature_type' => 'nullable|string|in:drawn,typed',
        ]);

        // At least one of signature_data or text_value must be provided
        if (!$request->input('signature_data') && !$request->input('text_value')) {
            return response()->json(['ok' => false, 'error' => 'Signature data or text value required.'], 422);
        }

        $signature = $this->signatureService->captureSignature(
            $marker,
            $request->input('signature_data'),
            $signingRequest->signer_name,
            $signingRequest->signer_email,
            $request->ip(),
            $request->userAgent(),
            $signingRequest,
            null,
            $request->input('signature_type', 'drawn'),
            $request->input('text_value'),
        );

        // FLATTEN: Bake this signature into the page image immediately
        $template = $signingRequest->template;
        $template->refresh(); // reload flattened_pages_json
        app(DocumentFlattener::class)->flattenSignature($template, $marker, $signature);

        // Update request status to partially_signed if not already
        if (!in_array($signingRequest->status, [
            SignatureRequest::STATUS_PARTIALLY_SIGNED,
            SignatureRequest::STATUS_COMPLETED,
        ])) {
            $signingRequest->update(['status' => SignatureRequest::STATUS_PARTIALLY_SIGNED]);
        }

        $allSigned = $this->signatureService->isPartyComplete($template, $signingRequest->party_role);

        $signedCount = $template->signatures()
            ->whereHas('marker', fn($q) => $q->where('assigned_party', $signingRequest->party_role))
            ->count();
        $totalRequired = $template->markers()
            ->where('assigned_party', $signingRequest->party_role)
            ->where('required', true)
            ->count();

        return response()->json([
            'ok' => true,
            'signature_id' => $signature->id,
            'all_signed' => $allSigned,
            'signed_count' => $signedCount,
            'total_required' => $totalRequired,
        ]);
    }

    /**
     * Save signer-completed field values back to the document.
     * Only allows updating fields assigned to the signer's party role.
     */
    public function saveFields(Request $request, $token)
    {
        $signingRequest = SignatureRequest::where('token', $token)
            ->with('template.document')
            ->firstOrFail();

        if ($signingRequest->isExpired()) {
            return response()->json(['ok' => false, 'error' => 'Signing link has expired.'], 410);
        }

        $document = $signingRequest->template->document;
        if (!$document) {
            return response()->json(['ok' => false, 'error' => 'Document not found.'], 404);
        }

        $incomingFields = $request->input('fields', []);
        $partyRole = $signingRequest->party_role;
        $existingFields = $document->fields_json ?? [];

        // Role aliases: assignedTo may use "lessor"/"lessee" while party_role uses "landlord"/"tenant"
        $roleAliases = ['lessor' => 'landlord', 'lessee' => 'tenant'];

        // Build a map of existing fields by ID for quick lookup
        $fieldMap = [];
        foreach ($existingFields as $idx => $field) {
            if (isset($field['id'])) {
                $fieldMap[$field['id']] = $idx;
            }
        }

        // Only update fields that are assigned to this signer's role
        foreach ($incomingFields as $incoming) {
            $id = $incoming['id'] ?? null;
            if (!$id || !isset($fieldMap[$id])) continue;

            $idx = $fieldMap[$id];
            $assignedTo = $existingFields[$idx]['assignedTo'] ?? 'creator';
            $normalizedAssignedTo = $roleAliases[$assignedTo] ?? $assignedTo;
            if ($normalizedAssignedTo !== $partyRole) continue;

            // Update allowed value fields based on type
            $type = $existingFields[$idx]['type'] ?? 'placeholder';
            if (in_array($type, ['placeholder', 'date'])) {
                $existingFields[$idx]['value'] = $incoming['value'] ?? '';
            } elseif (in_array($type, ['selection', 'tick'])) {
                $existingFields[$idx]['selectedValue'] = $incoming['selectedValue'] ?? null;
            } elseif ($type === 'strikethrough') {
                $existingFields[$idx]['active'] = !empty($incoming['active']);
            } elseif ($type === 'condition') {
                $existingFields[$idx]['text'] = $incoming['text'] ?? '';
            }
        }

        $document->update(['fields_json' => $existingFields]);

        SignatureAuditLog::create([
            'signature_template_id' => $signingRequest->template->id,
            'action' => 'fields_saved',
            'actor_type' => SignatureAuditLog::ACTOR_SIGNER,
            'actor_name' => $signingRequest->signer_name,
            'actor_email' => $signingRequest->signer_email,
            'actor_ip_address' => $request->ip(),
            'actor_user_agent' => $request->userAgent(),
            'signature_request_id' => $signingRequest->id,
            'metadata_json' => ['party_role' => $partyRole],
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Save web template field values back to the document.
     * Merges with existing web_template_data — only updates fields
     * assigned to the signer's party role.
     */
    public function saveWebFields(Request $request, $token)
    {
        $signingRequest = SignatureRequest::where('token', $token)
            ->with('template.document')
            ->firstOrFail();

        if ($signingRequest->isExpired()) {
            return response()->json(['ok' => false, 'error' => 'Signing link has expired.'], 410);
        }

        $document = $signingRequest->template->document;
        if (!$document) {
            return response()->json(['ok' => false, 'error' => 'Document not found.'], 404);
        }

        $incomingFields = $request->input('fields', []);
        $partyRole = $signingRequest->party_role;

        // Only allow updating fields assigned to this signer's party
        $allowedFields = WebTemplateFieldPartyMap::getEditableFields($partyRole);

        $existingData = $document->web_template_data ?? [];
        $updated = false;

        foreach ($incomingFields as $fieldName => $value) {
            if (!is_string($fieldName) || !in_array($fieldName, $allowedFields, true)) {
                continue;
            }
            $existingData[$fieldName] = $value;
            $updated = true;
        }

        if ($updated) {
            $document->update(['web_template_data' => $existingData]);
        }

        SignatureAuditLog::create([
            'signature_template_id' => $signingRequest->template->id,
            'action' => 'web_fields_saved',
            'actor_type' => SignatureAuditLog::ACTOR_SIGNER,
            'actor_name' => $signingRequest->signer_name,
            'actor_email' => $signingRequest->signer_email,
            'actor_ip_address' => $request->ip(),
            'actor_user_agent' => $request->userAgent(),
            'signature_request_id' => $signingRequest->id,
            'metadata_json' => ['party_role' => $partyRole, 'field_count' => count($incomingFields)],
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Complete external signing (all markers done for this party).
     */
    public function complete(Request $request, $token)
    {
        $signingRequest = SignatureRequest::where('token', $token)
            ->with('template')
            ->firstOrFail();

        if ($signingRequest->isExpired()) {
            return response()->json(['ok' => false, 'error' => 'Signing link has expired.'], 410);
        }

        $template = $signingRequest->template;
        $party = $signingRequest->party_role;

        // Validate required fields assigned to this signer are completed
        $roleAliases = ['lessor' => 'landlord', 'lessee' => 'tenant'];
        $document = $template->document;
        $docFields = $document->fields_json ?? [];
        $templateFields = $document->template ? ($document->template->fields_json ?? []) : [];
        $missingFields = [];
        foreach ($templateFields as $tField) {
            if (empty($tField['required'])) continue;
            $assignedTo = $tField['assignedTo'] ?? 'creator';
            $normalized = $roleAliases[$assignedTo] ?? $assignedTo;
            if ($normalized !== $party) continue;

            $fieldId = $tField['id'] ?? null;
            if (!$fieldId) continue;

            $docField = collect($docFields)->firstWhere('id', $fieldId);
            $hasValue = false;
            if ($docField) {
                $type = $tField['type'] ?? 'placeholder';
                if (in_array($type, ['placeholder', 'date'])) {
                    $hasValue = !empty(trim((string) ($docField['value'] ?? '')));
                } elseif ($type === 'condition') {
                    $hasValue = !empty(trim((string) ($docField['text'] ?? '')));
                } elseif (in_array($type, ['selection', 'tick'])) {
                    $hasValue = !empty($docField['selectedValue']);
                } else {
                    $hasValue = true;
                }
            }
            if (!$hasValue) {
                $missingFields[] = $tField['field_label'] ?? $tField['field_name'] ?? 'Required field';
            }
        }
        if (!empty($missingFields)) {
            return response()->json([
                'ok' => false,
                'error' => 'Please complete all required fields: ' . implode(', ', $missingFields),
            ], 422);
        }

        if ($this->signatureService->isPartyComplete($template, $party)) {
            $signingRequest->update([
                'status' => SignatureRequest::STATUS_COMPLETED,
                'completed_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            // Flatten any signer-completed fields onto the page images
            $flattener = app(DocumentFlattener::class);
            $flattener->flattenSignerFields($template, $party);

            $this->signatureService->handlePartyCompletion($template, $party);

            $fullyComplete = $this->signatureService->isFullyComplete($template);

            return response()->json([
                'ok' => true,
                'completed' => true,
                'fully_complete' => $fullyComplete,
                'redirect' => route('signatures.external.completed', $token),
            ]);
        }

        return response()->json(['ok' => false, 'error' => 'Not all required markers have been signed.'], 422);
    }

    /**
     * Show signing completed page.
     */
    public function completed($token)
    {
        $signingRequest = SignatureRequest::where('token', $token)->firstOrFail();

        $fullyComplete = false;
        if ($signingRequest->template) {
            $fullyComplete = $this->signatureService->isFullyComplete($signingRequest->template);
        }

        return view('docuperfect.signatures.external.completed', [
            'request' => $signingRequest,
            'fullyComplete' => $fullyComplete,
        ]);
    }

    /**
     * Upload wet ink document (external — supports multiple files).
     */
    public function uploadWetInk(Request $request, $token)
    {
        $signingRequest = SignatureRequest::where('token', $token)->firstOrFail();

        if ($signingRequest->isExpired()) {
            return redirect()->route('signatures.external', $token)
                ->with('error', 'Signing link has expired.');
        }

        // Verify session
        if (!session("signing_verified_{$token}")) {
            return redirect()->route('signatures.external', $token);
        }

        $request->validate([
            'files' => 'required|array|min:1',
            'files.*' => 'file|mimes:pdf,jpg,jpeg,png|max:20480',
        ]);

        $paths = [];
        foreach ($request->file('files') as $file) {
            $path = $file->store("docuperfect/wet-ink-uploads/{$signingRequest->id}", 'local');
            $paths[] = $path;
        }

        // Merge with any existing uploads (if re-uploading after rejection)
        $existingPaths = $signingRequest->wet_ink_upload_path
            ? json_decode($signingRequest->wet_ink_upload_path, true)
            : [];

        if (!is_array($existingPaths)) {
            $existingPaths = $signingRequest->wet_ink_upload_path
                ? [$signingRequest->wet_ink_upload_path]
                : [];
        }

        // On re-upload after rejection, replace all files
        if ($signingRequest->wet_ink_status === SignatureRequest::WET_INK_REJECTED) {
            $existingPaths = [];
        }

        $allPaths = array_merge($existingPaths, $paths);

        $signingRequest->update([
            'signing_method' => 'wet_ink',
            'wet_ink_upload_path' => json_encode($allPaths),
            'wet_ink_status' => SignatureRequest::WET_INK_UPLOADED_PENDING_REVIEW,
        ]);

        SignatureAuditLog::log(
            $signingRequest->template,
            SignatureAuditLog::ACTION_WET_INK_UPLOADED,
            SignatureAuditLog::ACTOR_SIGNER,
            $signingRequest->signer_name,
            $signingRequest->signer_email,
            requestId: $signingRequest->id,
            ip: $request->ip(),
            ua: $request->userAgent(),
            metadata: ['file_count' => count($paths), 'total_files' => count($allPaths)],
        );

        // Notify the agent
        $this->signatureService->notifyWetInkUploaded($signingRequest);

        return view('docuperfect.signatures.external.upload-received', [
            'request' => $signingRequest,
        ]);
    }

    /**
     * Download document for wet ink signing.
     * Generates a PDF on-the-fly from flattened page images (which include
     * document fields + previous signers' entries baked in), with colored
     * annotation markers overlaid showing where this party needs to sign/initial/fill.
     */
    public function downloadForSigning($token)
    {
        $signingRequest = SignatureRequest::where('token', $token)
            ->with(['template.document.template', 'template.markers'])
            ->firstOrFail();

        if ($signingRequest->isExpired()) {
            return redirect()->route('signatures.external', $token)
                ->with('error', 'Signing link has expired.');
        }

        $signatureTemplate = $signingRequest->template;
        $document = $signatureTemplate->document;
        $docTemplate = $document->template ?? null;

        $flattenedPages = $signatureTemplate->flattened_pages_json ?? [];
        if (!$docTemplate && empty($flattenedPages)) {
            return redirect()->route('signatures.external', $token)
                ->with('error', 'Document file not available for download.');
        }

        $flattener = app(DocumentFlattener::class);

        // Load this party's unsigned markers for annotation overlays
        $partyMarkers = $signatureTemplate->markers()
            ->where('assigned_party', $signingRequest->party_role)
            ->whereDoesntHave('signatures')
            ->orderBy('page_number')
            ->get();

        // Create annotated temp copies with marker overlays (or fall back to plain images)
        $annotatedPages = [];
        $usingAnnotated = false;

        if ($partyMarkers->isNotEmpty()) {
            $annotatedPages = $flattener->createAnnotatedPages($signatureTemplate, $partyMarkers);
            $usingAnnotated = !empty($annotatedPages);
        }

        // Fall back to plain page images if no markers or annotation failed
        if (!$usingAnnotated) {
            $annotatedPages = [];
        }
        $plainPages = $flattener->getPageImages($signatureTemplate);

        // Build HTML with each page image as a full-page image
        $html = '<html><head><style>'
            . 'body { margin: 0; padding: 0; }'
            . '.page { page-break-after: always; text-align: center; margin: 0; padding: 0; }'
            . '.page:last-child { page-break-after: auto; }'
            . '.page img { width: 100%; height: auto; display: block; }'
            . '</style></head><body>';

        $pageCount = !empty($flattenedPages) ? count($flattenedPages) : ($docTemplate ? $docTemplate->page_count : 0);
        for ($pageNum = 0; $pageNum < $pageCount; $pageNum++) {
            // Use annotated temp image if available, otherwise plain storage image
            if ($usingAnnotated && isset($annotatedPages[$pageNum])) {
                $content = @file_get_contents($annotatedPages[$pageNum]);
                $mime = 'image/png';
            } else {
                $storagePath = $plainPages[$pageNum] ?? null;
                if (!$storagePath || !Storage::disk('local')->exists($storagePath)) {
                    continue;
                }
                $content = Storage::disk('local')->get($storagePath);
                $ext = strtolower(pathinfo($storagePath, PATHINFO_EXTENSION));
                $mime = match ($ext) {
                    'jpg', 'jpeg' => 'image/jpeg',
                    'gif' => 'image/gif',
                    'webp' => 'image/webp',
                    default => 'image/png',
                };
            }

            if (!$content) continue;

            $base64 = base64_encode($content);
            $html .= '<div class="page">'
                . '<img src="data:' . $mime . ';base64,' . $base64 . '">'
                . '</div>';
        }

        $html .= '</body></html>';

        $pdf = Pdf::loadHTML($html);
        $pdf->setPaper('a4', 'portrait');
        $pdf->setOption('isRemoteEnabled', true);
        $pdf->setOption('isHtml5ParserEnabled', true);

        // Update signing method
        $signingRequest->update([
            'signing_method' => 'wet_ink',
            'wet_ink_status' => $signingRequest->wet_ink_status ?: SignatureRequest::WET_INK_PENDING_UPLOAD,
        ]);

        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $document->name) . ' - For Signing.pdf';

        $response = $pdf->download($filename);

        // Clean up temp annotated images
        if ($usingAnnotated) {
            DocumentFlattener::cleanupTempImages($annotatedPages);
        }

        return $response;
    }

    /**
     * Decline to sign (external).
     */
    public function decline(Request $request, $token)
    {
        $signingRequest = SignatureRequest::where('token', $token)->firstOrFail();

        if ($signingRequest->isExpired()) {
            return response()->json(['ok' => false, 'error' => 'Signing link has expired.'], 410);
        }

        $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        $this->signatureService->declineRequest(
            $signingRequest,
            $request->input('reason'),
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json(['ok' => true, 'declined' => true]);
    }

    /**
     * Serve a flattened page image for external signers (token-based, no auth).
     */
    public function flattenedPageImage(Request $request, $token, $page)
    {
        $signingRequest = SignatureRequest::where('token', $token)->firstOrFail();

        if ($signingRequest->isExpired()) {
            abort(403, 'Signing link has expired.');
        }

        // Require verified session
        if (!session("signing_verified_{$token}")) {
            abort(403, 'Identity not verified.');
        }

        $template = $signingRequest->template;
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
    // Signed Document Download (token-based, no auth)
    // ──────────────────────────────────────────────

    /**
     * Show the download page for a completed signed document.
     * Requires identity verification before file access.
     */
    public function downloadPage(Request $request, $token)
    {
        $signingRequest = SignatureRequest::where('token', $token)
            ->with(['template.document'])
            ->firstOrFail();

        $template = $signingRequest->template;
        $document = $template->document ?? null;

        // Document must be fully signed
        if (!$template || $template->status !== SignatureTemplate::STATUS_COMPLETED) {
            return view('docuperfect.signatures.external.download', [
                'request' => $signingRequest,
                'token' => $token,
                'error' => 'This document is not yet fully signed. All parties must complete signing before download is available.',
                'document' => $document,
                'template' => $template,
            ]);
        }

        // Already verified in this session — show download button
        if (session("download_verified_{$token}")) {
            return view('docuperfect.signatures.external.download', [
                'request' => $signingRequest,
                'token' => $token,
                'verified' => true,
                'document' => $document,
                'template' => $template,
            ]);
        }

        // Show verification form
        return view('docuperfect.signatures.external.download', [
            'request' => $signingRequest,
            'token' => $token,
            'needsVerification' => true,
            'document' => $document,
            'template' => $template,
        ]);
    }

    /**
     * Verify identity before allowing signed document download.
     * Matches ID number against this signer's record.
     */
    public function downloadVerify(Request $request, $token)
    {
        $signingRequest = SignatureRequest::where('token', $token)
            ->with('template.document')
            ->firstOrFail();

        $template = $signingRequest->template;

        if (!$template || $template->status !== SignatureTemplate::STATUS_COMPLETED) {
            return redirect()->route('signatures.download.page', $token);
        }

        $request->validate([
            'id_number' => 'required|string|min:3|max:20',
        ]);

        // Match ID number against this signer's record
        $submittedId = strtolower(trim($request->id_number));
        $expectedId = strtolower(trim($signingRequest->signer_id_number ?? ''));

        if (empty($expectedId) || $submittedId !== $expectedId) {
            SignatureAuditLog::log(
                $template,
                'download_verification_failed',
                SignatureAuditLog::ACTOR_SIGNER,
                $signingRequest->signer_name,
                $signingRequest->signer_email,
                requestId: $signingRequest->id,
                ip: $request->ip(),
                ua: $request->userAgent(),
                metadata: ['id_match' => false],
            );

            return redirect()->route('signatures.download.page', $token)
                ->with('error', 'The ID number does not match our records. Please try again.');
        }

        // Store verification in session
        session(["download_verified_{$token}" => true]);

        SignatureAuditLog::log(
            $template,
            'download_verified',
            SignatureAuditLog::ACTOR_SIGNER,
            $signingRequest->signer_name,
            $signingRequest->signer_email,
            requestId: $signingRequest->id,
            ip: $request->ip(),
            ua: $request->userAgent(),
        );

        Log::info('Signed document download verified', [
            'signer' => $signingRequest->signer_name,
            'email' => $signingRequest->signer_email,
            'template_id' => $template->id,
            'ip' => $request->ip(),
        ]);

        return redirect()->route('signatures.download.page', $token);
    }

    /**
     * Serve the final signed PDF file after identity verification.
     */
    public function downloadSignedFile(Request $request, $token)
    {
        // Must be verified
        if (!session("download_verified_{$token}")) {
            return redirect()->route('signatures.download.page', $token);
        }

        $signingRequest = SignatureRequest::where('token', $token)
            ->with(['template.document'])
            ->firstOrFail();

        $template = $signingRequest->template;
        $document = $template->document ?? null;

        if (!$template || $template->status !== SignatureTemplate::STATUS_COMPLETED) {
            return redirect()->route('signatures.download.page', $token)
                ->with('error', 'Document is not yet fully signed.');
        }

        if (!$template->signed_pdf_path) {
            return redirect()->route('signatures.download.page', $token)
                ->with('error', 'Signed PDF has not been generated yet. Please try again later.');
        }

        $pdfPath = storage_path("app/{$template->signed_pdf_path}");

        if (!file_exists($pdfPath)) {
            Log::error('Signed PDF file not found on disk', [
                'path' => $template->signed_pdf_path,
                'template_id' => $template->id,
            ]);

            return redirect()->route('signatures.download.page', $token)
                ->with('error', 'Signed PDF file not found. Please contact the agent.');
        }

        $documentName = $document ? $document->name : 'Document';
        $filename = preg_replace('/[^a-zA-Z0-9_\-\. ]/', '_', $documentName) . ' - Signed.pdf';

        SignatureAuditLog::log(
            $template,
            'signed_pdf_downloaded',
            SignatureAuditLog::ACTOR_SIGNER,
            $signingRequest->signer_name,
            $signingRequest->signer_email,
            requestId: $signingRequest->id,
            ip: $request->ip(),
            ua: $request->userAgent(),
        );

        return response()->download($pdfPath, $filename);
    }
}
