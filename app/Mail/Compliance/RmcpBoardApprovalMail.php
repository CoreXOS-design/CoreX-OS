<?php

namespace App\Mail\Compliance;

use App\Mail\Signatures\BaseSignatureMail;
use App\Models\Agency;
use App\Models\Compliance\RmcpBoardLetter;
use App\Models\Compliance\RmcpBoardSignature;
use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The email that asks one board member to sign the RMCP board approval letter. It carries the secure link only - no code and no
 * attachment: the member proves who they are with a code sent to this same address when they open the link (OtpService).
 * Goes out as the person who pressed Send, through their own mailbox when they have one (ComplianceMailDispatcher).
 */
class RmcpBoardApprovalMail extends BaseSignatureMail
{
    public function __construct(
        public Agency $agency,
        public RmcpBoardLetter $letter,
        public RmcpBoardSignature $signature,
        public string $url,
        public string $agencyName,
        public int $versionNumber,
        ?User $sender = null,
        public bool $isReminder = false,
    ) {
        if ($sender) {
            $this->fromAgent($sender);
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: ($this->isReminder ? 'Reminder: ' : '') . "Please sign the board approval of the {$this->agencyName} RMCP (version {$this->versionNumber})",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.compliance.rmcp-board-approval');
    }
}
