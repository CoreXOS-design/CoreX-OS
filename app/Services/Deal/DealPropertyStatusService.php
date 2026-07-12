<?php

declare(strict_types=1);

namespace App\Services\Deal;

use App\Exceptions\Deal\DuplicateGrantException;
use App\Models\Deal;
use Illuminate\Database\Eloquent\Builder;

/**
 * DR2 Wave 2 — the deal-aggregate authority for property-status derivation and
 * the "one granted deal per property" constraint.
 *
 * A property may legally carry multiple concurrent deals (two offers = two
 * deals). Property status therefore derives from the AGGREGATE of the deals on
 * the property, not any single deal. This service is the single source of truth
 * both for "may this property still revert?" (the Wave 2 revert listener) and
 * "may this deal be granted?" (every grant write site).
 *
 * All queries drop global scopes: a property's deals may span branches/agencies
 * (DealBranchScope + AgencyScope), and the constraint must see ALL of them.
 */
class DealPropertyStatusService
{
    /** accepted_status codes that keep a property live "under offer". */
    private const ACTIVE_CODES = ['P', 'G'];

    /** The exclusive lane — at most one deal per property may hold these. */
    private const COMMITTED_CODES = ['G', 'R'];

    /**
     * Does the property carry ANOTHER active (pending/granted) deal besides
     * $deal? This is the aggregate-revert gate: when a deal is declined, the
     * property only reverts to on-market if this returns false.
     */
    public function otherActiveDealsExist(Deal $deal): bool
    {
        if (empty($deal->property_id)) {
            return false;
        }

        return $this->otherPropertyDeals($deal)
            ->whereIn('accepted_status', self::ACTIVE_CODES)
            ->exists();
    }

    /**
     * The deal (other than $deal) already in the granted/registered lane on this
     * property, if any — the blocker for the granted-uniqueness constraint.
     */
    public function existingCommittedDeal(Deal $deal): ?Deal
    {
        if (empty($deal->property_id)) {
            return null;
        }

        return $this->otherPropertyDeals($deal)
            ->whereIn('accepted_status', self::COMMITTED_CODES)
            ->orderBy('id')
            ->first();
    }

    /**
     * Throw if granting $deal would put a SECOND deal into the committed lane on
     * its property. No-op when the deal has no property (a name-only deal can't
     * violate a property constraint) or is already the committed deal itself.
     *
     * @throws DuplicateGrantException
     */
    public function assertCanGrant(Deal $deal): void
    {
        $existing = $this->existingCommittedDeal($deal);
        if ($existing !== null) {
            throw new DuplicateGrantException($existing);
        }
    }

    /**
     * Would setting $propertyId's deal (id $selfId) to Granted be blocked? Convenience
     * for controllers that hold ids before the model is mutated. Returns the blocking
     * deal or null.
     */
    public function committedDealOnProperty(?int $propertyId, ?int $selfId = null): ?Deal
    {
        if (! $propertyId) {
            return null;
        }
        $probe = new Deal();
        $probe->property_id = $propertyId;
        $probe->id = $selfId ?? 0;

        return $this->existingCommittedDeal($probe);
    }

    /** Base query: other, non-deleted deals on $deal's property, all scopes off. */
    private function otherPropertyDeals(Deal $deal): Builder
    {
        return Deal::withoutGlobalScopes()
            ->where('property_id', $deal->property_id)
            ->where('id', '!=', (int) ($deal->id ?? 0))
            ->whereNull('deleted_at');
    }
}
