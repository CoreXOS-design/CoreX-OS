<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings\Prospecting;

use App\Events\Prospecting\SuburbMappingChanged;
use App\Http\Controllers\Controller;
use App\Models\Prospecting\Town;
use App\Models\Prospecting\TownSuburb;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class SuburbsController extends Controller
{
    public function store(Request $request, Town $town)
    {
        $this->authorizeAgency($request, $town);

        $validated = $request->validate([
            'suburb_name' => 'required|string|max:150',
        ]);

        $normalised = TownSuburb::normaliseSuburb($validated['suburb_name']);

        $request->validate([
            'suburb_name' => [
                Rule::unique('town_suburbs', 'suburb_normalised')
                    ->where('agency_id', $town->agency_id)
                    ->whereNull('deleted_at'),
            ],
        ], [
            'suburb_name.unique' => 'This suburb is already mapped to a town for this agency.',
        ]);

        $suburb = TownSuburb::create([
            'agency_id'         => $town->agency_id,
            'town_id'           => $town->id,
            'suburb_name'       => $validated['suburb_name'],
            'suburb_normalised' => $normalised,
        ]);

        event(new SuburbMappingChanged(
            suburb:      $suburb,
            town:        $town,
            action:      SuburbMappingChanged::ACTION_CREATED,
            actorUserId: Auth::id(),
            agencyId:    $town->agency_id,
        ));

        return redirect()->route('settings.prospecting.index', ['tab' => 'towns'])
            ->with('status', "Suburb '{$suburb->suburb_name}' added to {$town->name}.");
    }

    public function update(Request $request, TownSuburb $suburb)
    {
        $this->authorizeAgency($request, $suburb);

        $validated = $request->validate([
            'suburb_name' => 'required|string|max:150',
            'town_id'     => 'sometimes|integer|exists:towns,id',
        ]);

        $normalised = TownSuburb::normaliseSuburb($validated['suburb_name']);

        // Re-check uniqueness only if the normalised form changed.
        if ($normalised !== $suburb->suburb_normalised) {
            $request->validate([
                'suburb_name' => [
                    Rule::unique('town_suburbs', 'suburb_normalised')
                        ->where('agency_id', $suburb->agency_id)
                        ->whereNull('deleted_at')
                        ->ignore($suburb->id),
                ],
            ], [
                'suburb_name.unique' => 'This suburb is already mapped to a town for this agency.',
            ]);
        }

        if (isset($validated['town_id'])) {
            $newTown = Town::withoutGlobalScopes()->findOrFail($validated['town_id']);
            $this->authorizeAgency($request, $newTown);
            $suburb->town_id = $newTown->id;
        }

        $suburb->suburb_name = $validated['suburb_name'];
        $suburb->suburb_normalised = $normalised;
        $suburb->save();

        event(new SuburbMappingChanged(
            suburb:      $suburb->fresh(),
            town:        Town::withoutGlobalScopes()->find($suburb->town_id),
            action:      SuburbMappingChanged::ACTION_UPDATED,
            actorUserId: Auth::id(),
            agencyId:    $suburb->agency_id,
        ));

        return redirect()->route('settings.prospecting.index', ['tab' => 'towns'])
            ->with('status', 'Suburb updated.');
    }

    public function archive(Request $request, TownSuburb $suburb)
    {
        $this->authorizeAgency($request, $suburb);

        $town = Town::withoutGlobalScopes()->find($suburb->town_id);
        $suburb->delete();

        event(new SuburbMappingChanged(
            suburb:      $suburb,
            town:        $town,
            action:      SuburbMappingChanged::ACTION_ARCHIVED,
            actorUserId: Auth::id(),
            agencyId:    $suburb->agency_id,
        ));

        return redirect()->route('settings.prospecting.index', ['tab' => 'towns'])
            ->with('status', "Suburb '{$suburb->suburb_name}' archived.");
    }

    private function authorizeAgency(Request $request, Town|TownSuburb $model): void
    {
        $user = $request->user();
        $userAgencyId = method_exists($user, 'effectiveAgencyId')
            ? $user->effectiveAgencyId()
            : ($user->agency_id ?? null);

        if ($model->agency_id !== $userAgencyId) {
            abort(403, 'Cross-agency access denied.');
        }
    }
}
