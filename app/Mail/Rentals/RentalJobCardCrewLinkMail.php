<?php

namespace App\Mail\Rentals;

use App\Mail\Signatures\BaseSignatureMail;
use App\Models\RentalJobCard;
use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * .ai/specs/rental-work-orders.md §14.28 — "Email to crew": the per-job link,
 * sent AS the agent who pressed the button (fromAgent()), through the agency
 * mailbox path (RentalMailDispatcher), never a plain Mail::to()->send().
 * Neutral wording, agency-branded; no prices, no landlord, no quote data. AT-459: also the tenant (when the agency allows it),
 * the access contact and an Open in Maps link — the same facts as the crew link page.
 */
class RentalJobCardCrewLinkMail extends BaseSignatureMail
{
    public function __construct(
        public RentalJobCard $jobCard,
        public string $url,
        public ?string $expiresOn,
        ?User $agent = null,
    ) {
        if ($agent) {
            $this->fromAgent($agent);
        }
    }

    public function envelope(): Envelope
    {
        $agency = \App\Models\Agency::displayNameFor($this->jobCard->agency);

        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: "[{$agency}] Job: {$this->jobCard->title}",
        );
    }

    public function content(): Content
    {
        $property = $this->jobCard->property;
        $address = $property?->buildDisplayAddress();
        // AT-459 — where the worker is going and who lets them in. The tenant is shown only when the agency has switched that on for
        // its crews (the same setting as the crew link page); the access contact and the map link always.
        $tenants = \App\Models\RentalPortalSetting::crewLinkShowTenantContactFor($this->jobCard->agency_id)
            ? \App\Services\Rentals\JobSiteInfo::tenants($this->jobCard->lease_id)
            : [];

        return new Content(view: 'emails.rentals.crew-link', with: [
            'agencyName' => \App\Models\Agency::displayNameFor($this->jobCard->agency),
            'title' => $this->jobCard->title,
            'address' => $address,
            'mapUrl' => \App\Services\Rentals\JobSiteInfo::mapUrl($property, (string) $address),
            'tenants' => $tenants,
            'access' => \App\Services\Rentals\JobSiteInfo::accessContact($this->jobCard->workOrder()->withoutGlobalScopes()->first(), $this->jobCard),
            'scheduledAt' => $this->jobCard->scheduled_at?->copy()->setTimezone($this->jobCard->agency?->outreachTimezone() ?: config('app.timezone'))->format('D j M Y, H:i'),
            'crewName' => $this->jobCard->crew?->name,
            'url' => $this->url,
            'expiresOn' => $this->expiresOn,
            'footer' => $this->getAgentFooter(),
        ]);
    }
}
