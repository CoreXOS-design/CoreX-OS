<?php

namespace App\Mail;

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
class RentalApplicationInviteMail extends BaseSignatureMail
{
    public string $contactName;
    public string $agencyName;
    public string $onlineUrl;
    public string $downloadUrl;
    public string $expiresAt;

    public function __construct(public RentalApplication $application)
    {
        $this->contactName = $application->contact->full_name ?: 'there';
        $this->agencyName  = $application->agency->name ?? config('mail.from.name', 'CoreX OS');
        $this->onlineUrl   = route('rental-applications.public.show', $application->token);
        $this->downloadUrl = route('rental-applications.public.pdf', $application->token);
        $this->expiresAt   = $application->token_expires_at->format('d M Y');
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
