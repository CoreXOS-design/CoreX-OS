<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Contact;
use App\Models\FicaSubmission;
use App\Services\Compliance\FicaLinkBlockedException;
use Illuminate\Support\Facades\DB;

/**
 * Link-time FICA gate (Johan, post-incident — property "30 Captain Smith" /
 * Kym Pollard, 2026-08-05): an agent may not link a contact to a property in a
 * seller-side role (owner/seller/landlord/lessor) unless that contact has a
 * genuine, current FICA approval — see FicaSubmission::applyGenuineApprovalFilter().
 * Buyer-side roles (buyer, tenant, etc.) are NOT gated — in this codebase FICA
 * is a seller-side compliance fact, mirroring
 * MarketingReadinessService::sellerContactIds().
 *
 * Call this BEFORE writing the contact_property pivot row on any path that
 * lets an agent attach a contact to a property in one of the gated roles.
 */
trait EnforcesFicaBeforeLink
{
    private const FICA_LINK_ROLES = ['owner', 'seller', 'landlord', 'lessor'];

    /** @throws FicaLinkBlockedException */
    protected function enforceFicaBeforeLink(Contact $contact, ?string $role): void
    {
        if (! in_array($role, self::FICA_LINK_ROLES, true)) {
            return;
        }

        $approved = FicaSubmission::applyGenuineApprovalFilter(
            DB::table('fica_submissions')->where('contact_id', $contact->id)
        )->exists();

        if (! $approved) {
            throw new FicaLinkBlockedException($contact);
        }
    }
}
