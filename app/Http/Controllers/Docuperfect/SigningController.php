<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureMarker;
use App\Models\Docuperfect\SignatureRequest;
use App\Services\Docuperfect\DocumentFlattener;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Http\Request;

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

        // Identity verification gate
        if (!session("signing_verified_{$token}")) {
            return view('docuperfect.signatures.external.verify', [
                'request' => $signingRequest,
                'requiresIdNumber' => !empty($signingRequest->signer_id_number),
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

        // Build page image URLs — use flattened images when available
        $docTemplate = $document->template;
        $flattenedPages = $template->flattened_pages_json ?? [];
        $hasFlattened = !empty($flattenedPages);
        $pageImages = [];
        $pageCount = $docTemplate ? $docTemplate->page_count : 0;

        for ($n = 0; $n < $pageCount; $n++) {
            if ($hasFlattened && isset($flattenedPages[$n])) {
                $pageImages[] = route('signatures.external.flattenedPage', ['token' => $token, 'page' => $n]);
            } elseif ($docTemplate) {
                $pageImages[] = route('docuperfect.page.image', ['id' => $docTemplate->id, 'page' => $n]);
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
            'token' => $token,
        ]);
    }

    /**
     * Verify signer identity (name + email + optional ID number).
     */
    public function verify(Request $request, $token)
    {
        $signingRequest = SignatureRequest::where('token', $token)
            ->with('template')
            ->firstOrFail();

        if ($signingRequest->isExpired()) {
            return redirect()->route('signatures.external', $token);
        }

        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
        ];

        if (!empty($signingRequest->signer_id_number)) {
            $rules['id_number'] = 'required|string|max:20';
        }

        $request->validate($rules);

        // Check name (case-insensitive, trim whitespace)
        $nameMatch = strtolower(trim($request->name)) === strtolower(trim($signingRequest->signer_name));

        // Check email
        $emailMatch = strtolower(trim($request->email)) === strtolower(trim($signingRequest->signer_email));

        // Check ID number if one was provided during setup
        $idMatch = true;
        if ($signingRequest->signer_id_number) {
            $idMatch = trim($request->id_number) === trim($signingRequest->signer_id_number);
        }

        if (!$nameMatch || !$emailMatch || !$idMatch) {
            SignatureAuditLog::log(
                $signingRequest->template,
                'identity_verification_failed',
                SignatureAuditLog::ACTOR_SIGNER,
                $request->name,
                $request->email,
                requestId: $signingRequest->id,
                ip: $request->ip(),
                ua: $request->userAgent(),
                metadata: [
                    'name_match' => $nameMatch,
                    'email_match' => $emailMatch,
                    'id_match' => $idMatch,
                ],
            );

            return redirect()->back()
                ->withInput()
                ->with('error', 'Your details do not match our records. Please check and try again.');
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
            'signature_data' => 'required|string',
            'signature_type' => 'nullable|string|in:drawn,typed',
        ]);

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

        if ($this->signatureService->isPartyComplete($template, $party)) {
            $signingRequest->update([
                'status' => SignatureRequest::STATUS_COMPLETED,
                'completed_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

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
     */
    public function downloadForSigning($token)
    {
        $signingRequest = SignatureRequest::where('token', $token)
            ->with(['template.document.template'])
            ->firstOrFail();

        if ($signingRequest->isExpired()) {
            return redirect()->route('signatures.external', $token)
                ->with('error', 'Signing link has expired.');
        }

        $document = $signingRequest->template->document;
        $docTemplate = $document->template;

        if (!$docTemplate || !$docTemplate->storage_path) {
            return redirect()->route('signatures.external', $token)
                ->with('error', 'Document file not available for download.');
        }

        $filePath = storage_path("app/{$docTemplate->storage_path}");

        if (!file_exists($filePath)) {
            return redirect()->route('signatures.external', $token)
                ->with('error', 'Document file not found.');
        }

        // Update signing method
        $signingRequest->update([
            'signing_method' => 'wet_ink',
            'wet_ink_status' => $signingRequest->wet_ink_status ?: SignatureRequest::WET_INK_PENDING_UPLOAD,
        ]);

        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $document->name) . '_for_signing.pdf';

        return response()->download($filePath, $filename);
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

        $path = storage_path('app/' . $flattenedPages[$pageNum]);
        if (!file_exists($path)) {
            abort(404, 'Flattened page file not found.');
        }

        return response()->file($path, [
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
