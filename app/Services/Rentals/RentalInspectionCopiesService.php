<?php

namespace App\Services\Rentals;

use App\Models\Contact;
use App\Models\RentalInspection;
use App\Models\SignedDocumentDistributionLog;
use App\Models\User;
use App\Notifications\RentalInspectionCopiesNotDelivered;
use App\Services\Distribution\SignedDocumentDistributionService;
use Illuminate\Support\Facades\Log;

/**
 * .ai/specs/rental-inspections.md §45.6 (Build I-4) — the ONE place a completed inspection's report is filed, copied to
 * its parties, and the outcome made visible. Before this, the file-and-email logic lived as a private method on the
 * recording controller, delivery failures were swallowed, and the delivery log had no reader.
 *
 * Still built on the SHARED App\Services\Distribution\SignedDocumentDistributionService — no second mail path. This
 * class adds what is inspection-specific: re-sending to ONE recipient, alerting the inspector when anything did not go
 * out, and reading the delivery log back for the "Copies sent" panel.
 */
class RentalInspectionCopiesService
{
    public function __construct(
        private SignedDocumentDistributionService $distribution,
        private RentalInspectionReportPdfService $pdf,
    ) {}

    /**
     * File the signed report to the property and (when allowed) email it to every recipient.
     *
     * `$autoOnly` is the completion hook: filing always happens, the email only when the agency's own
     * auto_send_report_enabled is on. `$onlyEmails` narrows a manual send to specific addresses (per-recipient Resend).
     * A failure to send NEVER undoes anything — but it is never silent either: the inspector is alerted.
     *
     * @param array<int, string>|null $onlyEmails lower-cased addresses
     * @return array<int, array{role:string, email:string, status:string, message_id:?string, error:?string}>
     */
    public function fileAndSend(RentalInspection $inspection, bool $autoOnly, ?User $triggeredBy = null, ?array $onlyEmails = null): array
    {
        $this->distribution->ensurePublicLink($inspection);
        $pdfBytes = $this->pdf->generate($inspection)->output();
        $filename = $this->pdf->filenameFor($inspection);

        $this->distribution->fileToProperty($inspection, $pdfBytes, $filename);

        if ($autoOnly && ! \App\Models\RentalInspectionSetting::autoSendReportEnabledFor($inspection->agency_id)) {
            return [];
        }

        $pdfPath = tempnam(sys_get_temp_dir(), 'insp-report-') . '.pdf';
        file_put_contents($pdfPath, $pdfBytes);

        try {
            $results = $this->distribution->emailParties(
                $inspection,
                $pdfPath,
                $filename,
                mode: $autoOnly ? 'auto' : 'manual',
                triggeredBy: $triggeredBy,
                onlyEmails: $onlyEmails,
            );
        } finally {
            @unlink($pdfPath);
        }

        $this->alertIfAnyDidNotGoOut($inspection, $results);

        // §45.8 (Build I-6b) — an automatic (or resent) mailing is a settings-driven action; the history records the
        // outcome counts, not the addresses (those are in the "Copies sent" panel).
        if ($results !== []) {
            $counts = array_count_values(array_column($results, 'status'));
            \App\Models\RentalInspectionAuditLog::record(
                $inspection,
                \App\Models\RentalInspectionAuditLog::EVENT_COPIES_SENT,
                ($autoOnly ? 'Report copies sent automatically on completion' : 'Report copies sent again') . ': '
                    . ($counts['sent'] ?? 0) . ' sent, ' . ($counts['failed'] ?? 0) . ' failed, ' . ($counts['skipped'] ?? 0) . ' not sent (no address).',
                null,
                ['sent' => $counts['sent'] ?? 0, 'failed' => $counts['failed'] ?? 0, 'skipped' => $counts['skipped'] ?? 0],
                $triggeredBy,
            );
        }

        return $results;
    }

    /** The whole send failed before any recipient was tried — tell the inspector, never swallow it. */
    public function alertSendFailed(RentalInspection $inspection, \Throwable $e): void
    {
        Log::warning('Rental inspection report distribution failed', ['inspection_id' => $inspection->id, 'error' => $e->getMessage()]);
        $this->notifyInspector($inspection, null);
    }

    /** @param array<int, array{status:string}> $results */
    private function alertIfAnyDidNotGoOut(RentalInspection $inspection, array $results): void
    {
        $problems = count(array_filter($results, fn ($r) => in_array($r['status'], ['failed', 'skipped'], true)));
        if ($problems > 0) {
            $this->notifyInspector($inspection, $problems);
        }
    }

