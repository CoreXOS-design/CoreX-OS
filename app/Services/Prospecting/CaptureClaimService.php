<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\Agency;
use App\Models\AddressMatchSetting;
use App\Models\ProspectingClaim;
use App\Models\ProspectingListing;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Features\AgencyFeatureService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A property an agent captures (a deeds capture, or a property added by hand) that turns out to be a property already in Market Intelligence
 * claims that Market Intelligence record for the agent (property-identity.md section 15, C2).
 *
 * What "claims" means here, so it behaves exactly like a claim an agent took by hand:
 *   - a real prospecting claim on every advert of the property file (MIC "my claims" lists it; no other agent can pitch it);
 *   - the agency's work record shows who holds it and since when, with the history line "claimed from a deeds capture";
 *   - Road to Sold gets a prospect for the agent at the Found stage (ProspectService::place - the same door every other Road to Sold
 *     source uses), so it counts in the agent's list, funnel and stats.
 *
 * Only a match the door was sure about claims, and only at or above the agency's own setting (address_match_settings.auto_claim_min_confidence,
 * default "high"). A probable or weak match was already recorded as a possible match by the door and is NOT claimed. A property that is new
 * (no Market Intelligence record), or that another agent in the agency already holds (claim or live pitch lock), is left alone.
 *
 * The claim row is written without the claim domain events: an automatic claim is not an act the agent took, so it must not mint the
 * "claim taken" activity points. What those events also do - the Market Intelligence counts cache and the work-record sync - is done here.
 * Own stock is untouched: that stays lane B's derived flag.
 */
final class CaptureClaimService
{
    public const ORIGIN_DEEDS = 'deeds';
    public const ORIGIN_MANUAL = 'manual';
    public const ORIGIN_JOIN = 'join';

    public const OUTCOME_CLAIMED = 'claimed';
    public const OUTCOME_ALREADY = 'already_yours';
    public const OUTCOME_HELD_BY_OTHER = 'held_by_other';
    public const OUTCOME_BELOW_THRESHOLD = 'below_threshold';
    public const OUTCOME_NEW_FILE = 'new_file';
    public const OUTCOME_NO_MIC_RECORD = 'no_mic_record';
    public const OUTCOME_NO_USER = 'no_user';

    private const RANK = ['weak' => 1, 'probable' => 2, 'high' => 3, 'exact' => 4];
    private const ORIGIN_TEXT = [self::ORIGIN_DEEDS => 'a deeds capture', self::ORIGIN_MANUAL => 'a property added by hand', self::ORIGIN_JOIN => 'one of the agency\'s own listings'];
    private const MAX_ADVERTS = 20;

    public function __construct(private readonly AgencyWorkService $work)
    {
    }

    /**
     * @return array{outcome:string, listing_ids:list<int>, holder_user_id:?int, road_to_sold:?string}
     */
    public function claim(int $agencyId, int $trackedPropertyId, int $userId, string $origin, ?string $confidence, bool $createdNewFile, ?int $propertyId = null, bool $placeOnRoadToSold = true): array
    {
        $out = ['outcome' => self::OUTCOME_NEW_FILE, 'listing_ids' => [], 'holder_user_id' => null, 'road_to_sold' => null];
        if ($createdNewFile) {
            return $out;
        }

        $tp = TrackedProperty::withoutGlobalScopes()->where('agency_id', $agencyId)->find($trackedPropertyId);
        $user = User::withoutGlobalScopes()->where('agency_id', $agencyId)->find($userId);
        if (! $tp || ! $user || ! $user->is_active) {
            return ['outcome' => self::OUTCOME_NO_USER] + $out;
        }
        $owner = (int) $user->ownershipUserId();   // an assistant claims for the agent they work for

        $min = (string) (AddressMatchSetting::forAgency($agencyId)['auto_claim_min_confidence'] ?? 'high');
        if ((self::RANK[(string) $confidence] ?? 0) < (self::RANK[$min] ?? 3)) {
            return ['outcome' => self::OUTCOME_BELOW_THRESHOLD] + $out;
        }

        $listings = ProspectingListing::withoutGlobalScopes()->where('agency_id', $agencyId)->where('tracked_property_id', $trackedPropertyId)
            ->whereNull('deleted_at')->orderByDesc('is_active')->orderByDesc('last_seen_at')->orderByDesc('id')->limit(self::MAX_ADVERTS)->get(['id']);
        if ($listings->isEmpty()) {
            return ['outcome' => self::OUTCOME_NO_MIC_RECORD] + $out;
        }
        $ids = $listings->pluck('id')->map(fn ($i) => (int) $i)->all();

        $holder = $this->otherHolder($agencyId, $ids, $owner);
        if ($holder !== null) {
            return ['outcome' => self::OUTCOME_HELD_BY_OTHER, 'holder_user_id' => $holder] + $out;
        }

        $mine = ProspectingClaim::withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->whereIn('prospecting_listing_id', $ids)
            ->where('user_id', $owner)->where('is_active', true)->whereNull('released_at')->whereNull('deleted_at')->pluck('prospecting_listing_id')->map(fn ($i) => (int) $i)->all();
        $todo = array_values(array_diff($ids, $mine));

        $text = self::ORIGIN_TEXT[$origin] ?? 'a capture';
        if ($todo !== []) {
            $note = '[' . now()->format('Y-m-d H:i') . '] Claimed automatically: matched ' . $text . ' by ' . $user->name . '.';
            ProspectingClaim::withoutEvents(function () use ($todo, $agencyId, $owner, $propertyId, $note) {
                foreach ($todo as $listingId) {
                    ProspectingClaim::withoutGlobalScope(AgencyScope::class)->create([
                        'agency_id' => $agencyId, 'prospecting_listing_id' => $listingId, 'property_id' => $propertyId, 'user_id' => $owner,
                        'status' => 'claimed', 'notes' => $note, 'claimed_at' => now(), 'last_updated_at' => now(), 'is_active' => true,
                    ]);
                }
            });
            ProspectingClaim::bumpCountsCacheVersion($agencyId);
            $this->work->syncClaim($agencyId, $trackedPropertyId, 'Claimed from ' . $text);
        }

        $rts = $placeOnRoadToSold ? $this->placeOnRoadToSold($agencyId, $trackedPropertyId, $user, $propertyId, $origin) : 'skipped';

        return [
            'outcome' => $todo === [] ? self::OUTCOME_ALREADY : self::OUTCOME_CLAIMED,
            'listing_ids' => $todo, 'holder_user_id' => $owner, 'road_to_sold' => $rts,
        ];
    }

