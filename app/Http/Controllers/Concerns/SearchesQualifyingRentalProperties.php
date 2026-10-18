<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The property-search dropdown behind a rental LIST/FILTER screen (Leases,
 * Fault Reports, Work Orders, Job Cards) must only offer properties that
 * actually have at least one record in THAT section, visible to the acting
 * user under that section's own own/branch/agency scope — never every
 * rental property. That wider, unscoped picker is the CREATE form's own job
 * (e.g. RentalWorkOrderController::searchProperties(),
 * RentalApplicationController::searchProperties()) and is deliberately left
 * untouched; this trait backs a DIFFERENT, separately-named endpoint used
 * only by each list's own filter form.
 *
 * One shared mechanism (BUILD_STANDARD §6, "fix the class, not the
 * instance") so the "qualifying properties only" restriction can never
 * drift per screen — every caller supplies its own section model class and
 * gets the identical scoping/search/archived behaviour.
 */
trait SearchesQualifyingRentalProperties
{
    /**
     * @param class-string<Builder> $sectionModelClass a rental entity model
     *     with its own scopeVisibleTo(Builder $query, User $user, ?string
     *     $requestedScope = null) and a property_id column.
     */
    protected function searchQualifyingRentalProperties(
        Request $request,
        string $sectionModelClass,
        string $propertyIdColumn = 'property_id'
    ): JsonResponse {
        $user = $request->user();
        $term = trim((string) $request->query('q', ''));
        $onlyArchived = $request->boolean('archived');

        $qualifyingPropertyIds = $sectionModelClass::query()
            ->visibleTo($user, $request->get('scope'))
            ->when($onlyArchived, fn ($q) => $q->onlyTrashed())
            ->select($propertyIdColumn)
            ->distinct();

        $properties = Property::query()
            ->where('listing_type', 'rental')
            ->visibleTo($user)
            ->whereIn('id', $qualifyingPropertyIds)
            ->searchAddress($term)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return response()->json($properties->map(fn (Property $p) => $p->toSearchResult([
            'ref' => $p->property_number,
        ])));
    }
}
