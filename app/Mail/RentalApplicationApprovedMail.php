<?php

namespace App\Mail;

use Illuminate\Contracts\Queue\ShouldQueue;

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
 *
 * AT-410d, 2026-09-16 — Johan's ruling on conditional approval: "if we
 * email an approval to someone whose FICA is outstanding, the email must
 * be honest about the condition and tell them what is still needed. Do
 * not send an unconditional 'congratulations' that we may have to walk
 * back." $isSubjectToFica/$ficaContinueUrl are passed in FRESH by the
 * caller at the moment of sending (RentalApplicationMailer::sendApproved())
 * — not read from a value frozen back when the authoriser decided — since
 * FICA can resolve in the gap between approval and the agent actually
 * clicking Send, and the email must say what's true when it leaves, not
 * what was true when someone clicked Approve.
 */
class RentalApplicationApprovedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $applicantName;
    public string $agencyName;
    public string $amount;

    /** @param Collection<int, \App\Models\Property> $properties */
    public function __construct(
        public RentalApplication $application,
        public Collection $properties,
        public bool $isSubjectToFica = false,
        public ?string $ficaContinueUrl = null,
    ) {
        // Queueable already declares $queue — set it via onQueue() rather than
        // redeclaring the property (fatals as an incompatible trait-property
        // redeclaration; see RentalApplicationReturnedMail's own note).
        $this->onQueue('mail');
        $this->applicantName = $application->contact->full_name ?: 'there';
        $this->agencyName = $application->agency->name ?? config('mail.from.name', 'CoreX OS');
        $this->amount = number_format((float) $application->approved_rental_amount, 2);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->isSubjectToFica
                ? "You're approved, subject to FICA verification — {$this->agencyName}"
                : "Congratulations — you're approved to rent! — {$this->agencyName}",
        );
    }

    public function content(): Content
    {
        // QA1, 2026-10-07 — Johan: "the properties must be clickable so the
        // tenant can start viewing them." Each line links to the property's
        // public, view-only listing page (Property route
        // public.agency.properties.show — the page the listing-share-link spec
        // names for sharing a listing), built from the application's OWN
        // agency slug, never a fixed one. The email still carries no street
        // address (descriptor + suburb only); the page it links to is the
        // listing as already advertised publicly.
        $agencySlug = (string) ($this->application->agency->slug ?? '');

        $cards = $this->properties->map(fn ($property) => [
            'descriptor' => $property->addressFreeDescriptor(),
            'suburb' => trim((string) ($property->suburb ?: $property->town)),
            'price' => number_format($property->effectivePrice(), 0),
            'url' => $agencySlug !== ''
                ? route('public.agency.properties.show', ['agencySlug' => $agencySlug, 'property' => $property->id])
                : null,
        ]);

        return new Content(view: 'emails.rental-application-approved', with: [
            'properties' => $this->properties,
            'propertyCards' => $cards,
            'viewAllUrl' => app(\App\Services\RentalApplications\RentalApplicationPropertyMatcher::class)->viewAllUrl($this->application),
            'isSubjectToFica' => $this->isSubjectToFica,
            'ficaContinueUrl' => $this->ficaContinueUrl,
        ]);
    }
}
