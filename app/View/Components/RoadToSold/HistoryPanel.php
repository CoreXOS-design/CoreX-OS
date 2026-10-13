<?php

namespace App\View\Components\RoadToSold;

use App\Services\RoadToSold\PropertyHistoryService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\Component;

/**
 * AT-454 - <x-road-to-sold.history-panel :tracked-property-id="$tp->id" /> or :property-id="$property->id".
 * Renders nothing for a viewer without Road to Sold or an agency without the feature.
 */
class HistoryPanel extends Component
{
    public ?LengthAwarePaginator $history = null;

    public function __construct(public ?int $trackedPropertyId = null, public ?int $propertyId = null)
    {
        $user = auth()->user();
        if (! $user || ! $user->hasFeature('road-to-sold') || ! $user->hasPermission('access_road_to_sold')) {
            return;
        }

        $svc = app(PropertyHistoryService::class);
        if ($trackedPropertyId) {
            $this->history = $svc->forTrackedProperty($user, $trackedPropertyId);
        } elseif ($propertyId) {
            $this->history = $svc->forProperty($user, $propertyId);
        }
    }

    public function shouldRender(): bool
    {
        return $this->history !== null;
    }

    public function render()
    {
        return view('components.road-to-sold.history-panel');
    }
}