    /**
     * Own stock matched to Market Intelligence counts as claimed (Johan, 29 Oct ruling, applied 30 Oct): once an own listing sits on its
     * property file (the identity join, a review-screen "Same property", or the resolver going forward), the file's Market Intelligence
     * adverts are
     *   - recognised as ours (prospecting_listings.matched_property_id, where still empty - never overwritten);
     *   - claimed for the listing's agent, exactly like a capture claim (claim rows, the agency's work record, Road to Sold for a sale
     *     listing) - unless another agent already holds or is pitching one of them;
     * and the work record's own-stock flag is re-derived (the file now has an own listing on it). Another agency's view is never touched:
     * every query is bound to the property's own agency.
     *
     * @return array{outcome:string, matched:int, claimed:int, holder_user_id:?int, road_to_sold:?string, own_stock:bool}
     */
    public function claimFromJoin(int $propertyId): array
    {
        $none = ['outcome' => 'skipped', 'matched' => 0, 'claimed' => 0, 'holder_user_id' => null, 'road_to_sold' => null, 'own_stock' => false];
        try {
            $p = DB::table('properties')->where('id', $propertyId)->whereNull('deleted_at')
                ->first(['id', 'agency_id', 'agent_id', 'tracked_property_id', 'identity_confidence', 'status', 'listing_type']);
            if (! $p || ! $p->tracked_property_id || ! $p->agency_id) {
                return $none;
            }
            $agencyId = (int) $p->agency_id;
            $tp = TrackedProperty::withoutGlobalScopes()->where('agency_id', $agencyId)->find($p->tracked_property_id);
            if (! $tp || $tp->status === TrackedProperty::STATUS_DUPLICATE) {
                return ['outcome' => 'file_gone'] + $none;
            }
            if (in_array((string) $p->status, \App\Models\Property::NOT_OWN_STOCK_STATUSES, true)) {
                return ['outcome' => 'not_own_stock'] + $none;   // a prospecting / not-selling / draft row is not stock yet
            }

            // 1. recognise the file's adverts as ours (never overwrite another property)
            $matched = ProspectingListing::withoutGlobalScopes()->where('agency_id', $agencyId)->where('tracked_property_id', $tp->id)
                ->whereNull('deleted_at')->whereNull('matched_property_id')
                ->update(['matched_property_id' => $propertyId, 'matched_at' => now()]);

            // 2. claim them for the listing's agent
            $claim = ['outcome' => self::OUTCOME_NO_MIC_RECORD, 'listing_ids' => [], 'holder_user_id' => null, 'road_to_sold' => null];
            if ($p->agent_id) {
                $confidence = match ((string) $p->identity_confidence) {
                    'confirmed', 'exact' => 'exact',
                    default              => (string) $p->identity_confidence ?: null,
                };
                $claim = $this->claim($agencyId, (int) $tp->id, (int) $p->agent_id, self::ORIGIN_JOIN, $confidence, false, $propertyId, (string) $p->listing_type !== 'rental');
            }

            // 3. the work record's own-stock flag (the file has an own listing on it now)
            $own = $this->work->syncOwnStock($agencyId, (int) $tp->id);

            return ['outcome' => $claim['outcome'], 'matched' => $matched, 'claimed' => count($claim['listing_ids']), 'holder_user_id' => $claim['holder_user_id'],
                'road_to_sold' => $claim['road_to_sold'], 'own_stock' => (bool) $own->is_own_stock];
        } catch (\Throwable $e) {
            Log::warning('Own-stock claim after a join failed (the link stands)', ['property_id' => $propertyId, 'error' => $e->getMessage()]);

            return ['outcome' => 'failed'] + $none;
        }
    }

