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

        // AT-245 — the DB constraint is (agency_id, suburb_normalised) across ALL
        // towns. Validate the NORMALISED value: the old Rule::unique checked the raw
        // input against the lowercased column, never matched, and let the DB throw a
        // 500. A suburb string resolves to one town per agency (the matcher only sees
        // the string), so name the town it's already on rather than duplicate it.
        $existing = TownSuburb::withoutGlobalScopes()
            ->where('agency_id', $town->agency_id)
            ->where('suburb_normalised', $normalised)
            ->whereNull('deleted_at')
            ->first();
        if ($existing) {
            $onTown = Town::withoutGlobalScopes()->find($existing->town_id);
            $name = $onTown?->name ?? 'another town';
            return back()->withInput()->withErrors([
                'suburb_name' => "“{$validated['suburb_name']}” is already mapped to {$name}. A suburb maps to one town per agency — move it there instead of adding a duplicate.",
            ]);
        }

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

        // Re-check uniqueness only if the normalised form changed — on the NORMALISED
        // value (agency-wide, all towns), mirroring the DB constraint. Never a 500.
        if ($normalised !== $suburb->suburb_normalised) {
            $existing = TownSuburb::withoutGlobalScopes()
                ->where('agency_id', $suburb->agency_id)
                ->where('suburb_normalised', $normalised)
                ->whereNull('deleted_at')
                ->where('id', '!=', $suburb->id)
                ->first();
            if ($existing) {
                $onTown = Town::withoutGlobalScopes()->find($existing->town_id);
                $name = $onTown?->name ?? 'another town';
                return back()->withInput()->withErrors([
                    'suburb_name' => "“{$validated['suburb_name']}” is already mapped to {$name}. A suburb maps to one town per agency.",
                ]);
            }
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
