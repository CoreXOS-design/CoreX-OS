<?php

namespace App\Mail\Rentals;

use App\Mail\Signatures\BaseSignatureMail;
use App\Models\Lease;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * .ai/specs/leases.md §15.5 / §15.16 (Build L3b) — the one mail the agent gets when a lease agreement they sent out
 * ends: signed and accepted (the lease is now active), declined, or its signing links lapsed. Internal only — it goes
 * to the agent, never to a tenant or a landlord. Sent AS the agent through the agency mailbox path
 * (RentalMailDispatcher::send()), like every other rental mail; the agency's own name is used throughout, nothing
 * here names any one agency.
 */
class LeaseAgreementStatusMail extends BaseSignatureMail
{
    public const OUTCOME_SIGNED = 'signed';
    public const OUTCOME_SIGNED_NOT_ACTIVE = 'signed_not_active';
    public const OUTCOME_DECLINED = 'declined';
    public const OUTCOME_EXPIRED = 'expired';

    public function __construct(
        public readonly Lease $lease,
        public readonly string $outcome,
        public readonly ?string $detail = null,
    ) {}

    protected function agencyName(): string
    {
        $agency = $this->lease->agency ?? $this->lease->property?->agency;

        return $agency?->trading_name ?: ($agency?->name ?: config('mail.from.name', 'Your agency'));
    }

    protected function address(): string
    {
        return $this->lease->property?->buildDisplayAddress() ?: ('Property #' . $this->lease->property_id);
    }

    public function subjectText(): string
    {
        return match ($this->outcome) {
            self::OUTCOME_SIGNED => 'Lease agreement signed and active — ' . $this->address(),
            self::OUTCOME_SIGNED_NOT_ACTIVE => 'Lease agreement signed — not yet active — ' . $this->address(),
            self::OUTCOME_DECLINED => 'Lease agreement declined — ' . $this->address(),
            default => 'Lease agreement expired — ' . $this->address(),
        };
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: '[' . $this->agencyName() . '] ' . $this->subjectText(),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rentals.lease-agreement-status', with: [
            'agencyName' => $this->agencyName(),
            'address' => $this->address(),
            'footer' => $this->getAgentFooter(),
            'outcome' => $this->outcome,
            'detail' => $this->detail,
            'leaseUrl' => route('corex.leases.show', $this->lease->id),
            'heading' => 'Lease agreement',
        ]);
    }
}
