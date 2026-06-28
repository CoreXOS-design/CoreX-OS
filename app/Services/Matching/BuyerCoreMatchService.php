<?php

namespace App\Services\Matching;

use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Property;
use Illuminate\Support\Collection;

/**
 * Canonical buyer Core Match resolver (AT-108) — the ONE source of truth for
 * "a buyer's Core Matches" across the whole app (pipeline, buyer detail, viewing
 * packs, the Core Matches surface). Wraps the canonical engine
 * (MatchingService / ClientMatchResolver) — never a parallel query, never a
 * hardcoded match_score.
 *
 * A property is a Core Match for a buyer iff it is a VISIBLE canonical match in
 * AT LEAST ONE of the buyer's active + countable wishlists (visible = not in that
 * wishlist's hidden_property_ids — enforced by ClientMatchResolver's
 * include_hidden=false). The list is deduped across wishlists (best score wins),
 * score >= MatchingService::MIN_SCORE_TO_DISPLAY. count() == the Core Matches
 * surface's "visible" count, exactly.
 *
 * Previously this lived in ViewingPackSelectionService (Step 3); promoted here so
 * pipeline/detail don't depend on the ViewingPack namespace.
 */
class BuyerCoreMatchService
{
    /** The buyer's active, countable wishlists — the canonical match basis. */
    public function activeCountableMatches(Contact $buyer): Collection
    {
        return $buyer->matches()
            ->active()
            ->get()
            ->filter(fn (ContactMatch $m) => $m->isCountable())
            ->values();
    }

    /**
     * The buyer's current Core Matches via the canonical engine, deduped across
     * wishlists (best match_score wins), best-first. Each Property carries the
     * engine-stamped match_score / match_tier. VISIBLE-only.
     *
     * @return Collection<int, Property>
     */
    public function coreMatchesFor(Contact $buyer): Collection
    {
        $resolver = app(ClientMatchResolver::class);

        $byId = [];
        foreach ($this->activeCountableMatches($buyer) as $match) {
            foreach ($resolver->resolve($match, false) as $property) {
                $current = $byId[$property->id] ?? null;
                if (! $current || (int) ($property->match_score ?? 0) > (int) ($current->match_score ?? 0)) {
                    $byId[$property->id] = $property;
                }
            }
        }

        return collect(array_values($byId))
            ->sortByDesc(fn (Property $p) => (int) ($p->match_score ?? 0))
            ->values();
    }

    /** Canonical Core Match COUNT for a buyer (== the surface's visible count). */
    public function countFor(Contact $buyer): int
    {
        return $this->coreMatchesFor($buyer)->count();
    }

    /**
     * Is this property a current Core Match for this buyer? True iff ANY active
     * countable wishlist scores it >= MIN_SCORE_TO_DISPLAY via the canonical scorer.
     */
    public function isCoreMatch(Contact $buyer, Property $property): bool
    {
        $matching = app(MatchingService::class);

        foreach ($this->activeCountableMatches($buyer) as $match) {
            if ($matching->score($property, $match) >= MatchingService::MIN_SCORE_TO_DISPLAY) {
                return true;
            }
        }

        return false;
    }
}
