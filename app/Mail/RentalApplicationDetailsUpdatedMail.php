<?php

namespace App\Mail;

use App\Mail\Signatures\BaseSignatureMail;
use App\Models\RentalApplication;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * AT-516 - sent automatically when an agent corrects details the applicant typed. One line per corrected field
 * ("<label>: <old> -> <new>"), then who to contact if it is wrong. Same base, From/reply-to routing and agent footer as every
 * other applicant mail on this feature (see RentalApplicationReopenedMail); queued like them.
 */
class RentalApplicationDetailsUpdatedMail extends BaseSignatureMail implements ShouldQueue
{
    public $queue = 'mail';

    public string $contactName;
    public string $agencyName;
    public string $propertyAddress;
    public string $agentName;
    public string $agentTel;

    /**
     * @param array<int, array{label: string, old: string, new: string}> $lines already in plain words
     */
    public function __construct(public RentalApplication $application, public array $lines, ?User $agent = null)
    {
        $this->contactName = $application->full_name ?: ($application->contact?->full_name ?: 'there');
        $this->agencyName = $application->agency->name ?? \App\Support\ClientBrand::NEUTRAL_RENTALS;
        $this->propertyAddress = (string) ($application->property?->buildDisplayAddress() ?? '');
        $this->agentName = (string) ($agent?->name ?? $application->createdBy?->name ?? $this->agencyName);
        $this->agentTel = (string) ($agent?->cell ?: ($agent?->phone ?: ($application->createdBy?->cell ?: $application->createdBy?->phone ?: '')));
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: "Your rental application was updated - {$this->agencyName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.rental-application-details-updated',
            with: [
                'agentFooter' => $this->getAgentFooter(),
            ],
        );
    }
}
