<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Property;
use App\Models\User;

/**
 * rental-inspections.md §45.8 H2 — the guard for every action reached through a PROPERTY rather than a bound inspection (the property
 * tab's data, start, item/room edits, seeding, photo matching, and — AT-508 P5 — the mobile checklist). Moved out of
 * RentalInspectionRecordingController unchanged so the web and the mobile API apply ONE rule.
 *
 * Route binding on {property} only enforces the agency boundary; without this, any same-agency user holding rental_inspections.create
 * could read or change the inspection checklist of a colleague's or another branch's listing by id.
 *
 * Two layers, both required: (1) the property's own own/branch/agency scope — exactly what the property page itself applies, so the
 * tab is never reachable here when the page is not; (2) the rental_inspections scope ceiling — a user held to their BRANCH's
 * inspections cannot act on another branch's property even if their properties scope is wider. `own` needs no property-level check
 * beyond (1): "own" for inspections is creator/inspector, enforced per inspection wherever one is addressed
 * (guardRentalRecordScope on every inspection action). Reads use view breadth, writes use mutation breadth (assistants), as the
 * property page does.
 *
 * The host class must also `use AuthorizesPropertyAccess` (authorizeProperty()).
 */
trait AuthorizesRentalInspectionProperty
{
    use AuthorizesPropertyAccess;

    private function authorizePropertyForInspections(Property $property, bool $forEdit = true): void
    {
        $this->authorizeProperty($property, $forEdit);

        /** @var User $user */
        $user = auth()->user();
        $inspectionScope = \App\Services\PermissionService::getDataScope($user, 'rental_inspections');

        $allowed = match ($inspectionScope) {
            'all', 'own' => true,
            'branch' => (int) $property->branch_id === (int) $user->effectiveBranchId(),
            default => false,
        };

        abort_unless($allowed, 403);
    }
}
