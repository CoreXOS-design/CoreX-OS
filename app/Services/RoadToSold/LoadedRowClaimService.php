<?php

namespace App\Services\RoadToSold;

use App\Models\ProspectingClaim;
use App\Models\RoadToSold\RtsListItem;
use App\Models\RoadToSold\RtsProspect;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Road to Sold, "Find owner" on a loaded row (road-to-sold.md 23.30.3, conductor ruling on Johan's instruction, 3 Nov 2026).
 *
 * Loading a property onto an agent's day does NOT claim it: it sits at Loaded (stage found), no hold, still in the Market Intelligence pool. The first action on
 * the row is the claim. This service is that action: it takes the property out of the pool the same way a hand-claim in Market Intelligence does (a
 * ProspectingClaim on one of the property file's adverts, same hold clock) and moves the belt record from Loaded to Claimed - both in ONE transaction, so a
 * record is never in two stages, and never claimed without being on the belt.
 *
 * Idempotent: a row whose record is already at Claimed or later answers ok without writing anything.
 */
class LoadedRowClaimService
{
    public function __construct(private readonly ClaimBeltService $belt, private readonly ProspectService $prospects)
    {
    }

    /**
     * @return array{ok:bool, message:string, prospect:?RtsProspect, claim:?ProspectingClaim, already:bool}
     * @throws InvalidArgumentException with a plain-English message when the row cannot be claimed
     */
    public function claim(User $agent, int $listItemId): array
    {
        $ownerId = (int) $agent->ownershipUserId();
        $agencyId = (int) ($agent->effectiveAgencyId() ?: 0);
        $item = RtsListItem::query()->where('user_id', $ownerId)->find($listItemId);
        if (! $item || $agencyId <= 0) {
            throw new InvalidArgumentException('That property is not on your list.');
        }
        if (! in_array($item->status, [RtsListItem::STATUS_OPEN, RtsListItem::STATUS_WORKED], true)) {
            throw new InvalidArgumentException('That property has already been dealt with.');
        }
        $prospect = $item->prospect_id ? RtsProspect::query()->find($item->prospect_id) : null;
        $trackedId = (int) ($prospect?->tracked_property_id ?: $item->tracked_property_id);
        if (! $prospect || $trackedId <= 0) {
            throw new InvalidArgumentException('That property is not on your Road to Sold yet.');
        }
        if ($prospect->stage !== ProspectStages::FOUND) {
            $this->markStarted($item);   // a row left open by the old rule: the property has moved on, so the row is worked

            return ['ok' => true, 'message' => 'Already claimed.', 'prospect' => $prospect, 'claim' => null, 'already' => true];
        }
        if ($item->status === RtsListItem::STATUS_WORKED) {
            throw new InvalidArgumentException('That property has already been dealt with.');
        }
        if ($prospect->closed_at !== null || $prospect->released_at !== null) {
            throw new InvalidArgumentException('That property is no longer on your list.');
        }

        // Somebody else may have started on it since it was loaded (a claim or a belt record past Loaded): then it is theirs.
        $holder = $this->belt->holderOfFile($agencyId, $trackedId, (int) $agent->id, $ownerId);
        if ($holder !== null) {
            $name = User::withoutGlobalScopes()->whereKey($holder)->value('name') ?: 'another agent';
            throw new InvalidArgumentException('Already held by ' . $name . ' - this property is on their Road to Sold.');
        }

        $listingId = $this->listingFor($agencyId, $trackedId, $item->prospecting_listing_id ?: $prospect->prospecting_listing_id);

        $claim = null;
        $moved = DB::transaction(function () use ($agent, $ownerId, $agencyId, $prospect, $listingId, $item, &$claim) {
            // the first action on the row is the work: it counts as worked on the day's tile and the midnight job leaves it alone (23.30.3)
            $this->markStarted($item);
            if ($listingId !== null) {
                // same write as a hand-claim in Market Intelligence (MarketIntelligenceController::claim): the observer raises ClaimCreated, the listener puts it on the belt
                $claim = ProspectingClaim::create([
                    'agency_id' => $agencyId, 'prospecting_listing_id' => $listingId, 'user_id' => $ownerId,
                    'status' => ProspectingClaim::STATUS_CLAIMED, 'claimed_at' => now(), 'last_updated_at' => now(),
                ]);
                $p = $this->belt->placeClaim($claim->fresh(), ProspectStages::CLAIMED, 'claimed_find_owner', 'Claimed by you on ' . now()->format('j M Y') . ' (Find owner)');
                if ($p === null || $p->stage === ProspectStages::FOUND) {
                    throw new \RuntimeException('The claim could not be put on the belt.');
                }

                return $p;
            }
            // no advert to hang a Market Intelligence claim on (a property file with no listing): the record still moves, with no hold clock
            $this->prospects->advance($prospect, ProspectStages::CLAIMED, $agent, ['event_class' => 'FindOwner', 'note' => 'Find owner']);

            return $prospect->refresh();
        });

        return ['ok' => true, 'message' => 'Claimed - it is now yours to find the owner.', 'prospect' => $moved, 'claim' => $claim, 'already' => false];
    }

    private function markStarted(RtsListItem $item): void
    {
        if ($item->status === RtsListItem::STATUS_OPEN) {
            $item->forceFill(['status' => RtsListItem::STATUS_WORKED, 'status_changed_at' => now()])->save();
        }
    }

    /** The advert the claim sits on: the row's own, else the newest live advert of the property file. */
    private function listingFor(int $agencyId, int $trackedId, ?int $preferred): ?int
    {
        if ($preferred && DB::table('prospecting_listings')->where('id', $preferred)->where('agency_id', $agencyId)->exists()) {
            return (int) $preferred;
        }
        $id = DB::table('prospecting_listings')->where('agency_id', $agencyId)->where('tracked_property_id', $trackedId)->orderByDesc('id')->value('id');

        return $id ? (int) $id : null;
    }
}
