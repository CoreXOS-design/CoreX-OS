<?php

namespace App\Services\Matching;

use App\Models\ContactMatch;
use App\Models\ContactMatchLinkOpen;
use App\Models\ContactMatchShareProperty;
use App\Models\Property;
use Illuminate\Support\Collection;

/**
 * AT-Core-Matches, share-history piece. The buyer's own live link never
 * changes (Johan's ruling 4 — one permanent link, always current stock);
 * everything here is the INTERNAL record built from every share event, and
 * the one thing an agent actually asked for: "properties this buyer has
 * not seen since I last sent it."
 *
 * "Not seen since" is decided purely by property identity: once a
 * property_id has appeared in ANY prior share for this match, it never
 * counts as new again — even if it was later withdrawn and relisted.
 * Withdraw/relist never creates a new property row (MatchOrCreate is the
 * only way a property row comes to exist, and CoreX properties are never
 * hard-deleted), so the same property_id persists through that whole
 * cycle. The buyer already knows about that property; re-surfacing it as
 * "new" the moment it comes back on the market would be wrong, not
 * helpful — a genuinely newsworthy relist (price drop, back on the
 * market after a failed deal) is a re-engagement question for the agent
 * to raise deliberately, not something share-history should paper over by
 * quietly calling it "new".
 */
class CoreMatchShareHistoryService
{
    public function __construct(protected ClientMatchResolver $resolver)
    {
    }

    /**
     * Every property_id that has EVER appeared in a (non-deleted) share
     * for this match — the full "already seen" set, regardless of the
     * property's own status history since.
     */
    public function everSharedPropertyIds(ContactMatch $match): Collection
    {
        return ContactMatchShareProperty::query()
            ->whereHas('share') // excludes rows whose parent share was soft-deleted
            ->where('contact_match_id', $match->id)
            ->distinct()
            ->pluck('property_id');
    }

    /**
     * Today's live matches (the exact query the live link itself runs)
     * minus every property ever shared for this match. THE deliverable:
     * "four properties this buyer has not seen since you last sent it."
     *
     * @return Collection<int, Property>
     */
    public function neverSentProperties(ContactMatch $match): Collection
    {
        $everSent = $this->everSharedPropertyIds($match)->all();

        return $this->resolver->resolve($match, false)
            ->reject(fn (Property $property) => in_array($property->id, $everSent, true))
            ->values();
    }

    /**
     * Full CONFIRMED share event log for this match, latest first. A minted
     * but never-sent link (confirmed_at null) never appears here — it isn't
     * a share, it's an abandoned composer.
     */
    public function shares(ContactMatch $match): Collection
    {
        return $match->shares()->whereNotNull('confirmed_at')->with('sharedBy')->latest('shared_at')->get();
    }

    /** Latest CONFIRMED share timestamp for this match, or null if never sent. */
    public function lastSharedAt(ContactMatch $match): ?\Illuminate\Support\Carbon
    {
        return $match->shares()->whereNotNull('confirmed_at')->latest('shared_at')->first()?->shared_at;
    }

    /**
     * "Sent N times, never opened" — the open count/last-opened-at is a
     * SEPARATE signal from the share log (see ContactMatchLinkOpen's own
     * docblock), never merged into the share count.
     */
    public function openSummary(ContactMatch $match): array
    {
        $query = ContactMatchLinkOpen::query()->where('contact_match_id', $match->id);

        return [
            'count'          => (clone $query)->count(),
            'last_opened_at' => (clone $query)->max('opened_at'),
        ];
    }
}
