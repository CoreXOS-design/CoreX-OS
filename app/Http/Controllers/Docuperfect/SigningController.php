<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Docuperfect\ConditionInitial;
use App\Models\Docuperfect\DocumentAmendment;
use App\Models\Docuperfect\DocumentClauseStrikethrough;
use App\Models\Docuperfect\DocumentCondition;
use App\Models\Docuperfect\ESignConsentLog;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureMarker;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\FicaSubmission;
use App\Services\Docuperfect\DocumentFlattener;
use App\Services\Docuperfect\SignatureService;
use App\Services\Docuperfect\LetterheadRefresher;
use App\Services\Docuperfect\SignatureSurfaceNormalizer;
use App\Services\WebTemplateFieldPartyMap;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        // Phase 1B.7 (FIX H) — BUT bypass this early-return when the
        // parent template is in an amendment-initialing cascade. The
        // recipient previously signed; an amendment was raised and the
        // agent approved it; the recipient must now initial the changed
        // regions. Falling through to the show() body lets the existing
        // showInitialingView() switch (Phase 1B.5) route them into the
        // focused initialing view.
        if ($signingRequest->status === SignatureRequest::STATUS_COMPLETED
            && optional($signingRequest->template)->status !== SignatureTemplate::STATUS_AMENDMENT_INITIALING
        ) {
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

        // Phase 1B.5 — focused initialing view-switch.
        // When the parent template's amendment_status indicates an
        // initialing cascade is in progress, recipients land on a focused
        // view showing only changed regions (not the entire document).
        $tplForSwitch = $signingRequest->template;
        if ($tplForSwitch
            && $tplForSwitch->status === SignatureTemplate::STATUS_AMENDMENT_INITIALING
            && $tplForSwitch->amendment_status === SignatureTemplate::AMENDMENT_STATUS_INITIALING
        ) {
            return $this->showInitialingView($signingRequest, $token);
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

        // FICA gate — external signers must have submitted FICA before signing
        if ($signingRequest->fica_required && $signingRequest->contact_id) {
            $ficaApproved = FicaSubmission::where('contact_id', $signingRequest->contact_id)
                ->whereIn('status', ['submitted', 'under_review', 'agent_approved', 'approved'])
                ->exists();

            if (! $ficaApproved) {
                $ficaSub = $signingRequest->fica_submission_id
                    ? FicaSubmission::find($signingRequest->fica_submission_id)
                    : FicaSubmission::where('contact_id', $signingRequest->contact_id)
                        ->whereIn('status', ['draft', 'submitted', 'under_review', 'agent_approved'])
                        ->orderByDesc('created_at')
                        ->orderByDesc('id')
                        ->first();

                $signingUrl = route('signatures.external', $token);

                // Defensive: fica_submissions.token is nullable; tokenless
                // submissions exist (reused drafts, seeder/wet-ink/legacy).
                // route('fica.form', null) throws UrlGenerationException and
                // 500s the signing page. Mint a token if one is missing so
                // the FICA link always works — the page must never 500 here.
                if ($ficaSub && empty($ficaSub->token)) {
                    $ficaSub->token = \Illuminate\Support\Str::random(64);
                    $ficaSub->token_expires_at = now()->addDays(14);
                    $ficaSub->save();
                }

                $ficaUrl = ($ficaSub && $ficaSub->token)
                    ? route('fica.form', $ficaSub->token) . '?return_url=' . urlencode($signingUrl)
                    : null;

                // Determine FICA status for gate display
                $ficaStatus = 'none';
                if ($ficaSub) {
                    $ficaStatus = in_array($ficaSub->status, ['submitted', 'under_review', 'agent_approved'])
                        ? 'pending_review'
                        : 'needs_form';
                }

                $branding = $this->getAgencyBranding($signingRequest);

                return view('docuperfect.signatures.external.fica-gate', [
                    'request'     => $signingRequest,
                    'ficaUrl'     => $ficaUrl,
                    'ficaStatus'  => $ficaStatus,
                    'signingUrl'  => $signingUrl,
                    'agencyName'  => $branding['name'],
                    'agencyLogo'  => $branding['logo'],
                    'agencyColor' => $branding['color'],
                ]);
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

            // Inline templates carry data-marker-party on a .signature-col /
            // .signature-section wrapper but never emit data-marker-type, so the
            // signing engine's [data-marker-party][data-marker-type="signature"]
            // selector finds zero surfaces. Normalise additively so every web
            // template is signable without touching the template files (BL-5/6).
            $webTemplateHtml = SignatureSurfaceNormalizer::normalize($webTemplateHtml);

            // Re-resolve the letterhead so a stored merged_html snapshot
            // never serves stale agency data ("The Mandate Company /
            // Margate") at signing — always show CURRENT agency.
            $webTemplateHtml = LetterheadRefresher::refresh($webTemplateHtml);

            // Phase 1B.5 — replace `~~~~MARKER~~~~` tokens with styled
            // insertable-block partials. Recipient context wires the
            // "+ Add condition" affordance + per-condition initial slots
            // (Phase 1B.7). Phase 1B.7 also passes the current party_role
            // so the renderer can mark THIS party's pending initial slots
            // as actionable.
            $blocksMeta = $docTemplate->insertable_blocks ?? [];
            $webTemplateHtml = app(\App\Services\Docuperfect\InsertableBlockRenderer::class)
                ->renderInDocument(
                    $webTemplateHtml,
                    $template,
                    is_array($blocksMeta) ? $blocksMeta : [],
                    \App\Services\Docuperfect\InsertableBlockRenderer::CONTEXT_RECIPIENT_SIGNING,
                    $token,
                    $signingRequest->party_role
                );

            // Recipient Loop Engine — B2.5/B3 expansion pass. Detects role
            // blocks, duplicates single-block templates per recipient with
            // per-instance contact pre-fill, AND (B3) stamps
            // `data-viewer-editable="1"` on every field this signing
            // recipient is authorised to edit so the signing-view JS can
            // gate input rendering by attribute, not by name-lookup.
            $allRecipientsForTemplate = SignatureRequest::where('signature_template_id', $template->id)->get();
            $fieldMappingsRaw = is_array($docTemplate->field_mappings ?? null)
                ? $docTemplate->field_mappings
                : [];
            $webTemplateHtml = app(\App\Services\Docuperfect\RoleBlockExpansionService::class)
                ->expandWithLooping(
                    $docTemplate,
                    $webTemplateHtml,
                    $allRecipientsForTemplate,
                    $signingRequest,
                    $fieldMappingsRaw,
                );
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

        $signingParties = collect($template->parties_json ?? [])->map(fn($p) => [
            'role' => $p['role'] ?? 'unknown',
            'label' => ucfirst(str_replace('_', ' ', $p['role_label'] ?? $p['role'] ?? 'unknown')),
        ])->values()->toArray();

        // Phase 1B.6 (FIX 4) — extract numbered clauses from the body for
        // the Add Condition + Flag Clause modal pickers.
        $numberedClauses = $this->extractNumberedClauses($webTemplateHtml);

        // Phase 1B.6 (FIX 6) — seed the persisted clause flags state so a
        // page refresh restores the visible flag UI (the legacy webData
        // path only persisted at signComplete; reading it back means a
        // mid-session flag survives reload).
        $persistedClauseFlags = $webTemplateData['clause_flags'] ?? [];

        // Phase 1B.6 (FIX 5) — flag whether this signing party has already
        // completed signing. The view uses this to render captured
        // signatures read-only instead of "click to sign" affordances when
        // the document is in an amendment cycle.
        $partyAlreadySigned = $signingRequest->status === SignatureRequest::STATUS_COMPLETED
            || $signingRequest->completed_at !== null;
        $inAmendmentInitialing = $template->status === SignatureTemplate::STATUS_AMENDMENT_INITIALING;

        // CONSENT GATE — Apply-to-all initials is an agent-only affordance.
        // Recipients must initial each page individually for legal informed-
        // consent reasons (each initial = explicit affirm).
        //
        // The gate is `signingRequest.party_role === 'agent'` ALONE. The
        // viewing browser session's permissions are NOT consulted: a
        // dispatching agent who opens a recipient's signing link in their
        // own browser (testing, screen-share, supervision) must NOT inherit
        // the apply-to-all bypass — that token belongs to the recipient,
        // and the recipient's per-page consent surface is what renders.
        //
        // The previous OR-with-hasPermission predicate (pre-2026-05-27) is
        // the bug fixed by .ai/audits/esign-reset-investigation-2026-05-27.md
        // Q4 — it conflated viewer permissions with token identity and
        // exposed a legal bypass.
        $isAgent = ($signingRequest->party_role === 'agent');

        return view('docuperfect.signatures.external.sign', [
            'request' => $signingRequest,
            'currentRecipient' => $signingRequest,        // B1 — alias for the loop-engine downstream layers
            'currentRoleIdentity' => $signingRequest->role_identity,  // B1 — '{party_role}_{role_index}'
            'template' => $template,
            'document' => $document,
            'docTemplate' => $docTemplate,                // B3 info panel — isSalesDocument() / role labelling
            'isRecipientSigningView' => true,             // B3 info panel — render flag (false on agent wizard)
            'numberedClauses' => $numberedClauses,
            'persistedClauseFlags' => $persistedClauseFlags,
            'partyAlreadySigned' => $partyAlreadySigned,
            'inAmendmentInitialing' => $inAmendmentInitialing,
            'isAgent' => $isAgent,
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
            'signingParties' => $signingParties,
            'storedInitials' => $webTemplateData['signed_initials'] ?? [],
            'storedDisclosure' => $webTemplateData['disclosure_answers'] ?? [],
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
     *
     * B3 hardening — every incoming field is validated against the
     * server-side per-recipient editable scope (RoleBlockExpansionService's
     * identity + role rule). DOM trust is never the security layer:
     * even if a malicious client strips data-viewer-editable client-side,
     * the server re-derives editability from field_mappings + viewer
     * identity. Any field the viewer cannot edit triggers a 403 + audit
     * log entry (one per violation, never silently dropped).
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

        $docTemplate = $document->template;
        $fieldMappingsRaw = is_array($docTemplate?->field_mappings ?? null)
            ? $docTemplate->field_mappings
            : [];

        $authResult = $this->authoriseWebFieldWrite(
            $signingRequest,
            $incomingFields,
            $fieldMappingsRaw,
        );
        if ($authResult['violation'] !== null) {
            SignatureAuditLog::create([
                'signature_template_id' => $signingRequest->template->id,
                'action' => 'web_fields_save_denied',
                'actor_type' => SignatureAuditLog::ACTOR_SIGNER,
                'actor_name' => $signingRequest->signer_name,
                'actor_email' => $signingRequest->signer_email,
                'actor_ip_address' => $request->ip(),
                'actor_user_agent' => $request->userAgent(),
                'signature_request_id' => $signingRequest->id,
                'metadata_json' => [
                    'actor_role_identity' => $signingRequest->role_identity,
                    'denied_field'        => $authResult['violation']['field'],
                    'denied_identity'     => $authResult['violation']['identity'],
                    'reason'              => $authResult['violation']['reason'],
                ],
            ]);
            return response()->json([
                'ok'    => false,
                'error' => 'Field not editable by current party',
                'field' => $authResult['violation']['field'],
            ], 403);
        }

        $existingData = $document->web_template_data ?? [];
        $updated = false;

        foreach ($authResult['accepted'] as $logicalName => $value) {
            $existingData[$logicalName] = $value;
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
            'metadata_json' => [
                'party_role'         => $partyRole,
                'actor_role_identity'=> $signingRequest->role_identity,
                'field_count'        => count($authResult['accepted']),
            ],
        ]);

        return response()->json(['ok' => true, 'saved' => count($authResult['accepted'])]);
    }

    /**
     * Per-recipient field-write authorisation.
     *
     * Accepts two payload shapes for backward compat:
     *   1. Legacy flat:   `fields: { field_name: "value", ... }`
     *      → resolved against the viewer's party-role + WebTemplateFieldPartyMap.
     *   2. B3 identity:   `fields: { field_name__r2: { value, identity, original_field } }`
     *      → identity must match the viewer's role_identity AND the field's
     *        editable_by must include the viewer's canonical role token.
     *
     * Returns:
     *   - `accepted` : map of logical field name → value to persist.
     *   - `violation`: first denial seen, or null when every field is allowed.
     *
     * @param  array<string, mixed>                                         $incomingFields
     * @param  array<string, array<string, mixed>>                          $fieldMappingsRaw
     * @return array{accepted: array<string, mixed>, violation: ?array<string, string>}
     */
    private function authoriseWebFieldWrite(
        SignatureRequest $signingRequest,
        array $incomingFields,
        array $fieldMappingsRaw,
    ): array {
        $partyRole       = strtolower((string) $signingRequest->party_role);
        $viewerIdentity  = strtolower((string) $signingRequest->role_identity);
        $isAgent         = $partyRole === 'agent';

        $roleToEditableBy = [
            'landlord' => 'owner_party',
            'lessor'   => 'owner_party',
            'seller'   => 'owner_party',
            'tenant'   => 'acquiring_party',
            'lessee'   => 'acquiring_party',
            'buyer'    => 'acquiring_party',
            'agent'    => 'agent',
            'witness'  => 'witness',
        ];
        $canonicalForViewer = $roleToEditableBy[$partyRole] ?? $partyRole;

        // Build name → editable_by[] map (mirrors the expansion service).
        $editableByByName = [];
        foreach ($fieldMappingsRaw as $mapping) {
            if (!is_array($mapping)) continue;
            $editableBy = $mapping['editable_by'] ?? [];
            if (!is_array($editableBy)) continue;
            $name = $mapping['field_name'] ?? null;
            if (!is_string($name) || $name === '') {
                $label = (string) ($mapping['label'] ?? '');
                if ($label === '') continue;
                $name = strtolower(trim($label));
                $name = preg_replace('/[^a-z0-9]+/', '_', $name);
                $name = trim((string) $name, '_');
            }
            if (is_string($name) && $name !== '') {
                $editableByByName[$name] = $editableBy;
            }
        }

        $accepted = [];
        foreach ($incomingFields as $fieldKey => $payload) {
            if (!is_string($fieldKey)) continue;

            // Resolve value + identity from either payload shape.
            if (is_array($payload)) {
                $value             = $payload['value'] ?? null;
                $claimedIdentity   = strtolower((string) ($payload['identity'] ?? ''));
                $declaredOriginal  = (string) ($payload['original_field'] ?? '');
            } else {
                $value             = $payload;
                $claimedIdentity   = '';
                $declaredOriginal  = '';
            }
            $logicalName = $declaredOriginal !== ''
                ? $declaredOriginal
                : preg_replace('/__r\d+$/', '', $fieldKey);
            if (!is_string($logicalName) || $logicalName === '') continue;

            $editableBy = $editableByByName[$logicalName] ?? null;

            // Backward-compat lane: no field_mappings (legacy PDF template).
            // Defer to the static party-map.
            if ($editableBy === null) {
                $allowed = WebTemplateFieldPartyMap::getEditableFields($partyRole);
                if (in_array($logicalName, $allowed, true)) {
                    $accepted[$logicalName] = $value;
                    continue;
                }
                return [
                    'accepted'  => $accepted,
                    'violation' => [
                        'field'    => (string) $fieldKey,
                        'identity' => $claimedIdentity,
                        'reason'   => 'Field not in static party map for role ' . $partyRole,
                    ],
                ];
            }

            $allowsAll  = in_array('all', $editableBy, true);
            $roleAllows = $allowsAll || in_array($canonicalForViewer, $editableBy, true);

            if ($isAgent) {
                if ($allowsAll || in_array('agent', $editableBy, true)) {
                    $accepted[$logicalName] = $value;
                    continue;
                }
            } elseif ($roleAllows) {
                // Per-instance identity check: when the incoming payload
                // claims an identity, the viewer's must match. When no
                // identity is claimed (legacy client), require the
                // logical field to be assignable to the viewer's role
                // and accept (Case B/legacy single-recipient path).
                if ($claimedIdentity === '' || $claimedIdentity === $viewerIdentity) {
                    $accepted[$logicalName] = $value;
                    continue;
                }
            }

            return [
                'accepted'  => $accepted,
                'violation' => [
                    'field'    => (string) $fieldKey,
                    'identity' => $claimedIdentity,
                    'reason'   => $isAgent
                        ? 'Agent role not present in editable_by for field ' . $logicalName
                        : 'Viewer ' . $viewerIdentity . ' not authorised for field ' . $logicalName,
                ],
            ];
        }

        return ['accepted' => $accepted, 'violation' => null];
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

            // ES-4 — Promote each flag to a first-class DocumentAmendment row
            // so it flows through the same agent review surface used by
            // Other Conditions + Strikethroughs (Phase 1B). The JSON note in
            // web_template_data is preserved for backward compatibility and
            // forensic context; it is no longer the sole record.
            $this->promoteClauseFlagsToAmendments(
                $signingRequest,
                $document,
                $clauseFlags
            );
        }

        // Save signatures (base64 data URIs keyed by block ID)
        $signatures = $request->input('signatures', []);
        if (!empty($signatures)) {
            $existingSigs = $webData['signatures'] ?? [];
            $webData['signatures'] = array_merge($existingSigs, $signatures);
        }

        // Separate initials into signed_initials so review/print can restore them
        $partyRole = $signingRequest->party_role;
        $initials = [];
        foreach ($signatures as $key => $value) {
            if (str_contains($key, '-init-')) {
                $initials[$key] = $value;
            }
        }
        // Also capture page-break initials sent as separate 'initials' input
        $pageBreakInitials = $request->input('initials', []);
        if (!empty($pageBreakInitials)) {
            $initials = array_merge($initials, $pageBreakInitials);
        }
        if (!empty($initials)) {
            $existingInitials = $webData['signed_initials'] ?? [];
            $existingInitials[$partyRole] = $initials;
            $webData['signed_initials'] = $existingInitials;
        }

        // §19 Option 2 — the client posts the EXACT signed-and-paginated DOM,
        // but it is NOT fed back into merged_html (doing so caused the
        // re-pagination accretion loop: accreted .corex-a4-page, stacked
        // stale footers, inflated gate). merged_html stays the CANONICAL,
        // UN-paginated document; the embed step below applies THIS signer's
        // values to its un-paginated markers so the next signer, loading
        // canonical merged_html, sees all prior marks. The paginated DOM is
        // persisted ONCE to signed_paginated_html (below) and consumed only
        // by splitMergedHtml()/the PDF generator — never re-paginated.
        $paginatedHtml = (string) $request->input('paginated_html', '');

        // Embed this signer's signatures, initials, and ceremony values into merged_html
        if (!empty($webData['merged_html']) && (!empty($signatures) || !empty($pageBreakInitials) || !empty($ceremonyValues))) {
            $sigController = app(SignatureController::class);
            // Stamp the engine's signature convention onto inline templates so
            // embedSignaturesIntoHtml's [data-marker-party][data-marker-type=
            // "signature"] match finds the surface and the final PDF carries
            // the signature (idempotent — no-op once embedded). BL-5/6.
            $html = SignatureSurfaceNormalizer::normalize($webData['merged_html']);
            if (!empty($signatures)) {
                $html = $sigController->embedSignaturesIntoHtml($html, $signatures, $signingRequest->party_role, $signingRequest->signer_name ?? '');
            }
            if (!empty($pageBreakInitials)) {
                $html = $sigController->embedInitialsIntoHtml($html, $pageBreakInitials, $signingRequest->party_role, $signingRequest->signer_name ?? '');
            }
            if (!empty($ceremonyValues)) {
                $html = $sigController->embedCeremonyValuesIntoHtml($html, $ceremonyValues);
            }
            $webData['merged_html'] = $html;
        }

        // Two-write: canonical un-paginated merged_html (above) + the exact
        // signed paginated DOM persisted ONCE to the derived-artifact column.
        $updates = ['web_template_data' => $webData];
        if (trim($paginatedHtml) !== '' && (
                str_contains($paginatedHtml, 'corex-a4-page') ||
                str_contains($paginatedHtml, 'corex-document-wrapper'))) {
            $updates['signed_paginated_html'] = $paginatedHtml;
        }
        $document->update($updates);

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

            // Save ceremony values (date, location, time) if provided
            $ceremonyValues = $request->input('ceremony_values', []);
            if (!empty($ceremonyValues)) {
                $document = $template->document;
                $webData = $document->web_template_data ?? [];
                $webData['ceremony_values'] = array_merge($webData['ceremony_values'] ?? [], $ceremonyValues);

                // Embed into merged_html if present (web templates)
                if (!empty($webData['merged_html'])) {
                    $sigController = app(\App\Http\Controllers\Docuperfect\SignatureController::class);
                    $webData['merged_html'] = $sigController->embedCeremonyValuesIntoHtml($webData['merged_html'], $ceremonyValues);
                }

                $document->update(['web_template_data' => $webData]);
            }

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

        // Web template with merged_html: redirect to print view (no dompdf — it hangs)
        $webTemplateData = $document->web_template_data ?? [];
        $mergedHtml = $webTemplateData['merged_html'] ?? '';

        if (!empty($mergedHtml) && $docTemplate && $docTemplate->render_type === 'web') {
            return redirect()->route('signatures.external.print', $token);
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

        $signingParties = collect($signatureTemplate->parties_json ?? [])->map(fn($p) => [
            'role' => $p['role'] ?? 'unknown',
            'label' => ucfirst(str_replace('_', ' ', $p['role_label'] ?? $p['role'] ?? 'unknown')),
        ])->values()->toArray();

        return view('docuperfect.signatures.external.print', [
            'document' => $document,
            'mergedHtml' => $mergedHtml,
            'signerName' => $signingRequest->signer_name,
            'token' => $token,
            'signingParties' => $signingParties,
            'storedInitials' => $webTemplateData['signed_initials'] ?? [],
            'signingMethod' => $signingRequest->signing_method,
        ]);
    }

    /**
     * Generate and download a proper PDF for web template documents via Puppeteer.
     * Uses html-to-pdf.mjs to produce A4-formatted PDF with correct margins.
     */
    public function downloadWebPdf($token)
    {
        set_time_limit(120);

        $signingRequest = SignatureRequest::where('token', $token)
            ->with(['template.document.template'])
            ->firstOrFail();

        if ($signingRequest->isExpired()) {
            return response()->json(['error' => 'Signing link has expired.'], 410);
        }

        $signatureTemplate = $signingRequest->template;
        $document = $signatureTemplate->document;
        $webTemplateData = $document->web_template_data ?? [];
        $mergedHtml = $webTemplateData['merged_html'] ?? '';

        if (empty($mergedHtml)) {
            return response()->json(['error' => 'Document content not available for PDF generation.'], 404);
        }

        try {
            $outputPath = $this->generatePdfFromHtml($mergedHtml, $document->id);
        } catch (\Throwable $e) {
            Log::error('downloadWebPdf — exception during PDF generation', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'PDF generation failed: ' . $e->getMessage()], 500);
        }

        if (!$outputPath || !file_exists($outputPath) || filesize($outputPath) === 0) {
            Log::error('downloadWebPdf — PDF generation failed', ['document_id' => $document->id]);
            @unlink($outputPath);
            return response()->json(['error' => 'PDF generation failed.'], 500);
        }

        $docName = $document->name ?? 'Document';
        $safeDocName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $docName);
        $filename = $safeDocName . '_' . date('Y-m-d') . '.pdf';

        return response()->download($outputPath, $filename)->deleteFileAfterSend(true);
    }

    /**
     * Generate a PDF from merged HTML using Puppeteer html-to-pdf.mjs.
     *
     * @return string|null Path to generated PDF, or null on failure
     */
    public function generatePdfFromHtml(string $mergedHtml, int $documentId): ?string
    {
        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $timestamp = time();
        $htmlPath = $tempDir . '/doc_' . $documentId . '_' . $timestamp . '.html';
        $pdfPath = $tempDir . '/doc_' . $documentId . '_' . $timestamp . '.pdf';

        // Wrap merged_html in a full HTML document shell matching WebTemplatePdfService::wrapHtml()
        $fullHtml = $this->wrapHtmlForPdf($mergedHtml);
        file_put_contents($htmlPath, $fullHtml);

        $startTime = time();

        // Puppeteer (Chromium) — primary PDF generator on all platforms
        // Build command — same pattern as WebTemplatePdfService::runPuppeteerFlatten()
        $scriptPath = base_path('scripts/html-to-pdf.mjs');
        $browserPath = config('services.pdf.puppeteer_browser_path', '');
        $isWindows = DIRECTORY_SEPARATOR === '\\';

        // Resolve full node path — proc_open may not have PATH on Windows
        $nodePath = 'node';
        if ($isWindows) {
            $candidates = [
                'C:\\Program Files\\nodejs\\node.exe',
                'C:\\Program Files (x86)\\nodejs\\node.exe',
                trim(shell_exec('where node 2>NUL') ?? ''),
            ];
            foreach ($candidates as $candidate) {
                $candidate = trim($candidate);
                if ($candidate && file_exists($candidate)) {
                    $nodePath = $candidate;
                    break;
                }
            }
        }

        $nodeArg = escapeshellarg(str_replace('\\', '/', $nodePath));
        $scriptArg = escapeshellarg(str_replace('\\', '/', $scriptPath));
        $htmlArg = escapeshellarg(str_replace('\\', '/', $htmlPath));
        $outArg = escapeshellarg(str_replace('\\', '/', $pdfPath));

        $envPrefix = '';
        if (!$isWindows) {
            $envPrefix = 'HOME=/tmp';
            if ($browserPath) {
                $envPrefix .= sprintf(' PUPPETEER_BROWSER_PATH=%s', escapeshellarg($browserPath));
            }
            $envPrefix .= ' ';
        }

        $command = sprintf('%s%s %s %s %s', $envPrefix, $nodeArg, $scriptArg, $htmlArg, $outArg);

        Log::info('PDF generation starting (Puppeteer)', ['doc_id' => $documentId, 'command' => $command]);

        $logPath = $tempDir . DIRECTORY_SEPARATOR . 'pdf_gen_' . $documentId . '.log';

        // Synchronous call with output redirected to file
        // Output redirect prevents PHP from waiting for Chrome child processes
        $fullCommand = $command . ' > ' . escapeshellarg(str_replace('/', DIRECTORY_SEPARATOR, $logPath)) . ' 2>&1';

        Log::info('PDF executing', ['command' => $fullCommand]);

        // shell_exec with output redirect — PHP waits for the main process
        // but Chrome children detach on their own
        $result = shell_exec($fullCommand);

        // Read the log to check result
        $logContent = file_exists($logPath) ? file_get_contents($logPath) : '';
        @unlink($logPath);

        Log::info('PDF execution done', [
            'doc_id' => $documentId,
            'seconds' => time() - $startTime,
            'log' => substr($logContent, 0, 500),
        ]);

        // Check if PDF was created
        clearstatcache();
        $normalizedOutput = str_replace('/', DIRECTORY_SEPARATOR, $pdfPath);

        if (!file_exists($normalizedOutput) || filesize($normalizedOutput) === 0) {
            @unlink($htmlPath);
            throw new \RuntimeException('PDF not generated. Log: ' . substr($logContent, 0, 200));
        }

        $pdfPath = $normalizedOutput;
        @unlink($htmlPath);

        Log::info('PDF generation complete', [
            'doc_id' => $documentId,
            'seconds' => time() - $startTime,
            'path' => $pdfPath,
            'size' => filesize($pdfPath),
        ]);

        return $pdfPath;
    }

    /**
     * Wrap merged HTML in a full document shell for Puppeteer PDF generation.
     * Mirrors WebTemplatePdfService::wrapHtml() structure with additional
     * CSS for clean PDF output (no interactive UI elements).
     */
    public function wrapHtmlForPdf(string $mergedHtml): string
    {
        // Load the full CDS stylesheet — this is what makes web documents look correct
        $cdsStylesheet = '';
        $cssPath = public_path('css/corex-document.css');
        if (file_exists($cssPath)) {
            $cdsStylesheet = file_get_contents($cssPath);
        }

        $cleanupCss = $this->getPdfCleanupCss();
        $brandFontCss = $this->embeddedBrandFontFaces();

        // Zero external requests during PDF render: strip any remote Google
        // Fonts <link>/@import the stored merged_html may carry. Chromium
        // loading these over a network it cannot reach is what hung
        // page.goto(networkidle0) for the full timeout. Fonts are now
        // self-hosted via $brandFontCss (embedded below).
        $mergedHtml = preg_replace(
            '#<link\b[^>]*href=["\']https?://fonts\.(?:googleapis|gstatic)\.com[^"\']*["\'][^>]*>#i',
            '',
            $mergedHtml
        );
        $mergedHtml = preg_replace(
            '#@import\s+(?:url\()?["\']?https?://fonts\.(?:googleapis|gstatic)\.com[^;]*;#i',
            '',
            $mergedHtml
        );

        // Zero external requests of ANY kind: inline local storage assets
        // (agency logo etc.) as base64 data: URIs and neutralise any
        // remaining remote/loopback <img>/<link>/<script>. Runs BEFORE the
        // path branch so BOTH return paths emit a fully self-contained doc
        // (the loopback logo on a single-threaded dev server was the last
        // network dependency that could stall page.goto).
        $mergedHtml = $this->inlineLocalAssetsAndStripRemote($mergedHtml);

        $pdfStyles = <<<CSS
/* === Self-hosted brand fonts (embedded — NO external network) === */
{$brandFontCss}

/* === CDS Document Stylesheet (inlined from corex-document.css) === */
{$cdsStylesheet}

/* === PDF: page setup === */
@page {
    size: A4;
    margin: 18mm 20mm;
    @bottom-center {
        content: "Page " counter(page) " of " counter(pages);
        font-size: 9pt;
        color: #94a3b8;
    }
}

/* === PDF: basic resets === */
body { margin: 0; padding: 0; }
html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }

/* === PDF: scale + screen → print container resets === */
.corex-document-wrapper {
    zoom: 0.82;
    max-width: 100% !important;
    background: transparent !important;
    padding: 0 !important;
    margin: 0 !important;
}
.corex-page, .page {
    width: 100% !important;
    max-width: 100% !important;
    min-height: auto !important;
    box-shadow: none !important;
    background: white !important;
    margin: 0 !important;
    padding: 0 !important;
    border: none !important;
    border-radius: 0 !important;
}
.corex-a4-page {
    min-height: auto;
    box-shadow: none;
    margin: 0;
    padding: 0;
}
.corex-page-gap { display: none; }

/* === PDF: page-break rules === */
.corex-clause, .corex-clause-indent-1, .corex-clause-indent-2, .corex-clause-indent-3 {
    page-break-inside: avoid;
}
.corex-h1, .corex-h2, .corex-h3, .corex-section-heading {
    page-break-after: avoid;
}
.corex-signature-section, .corex-signature-grid, .corex-signature-block,
.corex-ceremony-section,
[class*="thus-done"],
[class*="signature-block"] {
    page-break-inside: avoid !important;
}
.corex-header, .corex-title-banner {
    page-break-inside: avoid;
    page-break-after: avoid;
}
.corex-table tr, .corex-disclosure-table tr {
    page-break-inside: avoid;
}

/* === PDF: hide interactive elements === */
{$cleanupCss}
CSS;

        // If it already has a DOCTYPE or <html> tag, inject all styles before </head>
        if (preg_match('/<!DOCTYPE|<html/i', $mergedHtml)) {
            $styleTag = '<style>' . $pdfStyles . '</style>';
            if (preg_match('/<\/head>/i', $mergedHtml)) {
                return preg_replace('/<\/head>/i', $styleTag . '</head>', $mergedHtml, 1);
            }
            return $mergedHtml;
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        {$pdfStyles}
    </style>
</head>
<body>
{$mergedHtml}
</body>
</html>
HTML;
    }

    /**
     * Self-hosted @font-face rules for the document's brand families,
     * embedded as base64 data: URIs so PDF rendering makes ZERO external
     * network requests (the remote Google Fonts <link> previously hung
     * Puppeteer's networkidle0 for the full timeout when the server could
     * not reach fonts.googleapis.com).
     *
     * The brand woff2 (Plus Jakarta Sans / Dancing Script) are not bundled
     * in the repo and cannot be fetched offline; the bundled DejaVu faces
     * (already shipped with dompdf) are mapped under the brand family names
     * so every signed PDF renders deterministically and identically in any
     * environment. Pixel-exact brand typeface is a follow-up asset task —
     * it does not block correctness, determinism, or the legal record.
     * Fail-open: if a face file is unreadable, that face is skipped and
     * Chromium's default sans is used (still zero external requests).
     */
    private function embeddedBrandFontFaces(): string
    {
        $fontDir = base_path('vendor/dompdf/dompdf/lib/fonts');
        $faces = [
            ['family' => 'Plus Jakarta Sans', 'weight' => 400, 'style' => 'normal', 'file' => 'DejaVuSans.ttf'],
            ['family' => 'Plus Jakarta Sans', 'weight' => 700, 'style' => 'normal', 'file' => 'DejaVuSans-Bold.ttf'],
            ['family' => 'Plus Jakarta Sans', 'weight' => 400, 'style' => 'italic', 'file' => 'DejaVuSans-Oblique.ttf'],
            ['family' => 'Dancing Script',    'weight' => 400, 'style' => 'normal', 'file' => 'DejaVuSans.ttf'],
            ['family' => 'Dancing Script',    'weight' => 700, 'style' => 'normal', 'file' => 'DejaVuSans-Bold.ttf'],
        ];

        $css = '';
        $cache = [];
        foreach ($faces as $f) {
            $path = $fontDir . DIRECTORY_SEPARATOR . $f['file'];
            if (!array_key_exists($f['file'], $cache)) {
                $cache[$f['file']] = is_readable($path)
                    ? base64_encode((string) file_get_contents($path))
                    : null;
            }
            $b64 = $cache[$f['file']];
            if ($b64 === null) {
                continue; // fail-open — skip this face, no external fallback
            }
            $css .= "@font-face{font-family:'{$f['family']}';font-style:{$f['style']};"
                  . "font-weight:{$f['weight']};font-display:block;"
                  . "src:url(data:font/ttf;base64,{$b64}) format('truetype');}\n";
        }

        return $css;
    }

    /**
     * Make the PDF source fully self-contained: inline local storage assets
     * (agency logo etc.) as base64 data: URIs and neutralise any remaining
     * remote/loopback resource the renderer would issue a network request
     * for. After this, Puppeteer's page.goto fetches NOTHING over the
     * network — eliminating the loopback-logo stall on a single-threaded
     * server. Fail-open: an unresolved local asset is left untouched; an
     * unresolved REMOTE <img> is replaced with a 1x1 transparent pixel so
     * it can never block the load event.
     */
    private function inlineLocalAssetsAndStripRemote(string $html): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $mimeFor = function (string $path): string {
            return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png'         => 'image/png',
                'gif'         => 'image/gif',
                'svg'         => 'image/svg+xml',
                'webp'        => 'image/webp',
                default       => 'image/jpeg',
            };
        };

        // Resolve a "/storage/<rel>" web path (absolute, loopback, or bare)
        // to a local file under the public disk; return base64 data URI or
        // null if it cannot be resolved locally.
        $toDataUri = function (string $url) use ($mimeFor): ?string {
            $clean = preg_replace('/[?#].*$/', '', $url);
            if (!preg_match('#(?:^|//[^/]+)?/storage/(.+)$#i', $clean, $m)) {
                return null;
            }
            $rel = ltrim($m[1], '/');
            $candidates = [
                storage_path('app/public/' . $rel),
                public_path('storage/' . $rel),
            ];
            foreach ($candidates as $file) {
                if (is_file($file) && is_readable($file)) {
                    return 'data:' . $mimeFor($file) . ';base64,'
                        . base64_encode((string) file_get_contents($file));
                }
            }
            return null;
        };

        $transparentPx = 'data:image/png;base64,'
            . 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgAAIAAAUAAeImBZsAAAAASUVORK5CYII=';

        // 1. <img src="..."> — inline local storage assets; any remaining
        //    remote/loopback src becomes a transparent pixel (no request).
        $html = preg_replace_callback(
            '#(<img\b[^>]*\bsrc=)(["\'])(.*?)\2#is',
            function ($mm) use ($toDataUri, $transparentPx) {
                $src = $mm[3];
                if (stripos($src, 'data:') === 0) {
                    return $mm[0];
                }
                $data = $toDataUri($src);
                if ($data !== null) {
                    return $mm[1] . $mm[2] . $data . $mm[2];
                }
                if (preg_match('#^(?:https?:)?//#i', $src) || str_contains($src, '127.0.0.1') || str_contains($src, 'localhost')) {
                    return $mm[1] . $mm[2] . $transparentPx . $mm[2];
                }
                return $mm[0]; // local/relative non-storage — leave as-is
            },
            $html
        );

        // 2. Remove any remaining remote stylesheet/script the renderer
        //    would fetch (googleapis already stripped above; this catches
        //    any other remote <link rel=stylesheet>/<script src>).
        $html = preg_replace('#<link\b[^>]*\bhref=["\'](?:https?:)?//[^"\']*["\'][^>]*>#i', '', $html);
        $html = preg_replace('#<script\b[^>]*\bsrc=["\'](?:https?:)?//[^"\']*["\'][^>]*>\s*</script>#i', '', $html);

        return $html;
    }

    /**
     * CSS rules to hide interactive UI elements from PDF output.
     */
    private function getPdfCleanupCss(): string
    {
        return <<<'CSS'
/* Hide interactive signing UI elements */
.web-sig-prompt { display: none !important; }
.init-prompt { display: none !important; }
/* Neutralise interactive signing-UI state in the FLATTENED PDF ONLY.
   The green/dashed box, tinted background and 0.8 opacity are correct
   DURING signing but must NOT appear in a legal PDF. Selectors match the
   signing view's specificity (.web-sig-interactive.web-sig-signed) so its
   !important box rule cannot win by specificity + source order. Every
   signed surface — agent, seller, seller_2…seller_n, native OR
   resolver-injected — collapses to ONE consistent neutral signature line
   (border-bottom kept; box/tint/opacity stripped). */
.web-sig-interactive,
.web-sig-interactive.web-sig-signed,
.web-sig-signed,
.web-sig-other-signed,
.web-sig-other-party {
    border: none !important;
    border-bottom: 1px solid #333 !important;
    background: transparent !important;
    box-shadow: none !important;
    opacity: 1 !important;
}
/* Container-independent signature size: a fixed box (aspect preserved via
   object-fit) so the SAME capture renders identically whether its cell is
   full-width (agent, cols-1) or half-width (seller, cols-2). A stylesheet
   !important beats embedSigIntoElement's inline max-height. Height equals
   the previous 50px cap → no vertical growth → no re-pagination (§19.7). */
.web-sig-signed-img {
    display: block !important;
    width: 160px !important;
    height: 50px !important;
    max-height: 50px !important;
    object-fit: contain !important;
    margin: 2px auto !important;
    opacity: 1 !important;
}
/* Hide marker overlays, toolbars, panels */
[class*="marker-overlay"],
[class*="sig-marker"],
.signature-toolbar,
.signing-panel,
.print-toolbar,
.clause-flag-icon,
.clause-flag-comment {
    display: none !important;
}
/* Radio placeholders */
.corex-radio-placeholder {
    display: inline-block;
    font-size: 14pt;
    line-height: 1;
}
/* Hide input borders — show values only */
.field-editable,
input[data-ceremony-field="true"] {
    border: none !important;
    background: transparent !important;
    outline: none !important;
    padding: 0 !important;
    font: inherit !important;
    color: inherit !important;
}
CSS;
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

        // Resolve via the 'local' disk (where signed PDFs are written) —
        // raw storage_path('app/..') is one dir outside the disk root.
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        $pdfPath = $disk->path($template->signed_pdf_path);

        if (!$disk->exists($template->signed_pdf_path)) {
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

            if ($agent) {
                $reviewUrl = url("/docuperfect/documents/{$template->document_id}/signatures/review");
                $agent->notify(\App\Notifications\SignatureActivityNotification::sectionRejected(
                    $signingRequest->signer_name, $template->document->name ?? 'Document',
                    $template->document_id, $reviewUrl,
                ));
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

    /**
     * Phase 1B.6 — extract numbered clause refs + previews from a document
     * HTML body so the Add Condition + Flag Clause modals can offer a
     * "Relates to clause" / "Flag clause" picker.
     *
     * Matches paragraphs/list items/divs that begin with a clause-number
     * pattern (1., 1.1, 4.8, 12.3.4). Skips anything inside an
     * .insertable-block container.
     *
     * @return array<int, array{ref:string, preview:string}>
     */
    public function extractNumberedClauses(?string $documentHtml): array
    {
        if (! is_string($documentHtml) || $documentHtml === '') {
            return [];
        }

        // Strip insertable-block scopes first so we don't pick up
        // conditions inside their own block as "clauses".
        $stripped = preg_replace(
            '/<div\b[^>]*class="[^"]*insertable-block[^"]*"[^>]*>.*?<\/div>/si',
            '',
            $documentHtml
        ) ?? $documentHtml;

        $clauses = [];
        $seen = [];

        if (preg_match_all('/<(p|li|div)\b[^>]*>(.*?)<\/\1>/si', $stripped, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $inner = trim(strip_tags($m[2]));
                if ($inner === '') continue;
                if (preg_match('/^\s*(\d+(?:\.\d+)*)\b[\.\s]\s*(.*)$/su', $inner, $cm)) {
                    $ref = $cm[1];
                    if (isset($seen[$ref])) continue;
                    $seen[$ref] = true;
                    $preview = trim(preg_replace('/\s+/', ' ', $cm[2]));
                    $clauses[] = [
                        'ref'     => $ref,
                        'preview' => mb_substr($preview, 0, 80) . (mb_strlen($preview) > 80 ? '…' : ''),
                    ];
                }
            }
        }

        return $clauses;
    }

    // ─────────────────────────────────────────────────────────────────
    // Phase 1B.5 — recipient-side Other Conditions, strikethrough, and
    // focused initialing endpoints + helpers.
    // ─────────────────────────────────────────────────────────────────

    /**
     * GET surface routed to from show() when amendment_status indicates
     * an initialing cascade is in progress for this signing party.
     */
    private function showInitialingView(SignatureRequest $signingRequest, string $token)
    {
        $template = $signingRequest->template;

        // Amendments that are approved + still need initials from this party.
        $amendments = DocumentAmendment::query()
            ->where('signature_template_id', $template->id)
            ->where('status', DocumentAmendment::STATUS_ACCEPTED)
            ->orderBy('id')
            ->get();

        $alreadyInitialedAmendmentIds = ConditionInitial::query()
            ->whereIn('amendment_id', $amendments->pluck('id'))
            ->where('signature_request_id', $signingRequest->id)
            ->pluck('amendment_id')
            ->unique();

        $pendingAmendments = $amendments->reject(
            fn($a) => $alreadyInitialedAmendmentIds->contains($a->id)
        )->values();

        // Build initialing items per pending amendment: associated conditions
        // and strikethroughs.
        $items = [];
        foreach ($pendingAmendments as $amendment) {
            $conds = DocumentCondition::query()
                ->where('amendment_id', $amendment->id)
                ->whereNull('superseded_at')
                ->orderBy('condition_number')
                ->get();
            $strikes = DocumentClauseStrikethrough::query()
                ->where('amendment_id', $amendment->id)
                ->get();
            $items[] = [
                'amendment'      => $amendment,
                'conditions'     => $conds,
                'strikethroughs' => $strikes,
            ];
        }

        return view('docuperfect.signatures.external.initialing', [
            'request'      => $signingRequest,
            'template'     => $template,
            'document'     => $template->document,
            'token'        => $token,
            'pendingItems' => $items,
            'noItems'      => empty($items),
        ]);
    }

    /**
     * POST /docuperfect/api/sign/{token}/conditions
     * Recipient adds a condition to one of the document's insertable blocks.
     */
    public function addCondition(Request $request, string $token): \Illuminate\Http\JsonResponse
    {
        $signingRequest = SignatureRequest::where('token', $token)
            ->with('template')
            ->firstOrFail();

        if (! $this->signerCanAct($signingRequest)) {
            return response()->json(['error' => 'Not authorised at this stage.'], 403);
        }

        $validated = $request->validate([
            'block_id'              => ['required', 'string', 'max:100'],
            'block_purpose'         => ['required', 'in:other_conditions,included_items,excluded_items,custom_named'],
            'content'               => ['required', 'string', 'max:4000'],
            // Phase 1B.6 — recipient writes are always source=custom (no
            // library access from the recipient side). Accept the field for
            // backward compatibility with stored client payloads but force
            // the value to 'custom' below.
            'source'                => ['sometimes', 'in:library,custom'],
            'library_clause_id'     => ['nullable', 'integer'],
            // Phase 1B.8: relates_to_clause_ref dropdown removed from the
            // recipient modal. The DB column is retained for legacy rows;
            // recipient writes no longer populate it.
        ]);
        $validated['source'] = 'custom';
        $validated['library_clause_id'] = null;

        $template = $signingRequest->template;
        $document = $template->document;
        $agencyId = $signingRequest->template?->creator?->effectiveAgencyId();

        $result = DB::transaction(function () use ($validated, $signingRequest, $template, $document, $agencyId) {
            $amendment = $this->openOrReusePendingAmendment(
                $template,
                $document,
                DocumentAmendment::TYPE_ADDITION,
                originalText: '',
                newText: $validated['content']
            );

            $next = (int) DocumentCondition::query()
                ->where('signature_template_id', $template->id)
                ->where('block_id', $validated['block_id'])
                ->whereNull('superseded_at')
                ->max('condition_number');

            $condition = DocumentCondition::create([
                'signature_template_id' => $template->id,
                'agency_id'             => $agencyId,
                'block_id'              => $validated['block_id'],
                'block_purpose'         => $validated['block_purpose'],
                'condition_number'      => $next + 1,
                'content'               => $validated['content'],
                'is_locked'             => false,
                'is_override'           => false,
                'added_by_user_id'      => $signingRequest->signed_by_user_id ?? null,
                'added_by_party_id'     => $signingRequest->id,
                'added_via'             => 'recipient_signing',
                'source'                => $validated['source'],
                'library_clause_id'     => $validated['library_clause_id'] ?? null,
                'amendment_id'          => $amendment->id,
            ]);

            $template->update([
                'amendment_status' => SignatureTemplate::AMENDMENT_STATUS_PENDING_REVIEW,
                'status'           => SignatureTemplate::STATUS_AMENDMENT_REVIEW,
            ]);

            SignatureAuditLog::log(
                $template,
                'condition_added_by_recipient',
                SignatureAuditLog::ACTOR_SIGNER,
                $signingRequest->signer_name ?? 'Unknown',
                metadata: [
                    'amendment_id'  => $amendment->id,
                    'condition_id'  => $condition->id,
                    'block_id'      => $validated['block_id'],
                    'block_purpose' => $validated['block_purpose'],
                ],
            );

            return compact('amendment', 'condition');
        });

        // Phase 1B.9 (FIX 3) — render the new row HTML server-side so the
        // client can append it in place without reloading the page (the
        // previous location.reload() wiped Alpine signature state — the
        // critical reset bug Phase 1B.9 eliminates).
        $newCondition = $result['condition']->fresh(['initials']);
        $renderedRow  = app(\App\Services\Docuperfect\InsertableBlockRenderer::class)
            ->renderConditionRowPublic(
                $newCondition,
                \App\Services\Docuperfect\InsertableBlockRenderer::CONTEXT_RECIPIENT_SIGNING,
                $signingRequest->template,
                $token,
                $signingRequest->party_role
            );

        return response()->json([
            'ok'               => true,
            'condition'        => $newCondition,
            'amendment_id'     => $result['amendment']->id,
            'rendered_row'     => $renderedRow,
            'amendment_status' => 'pending_review',
        ], 201);
    }

    /**
     * POST /docuperfect/api/sign/{token}/flag-clause   (Phase 1B.6 — FIX 2)
     *
     * Recipient flags a numbered clause with a suggested change. Creates a
     * DocumentAmendment row with amendment_type = 'flag_raised' (existing
     * Phase 2 ES-4 enum value) and ALSO writes through to the legacy
     * web_template_data.clause_flags JSON so the orange-flag display
     * survives page refresh.
     *
     * Replaces Phase 1B.5's proposeStrikethrough — the override modal was
     * the wrong abstraction. The flag UI is the recipient's clause-change
     * path. Agent approval of a flag-raised amendment creates a numbered
     * condition in the Other Conditions block with is_override = true.
     */
    public function flagClause(Request $request, string $token): \Illuminate\Http\JsonResponse
    {
        $signingRequest = SignatureRequest::where('token', $token)
            ->with('template.document')
            ->firstOrFail();

        if (! $this->signerCanAct($signingRequest)) {
            return response()->json(['error' => 'Not authorised at this stage.'], 403);
        }

        $validated = $request->validate([
            'clause_ref'           => ['required', 'string', 'max:50'],
            'clause_original_text' => ['required', 'string', 'max:4000'],
            'suggested_change'     => ['required', 'string', 'max:4000'],
            'reason'               => ['nullable', 'string', 'max:2000'],
        ]);

        $template = $signingRequest->template;
        $document = $template->document;

        $result = DB::transaction(function () use ($validated, $signingRequest, $template, $document) {
            $version = (int) ($template->document_version ?? 1);

            // Compose the flag-raised reason text — pair the suggested
            // change with an optional why-explanation so the agent review
            // surface (Phase 1B AmendmentController) has full context.
            $flagReason = $validated['suggested_change'];
            if (! empty($validated['reason'])) {
                $flagReason .= "\n\nReason: " . $validated['reason'];
            }

            $amendment = DocumentAmendment::create([
                'document_id'             => $document?->id,
                'signature_template_id'   => $template->id,
                'amended_by_request_id'   => $signingRequest->id,
                'amendment_type'          => DocumentAmendment::TYPE_FLAG_RAISED,
                'flag_origin'             => DocumentAmendment::FLAG_ORIGIN_SIGNING_PARTY,
                'flag_clause_ref'         => $validated['clause_ref'],
                'flag_reason'             => $flagReason,
                'section_reference'       => 'Clause ' . $validated['clause_ref'],
                'original_text'           => $validated['clause_original_text'],
                'new_text'                => $validated['suggested_change'],
                'document_version_before' => $version,
                'document_version_after'  => $version,
                'document_hash_before'    => $template->document_hash,
                'document_hash_after'     => null,
                'status'                  => DocumentAmendment::STATUS_PENDING,
            ]);

            // Phase 1B.6 (FIX 6) — write through to web_template_data
            // .clause_flags JSON immediately so a refresh of the signing
            // page can re-seed the visible flag indicator (was previously
            // only persisted at signComplete time).
            if ($document) {
                $webData = $document->web_template_data ?? [];
                $existing = $webData['clause_flags'][$signingRequest->party_role] ?? [];
                $existing[] = [
                    'clauseNum'         => $validated['clause_ref'],
                    'concern'           => $validated['suggested_change'],
                    'reason'            => $validated['reason'] ?? null,
                    'amendment_id'      => $amendment->id,
                    'flagged_at'        => now()->toIso8601String(),
                    'status'            => 'pending_review',
                ];
                $webData['clause_flags'][$signingRequest->party_role] = $existing;
                $document->update(['web_template_data' => $webData]);
            }

            $template->update([
                'amendment_status' => SignatureTemplate::AMENDMENT_STATUS_PENDING_REVIEW,
                'status'           => SignatureTemplate::STATUS_AMENDMENT_REVIEW,
            ]);

            SignatureAuditLog::log(
                $template,
                'clause_flagged_by_recipient',
                SignatureAuditLog::ACTOR_SIGNER,
                $signingRequest->signer_name ?? 'Unknown',
                metadata: [
                    'amendment_id' => $amendment->id,
                    'clause_ref'   => $validated['clause_ref'],
                ],
            );

            return $amendment;
        });

        return response()->json([
            'ok'           => true,
            'amendment_id' => $result->id,
            'clause_ref'   => $validated['clause_ref'],
        ], 201);
    }

    /**
     * DELETE /docuperfect/api/sign/{token}/flag/{clauseRef}   (Phase 1B.9 — FIX 1, pre-completion)
     *
     * Recipient self-removes a flag they raised — allowed ONLY while the
     * party has not yet completed signing AND the amendment is still in
     * 'pending' state (agent hasn't acted on it).
     *
     * After signing completes, removal must go through the agent-initiated
     * consent flow via FlagRemovalController.
     */
    public function removeOwnFlag(Request $request, string $token, string $clauseRef): \Illuminate\Http\JsonResponse
    {
        $signingRequest = SignatureRequest::where('token', $token)
            ->with('template.document')
            ->firstOrFail();

        // Pre-completion gate. After completed_at is set the recipient
        // can no longer unilaterally remove their own flag — they must
        // go through the consent path.
        if ($signingRequest->status === SignatureRequest::STATUS_COMPLETED
            || $signingRequest->completed_at !== null
        ) {
            return response()->json([
                'error' => 'You have already signed. Removing a flag now requires the agent to request your authenticated consent.',
            ], 409);
        }

        $template = $signingRequest->template;
        $document = $template?->document;
        if (! $document) {
            return response()->json(['error' => 'Document not found.'], 404);
        }

        // Find a pending amendment matching the clause + this party.
        $amendment = DocumentAmendment::query()
            ->where('signature_template_id', $template->id)
            ->where('amendment_type', DocumentAmendment::TYPE_FLAG_RAISED)
            ->where('flag_clause_ref', $clauseRef)
            ->where('amended_by_request_id', $signingRequest->id)
            ->where('status', DocumentAmendment::STATUS_PENDING)
            ->latest('id')
            ->first();

        DB::transaction(function () use ($document, $signingRequest, $clauseRef, $amendment, $template) {
            // Scrub the matching entry from clause_flags JSON.
            $webData = $document->web_template_data ?? [];
            $partyRole = $signingRequest->party_role;
            $flagsByParty = $webData['clause_flags'] ?? [];
            if ($partyRole && isset($flagsByParty[$partyRole]) && is_array($flagsByParty[$partyRole])) {
                $flagsByParty[$partyRole] = array_values(array_filter(
                    $flagsByParty[$partyRole],
                    fn($f) => (string) ($f['clauseNum'] ?? '') !== (string) $clauseRef
                ));
                if (empty($flagsByParty[$partyRole])) {
                    unset($flagsByParty[$partyRole]);
                }
                $webData['clause_flags'] = $flagsByParty;
                $document->update(['web_template_data' => $webData]);
            }

            // Soft-delete the pending amendment (audit retained).
            if ($amendment) {
                $amendment->update(['status' => DocumentAmendment::STATUS_REJECTED]);
                $amendment->delete();
            }

            // If this was the only pending amendment, clear amendment_status.
            $stillPending = DocumentAmendment::query()
                ->where('signature_template_id', $template->id)
                ->where('status', DocumentAmendment::STATUS_PENDING)
                ->exists();
            if (! $stillPending) {
                $template->update([
                    'status'           => SignatureTemplate::STATUS_SIGNING,
                    'amendment_status' => null,
                ]);
            }

            SignatureAuditLog::log(
                $template,
                'flag_self_removed_pre_completion',
                SignatureAuditLog::ACTOR_SIGNER,
                $signingRequest->signer_name ?? 'Recipient',
                metadata: [
                    'clause_ref'    => $clauseRef,
                    'amendment_id'  => $amendment?->id,
                    'party_role'    => $partyRole,
                ],
            );
        });

        return response()->json(['ok' => true]);
    }

    /**
     * POST /docuperfect/api/sign/{token}/strikethroughs  (Phase 1B.5 — deprecated)
     *
     * Phase 1B.6 (FIX 2): retained as a soft-deprecated endpoint that 410s
     * with a clear message. The recipient flow now uses flagClause()
     * exclusively. Agent-side strikethrough creation (if introduced
     * later) will go through a different path.
     */
    public function proposeStrikethrough(Request $request, string $token): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'error' => 'The strikethrough override flow has been replaced by clause flagging. '
                . 'Use POST /sign/{token}/flag-clause instead.',
        ], 410);

        // The original implementation is retained below behind an
        // unreachable return so the diff stays auditable. The legacy
        // path can be deleted in a follow-up commit once no client
        // payload mentions it.
        $signingRequest = SignatureRequest::where('token', $token)
            ->with('template')
            ->firstOrFail();

        if (! $this->signerCanAct($signingRequest)) {
            return response()->json(['error' => 'Not authorised at this stage.'], 403);
        }

        $validated = $request->validate([
            'clause_ref'           => ['required', 'string', 'max:50'],
            'clause_original_text' => ['required', 'string', 'max:4000'],
            'replacement_content'  => ['required', 'string', 'max:4000'],
            'library_clause_id'    => ['nullable', 'integer'],
        ]);

        $template = $signingRequest->template;
        $document = $template->document;
        $agencyId = $template?->creator?->effectiveAgencyId();

        // The template MUST declare an other_conditions block for the
        // override to have a destination. Without it, fail fast.
        // SignatureTemplate has no direct template relation; walk through
        // its document → template → insertable_blocks.
        $hasOtherConditionsBlock = false;
        $otherConditionsBlockId  = 'other_conditions';
        $liveTemplate = $template->document?->template;
        foreach ((array) ($liveTemplate?->insertable_blocks ?? []) as $b) {
            if (($b['purpose'] ?? null) === 'other_conditions') {
                $hasOtherConditionsBlock = true;
                $otherConditionsBlockId  = (string) ($b['id'] ?? 'other_conditions');
                break;
            }
        }
        if (! $hasOtherConditionsBlock) {
            return response()->json([
                'error' => 'This document does not support clause overrides. Contact the agent.',
            ], 400);
        }

        $result = DB::transaction(function () use ($validated, $signingRequest, $template, $document, $agencyId, $otherConditionsBlockId) {
            $amendment = $this->openOrReusePendingAmendment(
                $template,
                $document,
                DocumentAmendment::TYPE_STRIKEOUT,
                originalText: $validated['clause_original_text'],
                newText: $validated['replacement_content']
            );

            $referenced = sprintf('As per clause %s, %s', $validated['clause_ref'], $validated['replacement_content']);

            $next = (int) DocumentCondition::query()
                ->where('signature_template_id', $template->id)
                ->where('block_id', $otherConditionsBlockId)
                ->whereNull('superseded_at')
                ->max('condition_number');

            $condition = DocumentCondition::create([
                'signature_template_id' => $template->id,
                'agency_id'             => $agencyId,
                'block_id'              => $otherConditionsBlockId,
                'block_purpose'         => 'other_conditions',
                'condition_number'      => $next + 1,
                'content'               => $referenced,
                'is_locked'             => false,
                'is_override'           => true,
                'overrides_clause_ref'  => $validated['clause_ref'],
                'added_by_user_id'      => null,
                'added_by_party_id'     => $signingRequest->id,
                'added_via'             => 'recipient_signing',
                'source'                => isset($validated['library_clause_id']) ? 'library' : 'custom',
                'library_clause_id'     => $validated['library_clause_id'] ?? null,
                'amendment_id'          => $amendment->id,
            ]);

            $strike = DocumentClauseStrikethrough::create([
                'signature_template_id'    => $template->id,
                'agency_id'                => $agencyId,
                'clause_ref'               => $validated['clause_ref'],
                'clause_original_text'     => $validated['clause_original_text'],
                'replacement_condition_id' => $condition->id,
                'proposed_by_user_id'      => null,
                'proposed_by_party_id'     => $signingRequest->id,
                'amendment_id'             => $amendment->id,
                'status'                   => DocumentClauseStrikethrough::STATUS_PROPOSED,
            ]);

            $template->update([
                'amendment_status' => SignatureTemplate::AMENDMENT_STATUS_PENDING_REVIEW,
                'status'           => SignatureTemplate::STATUS_AMENDMENT_REVIEW,
            ]);

            SignatureAuditLog::log(
                $template,
                'strikethrough_proposed_by_recipient',
                SignatureAuditLog::ACTOR_SIGNER,
                $signingRequest->signer_name ?? 'Unknown',
                metadata: [
                    'amendment_id'  => $amendment->id,
                    'clause_ref'    => $validated['clause_ref'],
                    'condition_id'  => $condition->id,
                    'strike_id'     => $strike->id,
                ],
            );

            return compact('amendment', 'condition', 'strike');
        });

        return response()->json([
            'ok'            => true,
            'strikethrough' => $result['strike'],
            'condition'     => $result['condition'],
            'amendment_id'  => $result['amendment']->id,
        ], 201);
    }

    /**
     * POST /docuperfect/api/sign/{token}/conditions/{condition}/initial   (Phase 1B.7 — FIX C)
     *
     * Per-condition initialing for the current signing party. This is the
     * inline initialing path used while the recipient is reading through
     * the conditions block — distinct from initialAmendments() which is
     * the bulk-submit path used by the focused initialing view during a
     * full amendment-initialing cascade.
     *
     * Insert-only via ConditionInitial::save() (Phase 1B model protection
     * throws DomainException on existing rows). If the party already has
     * an initial for this condition we 409 with the existing record.
     */
    public function initialCondition(Request $request, string $token, int $conditionId): \Illuminate\Http\JsonResponse
    {
        $signingRequest = SignatureRequest::where('token', $token)
            ->with('template')
            ->firstOrFail();
        if (! $this->signerCanAct($signingRequest)) {
            return response()->json(['error' => 'Not authorised at this stage.'], 403);
        }

        $condition = DocumentCondition::query()
            ->where('id', $conditionId)
            ->where('signature_template_id', $signingRequest->signature_template_id)
            ->whereNull('superseded_at')
            ->whereNull('deleted_at')
            ->first();
        if (! $condition) {
            return response()->json(['error' => 'Condition not found on this document.'], 404);
        }

        $partyKey = (string) $signingRequest->party_role;
        if ($partyKey === '') {
            return response()->json(['error' => 'No party_role on this signing request.'], 400);
        }

        // Duplicate-initial guard (the insert-only model would throw, but
        // a 409 is friendlier than a 500).
        $existing = ConditionInitial::query()
            ->where('initialable_type', DocumentCondition::class)
            ->where('initialable_id', $condition->id)
            ->where('party_key', $partyKey)
            ->first();
        if ($existing) {
            return response()->json([
                'error'   => 'Already initialed by this party.',
                'initial' => $existing,
            ], 409);
        }

        $initial = ConditionInitial::create([
            'initialable_type'     => DocumentCondition::class,
            'initialable_id'       => $condition->id,
            'party_key'            => $partyKey,
            'signature_request_id' => $signingRequest->id,
            'amendment_id'         => $condition->amendment_id,
            'initial_image_path'   => null,
            'ip_address'           => $request->ip(),
            'user_agent'           => substr((string) $request->userAgent(), 0, 500),
        ]);

        SignatureAuditLog::log(
            $signingRequest->template,
            'condition_initialed',
            SignatureAuditLog::ACTOR_SIGNER,
            $signingRequest->signer_name ?? 'Unknown',
            metadata: [
                'condition_id'   => $condition->id,
                'condition_no'   => $condition->condition_number,
                'block_id'       => $condition->block_id,
                'party_key'      => $partyKey,
                'amendment_id'   => $condition->amendment_id,
            ],
        );

        return response()->json([
            'ok'      => true,
            'initial' => $initial,
        ], 201);
    }

    /**
     * POST /docuperfect/api/sign/{token}/initial-amendments
     * Submit per-party initials for the changed regions of one or more
     * approved amendments. Insert-only via the ConditionInitial model.
     */
    public function initialAmendments(Request $request, string $token): \Illuminate\Http\JsonResponse
    {
        $signingRequest = SignatureRequest::where('token', $token)
            ->with('template')
            ->firstOrFail();

        $template = $signingRequest->template;
        if (! $template
            || $template->status !== SignatureTemplate::STATUS_AMENDMENT_INITIALING
        ) {
            return response()->json(['error' => 'Document is not currently in an initialing cascade.'], 400);
        }

        $validated = $request->validate([
            'amendments'                          => ['required', 'array', 'min:1'],
            'amendments.*.amendment_id'           => ['required', 'integer'],
            'amendments.*.initials'               => ['required', 'array', 'min:1'],
            'amendments.*.initials.*.initialable_type'  => ['required', 'in:condition,strikethrough'],
            'amendments.*.initials.*.initialable_id'    => ['required', 'integer'],
            'amendments.*.initials.*.initial_image_path' => ['nullable', 'string', 'max:500'],
        ]);

        $partyKey = $signingRequest->party_role ?? 'unknown';
        $created  = 0;

        DB::transaction(function () use ($validated, $signingRequest, $template, $partyKey, $request, &$created) {
            foreach ($validated['amendments'] as $amend) {
                foreach ($amend['initials'] as $ini) {
                    $morphClass = $ini['initialable_type'] === 'strikethrough'
                        ? DocumentClauseStrikethrough::class
                        : DocumentCondition::class;
                    ConditionInitial::create([
                        'initialable_type'     => $morphClass,
                        'initialable_id'       => $ini['initialable_id'],
                        'party_key'            => $partyKey,
                        'signature_request_id' => $signingRequest->id,
                        'amendment_id'         => $amend['amendment_id'],
                        'initial_image_path'   => $ini['initial_image_path'] ?? null,
                        'ip_address'           => $request->ip(),
                        'user_agent'           => substr((string) $request->userAgent(), 0, 500),
                    ]);
                    $created++;
                }
                SignatureAuditLog::log(
                    $template,
                    'amendment_initialed_by_party',
                    SignatureAuditLog::ACTOR_SIGNER,
                    $signingRequest->signer_name ?? 'Unknown',
                    metadata: [
                        'amendment_id' => $amend['amendment_id'],
                        'party'        => $partyKey,
                    ],
                );
            }
        });

        // Cascade completion check — if every other signing party has also
        // recorded initials for every accepted amendment, close the cascade.
        $this->checkInitialingCascadeComplete($template);

        return response()->json([
            'ok'         => true,
            'created'    => $created,
            'next_url'   => route('signatures.external', ['token' => $token]),
        ]);
    }

    /**
     * Return the active pending amendment (if one exists for this template
     * window) or open a new one. Re-using a pending amendment keeps every
     * condition/strikethrough proposed in the same review window grouped
     * together for the agent.
     */
    private function openOrReusePendingAmendment(
        SignatureTemplate $template,
        $document,
        string $type,
        string $originalText,
        string $newText
    ): DocumentAmendment {
        $existing = DocumentAmendment::query()
            ->where('signature_template_id', $template->id)
            ->where('status', DocumentAmendment::STATUS_PENDING)
            ->latest('id')
            ->first();
        if ($existing) {
            return $existing;
        }
        $version = (int) ($template->document_version ?? 1);
        return DocumentAmendment::create([
            'document_id'             => $document?->id,
            'signature_template_id'   => $template->id,
            'amended_by_request_id'   => null,
            'amendment_type'          => $type,
            'section_reference'       => 'Other Conditions',
            'original_text'           => $originalText,
            'new_text'                => $newText,
            'document_version_before' => $version,
            'document_version_after'  => $version + 1,
            'document_hash_before'    => $template->document_hash,
            'document_hash_after'     => null,
            'status'                  => DocumentAmendment::STATUS_PENDING,
        ]);
    }

    /**
     * Is this signing request authorised to add conditions / propose
     * strikethroughs right now? Allowed when the request hasn't completed
     * and the template isn't terminal-state.
     */
    private function signerCanAct(SignatureRequest $req): bool
    {
        if (in_array($req->status, [
            SignatureRequest::STATUS_COMPLETED,
            SignatureRequest::STATUS_DECLINED,
        ], true)) {
            return false;
        }
        $tplStatus = $req->template?->status;
        return ! in_array($tplStatus, [
            SignatureTemplate::STATUS_REJECTED,
            SignatureTemplate::STATUS_CANCELLED,
            SignatureTemplate::STATUS_EXPIRED,
            SignatureTemplate::STATUS_COMPLETED,
        ], true);
    }

    /**
     * If every signing party has recorded initials for every accepted
     * amendment, return the template to its prior signing flow.
     */
    private function checkInitialingCascadeComplete(SignatureTemplate $template): void
    {
        $acceptedAmendmentIds = DocumentAmendment::query()
            ->where('signature_template_id', $template->id)
            ->where('status', DocumentAmendment::STATUS_ACCEPTED)
            ->pluck('id');
        if ($acceptedAmendmentIds->isEmpty()) {
            return;
        }

        $partyKeys = collect($template->requests()->where('status', '!=', SignatureRequest::STATUS_DECLINED)->get())
            ->pluck('party_role')
            ->unique()
            ->values();

        foreach ($acceptedAmendmentIds as $amendId) {
            foreach ($partyKeys as $key) {
                $initialed = ConditionInitial::query()
                    ->where('amendment_id', $amendId)
                    ->where('party_key', $key)
                    ->exists();
                if (! $initialed) {
                    return; // still pending
                }
            }
        }

        // All initialed — flip back to signing state.
        $template->update([
            'status'           => SignatureTemplate::STATUS_SIGNING,
            'amendment_status' => SignatureTemplate::AMENDMENT_STATUS_RESOLVED,
        ]);
        SignatureAuditLog::log(
            $template,
            'amendment_initialing_cascade_complete',
            SignatureAuditLog::ACTOR_SYSTEM,
            'System',
            metadata: ['amendment_ids' => $acceptedAmendmentIds->toArray()],
        );
    }

    /**
     * ES-4 — promote signer-raised clause flags into first-class
     * DocumentAmendment rows. Each flag becomes its own amendment so the
     * agent review surface (Phase 1B) can render and action it through the
     * same approve / reject / reject-document workflow as conditions and
     * strikethroughs.
     *
     * Backward compatibility: the JSON note in
     * docuperfect_documents.web_template_data['clause_flags'] is preserved
     * by the caller — this method only ADDS the relational record.
     *
     * Failures are logged but never abort the signing transaction: a flag
     * record that fails to write should not block the signer's signature
     * commit.
     *
     * @param array $clauseFlags Array of { clauseNum, clauseIndex, concern }
     *
     * Spec: .ai/specs/esign-v3-complete-spec.md §17 ES-4
     */
    private function promoteClauseFlagsToAmendments(
        SignatureRequest $signingRequest,
        $document,
        array $clauseFlags
    ): void {
        try {
            $template = $signingRequest->template
                ?? SignatureTemplate::find($signingRequest->signature_template_id);
            if (! $template) {
                return;
            }

            foreach ($clauseFlags as $flag) {
                $clauseRef = $flag['clauseNum'] ?? $flag['clause_num'] ?? null;
                $reason    = trim((string) ($flag['concern'] ?? $flag['reason'] ?? ''));
                if ($reason === '') {
                    // A flag without a concern note is still a signal the
                    // signer stopped — but we can't action a blank. Skip
                    // promotion in that case (the JSON note is still kept).
                    continue;
                }

                $currentVersion = (int) ($template->document_version ?? 1);

                DocumentAmendment::create([
                    'document_id'              => $document->id,
                    'signature_template_id'    => $template->id,
                    'amended_by_request_id'    => $signingRequest->id,
                    'amendment_type'           => DocumentAmendment::TYPE_FLAG_RAISED,
                    'flag_origin'              => DocumentAmendment::FLAG_ORIGIN_SIGNING_PARTY,
                    'flag_clause_ref'          => $clauseRef ? (string) $clauseRef : null,
                    'flag_reason'              => $reason,
                    'section_reference'        => $clauseRef ? ('Clause ' . $clauseRef) : 'Flag',
                    'original_text'            => '',
                    'new_text'                 => $reason,
                    'document_version_before'  => $currentVersion,
                    'document_version_after'   => $currentVersion,
                    'document_hash_before'     => $template->document_hash,
                    'document_hash_after'      => null,
                    'status'                   => DocumentAmendment::STATUS_PENDING,
                ]);
            }

            // Transition into review state so the agent picks it up.
            $template->update([
                'status'           => SignatureTemplate::STATUS_AMENDMENT_REVIEW,
                'amendment_status' => SignatureTemplate::AMENDMENT_STATUS_PENDING_REVIEW,
            ]);

            SignatureAuditLog::log(
                $template,
                'flag_raised',
                SignatureAuditLog::ACTOR_SIGNER,
                $signingRequest->signer_name ?? 'Unknown',
                metadata: [
                    'flag_count'   => count($clauseFlags),
                    'party_role'   => $signingRequest->party_role,
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('ES-4 flag promotion failed', [
                'request_id' => $signingRequest->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
