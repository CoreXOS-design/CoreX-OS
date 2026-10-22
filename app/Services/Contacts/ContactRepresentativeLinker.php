<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\ContactRepresentative;

/**
 * AT-479 — writes the company ↔ natural-person link (`contact_representatives`, read back through Contact::representatives())
 * for callers that are not the Contact edit screen — the reOS import links the people it brings in for a company.
 *
 * The same rules, in the same order, as ContactRepresentativeController::attachRepresentative() (which stays as it is): only one
 * primary signatory / one proxy per company; a pair that was unlinked before is RESTORED, not re-inserted (the unique index
 * ignores soft-deleted rows — non-negotiable #1); a newly linked person goes to the end of the company's list.
 */
final class ContactRepresentativeLinker
{
    /**
     * @throws \DomainException  the company is not a company contact, the person is not a natural person, or they are in different agencies
     */
    public static function link(Contact $entity, Contact $representative, bool $isPrimary = false, ?string $capacity = null, bool $signsAsProxy = false, ?int $assertedByUserId = null): ContactRepresentative
    {
        if (! $entity->isEntity()) {
            throw new \DomainException('People can only be linked to a company contact.');
        }
        if ($representative->isEntity() || (int) $representative->id === (int) $entity->id) {
            throw new \DomainException('Only a person (not a company) can be linked to a company.');
        }
        if ((int) $representative->agency_id !== (int) $entity->agency_id) {
            throw new \DomainException('The person must be in the same agency as the company.');
        }
        if ($capacity !== null && ! in_array($capacity, ContactRepresentative::CAPACITIES, true)) {
            throw new \DomainException('That role is not one CoreX knows.');
        }

        if ($isPrimary) {
            self::demote($entity, 'is_primary');
        }
        if ($signsAsProxy) {
            self::demote($entity, 'signs_as_proxy');
        }

        $pivot = ContactRepresentative::withTrashed()
            ->where('entity_contact_id', $entity->id)
            ->where('representative_contact_id', $representative->id)
            ->first();

        $next = (int) (ContactRepresentative::withTrashed()->where('entity_contact_id', $entity->id)->max('sort_order') ?? -1) + 1;

        if ($pivot) {
            // Already linked (and live): leave the existing order and flags alone, only fill a role that was empty.
            if (! $pivot->trashed()) {
                if ($pivot->capacity === null && $capacity !== null) {
                    $pivot->update(['capacity' => $capacity]);
                }

                return $pivot;
            }
            $pivot->restore();
            $pivot->update(['is_primary' => $isPrimary, 'sort_order' => $next, 'capacity' => $capacity, 'signs_as_proxy' => $signsAsProxy]);

            return $pivot;
        }

        return ContactRepresentative::create([
            'entity_contact_id' => $entity->id,
            'representative_contact_id' => $representative->id,
            'is_primary' => $isPrimary,
            'sort_order' => $next,
            'capacity' => $capacity,
            'signs_as_proxy' => $signsAsProxy,
            'asserted_by_user_id' => $assertedByUserId,
        ]);
    }

    private static function demote(Contact $entity, string $flag): void
    {
        foreach ($entity->representatives()->pluck('contacts.id') as $existingId) {
            $entity->representatives()->updateExistingPivot($existingId, [$flag => false]);
        }
    }
}
