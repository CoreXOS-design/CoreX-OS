<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Docuperfect\ESignConsentLog;
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
use Illuminate\Support\Facades\Mail;
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

        // Already completed — show enhanced summary
        if ($signingRequest->status === SignatureRequest::STATUS_COMPLETED) {
            $branding = $this->getAgencyBranding($signingRequest);
            $consentLog = ESignConsentLog::where('signature_request_id', $signingRequest->id)
                ->orderBy('created_at', 'desc')
                ->first();
            $creator = $signingRequest->template->creator ?? null;
            $template = $signingRequest->template;
            $downloadAvailable = $template && !empty($template->signed_pdf_path);

            return view('docuperfect.signatures.external.already-completed', [
                'request' => $signingRequest,
                'consentLog' => $consentLog,
                'downloadAvailable' => $downloadAvailable,
                'agentName' => $creator->name ?? null,
                'agentEmail' => $creator->email ?? null,
                'agentPhone' => $creator->phone ?? $creator->cell ?? null,
                'agencyName' => $branding['name'],
                'agencyLogo' => $branding['logo'],
                'agencyColor' => $branding['color'],
            ]);
        }

        // Declined
        if ($signingRequest->status === SignatureRequest::STATUS_DECLINED) {
            return view('docuperfect.signatures.external.expired', [
                'request' => $signingRequest,
                'declined' => true,
            ]);
        }

        // Not yet their turn — sequential signing gate
        if ($signingRequest->status === SignatureRequest::STATUS_WAITING) {
            return view('docuperfect.signatures.external.waiting', [
                'request' => $signingRequest,
            ]);
        }

        // Gateway gate — signer must verify ID AND accept consent before seeing documents
        if (!empty($signingRequest->signer_id_number)) {
            if (!session("signing_verified_{$token}")) {
                return redirect()->route('signatures.external.gateway', ['token' => $token]);
            }
            if (!session("esign_consent_{$signingRequest->id}")) {
                return redirect()->route('signatures.external.showConsent', ['token' => $token]);
            }
        }

        // If signing method is forced wet_ink, redirect to wet ink portal
        if ($signingRequest->signing_method === 'wet_ink') {
            return redirect()->route('signatures.external.wetInkPortal', $token);
        }

        // Also check if template is e-sign blocked — force to wet ink portal
        $docTemplate = $signingRequest->template?->document?->template;
        if ($docTemplate && $docTemplate->isEsignBlocked()) {
            $signingRequest->update([
                'signing_method' => 'wet_ink',
                'wet_ink_status' => $signingRequest->wet_ink_status ?: SignatureRequest::WET_INK_PENDING_UPLOAD,
            ]);
            return redirect()->route('signatures.external.wetInkPortal', $token);
        }

        // Mark as viewed if pending (first real view — after gateway/consent)
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

        $template = $signingRequest->template;
        $document = $template->document;

        // Get this party's markers (use assigned_email to distinguish co-owners)
        $myMarkers = $template->markers()
            ->with('signatures')
            ->where('assigned_party', $signingRequest->party_role)
            ->where(function ($q) use ($signingRequest) {
                $q->where('assigned_email', $signingRequest->signer_email)
                  ->orWhereNull('assigned_email');
            })
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

        // Detect web template rendering — check for flattened document pages first
        $docTemplate = $document->template;
        $webTemplateData = $document->web_template_data ?? [];
        $hasDocumentPages = !empty($webTemplateData['flattened_page_count']);
        $isWebTemplate = false;
        $webTemplateHtml = '';
        $editableFields = [];

        if ($hasDocumentPages) {
            // Web template was flattened to page images — treat as PDF from here
            // Client fields are already positioned in fields_json for overlay rendering
            $isWebTemplate = false;
        } elseif ($docTemplate && $docTemplate->render_type === 'web' && $docTemplate->blade_view) {
            // Fallback: web template without flattening — use iframe (legacy path)
            $isWebTemplate = true;

            if (!empty($webTemplateData['merged_html'])) {
                $webTemplateHtml = $webTemplateData['merged_html'];
            } else {
                $viewData = $webTemplateData;
                if (!empty($docTemplate->signing_parties)) {
                    $viewData['signing_parties'] = $docTemplate->signing_parties;
                }
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
            // Prefer field_mappings with editable_by (CDS templates) over static party map
            $fieldMappingsFromData = $webTemplateData['field_mappings'] ?? [];
            if (!empty($fieldMappingsFromData)) {
                $editableFields = $this->getEditableFieldsFromMappings(
                    $fieldMappingsFromData,
                    $signingRequest->party_role
                );
            } else {
                $editableFields = WebTemplateFieldPartyMap::getEditableFields($signingRequest->party_role);
            }
        }

        // Build page image URLs — use flattened images when available (PDF path)
        $flattenedPages = $template->flattened_pages_json ?? [];
        $hasFlattened = !empty($flattenedPages);
        $pageImages = [];

        if ($hasDocumentPages) {
            // Flattened web template — use document-level page images
            $pageCount = (int) $webTemplateData['flattened_page_count'];
            for ($n = 0; $n < $pageCount; $n++) {
                if ($hasFlattened && isset($flattenedPages[$n])) {
                    $pageImages[] = route('signatures.external.flattenedPage', ['token' => $token, 'page' => $n]);
                } else {
                    $pageImages[] = route('docuperfect.documents.pageImage', ['id' => $document->id, 'page' => $n]);
                }
            }
        } else {
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
        }

        // Check if wet ink upload is pending review
        $wetInkPendingReview = $signingRequest->signing_method === 'wet_ink'
            && $signingRequest->wet_ink_status === SignatureRequest::WET_INK_UPLOADED_PENDING_REVIEW;

        // Check if wet ink was rejected (needs re-upload)
        $wetInkRejected = $signingRequest->wet_ink_status === SignatureRequest::WET_INK_REJECTED;

        // Section-by-section signing data
        $sections = $template->sections_json ?? [];
        $sectionAcceptances = [];
        if (!empty($sections)) {
            $sectionAcceptances = $signingRequest->sectionAcceptances()
                ->orderBy('section_index')
                ->get()
                ->keyBy('section_index')
                ->toArray();
        }

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
            'signerRole' => $signingRequest->party_role,
            'fieldMappings' => $webTemplateData['field_mappings'] ?? [],
            'token' => $token,
            'sections' => $sections,
            'sectionAcceptances' => $sectionAcceptances,
        ]);
    }

    /**
     * Verify signer identity (full ID/passport number).
     */
    public function verify(Request $request, $token)
    {
        $signingRequest = SignatureRequest::where('token', $token)
            ->with('template.creator')
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

            $branding = $this->getAgencyBranding($signingRequest);
            $creator = $signingRequest->template->creator ?? null;

            return redirect()->route('signatures.external.gateway', ['token' => $token])
                ->with('error', 'ID not recognised. Please contact your agent'
                    . ($creator ? " at {$creator->email}" . ($creator->phone ? " / {$creator->phone}" : '') : '')
                    . '.');
        }

        // Store verification in session
        session(["signing_verified_{$token}" => true]);
        // Store entered ID for consent step
        session(["signing_id_entered_{$token}" => $request->id_number]);

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

        // Proceed to consent declaration
        return redirect()->route('signatures.external.showConsent', ['token' => $token]);
    }

    /**
     * Gateway landing page — agency-branded ID entry ceremony.
     */
    public function gateway(Request $request, $token)
    {
        $signingRequest = SignatureRequest::where('token', $token)
            ->with(['template.document', 'template.creator'])
            ->firstOrFail();

        if ($signingRequest->isExpired()) {
            return view('docuperfect.signatures.external.expired', [
                'request' => $signingRequest,
            ]);
        }

        // Already completed — redirect to already-signed
        if ($signingRequest->status === SignatureRequest::STATUS_COMPLETED) {
            return redirect()->route('signatures.external', ['token' => $token]);
        }

        // Already verified + consented — go straight to sign
        if (session("signing_verified_{$token}") && session("esign_consent_{$signingRequest->id}")) {
            return redirect()->route('signatures.external', ['token' => $token]);
        }

        // Already verified but not yet consented — go to consent
        if (session("signing_verified_{$token}")) {
            return redirect()->route('signatures.external.showConsent', ['token' => $token]);
        }

        $branding = $this->getAgencyBranding($signingRequest);
        $documentName = $signingRequest->template->document->name ?? 'Document';

        return view('docuperfect.signatures.external.gateway', [
            'request' => $signingRequest,
            'documentName' => $documentName,
            'agencyName' => $branding['name'],
            'agencyLogo' => $branding['logo'],
            'agencyColor' => $branding['color'],
        ]);
    }

    /**
     * Show consent declaration (after ID verification).
     */
    public function showConsent(Request $request, $token)
    {
        $signingRequest = SignatureRequest::where('token', $token)
            ->with(['template.document', 'template.creator'])
            ->firstOrFail();

        if ($signingRequest->isExpired()) {
            return view('docuperfect.signatures.external.expired', [
                'request' => $signingRequest,
            ]);
        }

        // Must be verified first
        if (!session("signing_verified_{$token}")) {
            return redirect()->route('signatures.external.gateway', ['token' => $token]);
        }

        // Already consented — go to sign
        if (session("esign_consent_{$signingRequest->id}")) {
            return redirect()->route('signatures.external', ['token' => $token]);
        }

        $branding = $this->getAgencyBranding($signingRequest);
        $documentName = $signingRequest->template->document->name ?? 'Document';
        $idNumber = $signingRequest->signer_id_number ?? '';
        $idLastFour = strlen($idNumber) >= 4 ? substr($idNumber, -4) : $idNumber;

        return view('docuperfect.signatures.external.consent', [
            'token' => $token,
            'request' => $signingRequest,
            'signerName' => $signingRequest->signer_name,
            'idLastFour' => $idLastFour,
            'documentName' => $documentName,
            'agencyName' => $branding['name'],
            'agencyLogo' => $branding['logo'],
            'agencyColor' => $branding['color'],
        ]);
    }

    /**
     * Capture consent — create immutable consent log record, then proceed to signing.
     */
    public function captureConsent(Request $request, $token)
    {
        $signingRequest = SignatureRequest::where('token', $token)
            ->with(['template.document'])
            ->firstOrFail();

        if ($signingRequest->isExpired()) {
            return redirect()->route('signatures.external', ['token' => $token]);
        }

        // Must be verified first
        if (!session("signing_verified_{$token}")) {
            return redirect()->route('signatures.external.gateway', ['token' => $token]);
        }

        // Validate checkbox
        if (!$request->input('consent_accepted')) {
            return redirect()->back()->with('error', 'You must accept the consent declaration to proceed.');
        }

        $template = $signingRequest->template;
        $document = $template->document;

        // Build consent declaration text (exact text shown to signer)
        $idNumber = $signingRequest->signer_id_number ?? '';
        $idLastFour = strlen($idNumber) >= 4 ? substr($idNumber, -4) : $idNumber;
        $consentText = "By proceeding, I confirm:\n"
            . "1. I am {$signingRequest->signer_name} (ID: ****{$idLastFour}).\n"
            . "2. I am acting of my own free will and have not been coerced.\n"
            . "3. I understand I am about to review and electronically sign legal documents.\n"
            . "4. My electronic signature carries the same legal weight as a handwritten signature under the Electronic Communications and Transactions Act 25 of 2002.\n"
            . "5. I consent to the processing of my personal information for the purposes of this transaction in terms of the Protection of Personal Information Act 4 of 2013.\n\n"
            . "I have read and understood the above.";

        // Generate document hash (SHA-256 of current document content)
        $documentHash = '';
        if ($document) {
            $webData = $document->web_template_data ?? [];
            $htmlContent = $webData['merged_html'] ?? json_encode($webData);
            $documentHash = hash('sha256', $htmlContent);
        }

        // Parse user agent for device info
        $ua = $request->userAgent() ?? '';
        $deviceInfo = $this->parseDeviceInfo($ua);

        // Get the ID number that was entered during verification
        $idEntered = session("signing_id_entered_{$token}", $idNumber);

        // Create immutable consent log record
        $consentLog = new ESignConsentLog();
        $consentLog->flow_id = null; // Set if wizard flow exists
        $consentLog->document_id = $document->id ?? null;
        $consentLog->signature_request_id = $signingRequest->id;
        $consentLog->signing_party_id = null; // Set if esign_signing_party exists
        $consentLog->contact_id = null; // Will be linked if contact found
        $consentLog->id_number_entered = $idEntered; // Encrypted via mutator
        $consentLog->id_verified = true;
        $consentLog->consent_text = $consentText;
        $consentLog->consent_accepted_at = now();
        $consentLog->ip_address = $request->ip();
        $consentLog->user_agent = $ua;
        $consentLog->device_info = $deviceInfo;
        $consentLog->document_hash = $documentHash;
        $consentLog->created_at = now();
        $consentLog->save();

        // Store consent session flag
        session(["esign_consent_{$signingRequest->id}" => true]);

        // Audit log
        SignatureAuditLog::log(
            $template,
            'gateway_consent_captured',
            SignatureAuditLog::ACTOR_SIGNER,
            $signingRequest->signer_name,
            $signingRequest->signer_email,
            requestId: $signingRequest->id,
            ip: $request->ip(),
            ua: $ua,
            metadata: [
                'consent_log_id' => $consentLog->id,
                'document_hash' => $documentHash,
            ],
        );

        // Mark as viewed if pending (first real access after consent)
        if ($signingRequest->status === SignatureRequest::STATUS_PENDING) {
            $signingRequest->update([
                'status' => SignatureRequest::STATUS_VIEWED,
                'viewed_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $ua,
            ]);

            SignatureAuditLog::log(
                $template,
                SignatureAuditLog::ACTION_VIEWED,
                SignatureAuditLog::ACTOR_SIGNER,
                $signingRequest->signer_name,
                $signingRequest->signer_email,
                requestId: $signingRequest->id,
                ip: $request->ip(),
                ua: $ua,
            );
        }

        return redirect()->route('signatures.external', ['token' => $token]);
    }

    /**
     * Already-signed summary page.
     */
    public function alreadySigned(Request $request, $token)
    {
        $signingRequest = SignatureRequest::where('token', $token)
            ->with(['template.document', 'template.creator'])
            ->firstOrFail();

        if ($signingRequest->status !== SignatureRequest::STATUS_COMPLETED) {
            return redirect()->route('signatures.external', ['token' => $token]);
        }

        $branding = $this->getAgencyBranding($signingRequest);
        $consentLog = ESignConsentLog::where('signature_request_id', $signingRequest->id)
            ->orderBy('created_at', 'desc')
            ->first();
        $creator = $signingRequest->template->creator ?? null;
        $template = $signingRequest->template;
        $downloadAvailable = $template && !empty($template->signed_pdf_path);

        return view('docuperfect.signatures.external.already-completed', [
            'request' => $signingRequest,
            'consentLog' => $consentLog,
            'downloadAvailable' => $downloadAvailable,
            'agentName' => $creator->name ?? null,
            'agentEmail' => $creator->email ?? null,
            'agentPhone' => $creator->phone ?? $creator->cell ?? null,
            'agencyName' => $branding['name'],
            'agencyLogo' => $branding['logo'],
            'agencyColor' => $branding['color'],
        ]);
    }

    /**
     * Get agency branding data for external views.
     */
    private function getAgencyBranding(SignatureRequest $signingRequest): array
    {
        $agency = null;

        // Try to get agency from the creator's agency_id
        $creator = $signingRequest->template->creator ?? null;
        if ($creator && $creator->agency_id) {
            $agency = Agency::find($creator->agency_id);
        }

        // Fallback to first agency
        if (!$agency) {
            $agency = Agency::first();
        }

        return [
            'name' => $agency->name ?? 'Home Finders Coastal',
            'logo' => $agency && $agency->logo_path ? asset('storage/' . $agency->logo_path) : null,
            'color' => $agency->default_color ?? $agency->button_color ?? '#0b2a4a',
        ];
    }

    /**
     * Parse user agent into structured device info.
     */
    private function parseDeviceInfo(string $ua): array
    {
        $info = [
            'browser' => 'Unknown',
            'os' => 'Unknown',
            'raw' => $ua,
        ];

        // Browser detection
        if (preg_match('/Edg\/(\S+)/', $ua)) {
            $info['browser'] = 'Microsoft Edge';
        } elseif (preg_match('/Chrome\/(\S+)/', $ua) && !preg_match('/Edg/', $ua)) {
            $info['browser'] = 'Chrome';
        } elseif (preg_match('/Firefox\/(\S+)/', $ua)) {
            $info['browser'] = 'Firefox';
        } elseif (preg_match('/Safari\/(\S+)/', $ua) && !preg_match('/Chrome/', $ua)) {
            $info['browser'] = 'Safari';
        }

        // OS detection
        if (preg_match('/Windows NT/', $ua)) {
            $info['os'] = 'Windows';
        } elseif (preg_match('/Mac OS X/', $ua)) {
            $info['os'] = 'macOS';
        } elseif (preg_match('/Android/', $ua)) {
            $info['os'] = 'Android';
        } elseif (preg_match('/iPhone|iPad/', $ua)) {
            $info['os'] = 'iOS';
        } elseif (preg_match('/Linux/', $ua)) {
            $info['os'] = 'Linux';
        }

        return $info;
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
     * Show the dedicated wet ink portal page.
     * Used for documents that are forced wet-ink-only (sale agreements, OTPs).
     */
    public function wetInkPortal(Request $request, $token)
    {
        $signingRequest = SignatureRequest::where('token', $token)
            ->with(['template.document', 'sender'])
            ->firstOrFail();

        if ($signingRequest->isExpired()) {
            return view('docuperfect.signatures.external.expired', [
                'request' => $signingRequest,
            ]);
        }

        if ($signingRequest->status === SignatureRequest::STATUS_COMPLETED) {
            return redirect()->route('signatures.external.alreadySigned', $token);
        }

        // Verify session (gateway must be passed first)
        if ($signingRequest->signer_id_number && !session("signing_verified_{$token}")) {
            return redirect()->route('signatures.external.gateway', $token);
        }

        // Mark as wet ink if not already
        if ($signingRequest->signing_method !== 'wet_ink') {
            $signingRequest->update([
                'signing_method' => 'wet_ink',
                'wet_ink_status' => SignatureRequest::WET_INK_PENDING_UPLOAD,
            ]);
        }

        $branding = $this->getAgencyBranding($signingRequest);
        $document = $signingRequest->template->document ?? null;

        // Get version history
        $versions = $document
            ? \App\Models\Docuperfect\SignedDocumentVersion::where('document_id', $document->id)
                ->where('signature_request_id', $signingRequest->id)
                ->orderBy('version_number', 'desc')
                ->get()
            : collect();

        return view('docuperfect.signatures.external.wet-ink-portal', [
            'request' => $signingRequest,
            'document' => $document,
            'branding' => $branding,
            'token' => $token,
            'versions' => $versions,
        ]);
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

        // Sequential signing gate
        if ($signingRequest->status === SignatureRequest::STATUS_WAITING) {
            return response()->json(['ok' => false, 'error' => 'It is not your turn to sign yet.'], 403);
        }

        // Verify session
        if (!session("signing_verified_{$token}")) {
            return response()->json(['ok' => false, 'error' => 'Identity not verified.'], 403);
        }

        // Verify marker belongs to this party (and specific co-owner if assigned_email is set)
        if ($marker->assigned_party !== $signingRequest->party_role) {
            return response()->json(['ok' => false, 'error' => 'This marker is not assigned to you.'], 403);
        }
        if ($marker->assigned_email && $marker->assigned_email !== $signingRequest->signer_email) {
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

        $allSigned = $this->signatureService->isPartyComplete($template, $signingRequest->party_role, $signingRequest->signer_email);

        $signerEmail = $signingRequest->signer_email;
        $signedCount = $template->signatures()
            ->whereHas('marker', fn($q) => $q->where('assigned_party', $signingRequest->party_role)
                ->where(fn($q2) => $q2->where('assigned_email', $signerEmail)->orWhereNull('assigned_email')))
            ->count();
        $totalRequired = $template->markers()
            ->where('assigned_party', $signingRequest->party_role)
            ->where(fn($q) => $q->where('assigned_email', $signerEmail)->orWhereNull('assigned_email'))
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
     * Complete web template signing (CDS/web documents with live HTML).
     * Handles field values, signatures, disclosure answers, and consent logging.
     */
    public function completeWeb(Request $request, $token)
    {
        $signingRequest = SignatureRequest::where('token', $token)
            ->with('template.document')
            ->firstOrFail();

        if ($signingRequest->isExpired()) {
            return response()->json(['ok' => false, 'error' => 'Signing link has expired.'], 410);
        }

        // Sequential signing gate — reject if not this signer's turn
        if ($signingRequest->status === SignatureRequest::STATUS_WAITING) {
            return response()->json(['ok' => false, 'error' => 'It is not your turn to sign yet. Please wait for notification.'], 403);
        }

        // Validate consent
        if (!$request->input('consented')) {
            return response()->json(['message' => 'Consent is required to sign electronically.'], 422);
        }

        $template = $signingRequest->template;
        $document = $template->document;

        if (!$document) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        // Log consent to audit log
        SignatureAuditLog::create([
            'signature_template_id' => $template->id,
            'action' => 'electronic_consent_given',
            'actor_type' => SignatureAuditLog::ACTOR_SIGNER,
            'actor_name' => $signingRequest->signer_name,
            'actor_email' => $signingRequest->signer_email,
            'actor_ip_address' => $request->ip(),
            'actor_user_agent' => $request->userAgent(),
            'signature_request_id' => $signingRequest->id,
            'metadata_json' => [
                'consent_text' => 'Electronic signature consent per ECTA Section 13',
                'consent_timestamp' => $request->input('consent_timestamp', now()->toIso8601String()),
            ],
        ]);

        // Save field values into the document's web_template_data
        $webData = $document->web_template_data ?? [];
        $newFieldValues = $request->input('field_values', []);
        if (!empty($newFieldValues)) {
            $existingFieldValues = $webData['field_values'] ?? [];
            $webData['field_values'] = array_merge($existingFieldValues, $newFieldValues);
        }

        // Save disclosure answers
        $disclosureAnswers = $request->input('disclosure_answers', []);
        if (!empty($disclosureAnswers)) {
            $webData['disclosure_answers'] = array_merge(
                $webData['disclosure_answers'] ?? [],
                $disclosureAnswers
            );
        }

        // Save ceremony values (location, day, month, year, time, am_pm per party)
        $ceremonyValues = $request->input('ceremony_values', []);
        if (!empty($ceremonyValues)) {
            $webData['ceremony_values'] = array_merge($webData['ceremony_values'] ?? [], $ceremonyValues);
        }

        // Save clause flags (concerns raised by signer)
        $clauseFlags = $request->input('clause_flags', []);
        if (!empty($clauseFlags)) {
            $existingFlags = $webData['clause_flags'] ?? [];
            $webData['clause_flags'] = array_merge($existingFlags, [
                $signingRequest->party_role => $clauseFlags,
            ]);
        }

        // Save signatures (base64 data URIs keyed by block ID)
        $signatures = $request->input('signatures', []);
        if (!empty($signatures)) {
            $existingSigs = $webData['signatures'] ?? [];
            $webData['signatures'] = array_merge($existingSigs, $signatures);
        }

        // Embed this signer's signatures and ceremony values into merged_html
        if (!empty($webData['merged_html']) && !empty($signatures)) {
            $sigController = app(SignatureController::class);
            $html = $webData['merged_html'];
            $html = $sigController->embedSignaturesIntoHtml($html, $signatures, $signingRequest->party_role);
            if (!empty($ceremonyValues)) {
                $html = $sigController->embedCeremonyValuesIntoHtml($html, $ceremonyValues);
            }
            $webData['merged_html'] = $html;
        }

        $document->update(['web_template_data' => $webData]);

        // --- Amendment Detection (Other Conditions) ---
        $otherConditionsText = $request->input('other_conditions_text', '');
        if (!empty(trim($otherConditionsText))) {
            $detectedText = $this->signatureService->detectAmendment($template, $otherConditionsText);
            if ($detectedText !== null) {
                $amendment = $this->signatureService->createAmendment(
                    $template,
                    $signingRequest,
                    $detectedText,
                    $template->other_conditions_text
                );

                if ($amendment) {
                    // Mark this request as completed (they did sign)
                    $signingRequest->update([
                        'status' => SignatureRequest::STATUS_COMPLETED,
                        'completed_at' => now(),
                        'signing_method' => 'electronic',
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                    ]);

                    // Trigger amendment re-signing flow (halts forward progress)
                    $this->signatureService->handleAmendment($template, $amendment, $signingRequest);

                    return response()->json([
                        'ok' => true,
                        'completed' => true,
                        'amendment_detected' => true,
                        'amendment_id' => $amendment->id,
                        'message' => 'Your signature has been recorded. The document has been amended and previous signers will be notified for review.',
                        'redirect' => route('signatures.external.completed', $token),
                    ]);
                }
            }
        }

        // Mark signing request as completed
        $signingRequest->update([
            'status' => SignatureRequest::STATUS_COMPLETED,
            'completed_at' => now(),
            'signing_method' => 'electronic',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        SignatureAuditLog::create([
            'signature_template_id' => $template->id,
            'action' => 'web_signing_completed',
            'actor_type' => SignatureAuditLog::ACTOR_SIGNER,
            'actor_name' => $signingRequest->signer_name,
            'actor_email' => $signingRequest->signer_email,
            'actor_ip_address' => $request->ip(),
            'actor_user_agent' => $request->userAgent(),
            'signature_request_id' => $signingRequest->id,
            'metadata_json' => [
                'party_role' => $signingRequest->party_role,
                'field_count' => count($newFieldValues),
                'signature_count' => count($signatures),
                'disclosure_count' => count($disclosureAnswers),
            ],
        ]);

        // Check if ALL requests for this role are now complete
        $party = $signingRequest->party_role;
        $allRoleComplete = $template->requests()
            ->where('party_role', $party)
            ->where('status', '!=', SignatureRequest::STATUS_COMPLETED)
            ->doesntExist();

        if ($allRoleComplete) {
            // All co-owners for this role signed — run approval gate
            $this->signatureService->handlePartyCompletion($template, $party, $signingRequest);
        } else {
            // More co-owners still need to sign — still require agent approval before next co-owner
            $template->update(['status' => SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL]);
            $this->signatureService->handlePartyCompletion($template, $party, $signingRequest);
        }

        $fullyComplete = $this->signatureService->isFullyComplete($template);

        return response()->json([
            'ok' => true,
            'completed' => true,
            'fully_complete' => $fullyComplete,
            'redirect' => route('signatures.external.completed', $token),
        ]);
    }

    /**
     * Get editable field names from CDS field_mappings based on signer's party role.
     * Maps party roles to the editable_by values used in CDS templates.
     */
    private function getEditableFieldsFromMappings(array $fieldMappings, string $partyRole): array
    {
        // Map signing party roles to editable_by role names used in CDS builder
        $roleToEditableBy = [
            'landlord' => 'owner_party',
            'lessor' => 'owner_party',
            'seller' => 'owner_party',
            'tenant' => 'acquiring_party',
            'lessee' => 'acquiring_party',
            'buyer' => 'acquiring_party',
            'agent' => 'agent',
            'witness' => 'witness',
        ];

        $editableByRole = $roleToEditableBy[$partyRole] ?? $partyRole;
        $editableFields = [];

        foreach ($fieldMappings as $field) {
            $editableBy = $field['editable_by'] ?? [];
            $fieldName = $field['field_name'] ?? $field['label'] ?? '';

            if (empty($fieldName)) {
                continue;
            }

            // Normalize field name to match blade variable format
            $varName = str_replace('.', '_', $fieldName);
            $varName = preg_replace('/[^a-zA-Z0-9_]/', '_', $varName);

            $canEdit = in_array('all', $editableBy)
                || in_array($editableByRole, $editableBy);

            if ($canEdit) {
                $editableFields[] = $varName;
            }
        }

        return $editableFields;
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

        // Sequential signing gate — reject if not this signer's turn
        if ($signingRequest->status === SignatureRequest::STATUS_WAITING) {
            return response()->json(['ok' => false, 'error' => 'It is not your turn to sign yet. Please wait for notification.'], 403);
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
            // --- Amendment Detection (Other Conditions) for PDF signing ---
            $otherConditionsText = $request->input('other_conditions_text', '');
            if (!empty(trim($otherConditionsText))) {
                $detectedText = $this->signatureService->detectAmendment($template, $otherConditionsText);
                if ($detectedText !== null) {
                    $amendment = $this->signatureService->createAmendment(
                        $template, $signingRequest, $detectedText, $template->other_conditions_text
                    );
                    if ($amendment) {
                        $signingRequest->update([
                            'status' => SignatureRequest::STATUS_COMPLETED,
                            'completed_at' => now(),
                            'ip_address' => $request->ip(),
                            'user_agent' => $request->userAgent(),
                        ]);
                        $this->signatureService->handleAmendment($template, $amendment, $signingRequest);
                        return response()->json([
                            'ok' => true, 'completed' => true, 'amendment_detected' => true,
                            'amendment_id' => $amendment->id,
                            'message' => 'Your signature has been recorded. The document has been amended and previous signers will be notified for review.',
                            'redirect' => route('signatures.external.completed', $token),
                        ]);
                    }
                }
            }

            // Mark THIS specific request as completed (not just any request for the role)
            $signingRequest->update([
                'status' => SignatureRequest::STATUS_COMPLETED,
                'completed_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            // Flatten any signer-completed fields onto the page images
            $flattener = app(DocumentFlattener::class);
            $flattener->flattenSignerFields($template, $party);

            // Check if ALL requests for this role are now complete before advancing
            $allRoleComplete = $template->requests()
                ->where('party_role', $party)
                ->where('status', '!=', SignatureRequest::STATUS_COMPLETED)
                ->doesntExist();

            if ($allRoleComplete) {
                // All co-owners for this role have signed — advance
                $this->signatureService->handlePartyCompletion($template, $party, $signingRequest);
            } else {
                // More co-owners still need to sign — send to the next one
                $nextCoOwner = $template->requests()
                    ->where('party_role', $party)
                    ->where('status', SignatureRequest::STATUS_WAITING)
                    ->orderBy('signing_order', 'asc')
                    ->first();

                if ($nextCoOwner) {
                    // Set status to pending_agent_approval so agent can review
                    $template->update(['status' => SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL]);

                    // Notify agent about this co-owner completion
                    $this->signatureService->handlePartyCompletion($template, $party, $signingRequest);
                }
            }

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

        // Create version record for tracking
        $document = $signingRequest->template?->document;
        if ($document) {
            foreach ($paths as $path) {
                $ext = pathinfo($path, PATHINFO_EXTENSION);
                \App\Models\Docuperfect\SignedDocumentVersion::create([
                    'document_id' => $document->id,
                    'signature_request_id' => $signingRequest->id,
                    'version_number' => \App\Models\Docuperfect\SignedDocumentVersion::nextVersion($document->id),
                    'file_path' => $path,
                    'file_type' => $ext,
                    'uploaded_by_name' => $signingRequest->signer_name,
                    'uploaded_at' => now(),
                    'ip_address' => $request->ip(),
                ]);
            }
        }

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

        // Web template with merged_html: render document HTML directly via dompdf
        $webTemplateData = $document->web_template_data ?? [];
        $mergedHtml = $webTemplateData['merged_html'] ?? '';

        if (!empty($mergedHtml) && $docTemplate && $docTemplate->render_type === 'web') {
            return $this->downloadWebTemplateAsPdf($signingRequest, $document, $mergedHtml);
        }

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

        $webDataWetInk = $document->web_template_data ?? [];
        $pageCount = !empty($flattenedPages) ? count($flattenedPages) : ($docTemplate ? $docTemplate->page_count : 0);
        if ($pageCount < 1 && !empty($webDataWetInk['flattened_page_count'])) {
            $pageCount = (int) $webDataWetInk['flattened_page_count'];
        }
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

        // Do NOT set signing_method to wet_ink here — downloading does not commit to wet ink.
        // The signing method is set when the signer explicitly chooses via chooseMethod().

        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $document->name) . ' - For Signing.pdf';

        $response = $pdf->download($filename);

        // Clean up temp annotated images
        if ($usingAnnotated) {
            DocumentFlattener::cleanupTempImages($annotatedPages);
        }

        return $response;
    }

    /**
     * Generate a printable PDF from a web template's merged_html.
     * Used when the external signer chooses "Download, Print & Sign".
     */
    private function downloadWebTemplateAsPdf(SignatureRequest $signingRequest, $document, string $mergedHtml)
    {
        // Load corex-document.css inline for dompdf (it cannot resolve external URLs)
        $cssPath = public_path('css/corex-document.css');
        $css = file_exists($cssPath) ? file_get_contents($cssPath) : '';

        // Build a complete HTML document for dompdf
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<style>' . $css . '</style>'
            . '<style>'
            // Overrides for PDF rendering: remove screen-only styling
            . 'body { margin: 0; padding: 0; background: white; font-family: "Plus Jakarta Sans", Arial, Helvetica, sans-serif; font-size: 10.5pt; }'
            . '.corex-document-wrapper { max-width: none; padding: 0; background: white; }'
            . '.corex-page { box-shadow: none; margin: 0; width: auto; min-height: auto; }'
            // Ensure page breaks work at corex-page-break markers
            . '.corex-page-break { page-break-before: always; border-top: none; margin: 4pt 0; padding: 4pt 0; }'
            // Hide interactive UI elements that shouldn't appear in print
            . '.web-sig-prompt { display: none; }'
            . '.web-sig-interactive { border: 1px solid #ccc !important; background: transparent !important; }'
            . '.web-sig-other-party { opacity: 1; }'
            // Signature images should be visible
            . '.web-sig-signed-img { display: block; max-height: 50px; }'
            . '.corex-page-initials .initial-placeholder { font-size: 8px; color: #666; }'
            . '</style>'
            . '</head><body>';

        $html .= $mergedHtml;
        $html .= '</body></html>';

        $pdf = Pdf::loadHTML($html);
        $pdf->setPaper('a4', 'portrait');
        $pdf->setOption('isRemoteEnabled', true);
        $pdf->setOption('isHtml5ParserEnabled', true);

        // Do NOT set signing_method to wet_ink here — downloading does not commit to wet ink.
        // The signing method is set when the signer explicitly chooses via chooseMethod().

        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $document->name) . ' - For Signing.pdf';

        return $pdf->download($filename);
    }

    /**
     * Render the document as a clean printable HTML page.
     * Opens in a new tab — recipient uses browser Print / Save as PDF.
     * Primary path for "Download, Print & Sign" (faster and more reliable than dompdf).
     */
    public function printView($token)
    {
        $signingRequest = SignatureRequest::where('token', $token)
            ->with(['template.document.template'])
            ->firstOrFail();

        if ($signingRequest->isExpired()) {
            return redirect()->route('signatures.external', $token)
                ->with('error', 'Signing link has expired.');
        }

        $signatureTemplate = $signingRequest->template;
        $document = $signatureTemplate->document;
        $docTemplate = $document->template ?? null;
        $webTemplateData = $document->web_template_data ?? [];
        $mergedHtml = $webTemplateData['merged_html'] ?? '';

        if (empty($mergedHtml)) {
            // Fallback to dompdf download for PDF templates
            return redirect()->route('signatures.external.download', $token);
        }

        // Do NOT set signing_method here — viewing/printing does not commit to wet ink.

        return view('docuperfect.signatures.external.print', [
            'document' => $document,
            'mergedHtml' => $mergedHtml,
            'signerName' => $signingRequest->signer_name,
            'token' => $token,
        ]);
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

    // ──────────────────────────────────────────────
    // Section-by-Section Signing (External)
    // ──────────────────────────────────────────────

    /**
     * Accept a section (external signer).
     */
    public function acceptSection(Request $request, $token)
    {
        $signingRequest = SignatureRequest::where('token', $token)->firstOrFail();

        $request->validate([
            'section_index' => 'required|integer|min:0',
            'section_label' => 'required|string|max:255',
            'initial_image' => 'nullable|string',
        ]);

        $acceptance = \App\Models\Docuperfect\SectionAcceptance::updateOrCreate(
            [
                'signature_request_id' => $signingRequest->id,
                'section_index' => $request->section_index,
            ],
            [
                'section_label' => $request->section_label,
                'accepted' => true,
                'rejected' => false,
                'rejection_reason' => null,
                'initialled_at' => now(),
                'initial_image' => $request->initial_image,
            ]
        );

        SignatureAuditLog::log(
            $signingRequest->template,
            'section_accepted',
            SignatureAuditLog::ACTOR_SIGNER,
            $signingRequest->signer_name,
            $signingRequest->signer_email,
            requestId: $signingRequest->id,
            ip: $request->ip(),
            ua: $request->userAgent(),
            metadata: [
                'section_index' => $request->section_index,
                'section_label' => $request->section_label,
            ],
        );

        return response()->json([
            'success' => true,
            'acceptance' => $acceptance,
        ]);
    }

    /**
     * Reject a section (external signer).
     */
    public function rejectSection(Request $request, $token)
    {
        $signingRequest = SignatureRequest::where('token', $token)->firstOrFail();

        $request->validate([
            'section_index' => 'required|integer|min:0',
            'section_label' => 'required|string|max:255',
            'rejection_reason' => 'required|string|max:2000',
        ]);

        $acceptance = \App\Models\Docuperfect\SectionAcceptance::updateOrCreate(
            [
                'signature_request_id' => $signingRequest->id,
                'section_index' => $request->section_index,
            ],
            [
                'section_label' => $request->section_label,
                'accepted' => false,
                'rejected' => true,
                'rejection_reason' => $request->rejection_reason,
                'initialled_at' => null,
                'initial_image' => null,
            ]
        );

        SignatureAuditLog::log(
            $signingRequest->template,
            'section_rejected',
            SignatureAuditLog::ACTOR_SIGNER,
            $signingRequest->signer_name,
            $signingRequest->signer_email,
            requestId: $signingRequest->id,
            ip: $request->ip(),
            ua: $request->userAgent(),
            metadata: [
                'section_index' => $request->section_index,
                'section_label' => $request->section_label,
                'rejection_reason' => $request->rejection_reason,
            ],
        );

        // Notify the agent about the rejection
        $this->sendSectionRejectionNotification($signingRequest, $request->section_label, $request->rejection_reason);

        return response()->json([
            'success' => true,
            'acceptance' => $acceptance,
        ]);
    }

    /**
     * Get section progress for an external signer.
     */
    public function getSectionProgress(Request $request, $token)
    {
        $signingRequest = SignatureRequest::where('token', $token)
            ->with('sectionAcceptances')
            ->firstOrFail();

        $template = $signingRequest->template;
        $sections = $template->sections_json ?? [];

        $progress = [];
        foreach ($sections as $idx => $section) {
            $acceptance = $signingRequest->sectionAcceptances->firstWhere('section_index', $idx);
            $progress[] = [
                'index' => $idx,
                'label' => $section['label'] ?? "Section " . ($idx + 1),
                'accepted' => $acceptance?->accepted ?? false,
                'rejected' => $acceptance?->rejected ?? false,
                'rejection_reason' => $acceptance?->rejection_reason,
                'initialled_at' => $acceptance?->initialled_at?->toIso8601String(),
            ];
        }

        return response()->json([
            'sections' => $sections,
            'progress' => $progress,
            'total' => count($sections),
            'accepted' => collect($progress)->where('accepted', true)->count(),
            'rejected' => collect($progress)->where('rejected', true)->count(),
        ]);
    }

    /**
     * Notify agent about a section rejection.
     */
    private function sendSectionRejectionNotification(SignatureRequest $signingRequest, string $sectionLabel, string $reason): void
    {
        try {
            $template = $signingRequest->template;
            $template->loadMissing(['document', 'creator']);
            $agent = $template->creator;

            if ($agent && $agent->email) {
                Mail::raw(
                    "{$signingRequest->signer_name} has rejected section \"{$sectionLabel}\" on document \"{$template->document->name}\".\n\n" .
                    "Reason: {$reason}\n\n" .
                    "Please review and take action.",
                    function ($message) use ($agent, $template) {
                        $message->to($agent->email)
                            ->subject("Section Rejected — {$template->document->name}");
                    }
                );
            }
        } catch (\Exception $e) {
            Log::error('Failed to send section rejection notification', ['error' => $e->getMessage()]);
        }
    }

    // ──────────────────────────────────────────────
    // Amendment Review (External — re-signing)
    // ──────────────────────────────────────────────

    /**
     * Show amendment review page for external signer (token-based, no auth).
     */
    public function amendmentReview(Request $request, $token)
    {
        $signingRequest = SignatureRequest::where('token', $token)
            ->with(['template.document', 'template.amendments.acceptances'])
            ->firstOrFail();

        if ($signingRequest->isExpired()) {
            return view('docuperfect.signatures.external.expired', [
                'request' => $signingRequest,
            ]);
        }

        $template = $signingRequest->template;

        // Get pending amendments that need this signer's acceptance
        $pendingAmendments = $template->amendments()
            ->where('status', \App\Models\Docuperfect\DocumentAmendment::STATUS_PENDING)
            ->with(['amendedByRequest', 'acceptances' => function ($q) use ($signingRequest) {
                $q->where('signature_request_id', $signingRequest->id);
            }])
            ->get();

        $branding = $this->getAgencyBranding($signingRequest);

        return view('docuperfect.signatures.external.amendment-review', [
            'request' => $signingRequest,
            'template' => $template,
            'document' => $template->document,
            'amendments' => $pendingAmendments,
            'branding' => $branding,
            'token' => $token,
        ]);
    }

    /**
     * Accept a single amendment (external signer initials it).
     */
    public function acceptAmendment(Request $request, $token, $amendmentId)
    {
        $signingRequest = SignatureRequest::where('token', $token)->firstOrFail();

        if ($signingRequest->isExpired()) {
            return response()->json(['ok' => false, 'error' => 'Link expired.'], 410);
        }

        $amendment = \App\Models\Docuperfect\DocumentAmendment::findOrFail($amendmentId);
        $initialImage = $request->input('initial_image');

        $acceptance = $this->signatureService->acceptAmendment($amendment, $signingRequest, $initialImage);

        return response()->json([
            'ok' => true,
            'accepted' => true,
            'acceptance_id' => $acceptance->id,
        ]);
    }

    /**
     * Reject a single amendment (external signer gives reason).
     */
    public function rejectAmendment(Request $request, $token, $amendmentId)
    {
        $signingRequest = SignatureRequest::where('token', $token)->firstOrFail();

        if ($signingRequest->isExpired()) {
            return response()->json(['ok' => false, 'error' => 'Link expired.'], 410);
        }

        $amendment = \App\Models\Docuperfect\DocumentAmendment::findOrFail($amendmentId);
        $reason = $request->input('reason', '');

        if (empty(trim($reason))) {
            return response()->json(['ok' => false, 'error' => 'A reason is required when rejecting an amendment.'], 422);
        }

        $acceptance = $this->signatureService->rejectAmendment($amendment, $signingRequest, $reason);

        return response()->json([
            'ok' => true,
            'rejected' => true,
            'acceptance_id' => $acceptance->id,
        ]);
    }
}
