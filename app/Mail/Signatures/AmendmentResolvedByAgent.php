<?php

declare(strict_types=1);

namespace App\Mail\Signatures;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * E-sign walk-fix FIX 4 — agent has acted on the recipient's proposed
 * amendments. `$resolution` is one of:
 *
 *   - 'approved'         — agent accepted the change as-is
 *   - 'approved_with_edit' — agent accepted but tweaked the wording
 *   - 'rejected_change'  — agent rejected the change; original clause stands
 *   - 'rejected_document'— agent declined to amend the document at all
 *
 * The recipient returns to the signing link to complete or withdraw.
 */
final class AmendmentResolvedByAgent extends BaseSignatureMail
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $documentName,
        public string $agentName,
        public string $clauseRef,
        public string $resolution,
        public ?string $agentNote,
        public ?string $finalText,
        public string $signingUrl,
    ) {}

    public function envelope(): Envelope
    {
        $verb = match ($this->resolution) {
            'approved'           => 'accepted',
            'approved_with_edit' => 'accepted (with edits)',
            'rejected_change'    => 'rejected',
            'rejected_document'  => 'declined',
            default              => 'reviewed',
        };
        return new Envelope(
            from: $this->getFromAddress(),
            subject: 'Your proposed amendments to ' . $this->documentName . ' have been ' . $verb,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.signatures.amendment-resolved-by-agent',
            with: [
                'recipientName' => $this->recipientName,
                'documentName'  => $this->documentName,
                'agentName'     => $this->agentName,
                'clauseRef'     => $this->clauseRef,
                'resolution'    => $this->resolution,
                'agentNote'     => $this->agentNote,
                'finalText'     => $this->finalText,
                'signingUrl'    => $this->signingUrl,
            ],
        );
    }
}
