<?php

declare(strict_types=1);

namespace App\Services\Property;

use App\Exceptions\Property\OwnershipLockedException;
use App\Models\Deal;
use App\Models\Property;
use Illuminate\Support\Collection;

/**
 * AT-398 — Johan's ruling, verbatim: "we do not allow owner change, and
 * there cannot be a deal without a owner." Once a deal exists, the owner
 * set behind its properties is fixed for that deal — every seller on the
 * deal must be able to sign for every property on it, so the set the deal
 * was built on cannot move underneath it.
 *
 * A property is LOCKED while it is linked (via deal_properties, which
 * covers old single-property deals too — see the creating migration's
 * backfill) to any deal whose accepted_status is pending ('P') or granted
 * ('G') — the same ACTIVE definition DealPropertyStatusService already uses.
 * The lock lifts naturally the moment the deal is Declined (dead, nothing
 * left to protect) or Registered (the transfer has already happened — a
 * later ownership update is the normal, expected next step, not a violation).
 *
 * Deliberately checks ONLY the seller-side roles (owner/seller/landlord/
 * lessor) — this is a lock on OWNERSHIP, not on every contact link a
 * property has. Adding/removing a buyer, tenant, or lead on a property with
 * an open deal is unaffected.
 *
 * Every assert* method takes an optional $excludingDealId: a deal saving
 * its OWN party links onto its OWN already-linked property (DealRegisterController::
 * syncPartyLinks(), called on every DR2 save) is not "something changing the
 * owner set underneath the deal" — it IS the deal the lock exists for. Pass
 * that deal's id to exclude it from being its own blocker; every OTHER call
 * site (a different screen, a different deal, prospecting/deeds/seller-outreach)
 * omits it and is checked against every open deal with no exception.
 */
class PropertyOwnershipGuard
{
    private const SELLER_SIDE_ROLES = ['owner', 'seller', 'landlord', 'lessor'];

    /** Every open (pending/granted) deal currently linked to $property, other than $excludingDealId. */
    public function lockingDeals(Property $property, ?int $excludingDealId = null): Collection
    {
        return Deal::withoutGlobalScopes()
            ->whereIn('id', function ($q) use ($property) {
                $q->select('deal_id')
                    ->from('deal_properties')
                    ->where('property_id', $property->id)
                    ->whereNull('deleted_at');
            })
            ->when($excludingDealId, fn ($q) => $q->where('id', '!=', $excludingDealId))
            ->whereIn('accepted_status', ['P', 'G'])
            ->whereNull('deleted_at')
            ->get();
    }

    public function isLocked(Property $property, ?int $excludingDealId = null): bool
    {
        return $this->lockingDeals($property, $excludingDealId)->isNotEmpty();
    }

    /**
     * A bulk/wholesale seller-set operation (e.g. resyncing a property's
     * deed-sourced sellers) that isn't about one specific contact — always
     * gated, unconditionally, when the property is locked.
     */
    public function assertOwnershipMutable(Property $property, ?int $excludingDealId = null): void
    {
        $this->assertNotLocked($property, $excludingDealId);
    }

    /** Attaching/confirming a NEW role on $property. Only seller-side roles are gated. */
    public function assertCanLink(Property $property, ?string $role, ?int $excludingDealId = null): void
    {
        if (! $this->isSellerSide($role)) {
            return;
        }
        $this->assertNotLocked($property, $excludingDealId);
    }

    /** Removing an existing contact link from $property. Looks up the role being removed itself. */
    public function assertCanUnlink(Property $property, int $contactId, ?int $excludingDealId = null): void
    {
        $currentRole = $this->currentRole($property, $contactId);
        if (! $this->isSellerSide($currentRole)) {
            return;
        }
        $this->assertNotLocked($property, $excludingDealId);
    }

    /** Changing an existing link's role. Gated if EITHER the old or the new role is seller-side. */
    public function assertCanChangeRole(Property $property, int $contactId, ?string $newRole, ?int $excludingDealId = null): void
    {
        $currentRole = $this->currentRole($property, $contactId);
        if (! $this->isSellerSide($currentRole) && ! $this->isSellerSide($newRole)) {
            return;
        }
        $this->assertNotLocked($property, $excludingDealId);
    }

    private function currentRole(Property $property, int $contactId): ?string
    {
        return $property->contacts()
            ->where('contacts.id', $contactId)
            ->first()?->pivot?->role;
    }

    private function isSellerSide(?string $role): bool
    {
        return $role !== null && in_array(strtolower(trim($role)), self::SELLER_SIDE_ROLES, true);
    }

    /**
     * @throws OwnershipLockedException
     */
    private function assertNotLocked(Property $property, ?int $excludingDealId = null): void
    {
        $deals = $this->lockingDeals($property, $excludingDealId);
        if ($deals->isEmpty()) {
            return;
        }

        $refs = $deals->pluck('deal_no')->filter()->values()->all();
        $dealList = $refs !== [] ? implode(', ', $refs) : 'an open deal';
        $label = $property->address ?: "property #{$property->id}";

        throw new OwnershipLockedException(
            "Can't change the owner on {$label} — it's tied to {$dealList}, and the deal was built on who owns it now. "
            . 'The people on this deal are the ones who need to sign, so the owner list can\'t change while the deal is open. '
            . 'If the ownership has genuinely changed, that deal needs to be resolved (declined) first.'
        );
    }
}
