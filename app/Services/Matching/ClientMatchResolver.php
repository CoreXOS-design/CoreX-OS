<?php

namespace App\Services\Matching;

use App\Models\ContactMatch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Strict, deterministic resolver for the **mobile Client Portal** Core Matches.
 *
 * This is intentionally separate from `MatchingService`. Where MatchingService
 * is agent-facing (loose nulls, agency-wide scope overrides, scoring nuance),
 * this resolver mirrors the mobile app's filter intent exactly:
 *
 *   - every filter set on the match is enforced as a HARD constraint
 *   - properties with NULL on a filtered column are EXCLUDED (bad data is bad data)
 *   - no agent / branch scope overrides — only the match's agency
 *   - excluded statuses: sold / withdrawn / draft / archived / pending / rented
 *
 * Spec: .ai/specs/client-auth.md
 */
class ClientMatchResolver
{
    private const EXCLUDED_STATUSES = ['sold', 'withdrawn', 'draft', 'archived', 'pending', 'rented'];

    /**
     * Map listing_type → status values that ARE valid for that intent.
     * A "sale" client must never see To_rent / rented listings, and vice versa.
     */
    private const STATUS_BY_LISTING_TYPE = [
        'sale'   => ['for_sale', 'forsale', 'active', 'available', 'on_market'],
        'rental' => ['for_rent', 'forrent', 'to_rent', 'torent', 'available_rent', 'active'],
    ];

    /**
     * Returns the properties that match the client's filters.
     *
     * @return \Illuminate\Support\Collection<int, Property>
     */
    public function resolve(ContactMatch $match): Collection
    {
        $q = Property::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $match->agency_id)
            ->whereRaw('LOWER(COALESCE(status, "")) NOT IN ('
                . collect(self::EXCLUDED_STATUSES)->map(fn ($s) => "'$s'")->implode(',')
                . ')');

        // listing_type — STRICT. Most important rule.
        if ($match->listing_type) {
            $q->where('listing_type', $match->listing_type);

            // Belt-and-braces: also constrain by status so a property mis-tagged
            // with the wrong listing_type but correct status doesn't slip through.
            $allowedStatuses = self::STATUS_BY_LISTING_TYPE[$match->listing_type] ?? null;
            if ($allowedStatuses) {
                $q->where(function (Builder $sub) use ($allowedStatuses) {
                    $sub->whereNull('status')
                        ->orWhereRaw('LOWER(status) IN ('
                            . collect($allowedStatuses)->map(fn ($s) => "'$s'")->implode(',')
                            . ')');
                });
            }
        }

        // Category & property_type — STRICT when set.
        if ($match->category)      $q->where('category', $match->category);
        if ($match->property_type) $q->where('property_type', $match->property_type);

        // Numeric filters — STRICT when set, no NULL leniency.
        if ($match->price_min)   $q->where('price', '>=', $match->price_min);
        if ($match->price_max)   $q->where('price', '<=', $match->price_max);
        if ($match->beds_min)    $q->where('beds', '>=', $match->beds_min);
        if ($match->baths_min)   $q->where('baths', '>=', $match->baths_min);
        if ($match->garages_min) $q->where('garages', '>=', $match->garages_min);

        // Suburbs — STRICT match on P24 suburb id (hard cutover from free-text).
        $suburbIds = $match->p24SuburbIdList();
        if (!empty($suburbIds)) {
            $q->whereIn('p24_suburb_id', $suburbIds);
        }

        // Hidden by client.
        $hidden = $match->hidden_property_ids ?? [];
        if (!empty($hidden)) {
            $q->whereNotIn('id', $hidden);
        }

        return $q->with(['agent:id,name,phone,email', 'branch:id,name'])
            ->get()
            ->map(function (Property $p) use ($match) {
                $p->setAttribute('match_score', $this->score($p, $match));
                return $p;
            })
            ->sortByDesc('match_score')
            ->values();
    }

    /**
     * Lightweight relevance score (0–100). Each filter the property explicitly
     * satisfies adds a slice; this is just for sort ordering — every returned
     * property already passed every hard filter, so 100% is the common case.
     */
    private function score(Property $p, ContactMatch $m): int
    {
        $weights = 0; $hit = 0;

        $add = function (bool $applicable, bool $matches) use (&$weights, &$hit) {
            if (!$applicable) return;
            $weights++;
            if ($matches) $hit++;
        };

        $add((bool) $m->listing_type,  $p->listing_type === $m->listing_type);
        $add((bool) $m->category,      $p->category === $m->category);
        $add((bool) $m->property_type, $p->property_type === $m->property_type);
        $add((bool) $m->price_min,     $p->price !== null && $p->price >= (int) $m->price_min);
        $add((bool) $m->price_max,     $p->price !== null && $p->price <= (int) $m->price_max);
        $add((bool) $m->beds_min,      $p->beds !== null && $p->beds >= (int) $m->beds_min);
        $add((bool) $m->baths_min,     $p->baths !== null && $p->baths >= (int) $m->baths_min);
        $add((bool) $m->garages_min,   $p->garages !== null && $p->garages >= (int) $m->garages_min);

        $suburbIds = $m->p24SuburbIdList();
        if (!empty($suburbIds)) {
            $weights++;
            if ($p->p24_suburb_id && in_array((int) $p->p24_suburb_id, $suburbIds, true)) {
                $hit++;
            }
        }

        if ($weights === 0) return 100;
        return (int) round(($hit / $weights) * 100);
    }

}
