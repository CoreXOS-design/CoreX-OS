<?php

namespace App\Mail\Compliance;

use App\Mail\Signatures\BaseSignatureMail;
use App\Models\Agency;
use App\Models\Compliance\WhistleblowComplaint;
use App\Models\User;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WhistleblowComplaintMail extends BaseSignatureMail
{
    public WhistleblowComplaint $complaint;
    public Agency $agency;
    public bool $isDemoMode;
    public string $tierLabel;

    private static array $tierLabels = [
        'tier_1' => 'Tier 1 — Seller-Confirmed Paperwork Breach',
        'tier_2' => 'Tier 2 — No FFC Displayed',
        'tier_3' => 'Tier 3 — Unregistered Practitioner',
    ];

    /**
     * 2026-09-28 — $approver (the agent who approved this complaint, i.e.
     * $complaint->approvedBy) is who this submission goes out AS: fromAgent()
     * drives the From/Reply-To and lets ComplianceMailDispatcher route the
     * send through their own communication mailbox so it lands in their Sent
     * Items, same as every other CoreX outbound.
     */
    public function __construct(WhistleblowComplaint $complaint, ?User $approver = null)
    {
        $this->complaint  = $complaint;
        $this->agency     = Agency::withoutGlobalScopes()->find($complaint->agency_id);
        $this->isDemoMode = !config('compliance.whistleblow.ppra_live_send', false);
        $this->tierLabel  = self::$tierLabels[$complaint->tier] ?? $complaint->tier;

        if ($approver) {
            $this->fromAgent($approver);
        }
    }

    public function envelope(): Envelope
    {
        $complaint = $this->complaint;
        $agency    = $this->agency;
        $complaint->loadMissing('subjects');

        $agencyShort = $agency->trading_name ?? $agency->name;
        $tierNumber  = str_replace('tier_', '', $complaint->tier);
        $subjectsSummary = $complaint->subjects_summary ?? 'Unknown';
        $subject = "[{$agencyShort}] PPRA Complaint — Tier {$tierNumber} — {$subjectsSummary}";

        if ($this->isDemoMode) {
            $subject = '[DEMO] ' . $subject;
        }

        // To: tier-aware routing
        if ($this->isDemoMode) {
            $toList = [config('compliance.whistleblow.demo_recipient', config('mail.from.address', 'demo-compliance@corexdemo.co.za'))];
        } else {
            $tierRecipients = $agency->whistleblow_tier_recipients ?? [];
            $toList = $tierRecipients[$complaint->tier] ?? [];
            if (empty($toList)) {
                $toList = ['complaints@theppra.org.za'];
            }
        }

        // CC — compliance officer + approver
        // Demo mode suppresses the real CC recipients (must never reach real people).
        $cc = [];
        if (! $this->isDemoMode) {
            if ($agency->whistleblow_compliance_officer_email) {
                $cc[] = $agency->whistleblow_compliance_officer_email;
            }
            if ($complaint->approvedBy && $complaint->approvedBy->email) {
                $cc[] = $complaint->approvedBy->email;
            }
        }

        return new Envelope(
            from: $this->getFromAddress(),
            to: $toList,
            cc: $cc,
            replyTo: $this->getReplyTo(),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.compliance.whistleblow-complaint',
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $pdfPath = $this->complaint->complaint_pdf_path;

        if (!$pdfPath || !file_exists($pdfPath)) {
            return [];
        }

        return [
            Attachment::fromPath($pdfPath)
                ->as('CDX-WB-' . $this->complaint->id . '.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
