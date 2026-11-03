<?php

namespace App\Services\Prospecting\MicWork;

use App\Models\ContactMatch;
use App\Services\Matching\BuyerMatchRules;
use Illuminate\Support\Collection;

/**
 * A buyer's wishlists as MIC offers them (mic-work-tab.md 13.20): the active, countable ones - primary first, then the newest. The list, its count, the
 * "wants" line and the Fits chip are all scored against ONE of them (the primary unless the agent picks another); this is the single place that says which.
 */
final class BuyerWishlists
{
    /** @return Collection<int, ContactMatch> */
    public static function ordered(int $agencyId, int $contactId): Collection
    {
        return ContactMatch::withoutGlobalScopes()->where('agency_id', $agencyId)->where('contact_id', $contactId)->whereNull('deleted_at')
            ->where('status', ContactMatch::STATUS_ACTIVE)->with('createdBy:id,name')->get()
            ->filter(fn (ContactMatch $m) => $m->isCountable())
            ->sortByDesc(fn (ContactMatch $m) => ($m->is_primary ? 10_000_000_000 : 0) + ($m->created_at?->timestamp ?? 0))->values();
    }

    /** The asked-for wishlist when it is one of the buyer's, else the first (the primary). */
    public static function pick(Collection $ordered, ?int $requestedId): ?ContactMatch
    {
        return ($requestedId ? $ordered->firstWhere('id', $requestedId) : null) ?? $ordered->first();
    }

    /** "Apartment / Flat · ≤ R1.2m · 3 suburbs" - the wishlist's own name when it has one. */
    public static function label(ContactMatch $m): string
    {
        $name = trim((string) $m->name);
        if ($name !== '') {
            return $name;
        }
        $bits = [];
        $types = $m->propertyTypeList();
        if ($types) {
            $bits[] = implode(' / ', array_slice($types, 0, 2)) . (count($types) > 2 ? ' +' . (count($types) - 2) : '');
        }
        if ($m->price_max || $m->price_min) {
            $bits[] = BuyerMatchRules::moneyRange($m->price_min, $m->price_max);
        }
        $subs = is_array($m->suburbs) ? count($m->suburbs) : 0;
        if ($subs > 0) {
            $bits[] = $subs . ' suburb' . ($subs === 1 ? '' : 's');
        }

        return $bits ? implode(' · ', $bits) : 'Wishlist #' . $m->id;
    }
}
