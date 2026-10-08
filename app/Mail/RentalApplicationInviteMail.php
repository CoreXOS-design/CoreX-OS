<?php

namespace App\Mail;

use Illuminate\Contracts\Queue\ShouldQueue;

use App\Mail\Signatures\BaseSignatureMail;
use App\Models\RentalApplication;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * AT-392 spec §4 — one send, two return routes. Both links are always in the
 * one email; the applicant picks whichever suits them.
 *
 * AT-392, Johan, QA1: "email sent to applicant needs the same email format
 * as esign emails - agent details, photo etc." Extends BaseSignatureMail
 * (the same base the e-sign "please sign" email uses) rather than building a
 * second, parallel branded-email layout — the From-address routing, reply-to,
 * and agent footer (name/photo/phone/FFC/PPRA/agency logo) all come from
 * there, so this email and every e-sign email share one implementation and
 * can never drift apart. See fromAgent() call in RentalApplicationMailer.
 */
class RentalApplicationInviteMail extends BaseSignatureMail implements ShouldQueue
{
    // Prod-audit 2026-09-16 — request-triggered mail is queued (CLAUDE.md), never
    // sent inside the page request; the live mail worker group drains this queue.
    public $queue = 'mail';

    public string $contactName;
    public string $agencyName;
    public string $onlineUrl;
    public string $downloadUrl;
    public string $expiresAt;
    /** The agency's own sentence ('' = none), {agency} already replaced. Agency setting `invite_policy_sentence`. */
    public string $policySentence;
    /** The property the application is for, when one is linked ('' otherwise). */
    public string $propertyAddress;

    public function __construct(public RentalApplication $application)
    {
        $this->contactName = $application->contact->full_name ?: 'there';
        $this->agencyName  = $application->agency->name ?? config('mail.from.name', 'CoreX OS');
        $this->onlineUrl   = route('rental-applications.public.show', $application->token);
        $this->downloadUrl = route('rental-applications.public.pdf', $application->token);
        $this->expiresAt   = $application->token_expires_at->format('d M Y');
        $this->policySentence = \App\Models\RentalApplicationQualifyingSetting::renderedInvitePolicySentenceFor($application->agency_id, $application->agency->name ?? null);
        $this->propertyAddress = (string) ($application->property?->buildDisplayAddress() ?? '');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: "Rental Application — {$this->agencyName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.rental-application-invite',
            with: [
                'agentFooter' => $this->getAgentFooter(),
            ],
        );
    }
}
