<?php

namespace App\Mail;

use App\Models\RentalApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * AT-392 authoriser flow — the applicant-facing approval email. Johan:
 * "accept flow should essentially maybe email applicant... congrats you are
 * approved to rent for x amount." The matching-properties content this
 * class's own docblock once said not to build ("IDEA, not settled. Do NOT
 * build it") is now built, per Johan's explicit later decision — see
 * .ai/specs/rental-applications.md, "agent sends, not auto-send". Sent by
 * the AGENT (RentalApplicationReviewController::send()), never
 * automatically on approve() any more.
 */
class RentalApplicationApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $applicantName;
    public string $agencyName;
    public string $amount;

    /** @param Collection<int, \App\Models\Property> $properties */
    public function __construct(public RentalApplication $application, public Collection $properties)
    {
        $this->applicantName = $application->contact->full_name ?: 'there';
        $this->agencyName = $application->agency->name ?? config('mail.from.name', 'CoreX OS');
        $this->amount = number_format((float) $application->approved_rental_amount, 2);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Congratulations — you're approved to rent! — {$this->agencyName}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rental-application-approved', with: [
            'properties' => $this->properties,
        ]);
    }
}
