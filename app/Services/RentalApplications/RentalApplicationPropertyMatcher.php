<?php

declare(strict_types=1);

namespace App\Services\RentalApplications;

use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Property;
use App\Models\RentalApplication;
use App\Models\RentalApplicationApprovalEmailSetting;
use App\Services\Compliance\MarketingReadinessService;
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

            return $this->pick($matched, $application, $amount, $max);
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

        return $this->pick($onMarket, $application, $amount, $max);
    }

    /**
     * The one place both branches finish: ceiling, then price descending, then
     * "is this something a tenant can actually rent and open", then the cap.
     * The filters run lazily over the price-sorted list so the (queried)
     * marketability check only runs until the cap is filled.
     *
     * QA1, 2026-10-07 — Johan's test of application 438: the email offered
     * "1 Bedroom Commercial Property" to a residential tenant, and every line
     * was dead text. Both come from here: nothing excluded non-residential
     * stock, and nothing checked that the listing the email now LINKS to is
     * actually open to the public.
     *
     * @param  Collection<int, Property>  $candidates
     * @return Collection<int, Property>
     */
    private function pick(Collection $candidates, RentalApplication $application, float $amount, int $max): Collection
    {
        $allowNonResidential = $this->applicationIsForNonResidential($application);
        $readiness = app(MarketingReadinessService::class);

        return $this->applyCeiling($candidates, $amount)
            ->sortByDesc(fn (Property $p) => $p->effectivePrice())
            ->lazy()
            ->filter(fn (Property $p) => $p->isRental()
                && ! Property::matchesOffMarketStatus((string) $p->status)
                && ($allowNonResidential || self::isResidential($p))
                && $readiness->isMarketable($p))
            ->take($max)
            ->values()
            ->collect();
    }

    /**
     * A residential tenant must never be offered commercial, industrial,
     * farm or vacant-land stock. Both columns are checked because the data
     * disagrees with itself: on QA1, 33 rental rows are filed category
     * "Residential" with property_type "Commercial Property", and 4 more are
     * category "Commercial" — either signal alone would let one through.
     */
    public static function isResidential(Property $p): bool
    {
        $category = strtolower(trim((string) ($p->category ?? '')));
        if ($category !== '' && $category !== 'residential') {
            return false;
        }

        $type = strtolower(trim((string) ($p->property_type ?? '')));

        return ! preg_match('/commercial|industrial|retail|office|warehouse|farm|agricultur|vacant|land|plot|smallholding/', $type);
    }

    /**
     * The application is for a non-residential let only when the property the
     * agent linked to it is itself non-residential — an application has no
     * other commercial marker today. No linked property = residential.
     */
    private function applicationIsForNonResidential(RentalApplication $application): bool
    {
        $linked = $application->property_id ? Property::withoutGlobalScopes()->find($application->property_id) : null;

        return $linked !== null && ! self::isResidential($linked);
    }

    /**
     * The tenant's "View all properties that match" link: their own shared
     * wishlist page, which exists only when an active rental wishlist does.
     * Null when there is none — the email then shows no such button rather
     * than a link to somebody else's, or to an unfiltered list.
     */
    public function viewAllUrl(RentalApplication $application): ?string
    {
        $wishlist = $this->activeRentalWishlist($application->contact);

        if (! $wishlist || ! $wishlist->isCountable()) {
            return null;
        }

        return $wishlist->share_slug || $wishlist->share_token ? $wishlist->sharedUrl() : null;
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
