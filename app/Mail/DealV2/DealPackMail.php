<?php

namespace App\Mail\DealV2;

use App\Mail\Signatures\BaseSignatureMail;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * AT-228 — a party document PACK email. One email carries the whole part:
 *  - direct_attachment mode: the part's documents attached (pack), optionally "(Part N of M)".
 *  - secure_link mode: a list of OTP-gated links (no attachments); size never an issue.
 * Carries the agent's editable message. Extends BaseSignatureMail for the agent-from / footer.
 *
 * @param array<int,array{path:string,filename:string}> $attachmentFiles  absolute paths (direct mode)
 * @param array<int,array{title:string,url:string}>     $secureLinks      links (secure mode)
 */
class DealPackMail extends BaseSignatureMail
{
    public function __construct(
        public string $recipientName,
        public string $dealReference,
        public string $propertyAddress,
        public string $messageBody,
        public array $attachmentFiles = [],
        public array $secureLinks = [],
        public ?string $partLabel = null,
    ) {}

    public function envelope(): Envelope
    {
        $subject = 'Documents — ' . $this->dealReference;
        if ($this->partLabel) {
            $subject .= ' (' . $this->partLabel . ')';
        }
        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.deals.pack',
            with: [
                'recipientName'   => $this->recipientName,
                'dealReference'   => $this->dealReference,
                'propertyAddress' => $this->propertyAddress,
                'messageBody'     => $this->messageBody,
                'secureLinks'     => $this->secureLinks,
                'partLabel'       => $this->partLabel,
                'agentFooter'     => $this->getAgentFooter(),
            ],
        );
    }

    public function attachments(): array
    {
        $out = [];
        foreach ($this->attachmentFiles as $f) {
            $path = $f['path'] ?? null;
            if ($path && is_file($path)) {
                $out[] = Attachment::fromPath($path)->as($f['filename'] ?? basename($path))->withMime('application/pdf');
            }
        }
        return $out;
    }
}
