<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Property;
use App\Models\PropertySyndicationApproval;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Approval needed: <address>" — the email a chosen approver gets the moment
 * an agent sends a compliance-clear listing for approval.
 * Spec: .ai/specs/syndication-approval-gate.md §5.4 (Johan's D4).
 */
class SyndicationApprovalRequestedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public string $addressLine;
    public string $priceLine;
    public string $agentName;
    public string $agencyName;
    public ?string $note;
    public string $complianceLine;
    public string $propertyUrl;
    public string $queueUrl;

    public function __construct(
        public Property $property,
        public PropertySyndicationApproval $approval,
    ) {
        $this->addressLine = trim(implode(', ', array_filter([
            $property->address ?: $property->street_name,
            $property->suburb,
        ]))) ?: ($property->title ?: 'Property #' . $property->id);

        $price = $property->effectivePrice();
        $this->priceLine = $price
            ? 'R ' . number_format((float) $price, 0, '.', ',')
            : 'Price not set';

        $this->agentName   = $approval->requestedBy?->name ?? 'An agent';
        $this->agencyName  = $property->agency->name ?? config('mail.from.name', 'CoreX OS');
        $this->note        = $approval->request_note;

        $this->complianceLine = $property->compliance_snapshot_at
            ? 'Compliance complete — cleared ' . $property->compliance_snapshot_at->format('d M Y')
            : 'Compliance complete';

        $this->propertyUrl = route('corex.properties.show', $property->id);
        $this->queueUrl    = route('corex.properties.index', ['filter' => 'approval_pending']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Approval needed: {$this->addressLine}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.syndication-approval-requested',
        );
    }
}