    private function notifyInspector(RentalInspection $inspection, ?int $problemCount): void
    {
        try {
            $user = $inspection->inspector ?? $inspection->createdBy;
            if (! $user) {
                return;
            }
            $user->notify(new RentalInspectionCopiesNotDelivered(
                inspectionId: $inspection->id,
                inspectionType: (string) $inspection->type,
                propertyAddress: $inspection->property?->buildDisplayAddress() ?: 'the property',
                problemCount: $problemCount,
                url: route('corex.rental-inspections.show', $inspection),
            ));
        } catch (\Throwable $e) {
            // An alert that cannot be delivered must never break the completion that triggered it.
            Log::warning('Rental inspection copies alert could not be sent', ['inspection_id' => $inspection->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Resolve a previous delivery-log row to the CURRENT recipient it stands for, and return that recipient's address
     * (lower-cased) — or null when that party is no longer a recipient or still has no usable address. Resolved on the
     * server from the log row, never from an address posted by the browser.
     */
    public function currentAddressFor(RentalInspection $inspection, SignedDocumentDistributionLog $row): ?string
    {
        foreach ($inspection->distributionRecipients() as $recipient) {
            $sameContact = $row->recipient_contact_id !== null
                && (int) $recipient['contact_id'] === (int) $row->recipient_contact_id
                && $recipient['role'] === $row->recipient_role;
            $sameAddress = $row->recipient_email !== null
                && mb_strtolower($recipient['email']) === mb_strtolower($row->recipient_email)
                && $recipient['role'] === $row->recipient_role;
            if ($sameContact || $sameAddress) {
                return mb_strtolower($recipient['email']);
            }
        }

        return null;
    }

    /** One row of the delivery log, scoped to THIS inspection (and, through BelongsToAgency, its agency). */
    public function logRowFor(RentalInspection $inspection, int $logId): ?SignedDocumentDistributionLog
    {
        return SignedDocumentDistributionLog::where('id', $logId)
            ->where('distributable_type', RentalInspection::class)
            ->where('distributable_id', $inspection->id)
            ->where('channel', 'email')
            ->first();
    }

    /**
     * The "Copies sent" panel: the LATEST outcome per recipient (a failed send followed by a good resend reads as sent),
     * newest first. Read-only over the delivery log.
     *
     * @return array<int, array{log_id:int, party:string, role_label:string, email:?string, status:string, reason:?string, when:\Illuminate\Support\Carbon, mode:?string, can_resend:bool}>
     */
    public function panelFor(RentalInspection $inspection): array
    {
        $rows = SignedDocumentDistributionLog::where('distributable_type', RentalInspection::class)
            ->where('distributable_id', $inspection->id)
            ->where('channel', 'email')
            ->orderBy('id')
            ->get();

        $latest = [];
        foreach ($rows as $row) {
            $key = $row->recipient_role . '|' . ($row->recipient_contact_id ?? mb_strtolower((string) $row->recipient_email));
            $latest[$key] = $row;
        }

        $contacts = Contact::withoutGlobalScopes()->whereIn('id', collect($latest)->pluck('recipient_contact_id')->filter())->get()->keyBy('id');
        $roleLabels = ['tenant' => 'Tenant', 'landlord' => 'Landlord', 'agency' => 'Agency copy', 'inspector' => 'Inspector', 'creator' => 'Agent who created it'];

        return collect($latest)->sortByDesc(fn ($r) => $r->id)->map(function (SignedDocumentDistributionLog $row) use ($contacts, $roleLabels, $inspection) {
            $name = $row->recipient_contact_id ? ($contacts->get($row->recipient_contact_id)?->full_name ?? 'Contact removed') : ($roleLabels[$row->recipient_role] ?? ucfirst((string) $row->recipient_role));

            return [
                'log_id' => $row->id,
                'party' => $name,
                'role_label' => $roleLabels[$row->recipient_role] ?? ucfirst((string) $row->recipient_role),
                'email' => $row->recipient_email,
                'status' => $row->status,
                'reason' => $row->status === 'sent' ? null : $row->error,
                'when' => $row->created_at,
                'mode' => $row->mode,
                'can_resend' => $row->status !== 'sent' && $inspection->status === RentalInspection::STATUS_COMPLETED,
            ];
        })->values()->all();
    }
}
