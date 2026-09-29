<?php

namespace App\Mail\Compliance;

use App\Mail\Signatures\BaseSignatureMail;
use App\Models\Agency;
use App\Models\Compliance\WhistleblowComplaint;
use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * 2026-09-28 — notifies the agency's Compliance Officer that an agent has
 * just filed a whistleblow report, with a direct link to action it. Sent
 * FROM the reporting agent (fromAgent()) so it goes out through their own
 * communication mailbox and lands in their Sent Items, matching every other
 * CoreX outbound — see WhistleblowComplaintService::notifyComplianceOfficerOfSubmission().
 */
class WhistleblowSubmittedCoMail extends BaseSignatureMail
{
    public WhistleblowComplaint $complaint;
    public Agency $agency;
    public User $reporter;

    public function __construct(WhistleblowComplaint $complaint, Agency $agency, User $reporter)
    {
        $this->complaint = $complaint;
        $this->agency    = $agency;
        $this->reporter  = $reporter;

        $this->fromAgent($reporter);
    }

    public function envelope(): Envelope
    {
        $agencyShort = $this->agency->trading_name ?? $this->agency->name;
        $tierNumber  = str_replace('tier_', '', $this->complaint->tier);
        $reference   = 'CDX-WB-' . $this->complaint->id;

        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: "[{$agencyShort}] New Compliance Report Filed — Tier {$tierNumber} — {$reference}",
        );
    }

    public function content(): Content
    {
        $this->complaint->loadMissing('subjects');

        return new Content(
            view: 'emails.compliance.whistleblow-co-notify',
            with: [
                'complaint'    => $this->complaint,
                'agency'       => $this->agency,
                'reporter'     => $this->reporter,
                'reviewUrl'    => route('compliance.whistleblow.show', $this->complaint->id),
                'agentFooter'  => $this->getAgentFooter(),
            ],
        );
    }
}
