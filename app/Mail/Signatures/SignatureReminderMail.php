<?php

namespace App\Mail\Signatures;

use Carbon\Carbon;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SignatureReminderMail extends BaseSignatureMail
{
    /** @var string gentle|firm|final|manual */
    public string $tone;

    public function __construct(
        public string $signerName,
        public string $documentName,
        public string $signingUrl,
        public Carbon $expiresAt,
        public int $reminderNumber,
        public ?string $forceTone = null,
        public ?string $customSubject = null,
        public ?string $customBody = null,
        public int $daysSinceSent = 0,
    ) {
        $this->tone = $forceTone ?? match (true) {
            $this->reminderNumber >= 3 => 'final',
            $this->reminderNumber >= 2 => 'firm',
            default => 'gentle',
        };
    }

    public function envelope(): Envelope
    {
        if ($this->customSubject) {
            $subject = $this->replacePlaceholders($this->customSubject);
        } else {
            $subject = match ($this->tone) {
                'final' => "Final reminder: Your signature is needed — {$this->documentName}",
                'manual' => "Reminder from {$this->getAgentFooter()['name']}: Please sign {$this->documentName}",
                default => "Reminder: Your signature is needed — {$this->documentName}",
            };
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
            view: 'emails.signatures.reminder',
            with: [
                'agentFooter' => $this->getAgentFooter(),
                'customBody' => $this->customBody
                    ? $this->replacePlaceholders($this->customBody)
                    : null,
            ],
        );
    }

    private function replacePlaceholders(string $text): string
    {
        return str_replace(
            ['{signer_name}', '{document_name}', '{agent_name}', '{signing_link}', '{days_waiting}'],
            [
                $this->signerName,
                $this->documentName,
                $this->getAgentFooter()['name'],
                $this->signingUrl,
                (string) $this->daysSinceSent,
            ],
            $text,
        );
    }
}
