<?php

namespace App\Services\RoadToSold;

use App\Models\RoadToSold\RtsContactOutcome;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * AT-454 - the Road to Sold call history shown on the property page and the tracked-property (MIC) page.
 * Spec: .ai/specs/road-to-sold.md section 10.9.
 *
 * Every outcome is history on the property (who, when, what was said). It works for a property with no
 * owner contact, because it reads the call log by property, not by contact. What a viewer sees is narrowed
 * in the query by RtsContactOutcome::visibleTo(): an agent their own calls, a branch manager the branch,
 * an admin the agency. OutcomeService::historyForProperty() is the unscoped, unpaged read the call page
 * uses for its own panel; this one is the viewer-scoped, paged read.
 */
class PropertyHistoryService
{
    public const PER_PAGE = 10;
    public const PAGE_NAME = 'rts_page';

    /** History on one tracked property (the MIC page). */
    public function forTrackedProperty(User $viewer, int $trackedPropertyId, string $track = 'sales'): LengthAwarePaginator
    {
        return $this->paginate($this->base($viewer, $track)->where('tracked_property_id', $trackedPropertyId));
    }

    /**
     * History on one agency-stock property (the property page): calls logged against the property itself,
     * plus calls logged on the tracked property it was promoted from.
     */
    public function forProperty(User $viewer, int $propertyId, string $track = 'sales'): LengthAwarePaginator
    {
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);

        return $this->paginate($this->base($viewer, $track)->where(function ($w) use ($propertyId, $agencyId) {
            $w->where('property_id', $propertyId)
                ->orWhereIn('tracked_property_id', fn ($s) => $s->select('id')->from('tracked_properties')
                    ->where('agency_id', $agencyId)->where('promoted_to_property_id', $propertyId));
        }));
    }

    private function base(User $viewer, string $track)
    {
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);

        // agency_id <= 0 matches nothing (never an unscoped read).
        return RtsContactOutcome::query()->visibleTo($viewer)->with('agent:id,name')
            ->where('agency_id', $agencyId > 0 ? $agencyId : -1)->where('track', $track);
    }

    private function paginate($query): LengthAwarePaginator
    {
        $page = $query->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['*'], self::PAGE_NAME);

        return $page->withPath(request()->url())
            ->appends(request()->except(self::PAGE_NAME))
            ->fragment('rts-history');
    }
}
