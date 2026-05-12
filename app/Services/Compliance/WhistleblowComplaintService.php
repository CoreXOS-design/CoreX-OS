<?php

namespace App\Services\Compliance;

use App\Models\Agency;
use App\Models\Compliance\WhistleblowAuditLog;
use App\Models\Compliance\WhistleblowComplaint;
use App\Models\Compliance\WhistleblowComplaintEvidence;
use App\Models\Compliance\WhistleblowComplaintSubject;
use App\Models\Compliance\WhistleblowEmailLog;
use App\Models\Property;
use App\Models\User;
use App\Mail\Compliance\SellerInfoMail;
use App\Mail\Compliance\WhistleblowComplaintMail;
use App\Models\Compliance\SellerInfoShareLink;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class WhistleblowComplaintService
{
    /**
     * Create a new complaint in draft status.
     */
    public function createDraft(array $data, User $reporter): WhistleblowComplaint
    {
        $subjects = $data['subjects'] ?? [];
        unset($data['subjects']);

        $complaint = WhistleblowComplaint::withoutGlobalScopes()->create(array_merge($data, [
            'reported_by_user_id' => $reporter->id,
            'status' => 'draft',
        ]));

        // Create subject rows
        foreach ($subjects as $i => $subject) {
            WhistleblowComplaintSubject::create([
                'complaint_id'      => $complaint->id,
                'agency_name'       => $subject['agency_name'],
                'practitioner_name' => $subject['practitioner_name'] ?? null,
                'portal_url'        => $subject['portal_url'],
                'portal_source'     => $subject['portal_source'] ?? 'other',
                'portal_listing_ref' => $subject['portal_listing_ref'] ?? null,
                'display_order'     => $i,
            ]);
        }

        $this->writeAudit($complaint, 'created', $reporter, [
            'subject_count' => count($subjects),
        ]);

        return $complaint;
    }

    /**
     * Attach an evidence file to a complaint.
     */
    public function attachEvidence(
        WhistleblowComplaint $complaint,
        string $evidenceType,
        string $filePath,
        ?string $originalFilename,
        ?string $mimeType,
        ?int $sizeBytes,
        ?string $description,
        User $uploadedBy
    ): WhistleblowComplaintEvidence {
        $evidence = WhistleblowComplaintEvidence::create([
            'complaint_id'       => $complaint->id,
            'evidence_type'      => $evidenceType,
            'file_path'          => $filePath,
            'original_filename'  => $originalFilename,
            'mime_type'          => $mimeType,
            'size_bytes'         => $sizeBytes,
            'description'        => $description,
            'uploaded_by_user_id' => $uploadedBy->id,
        ]);

        $this->writeAudit($complaint, 'evidence_attached', $uploadedBy, [
            'evidence_id'   => $evidence->id,
            'evidence_type' => $evidenceType,
            'filename'      => $originalFilename,
        ]);

        return $evidence;
    }

    /**
     * Submit a draft complaint for approval.
     */
    public function submit(WhistleblowComplaint $complaint, User $submittedBy): WhistleblowComplaint
    {
        if (!in_array($complaint->status, ['draft', 'changes_requested'])) {
            throw new \InvalidArgumentException("Complaint #{$complaint->id} is in status '{$complaint->status}', cannot submit.");
        }

        // Validate required fields + tier-specific evidence requirements
        $this->validateTierRequirements($complaint);

        $complaint->update(['status' => 'pending_approval']);
        $this->writeAudit($complaint, 'submitted', $submittedBy);

        return $complaint->fresh();
    }

    /**
     * Approve a complaint — generates PDF, flags property.
     */
    public function approve(
        WhistleblowComplaint $complaint,
        User $approver,
        ?string $notes = null
    ): WhistleblowComplaint {
        if ($complaint->status !== 'pending_approval') {
            throw new \InvalidArgumentException("Complaint #{$complaint->id} is not pending approval (status: {$complaint->status}).");
        }

        $this->validateApproverPermission($complaint, $approver);

        $complaint->update([
            'status'              => 'approved',
            'approved_by_user_id' => $approver->id,
            'approved_at'         => now(),
            'approval_notes'      => $notes,
        ]);

        $this->writeAudit($complaint, 'approved', $approver, [
            'notes' => $notes,
        ]);

        // Generate PDF
        $pdfPath = $this->generatePdf($complaint);
        $complaint->update(['complaint_pdf_path' => $pdfPath]);

        $this->writeAudit($complaint, 'pdf_generated', $approver, [
            'pdf_path' => $pdfPath,
        ]);

        // Flag property if linked
        if ($complaint->property_id) {
            $this->flagPropertyEvidence($complaint);
        }

        // Auto-send email to PPRA (or demo recipient)
        $complaint->refresh();
        $this->sendToPpra($complaint);

        // Auto-send seller info to property sellers (non-blocking)
        try {
            $this->sendSellerInfoFromComplaint($complaint);
        } catch (\Throwable $e) {
            Log::warning('Seller info auto-send failed', [
                'complaint_id' => $complaint->id,
                'error'        => $e->getMessage(),
            ]);
        }

        return $complaint->fresh();
    }

    /**
     * Reject a complaint with reason.
     */
    public function reject(
        WhistleblowComplaint $complaint,
        User $rejector,
        string $reason
    ): WhistleblowComplaint {
        if ($complaint->status !== 'pending_approval') {
            throw new \InvalidArgumentException("Complaint #{$complaint->id} is not pending approval.");
        }

        $this->validateApproverPermission($complaint, $rejector);

        $complaint->update([
            'status'              => 'rejected',
            'rejected_by_user_id' => $rejector->id,
            'rejected_at'         => now(),
            'rejection_reason'    => $reason,
        ]);

        $this->writeAudit($complaint, 'rejected', $rejector, [
            'reason' => $reason,
        ]);

        return $complaint->fresh();
    }

    /**
     * Request changes — returns complaint to agent for revision.
     */
    public function requestChanges(
        WhistleblowComplaint $complaint,
        User $requester,
        string $notes
    ): WhistleblowComplaint {
        if ($complaint->status !== 'pending_approval') {
            throw new \InvalidArgumentException("Complaint #{$complaint->id} is not pending approval.");
        }

        $this->validateApproverPermission($complaint, $requester);

        $complaint->update(['status' => 'changes_requested']);

        $this->writeAudit($complaint, 'changes_requested', $requester, [
            'notes' => $notes,
        ]);

        return $complaint->fresh();
    }

    /**
     * Mark complaint as sent to PPRA. Called by auto-send pipeline (Prompt C).
     */
    public function markSentToPpra(WhistleblowComplaint $complaint): WhistleblowComplaint
    {
        $complaint->update([
            'status'          => 'sent',
            'sent_to_ppra_at' => now(),
        ]);

        $this->writeAudit($complaint, 'emailed_to_ppra', null);

        return $complaint->fresh();
    }

    /**
     * Record PPRA acknowledgement (manual entry by user).
     */
    public function markAcknowledged(
        WhistleblowComplaint $complaint,
        ?string $ppraReference = null
    ): WhistleblowComplaint {
        $complaint->update([
            'status'                => 'acknowledged_by_ppra',
            'ppra_acknowledged_at'  => now(),
            'ppra_reference_number' => $ppraReference,
        ]);

        $this->writeAudit($complaint, 'acknowledged_by_ppra', null, [
            'ppra_reference' => $ppraReference,
        ]);

        return $complaint->fresh();
    }

    /**
     * Send the complaint email to PPRA (or demo recipient).
     * Called automatically from approve(). Can also be called manually for retries.
     */
    public function sendToPpra(WhistleblowComplaint $complaint): void
    {
        if ($complaint->status !== 'approved') {
            throw new \InvalidArgumentException(
                "Complaint #{$complaint->id} must be in 'approved' status to send (current: {$complaint->status})."
            );
        }

        $complaint->loadMissing(['approvedBy', 'subjects']);
        $isDemoMode = !config('compliance.whistleblow.ppra_live_send', false);
        $agency = Agency::withoutGlobalScopes()->find($complaint->agency_id);

        try {
            if (!$complaint->complaint_pdf_path || !file_exists($complaint->complaint_pdf_path)) {
                throw new \RuntimeException(
                    "PDF not found at '{$complaint->complaint_pdf_path}' for complaint #{$complaint->id}."
                );
            }

            $mailable = new WhistleblowComplaintMail($complaint);

            // Pre-render HTML + text for email log
            $renderedHtml = $mailable->render();
            $renderedText = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $renderedHtml));

            // Determine actual recipients for logging
            if ($isDemoMode) {
                $recipientTo = [config('compliance.whistleblow.demo_recipient', 'johan@hfcoastal.co.za')];
            } else {
                $tierRecipients = $agency->whistleblow_tier_recipients ?? [];
                $recipientTo = $tierRecipients[$complaint->tier] ?? ['complaints@theppra.org.za'];
                if (empty($recipientTo)) {
                    $recipientTo = ['complaints@theppra.org.za'];
                }
            }

            $recipientCc = [];
            if ($agency->whistleblow_compliance_officer_email) {
                $recipientCc[] = $agency->whistleblow_compliance_officer_email;
            }
            if ($complaint->approvedBy?->email) {
                $recipientCc[] = $complaint->approvedBy->email;
            }

            // Build subject line for log
            $agencyShort = $agency->trading_name ?? $agency->name;
            $tierNumber = str_replace('tier_', '', $complaint->tier);
            $emailSubject = "[{$agencyShort}] PPRA Complaint — Tier {$tierNumber} — {$complaint->subjects_summary}";
            if ($isDemoMode) {
                $emailSubject = '[DEMO] ' . $emailSubject;
            }

            // Attachment info for log
            $attachmentInfo = [];
            if ($complaint->complaint_pdf_path && file_exists($complaint->complaint_pdf_path)) {
                $attachmentInfo[] = [
                    'filename' => 'HFC-WB-' . $complaint->id . '.pdf',
                    'path' => $complaint->complaint_pdf_path,
                    'size' => filesize($complaint->complaint_pdf_path),
                ];
            }

            Mail::send($mailable);

            // Write email log row — success
            WhistleblowEmailLog::create([
                'complaint_id'    => $complaint->id,
                'sent_at'         => now(),
                'email_type'      => 'ppra_submission',
                'subject'         => $emailSubject,
                'recipients_to'   => $recipientTo,
                'recipients_cc'   => $recipientCc,
                'rendered_html'   => $renderedHtml,
                'rendered_text'   => $renderedText,
                'attachments'     => $attachmentInfo,
                'sent_by_user_id' => $complaint->approved_by_user_id,
                'status'          => 'sent',
            ]);

            $complaint->update([
                'status'          => 'sent',
                'sent_to_ppra_at' => now(),
            ]);

            $this->writeAudit($complaint, 'emailed_to_ppra', null, [
                'recipient_to' => $recipientTo,
                'recipient_cc' => $recipientCc,
                'demo_mode'    => $isDemoMode,
            ]);

            Log::info('Whistleblow complaint email sent', [
                'complaint_id' => $complaint->id,
                'to'           => $recipientTo,
                'demo_mode'    => $isDemoMode,
            ]);
        } catch (\Throwable $e) {
            // Write email log row — failure
            WhistleblowEmailLog::create([
                'complaint_id'    => $complaint->id,
                'sent_at'         => now(),
                'email_type'      => 'ppra_submission',
                'subject'         => $emailSubject ?? 'Failed to generate subject',
                'recipients_to'   => $recipientTo ?? [],
                'recipients_cc'   => $recipientCc ?? [],
                'rendered_html'   => $renderedHtml ?? '',
                'rendered_text'   => $renderedText ?? '',
                'sent_by_user_id' => $complaint->approved_by_user_id,
                'status'          => 'failed',
                'error_message'   => $e->getMessage(),
            ]);

            $this->writeAudit($complaint, 'email_send_failed', null, [
                'error'     => $e->getMessage(),
                'demo_mode' => $isDemoMode,
            ]);

            Log::error('Whistleblow complaint email failed', [
                'complaint_id' => $complaint->id,
                'error'        => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Auto-send seller info emails to property sellers on complaint approval.
     * Returns summary of what was sent.
     */
    public function sendSellerInfoFromComplaint(WhistleblowComplaint $complaint): array
    {
        if (!$complaint->property_id) {
            return ['sent_count' => 0, 'whatsapp_link' => null];
        }

        $property = Property::withoutGlobalScopes()->find($complaint->property_id);
        if (!$property) {
            return ['sent_count' => 0, 'whatsapp_link' => null];
        }

        $agency = Agency::withoutGlobalScopes()->find($complaint->agency_id);
        $sellerRoles = ['owner', 'lessor', 'landlord', 'seller'];
        $sellers = $property->contacts()
            ->wherePivotIn('role', $sellerRoles)
            ->get();

        $sentCount = 0;

        foreach ($sellers as $contact) {
            $email = $contact->email;
            if (!$email) {
                continue;
            }

            $sellerName = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: 'Valued Seller';

            try {
                $mailable = new SellerInfoMail($agency, $complaint->tier, $sellerName, '');
                $renderedHtml = $mailable->render();

                Mail::to($email)->send($mailable);

                WhistleblowEmailLog::create([
                    'complaint_id'    => $complaint->id,
                    'sent_at'         => now(),
                    'email_type'      => 'seller_info_email',
                    'subject'         => $mailable->envelope()->subject,
                    'recipients_to'   => [$email],
                    'recipients_cc'   => [],
                    'rendered_html'   => $renderedHtml,
                    'rendered_text'   => strip_tags(str_replace(['<br>', '</p>', '</div>'], "\n", $renderedHtml)),
                    'sent_by_user_id' => $complaint->approved_by_user_id,
                    'status'          => 'sent',
                ]);
                $sentCount++;
            } catch (\Throwable $e) {
                WhistleblowEmailLog::create([
                    'complaint_id'    => $complaint->id,
                    'sent_at'         => now(),
                    'email_type'      => 'seller_info_email',
                    'subject'         => 'Seller info — failed',
                    'recipients_to'   => [$email],
                    'recipients_cc'   => [],
                    'rendered_html'   => '',
                    'rendered_text'   => '',
                    'sent_by_user_id' => $complaint->approved_by_user_id,
                    'status'          => 'failed',
                    'error_message'   => $e->getMessage(),
                ]);
                Log::warning('Seller info email failed', ['contact' => $contact->id, 'error' => $e->getMessage()]);
            }
        }

        // Generate WhatsApp shareable link
        $link = SellerInfoShareLink::create([
            'tier'            => $complaint->tier,
            'seller_name'     => null,
            'seller_email'    => null,
            'agent_message'   => null,
            'property_id'     => $complaint->property_id,
            'sent_by_user_id' => $complaint->approved_by_user_id ?? $complaint->reported_by_user_id,
            'agency_id'       => $complaint->agency_id,
            'token'           => Str::random(32),
            'expires_at'      => now()->addDays(90),
        ]);

        $whatsappUrl = url('/info/' . $link->token);

        WhistleblowEmailLog::create([
            'complaint_id'    => $complaint->id,
            'sent_at'         => now(),
            'email_type'      => 'seller_info_whatsapp_link',
            'subject'         => 'WhatsApp shareable link generated',
            'recipients_to'   => ['WhatsApp link generated'],
            'recipients_cc'   => [],
            'rendered_html'   => '',
            'rendered_text'   => $whatsappUrl,
            'sent_by_user_id' => $complaint->approved_by_user_id,
            'status'          => 'sent',
        ]);

        Log::info('Seller info auto-sent from complaint', [
            'complaint_id'  => $complaint->id,
            'emails_sent'   => $sentCount,
            'whatsapp_link' => $whatsappUrl,
        ]);

        return ['sent_count' => $sentCount, 'whatsapp_link' => $whatsappUrl];
    }

    /**
     * Append compliance evidence flag to linked property record.
     */
    public function flagPropertyEvidence(WhistleblowComplaint $complaint): void
    {
        if (!$complaint->property_id) {
            return;
        }

        $property = Property::withoutGlobalScopes()->find($complaint->property_id);
        if (!$property) {
            return;
        }

        $flags = $property->compliance_evidence_flags ?? [];
        $flags[] = [
            'type'         => 'third_party_no_mandate',
            'complaint_id' => $complaint->id,
            'tier'         => $complaint->tier,
            'flagged_at'   => now()->toIso8601String(),
        ];

        $property->compliance_evidence_flags = $flags;
        $property->saveQuietly();

        $this->writeAudit($complaint, 'property_flagged', null, [
            'property_id' => $property->id,
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // PDF generation
    // ══════════════════════════════════════════════════════════════

    /**
     * Generate Tier-specific PPRA complaint PDF via Puppeteer.
     */
    protected function generatePdf(WhistleblowComplaint $complaint): string
    {
        $complaint->loadMissing(['evidence', 'auditLog', 'reporter', 'sellerContact', 'subjects']);
        $agency = Agency::withoutGlobalScopes()->find($complaint->agency_id);

        // Pick template by tier
        $templateMap = [
            'tier_1' => 'compliance.whistleblow.pdf.tier1',
            'tier_2' => 'compliance.whistleblow.pdf.tier2',
            'tier_3' => 'compliance.whistleblow.pdf.tier3',
        ];

        $viewName = $templateMap[$complaint->tier]
            ?? throw new \InvalidArgumentException("Unknown tier: {$complaint->tier}");

        // Render HTML
        $html = view($viewName, [
            'complaint' => $complaint,
            'agency'    => $agency,
            'reporter'  => $complaint->reporter,
            'subjects'  => $complaint->subjects,
            'evidence'  => $complaint->evidence,
            'auditLog'  => $complaint->auditLog()->orderBy('created_at')->get(),
        ])->render();

        // Write temp HTML
        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $htmlPath = $tempDir . '/wb-' . $complaint->id . '-' . uniqid() . '.html';
        file_put_contents($htmlPath, $html);

        // Output PDF path
        $pdfDir = storage_path('app/whistleblow/complaints/' . $complaint->id);
        if (!is_dir($pdfDir)) {
            mkdir($pdfDir, 0755, true);
        }
        $pdfPath = $pdfDir . '/HFC-WB-' . $complaint->id . '.pdf';

        // Generate via Puppeteer
        $this->invokePuppeteer($htmlPath, $pdfPath, $complaint->id);

        // Clean up temp HTML
        @unlink($htmlPath);

        return $pdfPath;
    }

    /**
     * Puppeteer invocation — follows PayslipPdfService pattern.
     */
    private function invokePuppeteer(string $htmlPath, string $pdfPath, int $complaintId): void
    {
        $scriptPath = base_path('scripts/html-to-pdf.mjs');
        $browserPath = config('services.pdf.puppeteer_browser_path', '');
        $isWindows = DIRECTORY_SEPARATOR === '\\';

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

        $nodeArg   = escapeshellarg(str_replace('\\', '/', $nodePath));
        $scriptArg = escapeshellarg(str_replace('\\', '/', $scriptPath));
        $htmlArg   = escapeshellarg(str_replace('\\', '/', $htmlPath));
        $outArg    = escapeshellarg(str_replace('\\', '/', $pdfPath));

        $envPrefix = '';
        if (!$isWindows) {
            $envPrefix = 'HOME=/tmp';
            if ($browserPath) {
                $envPrefix .= sprintf(' PUPPETEER_BROWSER_PATH=%s', escapeshellarg($browserPath));
            }
            $envPrefix .= ' ';
        }

        $command = sprintf('%s%s %s %s %s', $envPrefix, $nodeArg, $scriptArg, $htmlArg, $outArg);

        $tempDir = storage_path('app/temp');
        $logPath = $tempDir . DIRECTORY_SEPARATOR . 'wb_pdf_' . $complaintId . '.log';

        Log::info('Whistleblow PDF generation starting', ['complaint_id' => $complaintId, 'command' => $command]);

        $fullCommand = $command . ' > ' . escapeshellarg(str_replace('/', DIRECTORY_SEPARATOR, $logPath)) . ' 2>&1';
        shell_exec($fullCommand);

        $logContent = file_exists($logPath) ? file_get_contents($logPath) : '';
        @unlink($logPath);

        clearstatcache();
        $normalizedOutput = str_replace('/', DIRECTORY_SEPARATOR, $pdfPath);

        if (!file_exists($normalizedOutput) || filesize($normalizedOutput) === 0) {
            Log::error('Whistleblow PDF not generated', [
                'complaint_id' => $complaintId,
                'log'          => substr($logContent, 0, 500),
            ]);
            throw new \RuntimeException(
                'PDF generation failed for complaint ' . $complaintId . '. '
                . ($logContent ? 'Script output: ' . substr($logContent, 0, 200) : 'No output from script.')
            );
        }

        Log::info('Whistleblow PDF complete', [
            'complaint_id' => $complaintId,
            'path'         => $normalizedOutput,
            'size'         => filesize($normalizedOutput),
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // Validation helpers
    // ══════════════════════════════════════════════════════════════

    /**
     * Validate tier-specific required fields before submission.
     */
    private function validateTierRequirements(WhistleblowComplaint $complaint): void
    {
        $missing = [];

        // Common to all tiers
        if ($complaint->subjects()->count() === 0) {
            $missing[] = 'at least one subject (agency/practitioner)';
        }
        if (empty($complaint->property_address)) {
            $missing[] = 'property_address';
        }

        // Tier-specific evidence requirements per spec §5
        $evidenceCount = $complaint->evidence()->count();

        if ($complaint->tier === 'tier_1') {
            // Tier 1: seller statement IS the primary evidence — file attachments optional
            if (empty($complaint->seller_statement) || mb_strlen(trim($complaint->seller_statement)) < 20) {
                $missing[] = 'seller_statement (required for Tier 1, minimum 20 characters)';
            }
        } elseif ($complaint->tier === 'tier_2') {
            if ($evidenceCount === 0) {
                $missing[] = 'screenshot evidence (required for Tier 2)';
            }
        } elseif ($complaint->tier === 'tier_3') {
            if ($evidenceCount === 0) {
                $missing[] = 'screenshot evidence (required for Tier 3)';
            }
        }

        if (!empty($missing)) {
            throw new \InvalidArgumentException(
                'Missing required fields for ' . $complaint->tier . ': ' . implode(', ', $missing)
            );
        }
    }

    /**
     * Validate that a user is authorised to approve/reject/request-changes.
     */
    private function validateApproverPermission(WhistleblowComplaint $complaint, User $user): void
    {
        // Check agency approver list first
        $agency = Agency::withoutGlobalScopes()->find($complaint->agency_id);
        $approverIds = $agency->whistleblow_approver_user_ids ?? [];

        if (!empty($approverIds) && in_array($user->id, $approverIds)) {
            return; // Explicitly listed as approver
        }

        // Fallback: admin, branch_manager, super_admin roles
        $allowedRoles = ['admin', 'branch_manager', 'super_admin'];
        $userRole = $user->role ?? 'agent';

        if (in_array($userRole, $allowedRoles)) {
            return;
        }

        throw new \InvalidArgumentException(
            "User #{$user->id} ({$userRole}) is not authorised to approve complaints for agency #{$complaint->agency_id}."
        );
    }

    // ══════════════════════════════════════════════════════════════
    // Audit log
    // ══════════════════════════════════════════════════════════════

    // ══════════════════════════════════════════════════════════════
    // Lawyer review pack
    // ══════════════════════════════════════════════════════════════

    /**
     * Generate a ZIP file containing 3 tier-specific PDF templates + cover email + README
     * for lawyer review. Uses placeholder/sample data — nothing persisted to DB.
     */
    public function generateLawyerReviewPack(User $requestedBy): string
    {
        $agency = Agency::withoutGlobalScopes()->find($requestedBy->agency_id ?? 1)
            ?? Agency::withoutGlobalScopes()->first();

        $timestamp = now()->format('Ymd-His');
        $packDir = storage_path("app/temp/lawyer-review-{$timestamp}");
        mkdir($packDir, 0755, true);

        // Build 3 synthetic complaints (in-memory only)
        $tiers = [
            'tier_1' => [
                'subject_agency_name'  => '[SAMPLE] Competing Realty Group',
                'subject_practitioner_name' => '[SAMPLE] John Smith',
                'subject_ffc_number'   => null,
                'property_address'     => '[SAMPLE] 14 Marine Drive, Margate',
                'property_portal_url'  => 'https://www.privateproperty.co.za/for-sale/sample-listing/T12345',
                'portal_source'        => 'pp',
                'portal_listing_ref'   => 'PP-SAMPLE-12345',
                'seller_statement'     => '[Verbatim seller statement would appear here. This is a sample for legal review. The actual complaint captures the seller\'s words directly from the agent\'s conversation with the property owner.]',
                'seller_consents_to_named_complaint' => true,
                'agent_notes'          => '[Internal agent notes from the conversation go here. Used to inform the legal team but not included in the PPRA-facing document.]',
            ],
            'tier_2' => [
                'subject_agency_name'  => '[SAMPLE] Beachfront Brokers SA',
                'subject_practitioner_name' => '[SAMPLE] Jane Doe',
                'subject_ffc_number'   => null,
                'property_address'     => '[SAMPLE] 7 Ocean View Road, Uvongo',
                'property_portal_url'  => 'https://www.property24.com/for-sale/sample-listing/12345678',
                'portal_source'        => 'p24',
                'portal_listing_ref'   => 'P24-SAMPLE-67890',
                'seller_statement'     => null,
                'seller_consents_to_named_complaint' => false,
                'agent_notes'          => 'The attached advertisement on Property24 for the subject property displays no Fidelity Fund Certificate number for the practitioner or the agency.',
            ],
            'tier_3' => [
                'subject_agency_name'  => '[SAMPLE] Unregistered Property Services',
                'subject_practitioner_name' => '[SAMPLE] Daniel Mokoena',
                'subject_ffc_number'   => null,
                'property_address'     => '[SAMPLE] 22 King George Street, Port Edward',
                'property_portal_url'  => 'https://www.property24.com/for-sale/sample-listing/99999999',
                'portal_source'        => 'p24',
                'portal_listing_ref'   => 'P24-SAMPLE-99999',
                'seller_statement'     => null,
                'seller_consents_to_named_complaint' => false,
                'agent_notes'          => 'We attempted to verify the advertising practitioner against the PPRA "Find a Property Practitioner" register. No record was found for the name displayed on the advertisement.',
            ],
        ];

        $templateMap = [
            'tier_1' => 'compliance.whistleblow.pdf.tier1',
            'tier_2' => 'compliance.whistleblow.pdf.tier2',
            'tier_3' => 'compliance.whistleblow.pdf.tier3',
        ];

        // Synthetic evidence + audit data (not persisted)
        $fakeEvidence = collect([
            (object) [
                'evidence_type'    => 'screenshot',
                'description'      => 'Screenshot of portal listing showing the subject property advertised without proper compliance',
                'original_filename' => 'portal-listing-screenshot.png',
                'size_bytes'       => 245000,
                'created_at'       => now()->subHours(2),
            ],
            (object) [
                'evidence_type'    => 'other',
                'description'      => 'Notes from telephone conversation with property owner',
                'original_filename' => 'call-notes.txt',
                'size_bytes'       => 1024,
                'created_at'       => now()->subHours(1),
            ],
        ]);

        $fakeAuditLog = collect([
            (object) ['action' => 'created',          'created_at' => now()->subDay(), 'user' => $requestedBy],
            (object) ['action' => 'evidence_attached', 'created_at' => now()->subDay()->addMinutes(5), 'user' => $requestedBy],
            (object) ['action' => 'submitted',         'created_at' => now()->subDay()->addMinutes(10), 'user' => $requestedBy],
            (object) ['action' => 'approved',          'created_at' => now()->subHours(20), 'user' => $requestedBy],
            (object) ['action' => 'pdf_generated',     'created_at' => now()->subHours(20)->addSeconds(3), 'user' => $requestedBy],
            (object) ['action' => 'emailed_to_ppra',   'created_at' => now()->subHours(20)->addMinutes(1), 'user' => null],
        ]);

        $pdfFiles = [];

        foreach ($tiers as $tier => $data) {
            // Build a synthetic complaint object for the view
            $complaint = new WhistleblowComplaint(array_merge($data, [
                'agency_id'            => $agency->id,
                'reported_by_user_id'  => $requestedBy->id,
                'tier'                 => $tier,
                'status'               => 'sent',
                'approved_by_user_id'  => $requestedBy->id,
            ]));
            // Set timestamps + fake ID directly (not mass-assignable)
            $complaint->id = 999;
            $complaint->created_at = now()->subDay();
            $complaint->updated_at = now();
            $complaint->approved_at = now()->subHours(20);
            $complaint->sent_to_ppra_at = now()->subHours(20)->addMinutes(1);

            // Build reporter relation manually
            $complaint->setRelation('reporter', $requestedBy);
            $complaint->setRelation('approvedBy', $requestedBy);
            $complaint->setRelation('sellerContact', null);
            $complaint->setRelation('evidence', $fakeEvidence);

            // Fake subjects for the review pack
            $fakeSubjects = collect([
                (object) ['agency_name' => $data['subject_agency_name'] ?? '[SAMPLE] Agency', 'practitioner_name' => $data['subject_practitioner_name'] ?? null, 'portal_url' => $data['property_portal_url'] ?? 'https://example.com', 'portal_source' => $data['portal_source'] ?? 'p24'],
            ]);
            $complaint->setRelation('subjects', $fakeSubjects);

            $viewName = $templateMap[$tier];
            $html = view($viewName, [
                'complaint' => $complaint,
                'agency'    => $agency,
                'reporter'  => $requestedBy,
                'subjects'  => $fakeSubjects,
                'evidence'  => $fakeEvidence,
                'auditLog'  => $fakeAuditLog,
            ])->render();

            // Write temp HTML
            $tempDir = storage_path('app/temp');
            $htmlPath = $tempDir . '/lr-' . $tier . '-' . uniqid() . '.html';
            file_put_contents($htmlPath, $html);

            $pdfFilename = str_replace('_', '', $tier) . '-template.pdf';
            $pdfPath = $packDir . '/' . $pdfFilename;

            $this->invokePuppeteer($htmlPath, $pdfPath, 999);
            @unlink($htmlPath);

            $pdfFiles[] = $pdfFilename;
        }

        // Render cover email HTML
        $coverComplaint = new WhistleblowComplaint([
            'agency_id'            => $agency->id,
            'tier'                 => 'tier_1',
            'subject_agency_name'  => '[SAMPLE] Competing Realty Group',
            'status'               => 'sent',
            'approved_by_user_id'  => $requestedBy->id,
        ]);
        $coverComplaint->id = 999;
        $coverComplaint->created_at = now()->subDay();
        $coverComplaint->setRelation('approvedBy', $requestedBy);

        $coverHtml = view('emails.compliance.whistleblow-complaint', [
            'complaint' => $coverComplaint,
            'agency'    => $agency,
            'tierLabel' => 'Tier 1 — Seller-Confirmed Paperwork Breach',
            'isDemoMode' => true,
        ])->render();

        file_put_contents($packDir . '/cover-email.html', $coverHtml);
        file_put_contents($packDir . '/cover-email.txt', strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $coverHtml)));

        // Generate README.md
        $coEmail = $agency->whistleblow_compliance_officer_email ?? $agency->email ?? 'compliance@agency.co.za';
        $readme = <<<MD
# Whistleblower Compliance Reporting — Legal Review Pack

Generated: {$timestamp}
For: {$agency->trading_name} ({$agency->name})
By: {$requestedBy->name}

## Contents

This pack contains the three tier-specific PPRA complaint PDF templates used by the CoreX OS Whistleblower Compliance Module, plus the cover email body that accompanies each submission.

- `tier1-template.pdf` — Used when a seller confirms a competing agency is marketing their property without proper mandate, FICA, or MDF. Cites Property Practitioners Act §47, §67 and FICA §21A.

- `tier2-template.pdf` — Used when an advert is observed to lack a valid FFC number in contravention of PPA §61.

- `tier3-template.pdf` — Used when an advertising practitioner cannot be located on the PPRA register, citing PPA §49 (operating without FFC — criminal offence).

- `cover-email.html` / `cover-email.txt` — The short cover email body sent to PPRA with the complaint PDF attached.

## What we'd like from you

1. Review the language in each template for legal accuracy and tone.
2. Confirm the sections of the Act cited are correct in context.
3. Suggest any wording changes that strengthen the complaint or reduce litigation risk to the lodging agency.
4. Review the seller-consent language in tier 1 for POPIA compliance.
5. Review the cover email for tone and accuracy.

## Disclaimer

The pack shows placeholder content marked [SAMPLE]. Real complaints use real agent / seller / property data captured in CoreX at the time of submission. The legal framework remains constant — only the variable content changes per complaint.

## Submission process (for context)

- Agent files a report from inside CoreX
- Configured approvers (admin / branch manager / designated users) review and approve
- On approval, CoreX generates the tier-specific PDF and emails it to PPRA at complaints@theppra.org.za with the agency's compliance officer copied
- System currently runs in demo mode — no real PPRA submissions until your review is complete

## Contact

{$coEmail}
MD;

        file_put_contents($packDir . '/README.md', $readme);

        // Bundle into ZIP
        $zipPath = storage_path("app/temp/lawyer-review-{$timestamp}.zip");
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to create ZIP file at ' . $zipPath);
        }

        foreach (glob($packDir . '/*') as $file) {
            $zip->addFile($file, basename($file));
        }
        $zip->close();

        // Clean up the temp directory (files are now in ZIP)
        foreach (glob($packDir . '/*') as $file) {
            @unlink($file);
        }
        @rmdir($packDir);

        Log::info('Lawyer review pack generated', [
            'user_id' => $requestedBy->id,
            'path'    => $zipPath,
            'size'    => filesize($zipPath),
        ]);

        return $zipPath;
    }

    /**
     * Convenience wrapper for audit row creation.
     */
    protected function writeAudit(
        WhistleblowComplaint $complaint,
        string $action,
        ?User $user,
        ?array $actionData = null
    ): void {
        WhistleblowAuditLog::create([
            'complaint_id' => $complaint->id,
            'user_id'      => $user?->id,
            'action'       => $action,
            'action_data'  => $actionData,
            'created_at'   => now(),
        ]);
    }
}
