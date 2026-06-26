<?php

declare(strict_types=1);

namespace App\Listeners\Contact;

use App\Events\Contact\ContactLinkedToProperty;
use App\Models\ContactType;
use Illuminate\Support\Facades\Log;

/**
 * AT-79 — a contact carrying the non-e-sign "Owner" type who gets linked to a
 * property is acting as that property's seller, so promote Owner -> Seller
 * automatically.
 *
 * Wired by Laravel's AUTOMATIC listener discovery (handle() type-hints the
 * concrete event) — do NOT also register it explicitly in AppServiceProvider or
 * it double-registers and fires twice (same trap as DesyndicateExpiredMandate;
 * see the note in AppServiceProvider). Failure-isolated: a problem here must
 * never break the property link.
 */
class PromoteOwnerToSellerOnPropertyLink
{
    /** Roles where the contact is the ACQUIRING/tenant party — never a seller. */
    private const ACQUIRING_ROLES = ['buyer', 'purchaser', 'tenant', 'lessee'];

    public function handle(ContactLinkedToProperty $event): void
    {
        try {
            if (in_array(strtolower($event->role ?? ''), self::ACQUIRING_ROLES, true)) {
                return; // linked as a buyer/tenant — not a seller
            }

            $owner  = ContactType::query()->where('name', 'Owner')->whereNull('esign_role')->first();
            $seller = ContactType::where('esign_role', 'seller')->first();
            if (!$owner || !$seller) {
                return;
            }
            $ownerId  = (int) $owner->id;
            $sellerId = (int) $seller->id;

            $contact = $event->contact;

            // Effective parent set = the multi-parent pivot plus the primary
            // mirror (covers writer-created contacts whose type is mirror-only).
            $effective = $contact->parentTypes()->pluck('contact_types.id')->map(fn ($i) => (int) $i)->all();
            if ($contact->contact_type_id) {
                $effective[] = (int) $contact->contact_type_id;
            }
            $effective = array_values(array_unique($effective));

            if (!in_array($ownerId, $effective, true)) {
                return; // not an Owner-typed contact — nothing to promote
            }

            // Swap Owner -> Seller; keep every sub-tag EXCEPT those nested under
            // Owner (their parent is being removed).
            $newParents = array_values(array_unique(array_map(
                fn ($id) => $id === $ownerId ? $sellerId : $id,
                $effective
            )));

            $allTagIds   = $contact->tags()->pluck('contact_tags.id')->map(fn ($i) => (int) $i)->all();
            $ownerTagIds = $contact->tags()->where('contact_tags.contact_type_id', $ownerId)
                ->pluck('contact_tags.id')->map(fn ($i) => (int) $i)->all();
            $keepTagIds  = array_values(array_diff($allTagIds, $ownerTagIds));

            $contact->syncTypeAssignments($newParents, $keepTagIds);
        } catch (\Throwable $e) {
            Log::warning('PromoteOwnerToSellerOnPropertyLink failed', [
                'contact_id'  => $event->contact->id ?? null,
                'property_id' => $event->property->id ?? null,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
