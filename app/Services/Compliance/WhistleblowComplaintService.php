<?php

namespace App\Services\Compliance;

use App\Models\Agency;
use App\Models\Compliance\WhistleblowAuditLog;
use App\Models\Compliance\WhistleblowComplaint;
use App\Models\Compliance\WhistleblowComplaintEvidence;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class WhistleblowComplaintService
{
    /**
     * Create a new complaint in draft status.
     */
    public function createDraft(array $data, User $reporter): WhistleblowComplaint
    {
        $complaint = WhistleblowComplaint::withoutGlobalScopes()->create(array_merge($data, [
            'reported_by_user_id' => $reporter->id,
            'status' => 'draft',
        ]));

        $this->writeAudit($complaint, 'created', $reporter);

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

        // Validate required fields per tier
        $this->validateTierRequirements($complaint);

        // Must have at least one evidence row
        if ($complaint->evidence()->count() === 0) {
            throw new \InvalidArgumentException('At least one evidence attachment is required before submission.');
        }

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

        $property->update(['compliance_evidence_flags' => $flags]);

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
        $complaint->loadMissing(['evidence', 'auditLog', 'reporter', 'sellerContact']);
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
        if (empty($complaint->subject_agency_name)) {
            $missing[] = 'subject_agency_name';
        }
        if (empty($complaint->property_address)) {
            $missing[] = 'property_address';
        }

        // Tier-specific
        if ($complaint->tier === 'tier_1') {
            if (empty($complaint->seller_statement)) {
                $missing[] = 'seller_statement (required for Tier 1)';
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
