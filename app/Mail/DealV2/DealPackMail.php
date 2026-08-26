<?php

namespace App\Mail\DealV2;

use App\Mail\Signatures\BaseSignatureMail;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

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
        // AT-231 P1 — the inbound-filing loop. $dealToken is the machine anchor
        // "[CX-D{deal_id}]" appended to the subject so an attorney's reply resolves
        // back to THIS deal hands-free; $messageId is a known bracketless Message-ID
        // so the reply's References/In-Reply-To threads onto this deal's outbound
        // comm (thread_key). See .ai/specs/at231-inbound-attorney-comms-filing.md §3.1.
        public string $dealToken = '',
        public ?string $messageId = null,
        // AT-330 — a meaningful subject base (e.g. "380 Wilfred Street, Shelly Beach —
        // Electrical COC Work Order") supplied by the sender. Empty → the generic
        // "Documents — {ref}" (unchanged). partLabel + the [CX-D…] token are always
        // appended after it, so AT-231 inbound reply-filing is never lost.
        public string $subjectDetail = '',
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->subjectDetail !== '' ? $this->subjectDetail : ('Documents — ' . $this->dealReference);
        if ($this->partLabel) {
            $subject .= ' (' . $this->partLabel . ')';
        }
        if ($this->dealToken !== '') {
            $subject .= ' ' . $this->dealToken;
        }
        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: $subject,
        );
    }

    public function headers(): Headers
    {
        // AT-231 P1 — stamp a known Message-ID (bracketless; Symfony renders the <>).
        // When null, Symfony keeps its auto-generated id (unchanged behaviour).
        return new Headers(
            messageId: $this->messageId ?: null,
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
