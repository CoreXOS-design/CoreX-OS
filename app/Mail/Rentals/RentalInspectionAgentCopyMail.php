<?php

namespace App\Mail\Rentals;

use App\Mail\Contracts\StaffFacingMail;
use Illuminate\Mail\Mailables\Content;

/**
 * .ai/specs/rental-inspections.md §46 - the agent's OWN copy of an inspection report: the recipient is a staff user, who signs
 * inside CoreX with their own login and PIN, so this mail may say "CoreX". Tenants and owners get RentalInspectionSigningLinkMail,
 * which never does. Built from the same arguments as the client mail (`canSign` is false for the agent), so the send path and the
 * "a signing-link mail was sent" checks are unchanged.
 */
class RentalInspectionAgentCopyMail extends RentalInspectionSigningLinkMail implements StaffFacingMail
{
    public function content(): Content
    {
        return new Content(
            view: 'emails.rentals.inspection-agent-copy',
            with: ['agentFooter' => $this->getAgentFooter()],
        );
    }
}
