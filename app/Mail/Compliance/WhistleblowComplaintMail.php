<?php

namespace App\Mail\Compliance;

use App\Models\Agency;
use App\Models\Compliance\WhistleblowComplaint;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WhistleblowComplaintMail extends Mailable
{
    use Queueable, SerializesModels;

    public WhistleblowComplaint $complaint;
    public Agency $agency;
    public bool $isDemoMode;
    public string $tierLabel;

    private static array $tierLabels = [
        'tier_1' => 'Tier 1 — Seller-Confirmed Paperwork Breach',
        'tier_2' => 'Tier 2 — No FFC Displayed',
        'tier_3' => 'Tier 3 — Unregistered Practitioner',
    ];

    public function __construct(WhistleblowComplaint $complaint)
    {
        $this->complaint  = $complaint;
        $this->agency     = Agency::withoutGlobalScopes()->find($complaint->agency_id);
        $this->isDemoMode = !config('compliance.whistleblow.ppra_live_send', false);
        $this->tierLabel  = self::$tierLabels[$complaint->tier] ?? $complaint->tier;
    }

    public function envelope(): Envelope
    {
        $complaint = $this->complaint;
        $agency    = $this->agency;

        // Subject line — demo prefix when not live
        $agencyShort = $agency->trading_name ?? $agency->name;
        $tierNumber  = str_replace('tier_', '', $complaint->tier);
        $subject     = "[{$agencyShort}] PPRA Complaint — Tier {$tierNumber} — {$complaint->subject_agency_name}";

        if ($this->isDemoMode) {
            $subject = '[DEMO] ' . $subject;
        }

        // To address — demo vs live
        if ($this->isDemoMode) {
            $to = config('compliance.whistleblow.demo_recipient', 'johan@hfcoastal.co.za');
        } else {
            $to = $agency->whistleblow_ppra_recipient_email
                ?? 'complaints@theppra.org.za';
        }

        // CC — compliance officer + approver
        $cc = [];
        if ($agency->whistleblow_compliance_officer_email) {
            $cc[] = $agency->whistleblow_compliance_officer_email;
        }
        if ($complaint->approvedBy && $complaint->approvedBy->email) {
            $cc[] = $complaint->approvedBy->email;
        }

        // From — compliance officer email or system default
        $fromAddress = $agency->whistleblow_compliance_officer_email
            ?? config('mail.from.address');
        $fromName = $agencyShort;

        // Reply-To — the approver
        $replyTo = [];
        if ($complaint->approvedBy) {
            $replyTo[] = new Address($complaint->approvedBy->email, $complaint->approvedBy->name);
        }

        return new Envelope(
            from: new Address($fromAddress, $fromName),
            to: [$to],
            cc: $cc,
            replyTo: $replyTo,
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
                ->as('HFC-WB-' . $this->complaint->id . '.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
