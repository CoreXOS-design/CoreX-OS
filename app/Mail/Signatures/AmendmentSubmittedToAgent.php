<?php

declare(strict_types=1);

namespace App\Mail\Signatures;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * E-sign walk-fix FIX 4 — legal trail for flag-blocks-signing.
 *
 * Sent to the recipient immediately after they flag a clause. Confirms
 * the proposed amendments are now under the agent's review and that
 * the document is NOT legally binding until the agent has resolved
 * the amendments and the recipient has completed signing.
 *
 * Sent ALONGSIDE the existing audit log entry — the email is the
 * external-facing legal trail; the audit log is the internal one.
 */
final class AmendmentSubmittedToAgent extends BaseSignatureMail
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $documentName,
        public string $agentName,
        public string $clauseRef,
        public string $suggestedChange,
        public ?string $reason,
        public string $signingUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->getFromAddress(),
            subject: 'Your proposed amendments to ' . $this->documentName . ' are under review',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.signatures.amendment-submitted-to-agent',
            with: [
                'recipientName'    => $this->recipientName,
                'documentName'     => $this->documentName,
                'agentName'        => $this->agentName,
                'clauseRef'        => $this->clauseRef,
                'suggestedChange'  => $this->suggestedChange,
                'reason'           => $this->reason,
                'signingUrl'       => $this->signingUrl,
            ],
        );
    }
}
