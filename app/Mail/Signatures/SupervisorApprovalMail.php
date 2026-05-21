<?php

declare(strict_types=1);

namespace App\Mail\Signatures;

use Carbon\Carbon;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * E-Sign V3 Phase 2 (ES-7) — dedicated supervisor approval notification.
 *
 * Replaces the placeholder copy that previously rode on SigningRequestMail
 * with the wrong subject ("Please sign: [Candidate Authorisation] ...").
 *
 * Subject line is purpose-built so it sorts correctly in inboxes alongside
 * normal signing requests. The body explicitly references Property
 * Practitioners Act section 35 to remind the supervisor of their statutory
 * responsibility.
 *
 * Spec: .ai/specs/esign-v3-complete-spec.md §11, §17 ES-7
 */
class SupervisorApprovalMail extends BaseSignatureMail
{
    public function __construct(
        public string $supervisorName,
        public string $candidateName,
        public string $documentName,
        public ?string $documentTypeLabel,
        public ?string $contactName,
        public ?string $propertyAddress,
        public ?string $candidatePhone,
        public string $reviewUrl,
        public Carbon $expiresAt,
        public string $reviewType = 'initial_review', // 'initial_review' | 'final_signoff'
    ) {}

    public function envelope(): Envelope
    {
        $label = $this->reviewType === 'final_signoff'
            ? 'final sign-off'
            : 'supervisor approval';

        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: "Action required: Document awaiting your {$label}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.signatures.supervisor-approval',
            with: [
                'agentFooter'         => $this->getAgentFooter(),
                'supervisorFirstName' => $this->firstName($this->supervisorName),
                'candidateFirstName'  => $this->firstName($this->candidateName),
            ],
        );
    }

    private function firstName(string $full): string
    {
        $parts = preg_split('/\s+/', trim($full));
        return $parts[0] ?? $full;
    }
}