    /**
     * Another agent's live claim or pitch lock on any advert of the property file: the user id, else null.
     *
     * @param  list<int>  $listingIds
     */
    private function otherHolder(int $agencyId, array $listingIds, int $owner): ?int
    {
        $claim = ProspectingClaim::withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->whereIn('prospecting_listing_id', $listingIds)
            ->where('user_id', '!=', $owner)->where('is_active', true)->whereNull('released_at')->whereNull('deleted_at')->value('user_id');
        if ($claim) {
            return (int) $claim;
        }
        $lock = DB::table('prospecting_pitch_locks')->where('agency_id', $agencyId)->whereIn('prospecting_listing_id', $listingIds)
            ->where('user_id', '!=', $owner)->whereNull('released_at')->where('expires_at', '>', now())->value('user_id');

        return $lock ? (int) $lock : null;
    }

    /** The existing Road to Sold door: a prospect at Found for the agent. Returns a plain word for the report, never throws. */
    private function placeOnRoadToSold(int $agencyId, int $trackedPropertyId, User $user, ?int $propertyId, string $origin): ?string
    {
        try {
            $agency = Agency::query()->withoutGlobalScopes()->find($agencyId);
            if (! $agency || ! app(AgencyFeatureService::class)->enabled('road-to-sold', $agency)) {
                return 'off';
            }
            if ($user->is_assistant) {
                return 'assistant';
            }
            $prospects = app(ProspectService::class);
            $existing = $prospects->find($agencyId, $trackedPropertyId, 'sales');
            if ($existing && $existing->closed_at !== null) {
                return 'closed';   // that story is over; a late claim does not restart it
            }
            $p = $prospects->place($user, $trackedPropertyId, 'sales', [
                'property_id' => $propertyId, 'selection' => 'claimed_' . $origin, 'stage' => ProspectStages::FOUND,
                'reason' => 'Claimed when you captured it (' . (self::ORIGIN_TEXT[$origin] ?? 'a capture') . ')', 'actor' => $user,
            ]);

            return $p ? 'placed' : 'owned_by_other';
        } catch (\Throwable $e) {
            Log::warning('Capture claim: Road to Sold placement failed (the claim stands)', ['tracked_property_id' => $trackedPropertyId, 'error' => $e->getMessage()]);

            return 'failed';
        }
    }

    /**
     * Entry point for the capture paths: reads the confidence the door saved on the link and never lets a failure reach the caller.
     * $source = the external-ref row the capture wrote (deeds: type deeds_capture + ref); manual: the property's own link columns.
     */
    public function claimFromDeeds(int $agencyId, TrackedProperty $tp, int $userId, string $sourceRef, bool $createdNewFile): array
    {
        try {
            $confidence = DB::table('tracked_property_external_refs')->where('agency_id', $agencyId)->where('tracked_property_id', $tp->id)
                ->where('source_type', 'deeds_capture')->where('source_ref', $sourceRef)->whereNull('deleted_at')->orderByDesc('id')->value('match_confidence');

            return $this->claim($agencyId, (int) $tp->id, $userId, self::ORIGIN_DEEDS, $confidence ? (string) $confidence : null, $createdNewFile);
        } catch (\Throwable $e) {
            Log::warning('Capture claim from deeds failed (the capture stands)', ['tracked_property_id' => $tp->id, 'error' => $e->getMessage()]);

            return ['outcome' => 'failed', 'listing_ids' => [], 'holder_user_id' => null, 'road_to_sold' => null];
        }
    }

    /** A property created by hand, after the door has linked it (properties.tracked_property_id / identity_confidence / identity_method). */
    public function claimFromManualProperty(int $propertyId, ?int $userId): ?array
    {
        try {
            $p = DB::table('properties')->where('id', $propertyId)->first(['agency_id', 'tracked_property_id', 'identity_confidence', 'identity_method']);
            if (! $p || ! $p->tracked_property_id || ! $p->agency_id || ! $userId) {
                return null;
            }

            return $this->claim((int) $p->agency_id, (int) $p->tracked_property_id, $userId, self::ORIGIN_MANUAL, $p->identity_confidence ? (string) $p->identity_confidence : null, (string) $p->identity_method === 'new_file', $propertyId);
        } catch (\Throwable $e) {
            Log::warning('Capture claim from a manual property failed (the property stands)', ['property_id' => $propertyId, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
