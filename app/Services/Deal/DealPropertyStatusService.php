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
 * AT-398 — a deal can now carry more than one property. The constraint is
 * still fundamentally PER-PROPERTY (a property, not a deal, is what "only one
 * committed deal at a time" protects), so the primitives below
 * (*ForProperty()) take a property id directly and are the ones a
 * multi-property-aware caller should use, once per linked property. The
 * original Deal-shaped methods are kept for their existing single-candidate-
 * property call sites (the DR2 capture form checks ONE property_id before it
 * is even linked) and now internally loop every property ALREADY linked to
 * the deal when the deal itself is the subject (existingCommittedDeal()),
 * so a deal with multiple properties is still fully protected without every
 * caller needing to know that.
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

    /** Does $propertyId carry another active (pending/granted) deal besides $excludeDealId? */
    public function otherActiveDealsExistForProperty(int $propertyId, int $excludeDealId = 0): bool
    {
        return $this->otherPropertyDeals($propertyId, $excludeDealId)
            ->whereIn('accepted_status', self::ACTIVE_CODES)
            ->exists();
    }

    /** The deal (other than $excludeDealId) already in the granted/registered lane on $propertyId, if any. */
    public function existingCommittedDealForProperty(int $propertyId, int $excludeDealId = 0): ?Deal
    {
        return $this->otherPropertyDeals($propertyId, $excludeDealId)
            ->whereIn('accepted_status', self::COMMITTED_CODES)
            ->orderBy('id')
            ->first();
    }

    /**
     * Does ANY property linked to $deal (deal_properties — every property on
     * a multi-property deal, or the single legacy property_id) carry another
     * active deal besides $deal? This is the aggregate-revert gate: when a
     * deal is declined, checked ONCE PER PROPERTY by the revert listener —
     * this convenience answers it for the deal as a whole where a caller
     * genuinely wants "any of them", e.g. DealSyncService's twin check.
     */
    public function otherActiveDealsExist(Deal $deal): bool
    {
        foreach ($this->linkedPropertyIds($deal) as $propertyId) {
            if ($this->otherActiveDealsExistForProperty($propertyId, (int) ($deal->id ?? 0))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first deal (other than $deal) already in the granted/registered
     * lane on ANY property $deal is linked to. Loops every linked property —
     * a multi-property deal cannot be granted while ANY of its properties is
     * already committed elsewhere.
     */
    public function existingCommittedDeal(Deal $deal): ?Deal
    {
        foreach ($this->linkedPropertyIds($deal) as $propertyId) {
            $conflict = $this->existingCommittedDealForProperty($propertyId, (int) ($deal->id ?? 0));
            if ($conflict !== null) {
                return $conflict;
            }
        }

        return null;
    }

    /**
     * Throw if granting $deal would put a SECOND deal into the committed lane on
     * ANY of its linked properties. No-op when the deal has no property (a
     * name-only deal can't violate a property constraint) or is already the
     * committed deal itself.
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
     * for controllers that hold ONE CANDIDATE property id before the model is
     * mutated/linked (e.g. the property picked on the capture form, before
     * save). Returns the blocking deal or null.
     */
    public function committedDealOnProperty(?int $propertyId, ?int $selfId = null): ?Deal
    {
        if (! $propertyId) {
            return null;
        }

        return $this->existingCommittedDealForProperty($propertyId, $selfId ?? 0);
    }

    /** Every property id currently linked to $deal — deal_properties (includes the backfilled primary). */
    private function linkedPropertyIds(Deal $deal): array
    {
        if (! $deal->exists) {
            return $deal->property_id ? [(int) $deal->property_id] : [];
        }

        $ids = $deal->properties()->pluck('properties.id')->map(fn ($id) => (int) $id)->all();

        // Defensive fallback for a deal saved before deal_properties existed in
        // this request's transaction (e.g. mid-migration window) — never lose
        // the constraint just because the pivot hasn't caught up yet.
        if ($ids === [] && $deal->property_id) {
            $ids = [(int) $deal->property_id];
        }

        return $ids;
    }

    /**
     * Base query: other, non-deleted deals on $propertyId, all scopes off.
     * "On $propertyId" means EITHER the legacy scalar property_id matches OR
     * the deal_properties pivot links it — grouped in one nested WHERE so the
     * exclude-self and not-deleted conditions apply to both sides, not just
     * the first (a bare orWhere here would silently widen the whole query).
     * Public so AutoDeclineSiblingDealsOnGrant can find every sibling to
     * decline on one linked property without duplicating this join shape.
     */
    public function otherPropertyDeals(int $propertyId, int $excludeDealId): Builder
    {
        return Deal::withoutGlobalScopes()
            ->where(function (Builder $q) use ($propertyId) {
                $q->where('property_id', $propertyId)
                    ->orWhereIn('id', function ($sub) use ($propertyId) {
                        $sub->select('deal_id')->from('deal_properties')->where('property_id', $propertyId)->whereNull('deleted_at');
                    });
            })
            ->where('id', '!=', $excludeDealId)
            ->whereNull('deleted_at');
    }
}
