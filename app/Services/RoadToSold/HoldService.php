<?php

namespace App\Services\RoadToSold;

use App\Models\RoadToSold\RtsHold;
use App\Models\RoadToSold\RtsPropertyFlag;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * AT-454 — who is working a property. KEY = tracked_properties.id (CLAUDE.md rule 10).
 *
 * The contract lane 2 (ListBuilderService) codes against:
 *   isHeldByOther(int $trackedPropertyId, User $user, string $track='sales'): bool
 *   heldByOtherIds(array $trackedPropertyIds, User $user, string $track='sales'): int[]
 *   excludedIdsFor(User $user, array $trackedPropertyIds, string $track='sales'): int[]
 *       = held by another agent  ∪  marked "never suggest" (do-not-contact, for every agent)
 *
 * Every query filters agency_id explicitly: the overnight list build runs with no signed-in user,
 * where AgencyScope is skipped. agency_id <= 0 answers "nothing excluded", never throws.
 */
class HoldService
{
    public function isHeldByOther(int $trackedPropertyId, User $user, string $track = 'sales'): bool
    {
        return $this->heldByOtherIds([$trackedPropertyId], $user, $track) !== [];
    }

    /** @return int[] ids (from the given list) that another agent holds right now. */
    public function heldByOtherIds(array $trackedPropertyIds, User $user, string $track = 'sales'): array
    {
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        $ids = array_values(array_unique(array_filter(array_map('intval', $trackedPropertyIds))));
        if ($agencyId <= 0 || $ids === []) {
            return [];
        }

        return RtsHold::query()
            ->where('agency_id', $agencyId)->where('track', $track)
            ->whereIn('tracked_property_id', $ids)
            ->active()
            ->whereNotIn('user_id', $user->dataIdentityIds())
            ->pluck('tracked_property_id')->map(fn ($v) => (int) $v)->all();
    }

    /** @return int[] ids (from the given list) the user's own identity holds right now. */
    public function ownHeldIds(array $trackedPropertyIds, User $user, string $track = 'sales'): array
    {
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        $ids = array_values(array_unique(array_filter(array_map('intval', $trackedPropertyIds))));
        if ($agencyId <= 0 || $ids === []) {
            return [];
        }

        return RtsHold::query()
            ->where('agency_id', $agencyId)->where('track', $track)
            ->whereIn('tracked_property_id', $ids)
            ->active()
            ->whereIn('user_id', $user->dataIdentityIds())
            ->pluck('tracked_property_id')->map(fn ($v) => (int) $v)->all();
    }

    /** @return int[] marked "never suggest" (any agent's do-not-contact). */
    public function neverSuggestIds(int $agencyId, array $trackedPropertyIds, string $track = 'sales'): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $trackedPropertyIds))));
        if ($agencyId <= 0 || $ids === []) {
            return [];
        }

        return RtsPropertyFlag::query()
            ->where('agency_id', $agencyId)->where('track', $track)
            ->where('flag', RtsPropertyFlag::NEVER_SUGGEST)
            ->whereIn('tracked_property_id', $ids)
            ->pluck('tracked_property_id')->map(fn ($v) => (int) $v)->all();
    }

    /** @return int[] */
    public function excludedIdsFor(User $user, array $trackedPropertyIds, string $track = 'sales'): array
    {
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);

        return array_values(array_unique(array_merge(
            $this->heldByOtherIds($trackedPropertyIds, $user, $track),
            $this->neverSuggestIds($agencyId, $trackedPropertyIds, $track),
        )));
    }

    /** The hold in force on this property now, if any. */
    public function activeHold(int $agencyId, int $trackedPropertyId, string $track = 'sales', ?CarbonInterface $at = null): ?RtsHold
    {
        if ($agencyId <= 0) {
            return null;
        }

        return RtsHold::query()
            ->where('agency_id', $agencyId)->where('track', $track)
            ->where('tracked_property_id', $trackedPropertyId)
            ->active($at)->first();
    }

    /**
     * Give $holderUserId the property for $holdDays from $at. One row per property: a lapsed or
     * released row (even a soft-deleted one) is re-used and restarted — never a bare create
     * (BUILD_STANDARD §5a, unique index ignores soft deletes).
     */
    public function acquire(int $agencyId, ?int $branchId, int $trackedPropertyId, int $holderUserId, int $holdDays, ?int $outcomeId, string $track = 'sales', ?CarbonInterface $at = null): RtsHold
    {
        $at = $at ?? now();
        $hold = RtsHold::withTrashed()
            ->where('agency_id', $agencyId)->where('track', $track)
            ->where('tracked_property_id', $trackedPropertyId)->first();

        $attrs = [
            'user_id'         => $holderUserId,
            'branch_id'       => $branchId,
            'last_outcome_id' => $outcomeId,
            'started_at'      => $at,
            'expires_at'      => $at->copy()->addDays(max(1, $holdDays)),
            'released_at'     => null,
        ];

        if ($hold) {
            if ($hold->trashed()) {
                $hold->restore();
            }
            $hold->forceFill($attrs)->save();

            return $hold;
        }

        return RtsHold::create($attrs + [
            'agency_id' => $agencyId, 'track' => $track, 'tracked_property_id' => $trackedPropertyId,
        ]);
    }

    /** Mark a property "never suggest" for every agent. Idempotent; restores a trashed marker. */
    public function markNeverSuggest(int $agencyId, int $trackedPropertyId, ?int $byUserId, ?int $outcomeId, string $reason, string $track = 'sales'): RtsPropertyFlag
    {
        $flag = RtsPropertyFlag::withTrashed()
            ->where('agency_id', $agencyId)->where('track', $track)
            ->where('tracked_property_id', $trackedPropertyId)
            ->where('flag', RtsPropertyFlag::NEVER_SUGGEST)->first();

        if ($flag) {
            if ($flag->trashed()) {
                $flag->restore();
            }
            $flag->forceFill(['set_by_user_id' => $byUserId, 'outcome_id' => $outcomeId, 'reason' => $reason])->save();

            return $flag;
        }

        return RtsPropertyFlag::create([
            'agency_id' => $agencyId, 'track' => $track, 'tracked_property_id' => $trackedPropertyId,
            'flag' => RtsPropertyFlag::NEVER_SUGGEST, 'set_by_user_id' => $byUserId,
            'outcome_id' => $outcomeId, 'reason' => $reason,
        ]);
    }
}
