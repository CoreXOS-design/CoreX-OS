<?php

namespace App\Mail\Signatures;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Internal notification — sent to the agent from the system.
 * Does NOT extend BaseSignatureMail (no fromAgent needed).
 */
class LeaseExpirationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $agentName,
        public string $propertyAddress,
        public string $tenantName,
        public int $daysRemaining,
        public Carbon $leaseEndDate,
    ) {}

    public function envelope(): Envelope
    {
        $urgency = match (true) {
            $this->daysRemaining <= 0  => 'EXPIRED',
            $this->daysRemaining <= 30 => 'URGENT',
            $this->daysRemaining <= 60 => 'WARNING',
            default                    => 'NOTICE',
        };

        return new Envelope(
            subject: "[{$urgency}] Lease expiring: {$this->propertyAddress}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.signatures.lease-expiration',
        );
    }
}
