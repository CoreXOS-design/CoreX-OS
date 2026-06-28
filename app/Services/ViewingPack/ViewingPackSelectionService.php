<?php

namespace App\Services\ViewingPack;

use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\CoreMatchMiss;
use App\Models\Property;
use App\Models\ViewingPack;
use App\Models\ViewingPackProperty;
use App\Services\Matching\BuyerCoreMatchService;
use App\Services\Matching\ClientMatchResolver;
use App\Services\Matching\MatchingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Viewing Pack property selection (AT-XX, Step 3).
 *
 * The ONE place selection talks to the canonical buyer-match engine. Core
 * Matches and the "is this property a match?" guard both go through
 * MatchingService / ClientMatchResolver — never a parallel query, never a
 * hardcoded score. The true `source` of a selected property is ALWAYS computed
 * here (core_match iff the canonical engine scores it ≥ MIN_SCORE_TO_DISPLAY),
 * so an ad-hoc add that is in fact a current match is correctly stamped
 * core_match and fires no miss; a genuine non-match is stamped ad_hoc and
 * silently captures a core_match_miss with point-in-time snapshots (spec §3).
 */
class ViewingPackSelectionService
{
    /**
     * The buyer's active, countable wishlists — the canonical match basis.
     * Delegates to the shared BuyerCoreMatchService (AT-108 — one canonical source).
     */
    public function activeCountableMatches(Contact $buyer): Collection
    {
        return app(BuyerCoreMatchService::class)->activeCountableMatches($buyer);
    }

    /**
     * The buyer's current Core Matches via the canonical engine (delegated to the
     * shared BuyerCoreMatchService). Deduped, visible-only, best-first.
     *
     * @return Collection<int, Property>
     */
    public function coreMatchesFor(Contact $buyer): Collection
    {
        return app(BuyerCoreMatchService::class)->coreMatchesFor($buyer);
    }

    /**
     * Is this property a current Core Match for this buyer? Delegates to the
     * shared canonical service.
     */
    public function isCoreMatch(Contact $buyer, Property $property): bool
    {
        return app(BuyerCoreMatchService::class)->isCoreMatch($buyer, $property);
    }

    /**
     * Add a property to the pack. Source is computed canonically; a genuine
     * non-match silently captures a core_match_miss. Idempotent: re-adding the
     * same property never creates a duplicate pivot or a duplicate miss; a
     * previously-removed (soft-deleted) pivot is restored instead of duplicated.
     */
    public function addProperty(ViewingPack $pack, Property $property, int $agentId): ViewingPackProperty
    {
        return DB::transaction(function () use ($pack, $property, $agentId) {
            $buyer = $pack->contact;

            $existing = $pack->viewingPackProperties()
                ->withTrashed()
                ->where('property_id', $property->id)
                ->first();

            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }
                // Honour "no duplicate miss": only ensure one exists if the
                // stored source is ad_hoc and somehow none was captured.
                if ($existing->source === ViewingPackProperty::SOURCE_AD_HOC) {
                    $this->captureMiss($pack, $buyer, $property, $agentId);
                }

                return $existing;
            }

            $source = $this->isCoreMatch($buyer, $property)
                ? ViewingPackProperty::SOURCE_CORE_MATCH
                : ViewingPackProperty::SOURCE_AD_HOC;

            $nextOrder = (int) $pack->viewingPackProperties()->max('sort_order') + 1;

            $vpp = $pack->viewingPackProperties()->create([
                'agency_id'  => $pack->agency_id,
                'property_id' => $property->id,
                'sort_order' => $nextOrder,
                'source'     => $source,
            ]);

            if ($source === ViewingPackProperty::SOURCE_AD_HOC) {
                $this->captureMiss($pack, $buyer, $property, $agentId);
            }

            return $vpp;
        });
    }

    /** Remove a selected property (soft delete; children cascade per Step 2). */
    public function removeProperty(ViewingPackProperty $vpp): void
    {
        $vpp->delete();
    }

    /**
     * Silently write a core_match_miss with immutable point-in-time snapshots.
     * Idempotent per (pack, property): never writes a second miss for the same
     * add target.
     */
    private function captureMiss(ViewingPack $pack, Contact $buyer, Property $property, int $agentId): void
    {
        $already = CoreMatchMiss::where('viewing_pack_id', $pack->id)
            ->where('property_id', $property->id)
            ->exists();

        if ($already) {
            return;
        }

        CoreMatchMiss::create([
            'agency_id'                    => $pack->agency_id,
            'contact_id'                   => $buyer->id,
            'property_id'                  => $property->id,
            'agent_id'                     => $agentId,
            'viewing_pack_id'              => $pack->id,
            'buyer_criteria_snapshot'      => $this->buyerCriteriaSnapshot($buyer),
            'property_attributes_snapshot' => $this->propertyAttributesSnapshot($property),
            'captured_at'                  => now(),
        ]);
    }

    /** Point-in-time copy of the buyer's criteria (all active countable wishlists). */
    private function buyerCriteriaSnapshot(Contact $buyer): array
    {
        return [
            'contact_id'         => $buyer->id,
            'preapproval_amount' => $buyer->preapproval_amount,
            'captured_at'        => now()->toIso8601String(),
            'wishlists'          => $this->activeCountableMatches($buyer)->map(fn (ContactMatch $m) => [
                'id'                    => $m->id,
                'name'                  => $m->name,
                'listing_type'          => $m->listing_type,
                'category'              => $m->category,
                'property_type'         => $m->property_type,
                'property_types'        => $m->property_types,
                'price_min'             => $m->price_min,
                'price_max'             => $m->price_max,
                'beds_min'              => $m->beds_min,
                'bedrooms_max'          => $m->bedrooms_max,
                'baths_min'             => $m->baths_min,
                'garages_min'           => $m->garages_min,
                'parking_min'           => $m->parking_min,
                'floor_size_min'        => $m->floor_size_min,
                'floor_size_max'        => $m->floor_size_max,
                'erf_size_min'          => $m->erf_size_min,
                'erf_size_max'          => $m->erf_size_max,
                'suburbs'               => $m->suburbs,
                'p24_suburb_ids'        => $m->p24_suburb_ids,
                'must_have_features'    => $m->must_have_features,
                'nice_to_have_features' => $m->nice_to_have_features,
                'deal_breakers'         => $m->deal_breakers,
            ])->all(),
        ];
    }

    /** Point-in-time copy of the property attributes matching compares against. */
    private function propertyAttributesSnapshot(Property $property): array
    {
        return [
            'property_id'  => $property->id,
            'price'        => $property->price,
            'suburb'       => $property->suburb,
            'beds'         => $property->beds,
            'baths'        => $property->baths,
            'garages'      => $property->garages,
            'size_m2'      => $property->size_m2,
            'erf_size_m2'  => $property->erf_size_m2,
            'property_type' => $property->property_type,
            'category'     => $property->category,
            'listing_type' => $property->listing_type,
            'status'       => $property->status,
            'captured_at'  => now()->toIso8601String(),
        ];
    }
}
