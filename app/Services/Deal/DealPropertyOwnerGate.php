<?php

declare(strict_types=1);

namespace App\Services\Deal;

use App\Exceptions\Deal\PropertyOwnerMismatchException;
use App\Models\Deal;
use App\Models\Property;

/**
 * AT-398 — Johan's ruling, verbatim: "multi property means exact same
 * owners - the problem like steve owns 2 properties - 1 sole other with
 * dave. for the transfer to happen dave cannot sign for the 1 property so it
 * then has to be 2 deals. but if steve owned both outright then when the
 * agent selects the first property that we know the seller then the multi
 * property allows to add but only where steve is the owner as well."
 *
 * The reason is legal, not cosmetic: every seller on a deal must be able to
 * sign for every property on it. A property that shares SOME but not all
 * owners with the deal's existing properties is refused — overlap is not
 * enough, the set must be IDENTICAL. And per Johan's second ruling: "there
 * cannot be a deal without an owner" — a property with no resolvable
 * seller-side contact can never be the first (or only) property on a deal.
 *
 * "Owner" here pools the four seller-side contact_property roles — owner,
 * seller, landlord, lessor — used interchangeably across the codebase
 * already (deeds-derived contacts are written as 'owner', agent-mandate
 * capture writes 'seller'/'landlord'; the compliance/e-sign gates already
 * treat all four as one bucket). A 'lead' or 'buyer' role is never part of
 * the owner set.
 */
class DealPropertyOwnerGate
{
    private const SELLER_SIDE_ROLES = ['owner', 'seller', 'landlord', 'lessor'];

    /** The full set of seller-side contact IDs on $property, sorted for stable comparison. */
    public function sellerSideContactIds(Property $property): array
    {
        $ids = $property->contacts()
            ->wherePivotIn('role', self::SELLER_SIDE_ROLES)
            ->pluck('contacts.id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $ids;
    }

    public function hasKnownOwner(Property $property): bool
    {
        return count($this->sellerSideContactIds($property)) > 0;
    }

    /** Exact-set equality — same people, all of them, no extras, none missing. Overlap is not sufficient. */
    public function ownerSetsMatch(Property $a, Property $b): bool
    {
        return $this->sellerSideContactIds($a) === $this->sellerSideContactIds($b);
    }

    /**
     * "There cannot be a deal without an owner." Call before creating/saving
     * a deal against $property (whether it's the only property or the first
     * of several).
     *
     * @throws PropertyOwnerMismatchException
     */
    public function assertHasKnownOwner(Property $property): void
    {
        if (! $this->hasKnownOwner($property)) {
            throw new PropertyOwnerMismatchException(
                "We can't confirm who owns {$this->label($property)} yet — link the seller (or owner/landlord) to this property before it can go on a deal."
            );
        }
    }

    /**
     * The multi-property gate. $deal must already have at least one property
     * linked (its owner set is established by whichever property was added
     * first — Johan: "the FIRST property selected establishes the owner set
     * for the deal"). Refuses a candidate whose owners aren't known at all,
     * or whose owner set isn't IDENTICAL to the deal's existing one.
     *
     * @throws PropertyOwnerMismatchException
     */
    public function assertCanAddToDeal(Deal $deal, Property $candidate): void
    {
        $this->assertHasKnownOwner($candidate);

        $existingProperties = $deal->properties()->get();
        if ($existingProperties->isEmpty()) {
            return; // candidate becomes the first property — nothing to match against yet.
        }

        $reference = $existingProperties->first();
        if ($this->ownerSetsMatch($reference, $candidate)) {
            return;
        }

        throw new PropertyOwnerMismatchException($this->mismatchMessage($reference, $candidate));
    }

    /**
     * Plain language, no jargon: names who can't sign for what. Johan:
     * "the refusal message must tell the agent WHY in plain language — that
     * a seller on this deal cannot sign for that property, so it needs its
     * own deal."
     */
    private function mismatchMessage(Property $reference, Property $candidate): string
    {
        $referenceOwners = $this->ownerNames($reference);
        $candidateOwners = $this->ownerNames($candidate);

        $referenceOnly = array_diff($referenceOwners, $candidateOwners);
        $candidateOnly = array_diff($candidateOwners, $referenceOwners);

        $who = $referenceOnly !== [] ? implode(' and ', $referenceOnly) : 'one of the sellers on this deal';

        return "Can't add {$this->label($candidate)} to this deal — {$who} cannot sign for the transfer of this property, "
            . ($candidateOnly !== [] ? 'because ' . implode(' and ', $candidateOnly) . ' also owns it and is not on this deal. ' : 'because the owners don\'t match exactly. ')
            . 'A deal can only cover properties that share the exact same owners. This property needs its own, separate deal.';
    }

    private function ownerNames(Property $property): array
    {
        return $property->contacts()
            ->wherePivotIn('role', self::SELLER_SIDE_ROLES)
            ->get()
            ->map(fn ($c) => $c->full_name)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function label(Property $property): string
    {
        return $property->address ?: "property #{$property->id}";
    }
}
