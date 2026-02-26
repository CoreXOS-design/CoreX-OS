<?php

namespace App\Services\Docuperfect;

use App\Mail\Signatures\SignatureReminderMail;
use App\Mail\Signatures\SignedDocumentMail;
use App\Mail\Signatures\SigningRequestMail;
use App\Mail\Signatures\WetInkRejectionMail;
use App\Mail\Signatures\WetInkUploadedNotification;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\LeaseRecord;
use App\Models\Docuperfect\Signature;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureMarker;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\TemplateSignatureZone;
use App\Models\Docuperfect\WetInkInspection;
use App\Models\User;
use App\Services\Docuperfect\SignaturePdfService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SignatureService
{
    protected SignaturePdfService $pdfService;

    public function __construct(SignaturePdfService $pdfService)
    {
        $this->pdfService = $pdfService;
    }

    // ──────────────────────────────────────────────
    // Template lifecycle
    // ──────────────────────────────────────────────

    /**
     * Create a signature template for a document.
     */
    public function createTemplate(Document $document, User $user): SignatureTemplate
    {
        $template = SignatureTemplate::create([
            'document_id' => $document->id,
            'status' => SignatureTemplate::STATUS_DRAFT,
            'created_by' => $user->id,
            'signing_order_json' => ['agent', 'tenant', 'landlord'],
        ]);

        SignatureAuditLog::log(
            $template,
            SignatureAuditLog::ACTION_CREATED,
            SignatureAuditLog::ACTOR_USER,
            $user->name,
            $user->email,
            $user->id,
        );

        return $template;
    }

    // ──────────────────────────────────────────────
    // Field completion validation
    // ──────────────────────────────────────────────

    /**
     * Validate that all required document fields are completed.
     * Checks the document's fields_json for fields marked required=true and verifies
     * they have a non-empty value appropriate to their type.
     *
     * Returns ['valid' => bool, 'total' => int, 'filled' => int, 'missing' => [...labels]]
     */
    public function validateFieldCompletion(Document $document): array
    {
        $documentFields = $document->fields_json ?? [];

        // Build a map of document field values indexed by field ID
        $docFieldMap = [];
        foreach ($documentFields as $field) {
            $id = $field['id'] ?? null;
            if ($id) {
                $docFieldMap[$id] = $field;
            }
        }

        // Use template fields as the source of truth for required flags
        $template = $document->template;
        $templateFields = $template ? ($template->fields_json ?? []) : [];

        $missing = [];
        $total = 0;
        $filled = 0;

        foreach ($templateFields as $tField) {
            if (empty($tField['required'])) {
                continue;
            }

            $fieldId = $tField['id'] ?? null;
            if (!$fieldId) {
                continue;
            }

            $total++;

            // Find matching document field by ID
            $docField = $docFieldMap[$fieldId] ?? null;

            $hasValue = false;
            if ($docField) {
                $fieldType = $tField['type'] ?? 'placeholder';
                switch ($fieldType) {
                    case 'placeholder':
                    case 'date':
                        $hasValue = !empty(trim((string) ($docField['value'] ?? '')));
                        break;
                    case 'condition':
                        $hasValue = !empty(trim((string) ($docField['text'] ?? '')));
                        break;
                    case 'selection':
                        $hasValue = !empty($docField['selectedValue']);
                        break;
                    case 'strikethrough':
                        $hasValue = true; // toggles are always "filled"
                        break;
                    case 'initial':
                    case 'signature':
                        $hasValue = true; // handled by signature markers, not field completion
                        break;
                    default:
                        $hasValue = !empty(trim((string) ($docField['value'] ?? '')));
                }
            }

            if ($hasValue) {
                $filled++;
            } else {
                $label = $tField['field_label']
                    ?? $tField['field_name']
                    ?? $tField['named_field_name']
                    ?? ('Field on page ' . (($tField['pageIndex'] ?? 0) + 1));
                $missing[] = $label;
            }
        }

        return [
            'valid' => empty($missing),
            'total' => $total,
            'filled' => $filled,
            'missing' => $missing,
        ];
    }

    // ──────────────────────────────────────────────
    // Document integrity (SHA-256)
    // ──────────────────────────────────────────────

    /**
     * Generate a SHA-256 hash of the document's content for tamper detection.
     */
    public function generateDocumentHash(Document $document): string
    {
        $content = json_encode($document->fields_json ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', $content);
    }

    /**
     * Verify that the document hasn't been tampered with since signing started.
     */
    public function verifyDocumentHash(SignatureTemplate $template): bool
    {
        if (!$template->document_hash) {
            return true;
        }

        $currentHash = $this->generateDocumentHash($template->document);
        return hash_equals($template->document_hash, $currentHash);
    }

    // ──────────────────────────────────────────────
    // Markers
    // ──────────────────────────────────────────────

    /**
     * Bulk-save markers for a template (replaces all existing).
     */
    public function saveMarkers(SignatureTemplate $template, array $markers): int
    {
        if (!$template->isDraft()) {
            throw new \LogicException('Cannot modify markers on a template that is not in draft status.');
        }

        return DB::transaction(function () use ($template, $markers) {
            $template->markers()->delete();

            $count = 0;
            foreach ($markers as $i => $data) {
                SignatureMarker::create([
                    'signature_template_id' => $template->id,
                    'page_number' => $data['page_number'],
                    'x_position' => $data['x_position'],
                    'y_position' => $data['y_position'],
                    'width' => $data['width'] ?? 20,
                    'height' => $data['height'] ?? 5,
                    'type' => $data['type'] ?? 'signature',
                    'assigned_party' => $data['assigned_party'],
                    'assigned_email' => $data['assigned_email'] ?? null,
                    'label' => $data['label'] ?? null,
                    'sort_order' => $data['sort_order'] ?? $i,
                    'required' => $data['required'] ?? true,
                ]);
                $count++;
            }

            return $count;
        });
    }

    // ──────────────────────────────────────────────
    // Template zone → marker conversion
    // ──────────────────────────────────────────────

    /**
     * Convert template signature zones to markers on a signature template.
     * Creates one marker per party per zone. Idempotent — skips existing markers.
     */
    public function convertZonesToMarkers(SignatureTemplate $sigTemplate): int
    {
        $document = $sigTemplate->document;
        $docTemplate = $document ? $document->template : null;

        if (!$docTemplate) {
            return 0;
        }

        $zones = $docTemplate->signatureZones()
            ->orderBy('page_index')
            ->orderBy('sort_order')
            ->get();

        if ($zones->isEmpty()) {
            return 0;
        }

        $count = 0;
        $sortOrder = $sigTemplate->markers()->max('sort_order') ?? -1;

        foreach ($zones as $zone) {
            $parties = $zone->assigned_parties ?? [];

            foreach ($parties as $partyIndex => $party) {
                // Skip if a marker from this zone+party already exists
                $exists = $sigTemplate->markers()
                    ->where('from_template_zone_id', $zone->id)
                    ->where('assigned_party', $party)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $sortOrder++;

                // Offset multiple parties slightly so they don't stack exactly
                $yOffset = $partyIndex * 6;

                SignatureMarker::create([
                    'signature_template_id' => $sigTemplate->id,
                    'page_number' => $zone->page_index + 1, // convert 0-based to 1-based
                    'x_position' => $zone->x_position,
                    'y_position' => min(100 - $zone->height, $zone->y_position + $yOffset),
                    'width' => $zone->width,
                    'height' => $zone->height,
                    'type' => $zone->type,
                    'assigned_party' => $party,
                    'label' => $zone->label ?? ucfirst($party) . ' ' . $zone->type . ' — Page ' . ($zone->page_index + 1),
                    'sort_order' => $sortOrder,
                    'required' => $zone->required,
                    'from_template_zone_id' => $zone->id,
                ]);
                $count++;
            }
        }

        return $count;
    }

    // ──────────────────────────────────────────────
    // Signing requests
    // ──────────────────────────────────────────────

    /**
     * Create a signing request for a party.
     */
    public function createSigningRequest(
        SignatureTemplate $template,
        string $partyRole,
        string $signerName,
        string $signerEmail,
        ?string $signerIdNumber = null,
        ?string $message = null,
        ?User $sentBy = null
    ): SignatureRequest {
        $token = $this->generateToken();

        $signingOrder = match ($partyRole) {
            'agent' => 1,
            'tenant' => 2,
            'landlord' => 3,
            default => 99,
        };

        $request = SignatureRequest::create([
            'signature_template_id' => $template->id,
            'party_role' => $partyRole,
            'signing_order' => $signingOrder,
            'signer_name' => $signerName,
            'signer_email' => $signerEmail,
            'signer_id_number' => $signerIdNumber,
            'token' => $token,
            'token_expires_at' => now()->addDays(14),
            'status' => SignatureRequest::STATUS_WAITING,
            'sent_by' => $sentBy?->id,
            'message' => $message,
        ]);

        SignatureAuditLog::log(
            $template,
            SignatureAuditLog::ACTION_CREATED,
            $sentBy ? SignatureAuditLog::ACTOR_USER : SignatureAuditLog::ACTOR_SYSTEM,
            $sentBy ? $sentBy->name : 'System',
            $sentBy?->email,
            $sentBy?->id,
            $request->id,
            metadata: ['party_role' => $partyRole, 'signer_email' => $signerEmail],
        );

        return $request;
    }

    /**
     * Send a signing request (transitions from waiting to pending, sends email).
     */
    public function sendSigningRequest(SignatureRequest $request): void
    {
        $request->update([
            'status' => SignatureRequest::STATUS_PENDING,
            'sent_at' => now(),
            'token_expires_at' => now()->addDays(14),
        ]);

        $template = $request->template;

        SignatureAuditLog::log(
            $template,
            SignatureAuditLog::ACTION_SENT,
            SignatureAuditLog::ACTOR_SYSTEM,
            'System',
            requestId: $request->id,
            metadata: ['signer_email' => $request->signer_email],
        );

        $this->sendSigningRequestEmail($request);
    }

    /**
     * Send the template to the first party (agent) — initiates signing workflow.
     */
    public function sendForSigning(SignatureTemplate $template, User $agent): void
    {
        if (!in_array($template->status, [SignatureTemplate::STATUS_DRAFT, SignatureTemplate::STATUS_READY])) {
            throw new \LogicException('Template must be in draft or ready status to send.');
        }

        DB::transaction(function () use ($template, $agent) {
            // Capture document hash at signing start
            $hash = $this->generateDocumentHash($template->document);
            $template->update([
                'document_hash' => $hash,
                'status' => SignatureTemplate::STATUS_SIGNING,
            ]);

            // Find or create the agent's request and send it
            $agentRequest = $template->requests()
                ->where('party_role', 'agent')
                ->first();

            if ($agentRequest) {
                $this->sendSigningRequest($agentRequest);
            }
        });
    }

    // ──────────────────────────────────────────────
    // Signature capture
    // ──────────────────────────────────────────────

    /**
     * Capture a signature on a marker.
     */
    public function captureSignature(
        SignatureMarker $marker,
        string $signatureData,
        string $signerName,
        string $signerEmail,
        string $ipAddress,
        ?string $userAgent = null,
        ?SignatureRequest $request = null,
        ?User $signerUser = null,
        string $signatureType = 'drawn'
    ): Signature {
        $signature = Signature::create([
            'signature_template_id' => $marker->signature_template_id,
            'signature_marker_id' => $marker->id,
            'signature_request_id' => $request?->id,
            'signer_user_id' => $signerUser?->id,
            'signer_name' => $signerName,
            'signer_email' => $signerEmail,
            'signer_ip_address' => $ipAddress,
            'signer_user_agent' => $userAgent,
            'signature_data' => $signatureData,
            'signature_type' => $signatureType,
            'signed_at' => now(),
        ]);

        $template = $marker->template;

        SignatureAuditLog::log(
            $template,
            SignatureAuditLog::ACTION_SIGNED,
            $signerUser ? SignatureAuditLog::ACTOR_USER : SignatureAuditLog::ACTOR_SIGNER,
            $signerName,
            $signerEmail,
            $signerUser?->id,
            $request?->id,
            $ipAddress,
            $userAgent,
            ['marker_id' => $marker->id, 'marker_type' => $marker->type, 'page' => $marker->page_number],
            $template->document_hash,
        );

        return $signature;
    }

    // ──────────────────────────────────────────────
    // Completion checks
    // ──────────────────────────────────────────────

    /**
     * Check if all required markers for a party have been signed.
     */
    public function isPartyComplete(SignatureTemplate $template, string $party): bool
    {
        $requiredMarkers = $template->markers()
            ->where('assigned_party', $party)
            ->where('required', true)
            ->pluck('id');

        if ($requiredMarkers->isEmpty()) {
            return true;
        }

        $signedMarkerIds = $template->signatures()
            ->whereIn('signature_marker_id', $requiredMarkers)
            ->pluck('signature_marker_id')
            ->unique();

        return $requiredMarkers->diff($signedMarkerIds)->isEmpty();
    }

    /**
     * Check if all parties have completed signing.
     */
    public function isFullyComplete(SignatureTemplate $template): bool
    {
        $order = $template->signing_order_json ?? ['agent', 'tenant', 'landlord'];

        foreach ($order as $party) {
            if (!$this->isPartyComplete($template, $party)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Handle party completion — transition template status and send to next party.
     */
    public function handlePartyCompletion(SignatureTemplate $template, string $completedParty): void
    {
        DB::transaction(function () use ($template, $completedParty) {
            // Mark the request as completed
            $request = $template->requests()
                ->where('party_role', $completedParty)
                ->where('status', '!=', SignatureRequest::STATUS_COMPLETED)
                ->first();

            if ($request) {
                $request->update([
                    'status' => SignatureRequest::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);
            }

            // Determine next party
            $order = $template->signing_order_json ?? ['agent', 'tenant', 'landlord'];
            $currentIndex = array_search($completedParty, $order);
            $nextParty = $order[$currentIndex + 1] ?? null;

            if ($nextParty && !$this->isPartyComplete($template, $nextParty)) {
                // Transition to next party
                $statusMap = [
                    'tenant' => SignatureTemplate::STATUS_AWAITING_TENANT,
                    'landlord' => SignatureTemplate::STATUS_AWAITING_LANDLORD,
                ];
                $newStatus = $statusMap[$nextParty] ?? SignatureTemplate::STATUS_SIGNING;
                $template->update(['status' => $newStatus]);

                // Send to next party
                $nextRequest = $template->requests()
                    ->where('party_role', $nextParty)
                    ->first();

                if ($nextRequest && $nextRequest->status === SignatureRequest::STATUS_WAITING) {
                    $this->sendSigningRequest($nextRequest);
                }
            } elseif ($this->isFullyComplete($template)) {
                $this->completeDocument($template);
            }
        });
    }

    /**
     * Mark the document as fully signed, generate PDF, and email all parties.
     */
    public function completeDocument(SignatureTemplate $template): void
    {
        // 1. Lock the document
        $template->update([
            'status' => SignatureTemplate::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        SignatureAuditLog::log(
            $template,
            SignatureAuditLog::ACTION_COMPLETED,
            SignatureAuditLog::ACTOR_SYSTEM,
            'System',
            documentHash: $template->document_hash,
        );

        // 2. Generate signed PDF with embedded signatures + audit certificate
        $signedPdfPath = $this->pdfService->generate($template);

        if ($signedPdfPath) {
            $template->update(['signed_pdf_path' => $signedPdfPath]);

            SignatureAuditLog::log(
                $template,
                SignatureAuditLog::ACTION_DOCUMENT_COMPLETED,
                SignatureAuditLog::ACTOR_SYSTEM,
                'System',
                metadata: [
                    'signed_pdf_path' => $signedPdfPath,
                    'total_signatures' => $template->signatures()->count(),
                    'parties_completed' => $template->partyProgress(),
                ],
                documentHash: $template->document_hash,
            );
        } else {
            Log::warning('SignatureService: Signed PDF generation failed, sending emails without attachment', [
                'template_id' => $template->id,
            ]);
        }

        // 3. Email signed copies to all parties
        $this->sendCompletionEmails($template, $signedPdfPath);

        // 4. Extract lease data if this is a lease/rental document
        if ($this->isLeaseDocument($template)) {
            $this->createLeaseRecord($template);
        }
    }

    // ──────────────────────────────────────────────
    // Wet ink
    // ──────────────────────────────────────────────

    /**
     * Handle wet ink upload from signer.
     */
    public function handleWetInkUpload(SignatureRequest $request, UploadedFile $file): void
    {
        $path = $file->store('docuperfect/wet-ink-uploads', 'local');

        $request->update([
            'signing_method' => 'wet_ink',
            'wet_ink_upload_path' => $path,
            'wet_ink_status' => SignatureRequest::WET_INK_UPLOADED_PENDING_REVIEW,
        ]);

        SignatureAuditLog::log(
            $request->template,
            SignatureAuditLog::ACTION_WET_INK_UPLOADED,
            SignatureAuditLog::ACTOR_SIGNER,
            $request->signer_name,
            $request->signer_email,
            requestId: $request->id,
        );

        $this->sendWetInkUploadedNotification($request);
    }

    /**
     * Submit wet ink inspection result.
     */
    public function submitInspection(
        SignatureRequest $request,
        User $inspector,
        string $result,
        array $checklist,
        ?string $notes = null
    ): WetInkInspection {
        return DB::transaction(function () use ($request, $inspector, $result, $checklist, $notes) {
            $inspection = WetInkInspection::create([
                'signature_request_id' => $request->id,
                'inspector_user_id' => $inspector->id,
                'checklist_json' => $checklist,
                'result' => $result,
                'notes' => $notes,
            ]);

            $template = $request->template;
            $action = $result === WetInkInspection::RESULT_APPROVED
                ? SignatureAuditLog::ACTION_WET_INK_APPROVED
                : SignatureAuditLog::ACTION_WET_INK_REJECTED;

            SignatureAuditLog::log(
                $template,
                $action,
                SignatureAuditLog::ACTOR_USER,
                $inspector->name,
                $inspector->email,
                $inspector->id,
                $request->id,
            );

            if ($result === WetInkInspection::RESULT_APPROVED) {
                $request->update([
                    'wet_ink_status' => SignatureRequest::WET_INK_APPROVED,
                    'reviewed_by' => $inspector->id,
                    'reviewed_at' => now(),
                    'status' => SignatureRequest::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);

                $this->handlePartyCompletion($template, $request->party_role);
            } else {
                $request->update([
                    'wet_ink_status' => SignatureRequest::WET_INK_REJECTED,
                    'wet_ink_rejection_note' => $notes,
                    'reviewed_by' => $inspector->id,
                    'reviewed_at' => now(),
                    'wet_ink_upload_path' => null,
                ]);

                $this->sendWetInkRejectionEmail($request);
            }

            return $inspection;
        });
    }

    // ──────────────────────────────────────────────
    // Decline
    // ──────────────────────────────────────────────

    /**
     * Decline a signing request.
     */
    public function declineRequest(SignatureRequest $request, ?string $reason = null, ?string $ip = null, ?string $ua = null): void
    {
        DB::transaction(function () use ($request, $reason, $ip, $ua) {
            $request->update([
                'status' => SignatureRequest::STATUS_DECLINED,
                'ip_address' => $ip,
                'user_agent' => $ua,
            ]);

            $template = $request->template;
            $template->update(['status' => SignatureTemplate::STATUS_DECLINED]);

            SignatureAuditLog::log(
                $template,
                SignatureAuditLog::ACTION_DECLINED,
                SignatureAuditLog::ACTOR_SIGNER,
                $request->signer_name,
                $request->signer_email,
                requestId: $request->id,
                ip: $ip,
                ua: $ua,
                metadata: ['reason' => $reason],
            );
        });
    }

    // ──────────────────────────────────────────────
    // Reminders / expiry
    // ──────────────────────────────────────────────

    /**
     * Send a reminder for a pending request.
     */
    public function resendNotification(SignatureRequest $request): void
    {
        $request->update([
            'reminder_count' => $request->reminder_count + 1,
            'reminder_sent_at' => now(),
        ]);

        SignatureAuditLog::log(
            $request->template,
            SignatureAuditLog::ACTION_REMINDER_SENT,
            SignatureAuditLog::ACTOR_SYSTEM,
            'System',
            requestId: $request->id,
            metadata: ['reminder_number' => $request->reminder_count],
        );

        $this->sendReminderEmail($request);
    }

    /**
     * Send a manual reminder (agent-triggered). Does NOT increment reminder_count
     * so it won't interfere with the automatic escalation schedule.
     */
    public function sendManualReminder(SignatureRequest $request, User $sentBy): void
    {
        $request->update(['reminder_sent_at' => now()]);

        SignatureAuditLog::log(
            $request->template,
            SignatureAuditLog::ACTION_MANUAL_REMINDER_SENT,
            SignatureAuditLog::ACTOR_USER,
            $sentBy->name,
            $sentBy->email,
            $sentBy->id,
            $request->id,
            metadata: [
                'signer_name' => $request->signer_name,
                'signer_email' => $request->signer_email,
            ],
        );

        $this->sendManualReminderEmail($request);
    }

    /**
     * Expire all outstanding requests past their expiry date.
     * Returns the number of expired requests.
     */
    public function expireOutstandingRequests(): int
    {
        $expired = 0;

        $requests = SignatureRequest::expirable()->with('template')->get();

        foreach ($requests as $request) {
            $request->update(['status' => SignatureRequest::STATUS_EXPIRED]);

            $template = $request->template;

            // Check if all requests for this template are expired/declined
            $hasActiveRequests = $template->requests()
                ->whereNotIn('status', [
                    SignatureRequest::STATUS_EXPIRED,
                    SignatureRequest::STATUS_DECLINED,
                    SignatureRequest::STATUS_COMPLETED,
                ])
                ->exists();

            if (!$hasActiveRequests && $template->status !== SignatureTemplate::STATUS_COMPLETED) {
                $template->update(['status' => SignatureTemplate::STATUS_EXPIRED]);
            }

            SignatureAuditLog::log(
                $template,
                SignatureAuditLog::ACTION_EXPIRED,
                SignatureAuditLog::ACTOR_SYSTEM,
                'System',
                requestId: $request->id,
            );

            $expired++;
        }

        return $expired;
    }

    // ──────────────────────────────────────────────
    // Rental dashboard data
    // ──────────────────────────────────────────────

    /**
     * Get data for the rental documents dashboard.
     */
    public function getRentalDashboardData(User $user): array
    {
        // Get all rental documents visible to this user
        $rentalDocuments = Document::active()
            ->visibleTo($user)
            ->whereHas('template', function ($q) {
                $q->where('template_type', 'rental');
            })
            ->with(['template.documentType', 'owner'])
            ->get();

        $documentIds = $rentalDocuments->pluck('id');

        // Get signature templates for these documents
        $signatureTemplates = SignatureTemplate::whereIn('document_id', $documentIds)
            ->with(['requests'])
            ->get()
            ->keyBy('document_id');

        // Group documents by status
        $groups = [
            'draft' => collect(),
            'ready_to_sign' => collect(),
            'awaiting_signatures' => collect(),
            'completed' => collect(),
        ];

        $fieldStatus = [];

        foreach ($rentalDocuments as $doc) {
            $sigTemplate = $signatureTemplates->get($doc->id);

            if (!$sigTemplate) {
                // No signature template yet — check field completion
                $validation = $this->validateFieldCompletion($doc);

                $fieldStatus[$doc->id] = [
                    'valid' => $validation['valid'],
                    'total' => $validation['total'],
                    'filled' => $validation['filled'],
                    'missing' => $validation['missing'],
                ];

                if ($validation['valid']) {
                    $groups['ready_to_sign']->push($doc);
                } else {
                    $groups['draft']->push($doc);
                }
                continue;
            }

            match ($sigTemplate->status) {
                SignatureTemplate::STATUS_COMPLETED => $groups['completed']->push($doc),
                SignatureTemplate::STATUS_SIGNING,
                SignatureTemplate::STATUS_AWAITING_TENANT,
                SignatureTemplate::STATUS_AWAITING_LANDLORD => $groups['awaiting_signatures']->push($doc),
                SignatureTemplate::STATUS_READY,
                SignatureTemplate::STATUS_DRAFT => $groups['ready_to_sign']->push($doc),
                default => $groups['draft']->push($doc),
            };
        }

        // Lease renewal data
        $upcomingRenewals = LeaseRecord::visibleTo($user)
            ->whereIn('status', [LeaseRecord::STATUS_ACTIVE, LeaseRecord::STATUS_EXPIRING_SOON])
            ->where('lease_end_date', '<=', now()->addDays(90))
            ->orderBy('lease_end_date')
            ->get();

        $expiredLeases = LeaseRecord::visibleTo($user)
            ->where('status', LeaseRecord::STATUS_EXPIRED)
            ->orderBy('lease_end_date', 'desc')
            ->limit(10)
            ->get();

        $activeLeaseCount = LeaseRecord::visibleTo($user)
            ->where('status', LeaseRecord::STATUS_ACTIVE)
            ->count();

        return [
            'groups' => $groups,
            'signatureTemplates' => $signatureTemplates,
            'fieldStatus' => $fieldStatus,
            'counts' => [
                'draft' => $groups['draft']->count(),
                'ready_to_sign' => $groups['ready_to_sign']->count(),
                'awaiting_signatures' => $groups['awaiting_signatures']->count(),
                'completed' => $groups['completed']->count(),
            ],
            'upcomingRenewals' => $upcomingRenewals,
            'expiredLeases' => $expiredLeases,
            'activeLeaseCount' => $activeLeaseCount,
        ];
    }

    // ──────────────────────────────────────────────
    // Lease record extraction
    // ──────────────────────────────────────────────

    /**
     * Check if the signed document is a lease/rental document.
     */
    private function isLeaseDocument(SignatureTemplate $template): bool
    {
        $template->loadMissing('document.template.documentType');
        $document = $template->document;

        if (!$document) {
            return false;
        }

        // Check document type name for rental/lease keywords
        $docType = $document->template->documentType->name ?? '';
        $docName = $document->name ?? '';

        $keywords = ['lease', 'rental', 'tenancy', 'rent'];

        foreach ($keywords as $keyword) {
            if (stripos($docType, $keyword) !== false || stripos($docName, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a lease record from a completed signature template.
     */
    public function createLeaseRecord(SignatureTemplate $template): LeaseRecord
    {
        $template->loadMissing(['document', 'requests']);
        $document = $template->document;
        $parties = $template->parties_json ?? [];

        // Extract party details from parties_json
        $tenant = collect($parties)->firstWhere('role', 'tenant');
        $landlord = collect($parties)->firstWhere('role', 'landlord');

        // Extract lease-specific fields from document fields_json
        $fields = $this->extractLeaseFields($document);

        $record = LeaseRecord::create([
            'document_id' => $document->id,
            'signature_template_id' => $template->id,
            'property_id' => $fields['property_id'],
            'property_address' => $fields['property_address'] ?? $document->name,
            'tenant_name' => $tenant['name'] ?? '',
            'tenant_email' => $tenant['email'] ?? '',
            'landlord_name' => $landlord['name'] ?? '',
            'landlord_email' => $landlord['email'] ?? '',
            'rental_amount' => $fields['rental_amount'] ?? 0,
            'lease_start_date' => $fields['lease_start_date'] ?? now()->toDateString(),
            'lease_end_date' => $fields['lease_end_date'] ?? now()->addYear()->toDateString(),
            'status' => LeaseRecord::STATUS_ACTIVE,
        ]);

        SignatureAuditLog::log(
            $template,
            'lease_record_created',
            SignatureAuditLog::ACTOR_SYSTEM,
            'System',
            metadata: [
                'lease_record_id' => $record->id,
                'lease_start' => $record->lease_start_date->toDateString(),
                'lease_end' => $record->lease_end_date->toDateString(),
                'rental_amount' => $record->rental_amount,
            ],
        );

        return $record;
    }

    /**
     * Extract lease-specific fields from a document's fields_json.
     */
    private function extractLeaseFields(Document $document): array
    {
        $fields = $document->fields_json ?? [];

        return [
            'property_address' => $fields['property_address'] ?? $fields['address'] ?? $fields['premises_address'] ?? null,
            'property_id' => $fields['property_id'] ?? $fields['erf_number'] ?? null,
            'rental_amount' => (float) ($fields['monthly_rental'] ?? $fields['rental_amount'] ?? $fields['rent'] ?? 0),
            'lease_start_date' => $this->parseLeaseDate($fields['lease_start_date'] ?? $fields['commencement_date'] ?? $fields['start_date'] ?? null),
            'lease_end_date' => $this->parseLeaseDate($fields['lease_end_date'] ?? $fields['termination_date'] ?? $fields['end_date'] ?? null),
        ];
    }

    /**
     * Safely parse a date value for lease records.
     */
    private function parseLeaseDate($value): ?string
    {
        if (!$value) {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($value)->toDateString();
        } catch (\Exception $e) {
            return null;
        }
    }

    // ──────────────────────────────────────────────
    // Email methods
    // ──────────────────────────────────────────────

    /**
     * Send signing request email — FROM the agent.
     */
    private function sendSigningRequestEmail(SignatureRequest $request): void
    {
        try {
            $request->loadMissing(['template.document', 'template.creator']);
            $template = $request->template;
            $agent = $template->creator;
            $documentName = $template->document->name ?? 'Document';
            $signingUrl = route('signatures.external', $request->token);

            Mail::to($request->signer_email)->send(
                (new SigningRequestMail(
                    signerName: $request->signer_name,
                    documentName: $documentName,
                    signingUrl: $signingUrl,
                    message: $request->message,
                    expiresAt: $request->token_expires_at,
                ))->fromAgent($agent)
            );
        } catch (\Throwable $e) {
            Log::error('Failed to send signing request email', [
                'request_id' => $request->id,
                'signer_email' => $request->signer_email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send reminder email — FROM the agent.
     */
    public function sendReminderEmail(SignatureRequest $request): void
    {
        try {
            $request->loadMissing(['template.document', 'template.creator']);
            $template = $request->template;
            $agent = $template->creator;
            $documentName = $template->document->name ?? 'Document';
            $signingUrl = route('signatures.external', $request->token);

            Mail::to($request->signer_email)->send(
                (new SignatureReminderMail(
                    signerName: $request->signer_name,
                    documentName: $documentName,
                    signingUrl: $signingUrl,
                    expiresAt: $request->token_expires_at,
                    reminderNumber: $request->reminder_count,
                ))->fromAgent($agent)
            );
        } catch (\Throwable $e) {
            Log::error('Failed to send reminder email', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send manual reminder email — FROM the agent, with 'manual' tone.
     */
    private function sendManualReminderEmail(SignatureRequest $request): void
    {
        try {
            $request->loadMissing(['template.document', 'template.creator']);
            $template = $request->template;
            $agent = $template->creator;
            $documentName = $template->document->name ?? 'Document';
            $signingUrl = route('signatures.external', $request->token);

            Mail::to($request->signer_email)->send(
                (new SignatureReminderMail(
                    signerName: $request->signer_name,
                    documentName: $documentName,
                    signingUrl: $signingUrl,
                    expiresAt: $request->token_expires_at,
                    reminderNumber: $request->reminder_count,
                    forceTone: 'manual',
                ))->fromAgent($agent)
            );
        } catch (\Throwable $e) {
            Log::error('Failed to send manual reminder email', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send wet ink rejection email — FROM the agent.
     */
    private function sendWetInkRejectionEmail(SignatureRequest $request): void
    {
        try {
            $request->loadMissing(['template.document', 'template.creator']);
            $template = $request->template;
            $agent = $template->creator;
            $documentName = $template->document->name ?? 'Document';
            $signingUrl = route('signatures.external', $request->token);

            Mail::to($request->signer_email)->send(
                (new WetInkRejectionMail(
                    signerName: $request->signer_name,
                    documentName: $documentName,
                    signingUrl: $signingUrl,
                    rejectionNote: $request->wet_ink_rejection_note,
                ))->fromAgent($agent)
            );
        } catch (\Throwable $e) {
            Log::error('Failed to send wet ink rejection email', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Public accessor for wet ink upload notification (called by SigningController after multi-file upload).
     */
    public function notifyWetInkUploaded(SignatureRequest $request): void
    {
        $this->sendWetInkUploadedNotification($request);
    }

    /**
     * Send wet ink uploaded notification to the agent (internal — from system).
     */
    private function sendWetInkUploadedNotification(SignatureRequest $request): void
    {
        try {
            $request->loadMissing(['template.document', 'template.creator']);
            $template = $request->template;
            $agent = $template->creator;

            if (!$agent) {
                return;
            }

            $documentName = $template->document->name ?? 'Document';
            $inspectUrl = url("/docuperfect/documents/{$template->document_id}/signatures/inspect/{$request->id}");

            Mail::to($agent->email)->send(
                new WetInkUploadedNotification(
                    signerName: $request->signer_name,
                    documentName: $documentName,
                    inspectUrl: $inspectUrl,
                )
            );
        } catch (\Throwable $e) {
            Log::error('Failed to send wet ink uploaded notification', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send completion emails to all signers + the agent, with signed PDF attached.
     */
    private function sendCompletionEmails(SignatureTemplate $template, ?string $pdfPath = null): void
    {
        try {
            $template->loadMissing(['document', 'creator', 'requests']);
            $agent = $template->creator;
            $documentName = $template->document->name ?? 'Document';
            $viewUrl = url("/docuperfect/documents/{$template->document_id}/signatures/audit");
            $progress = $template->partyProgress();

            $fullPdfPath = $pdfPath ? storage_path("app/{$pdfPath}") : null;
            if ($fullPdfPath && !file_exists($fullPdfPath)) {
                $fullPdfPath = null;
            }

            $pdfFilename = $fullPdfPath ? "Signed - {$documentName}.pdf" : null;

            // Notify each signer
            foreach ($template->requests as $request) {
                if ($request->status !== SignatureRequest::STATUS_COMPLETED) {
                    continue;
                }

                $mail = (new SignedDocumentMail(
                    recipientName: $request->signer_name,
                    documentName: $documentName,
                    envelopeUrl: $viewUrl,
                    progress: $progress,
                    pdfPath: $fullPdfPath,
                    pdfFilename: $pdfFilename,
                ))->fromAgent($agent);

                Mail::to($request->signer_email)->send($mail);

                SignatureAuditLog::log(
                    $template,
                    SignatureAuditLog::ACTION_SIGNED_PDF_EMAILED,
                    SignatureAuditLog::ACTOR_SYSTEM,
                    'System',
                    metadata: [
                        'recipient_role' => $request->party_role,
                        'recipient_name' => $request->signer_name,
                        'recipient_email' => $request->signer_email,
                        'pdf_attached' => $fullPdfPath !== null,
                    ],
                );
            }

            // Also notify the agent
            if ($agent) {
                Mail::to($agent->email)->send(
                    new SignedDocumentMail(
                        recipientName: $agent->name,
                        documentName: $documentName,
                        envelopeUrl: $viewUrl,
                        progress: $progress,
                        pdfPath: $fullPdfPath,
                        pdfFilename: $pdfFilename,
                    )
                );

                SignatureAuditLog::log(
                    $template,
                    SignatureAuditLog::ACTION_SIGNED_PDF_EMAILED,
                    SignatureAuditLog::ACTOR_SYSTEM,
                    'System',
                    metadata: [
                        'recipient_role' => 'agent',
                        'recipient_name' => $agent->name,
                        'recipient_email' => $agent->email,
                        'pdf_attached' => $fullPdfPath !== null,
                    ],
                );
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send completion emails', [
                'template_id' => $template->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * Generate a secure unique 64-char token.
     */
    private function generateToken(): string
    {
        do {
            $token = Str::random(64);
        } while (SignatureRequest::where('token', $token)->exists());

        return $token;
    }
}
