<?php

declare(strict_types=1);

namespace App\Services\RentalApplications;

use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Property;
use App\Models\RentalApplication;
use App\Models\RentalApplicationApprovalEmailSetting;
use App\Services\Matching\MatchingService;
use Illuminate\Support\Collection;

/**
 * Resolves which properties appear in the agent's approval email.
 *
 * Johan, verbatim, is an ABSOLUTE ceiling, not a preference: "agent
 * selected houses and they are approved for 10k - we share houses to them
 * thats below 10k - same as a sales wishlist. but never higher than the
 * approved amount. if no wishlist we share all properties below 10k
 * thats available for rental."
 *
 * The ceiling is enforced HERE, once, independently of whatever
 * MatchingService's own price band does — checked against
 * Property::effectivePrice() (rental_amount for a rental listing), never
 * the wishlist's own price_max, and never Property::price directly.
 * MatchingService's own price scoring compares wishlist criteria against
 * Property.price, which is 0/null for practically every rental listing
 * (949 of 950 checked on QA1) — a pre-existing bug, reported not fixed
 * (.ai/specs/rental-applications.md). This class does not depend on that
 * being fixed: the ceiling filter below runs after MatchingService
 * regardless of whether its own price band worked correctly, so it always
 * holds.
 *
 * Spec: .ai/specs/rental-applications.md — "Matching rule — the approved
 * amount is a HARD CEILING, never a preference".
 */
class RentalApplicationPropertyMatcher
{
    public function __construct(private readonly MatchingService $matcher) {}

    /**
     * @return Collection<int, Property>
     */
    public function forApproval(RentalApplication $application): Collection
    {
        $amount = (float) $application->approved_rental_amount;
        if ($amount <= 0) {
            return collect();
        }

        $max = RentalApplicationApprovalEmailSetting::maxPropertiesFor((int) $application->agency_id);

        $wishlist = $this->activeRentalWishlist($application->contact);

        if ($wishlist && $wishlist->isCountable()) {
            $matched = $this->matcher->propertiesForMatch($wishlist, [
                'agent_id' => null,        // agency-wide stock, not just the wishlist owner's own
                'include_hidden' => false,
            ]);

            return $this->applyCeiling($matched, $amount)
                ->sortByDesc(fn (Property $p) => $p->effectivePrice())
                ->take($max)
                ->values();
        }

        return $this->fallback($application, $amount, $max);
    }

    /**
     * No wishlist, or one that's too thin to score — own agency stock,
     * on-market, rental, at or under the ceiling. Never touches
     * MatchingService: there is nothing to score against.
     */
    private function fallback(RentalApplication $application, float $amount, int $max): Collection
    {
        $onMarket = Property::query()
            ->where('agency_id', $application->agency_id)
            ->where('listing_type', 'rental')
            ->onMarket()
            ->get();

        return $this->applyCeiling($onMarket, $amount)
            ->sortByDesc(fn (Property $p) => $p->effectivePrice())
            ->take($max)
            ->values();
    }

    /**
     * THE absolute rule. Never above the approved amount, ever — checked
     * against effectivePrice() (rental_amount for a rental listing), not
     * Property::price. Applied identically to both the wishlist-match and
     * fallback branches so it can never be forgotten in one of them.
     */
    private function applyCeiling(Collection $properties, float $amount): Collection
    {
        return $properties->filter(fn (Property $p) => $p->effectivePrice() > 0 && $p->effectivePrice() <= $amount)->values();
    }

    private function activeRentalWishlist(?Contact $contact): ?ContactMatch
    {
        if (! $contact) {
            return null;
        }

        return ContactMatch::withoutGlobalScopes()
            ->where('contact_id', $contact->id)
            ->where('listing_type', 'rental')
            ->where('status', ContactMatch::STATUS_ACTIVE)
            ->whereNull('deleted_at')
            ->orderByDesc('is_primary')
            ->orderByDesc('updated_at')
            ->first();
    }
}
