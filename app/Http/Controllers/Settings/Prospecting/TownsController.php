<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings\Prospecting;

use App\Events\Prospecting\SuburbMappingChanged;
use App\Events\Prospecting\TownConfigured;
use App\Http\Controllers\Controller;
use App\Models\Prospecting\Town;
use App\Models\Prospecting\TownSuburb;
use App\Services\Prospecting\ProspectingConfigurationService;
use App\Services\Prospecting\RegionSuggestionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TownsController extends Controller
{
    public function __construct(
        private readonly ProspectingConfigurationService $config,
    ) {}

    public function index(Request $request)
    {
        // Prospecting Setup now lives embedded in the unified Settings hub
        // (Operations → Prospecting Setup). Keep this legacy URL working —
        // and the per-tab redirects from the CRUD controllers — by
        // forwarding into the hub section, preserving the active tab.
        return redirect()->route('corex.settings', array_filter([
            's'   => 'prospecting-setup',
            'tab' => $request->query('tab'),
        ]));
    }

    // Unmapped-suburbs computation lives on ProspectingConfigurationService
    // so both this controller's settings page and the prospecting-tab drawer
    // can share it. See ProspectingConfigurationService::unmappedSuburbsFor().

    /**
     * POST /corex/settings/prospecting/suburbs/map
     *
     * One-click action from the unmapped-suburbs widget. Creates a TownSuburb
     * row attaching the suburb to the agency's chosen town. Idempotent —
     * re-mapping the same suburb returns a friendly status, not an error.
     */
    public function mapSuburb(Request $request)
    {
        $agencyId = $this->resolveAgencyId($request);

        $validated = $request->validate([
            'suburb_name' => 'required|string|max:150',
            'town_id'     => 'required|integer|exists:towns,id',
        ]);

        // Defence in depth: confirm the chosen town is for THIS agency before
        // attaching the new suburb to it.
        $town = Town::withoutGlobalScopes()
            ->where('id', $validated['town_id'])
            ->where('agency_id', $agencyId)
            ->first();
        abort_if($town === null, 404, 'Town not found for this agency.');

        $normalised = TownSuburb::normaliseSuburb($validated['suburb_name']);

        $existing = TownSuburb::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('suburb_normalised', $normalised)
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            return redirect()->route('settings.prospecting.index', ['tab' => 'towns'])
                ->with('status', "'{$validated['suburb_name']}' is already mapped.");
        }

        $suburb = TownSuburb::create([
            'agency_id'         => $agencyId,
            'town_id'           => $town->id,
            'suburb_name'       => $validated['suburb_name'],
            'suburb_normalised' => $normalised,
        ]);

        event(new \App\Events\Prospecting\SuburbMappingChanged(
            suburb:      $suburb,
            town:        $town,
            action:      \App\Events\Prospecting\SuburbMappingChanged::ACTION_CREATED,
            actorUserId: \Illuminate\Support\Facades\Auth::id(),
            agencyId:    $agencyId,
        ));

        return redirect()->route('settings.prospecting.index', ['tab' => 'towns'])
            ->with('status', "Mapped '{$validated['suburb_name']}' to {$town->name}.");
    }

    /**
     * GET /corex/settings/prospecting/suggestions/{regionKey}
     *
     * Returns the region's curated town/suburb list, annotated with
     * `already_exists` per suburb (case-insensitive normalised match against
     * the current agency's town_suburbs). The Build-from-Web UI uses this
     * to pre-tick only suburbs not yet in the agency's data.
     */
    public function suggestions(Request $request, string $regionKey, RegionSuggestionService $library)
    {
        $region = $library->region($regionKey);
        abort_if($region === null, 404, 'Unknown region.');

        $agencyId = $this->resolveAgencyId($request);

        $existing = TownSuburb::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->pluck('suburb_normalised')
            ->all();
        $existingSet = array_flip($existing);

        $towns = [];
        foreach ($region['towns'] as $town) {
            $suburbs = [];
            foreach ($town['suburbs'] as $suburbName) {
                $suburbs[] = [
                    'name'           => $suburbName,
                    'already_exists' => isset($existingSet[TownSuburb::normaliseSuburb($suburbName)]),
                ];
            }
            $towns[] = ['name' => $town['name'], 'suburbs' => $suburbs];
        }

        return response()->json([
            'key'   => $regionKey,
            'name'  => $region['name'],
            'towns' => $towns,
        ]);
    }

    /**
     * POST /corex/settings/prospecting/towns/bulk-import
     *
     * Transactional bulk-import for the Build-from-Web helper. Skips towns
     * that already exist (by slug) and suburbs that already exist (by
     * normalised match). Fires one TownConfigured event per new town and
     * one SuburbMappingChanged per new suburb.
     */
    public function bulkImport(Request $request)
    {
        $agencyId = $this->resolveAgencyId($request);

        $validated = $request->validate([
            'towns'              => 'required|array',
            'towns.*.name'       => 'required|string|max:100',
            'towns.*.suburbs'    => 'nullable|array',
            'towns.*.suburbs.*'  => 'string|max:150',
        ]);

        $created = ['towns' => 0, 'suburbs' => 0, 'skipped_towns' => 0, 'skipped_suburbs' => 0];

        DB::transaction(function () use ($validated, $agencyId, &$created) {
            foreach ($validated['towns'] as $townData) {
                $slug = Str::slug($townData['name']);

                $existing = Town::withoutGlobalScopes()
                    ->where('agency_id', $agencyId)
                    ->where('slug', $slug)
                    ->first();

                if ($existing) {
                    $town = $existing;
                    $created['skipped_towns']++;
                } else {
                    $town = Town::create([
                        'agency_id'     => $agencyId,
                        'name'          => $townData['name'],
                        'slug'          => $slug,
                        'display_order' => $this->nextDisplayOrder($agencyId),
                    ]);
                    event(new TownConfigured(
                        town:        $town,
                        action:      TownConfigured::ACTION_CREATED,
                        actorUserId: Auth::id(),
                        agencyId:    $agencyId,
                    ));
                    $created['towns']++;
                }

                foreach ($townData['suburbs'] ?? [] as $suburbName) {
                    $normalised = TownSuburb::normaliseSuburb($suburbName);

                    $exists = TownSuburb::withoutGlobalScopes()
                        ->where('agency_id', $agencyId)
                        ->where('suburb_normalised', $normalised)
                        ->exists();

                    if ($exists) {
                        $created['skipped_suburbs']++;
                        continue;
                    }

                    $suburb = TownSuburb::create([
                        'agency_id'         => $agencyId,
                        'town_id'           => $town->id,
                        'suburb_name'       => $suburbName,
                        'suburb_normalised' => $normalised,
                    ]);
                    event(new SuburbMappingChanged(
                        suburb:      $suburb,
                        town:        $town,
                        action:      SuburbMappingChanged::ACTION_CREATED,
                        actorUserId: Auth::id(),
                        agencyId:    $agencyId,
                    ));
                    $created['suburbs']++;
                }
            }
        });

        return redirect()->route('settings.prospecting.index', ['tab' => 'towns'])
            ->with('status', sprintf(
                'Imported %d new town(s) and %d new suburb(s). Skipped %d existing town(s) and %d existing suburb(s).',
                $created['towns'], $created['suburbs'], $created['skipped_towns'], $created['skipped_suburbs']
            ));
    }

    public function store(Request $request)
    {
        $agencyId = $this->resolveAgencyId($request);

        $validated = $request->validate([
            'name'   => 'required|string|max:100',
            'region' => 'nullable|string|max:100',
        ]);

        $validated['agency_id']     = $agencyId;
        $validated['slug']          = $this->generateUniqueSlug($validated['name'], $agencyId);
        $validated['display_order'] = $this->nextDisplayOrder($agencyId);

        $town = Town::create($validated);

        event(new TownConfigured(
            town:        $town,
            action:      TownConfigured::ACTION_CREATED,
            actorUserId: Auth::id(),
            agencyId:    $agencyId,
        ));

        return redirect()->route('settings.prospecting.index', ['tab' => 'towns'])
            ->with('status', "Town '{$town->name}' added.");
    }

    public function update(Request $request, Town $town)
    {
        $this->authorizeAgency($request, $town);

        $validated = $request->validate([
            'name'   => 'required|string|max:100',
            'region' => 'nullable|string|max:100',
        ]);

        if ($validated['name'] !== $town->name) {
            $validated['slug'] = $this->generateUniqueSlug($validated['name'], $town->agency_id, exceptTownId: $town->id);
        }

        $town->update($validated);

        event(new TownConfigured(
            town:        $town->fresh(),
            action:      TownConfigured::ACTION_UPDATED,
            actorUserId: Auth::id(),
            agencyId:    $town->agency_id,
        ));

        return redirect()->route('settings.prospecting.index', ['tab' => 'towns'])
            ->with('status', 'Town updated.');
    }

    public function archive(Request $request, Town $town)
    {
        $this->authorizeAgency($request, $town);

        $town->delete();

        event(new TownConfigured(
            town:        $town,
            action:      TownConfigured::ACTION_ARCHIVED,
            actorUserId: Auth::id(),
            agencyId:    $town->agency_id,
        ));

        return redirect()->route('settings.prospecting.index', ['tab' => 'towns'])
            ->with('status', "Town '{$town->name}' archived.");
    }

    public function restore(Request $request, int $townId)
    {
        $town = Town::withTrashed()->findOrFail($townId);
        $this->authorizeAgency($request, $town);

        $town->restore();

        event(new TownConfigured(
            town:        $town,
            action:      TownConfigured::ACTION_UPDATED,
            actorUserId: Auth::id(),
            agencyId:    $town->agency_id,
        ));

        return redirect()->route('settings.prospecting.index', ['tab' => 'towns'])
            ->with('status', "Town '{$town->name}' restored.");
    }

    public function reorder(Request $request)
    {
        $agencyId = $this->resolveAgencyId($request);

        $validated = $request->validate([
            'order'   => 'required|array',
            'order.*' => 'integer',
        ]);

        DB::transaction(function () use ($validated, $agencyId) {
            foreach ($validated['order'] as $position => $townId) {
                Town::withoutGlobalScopes()
                    ->where('id', $townId)
                    ->where('agency_id', $agencyId)
                    ->update(['display_order' => $position]);
            }
        });

        $firstTown = Town::withoutGlobalScopes()->where('agency_id', $agencyId)->first();
        if ($firstTown) {
            event(new TownConfigured(
                town:        $firstTown,
                action:      TownConfigured::ACTION_UPDATED,
                actorUserId: Auth::id(),
                agencyId:    $agencyId,
            ));
        }

        return redirect()->route('settings.prospecting.index', ['tab' => 'towns'])
            ->with('status', 'Towns reordered.');
    }

    private function resolveAgencyId(Request $request): int
    {
        $user = $request->user();
        $id = method_exists($user, 'effectiveAgencyId')
            ? $user->effectiveAgencyId()
            : ($user->agency_id ?? null);
        abort_if($id === null, 403, 'No agency context.');
        return (int) $id;
    }

    private function generateUniqueSlug(string $name, int $agencyId, ?int $exceptTownId = null): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 2;

        while (Town::withoutGlobalScopes()->withTrashed()
            ->where('agency_id', $agencyId)
            ->where('slug', $slug)
            ->when($exceptTownId, fn ($q) => $q->where('id', '!=', $exceptTownId))
            ->exists()
        ) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    private function nextDisplayOrder(int $agencyId): int
    {
        return (int) (Town::withoutGlobalScopes()->where('agency_id', $agencyId)->max('display_order') ?? 0) + 1;
    }

    private function authorizeAgency(Request $request, Town $town): void
    {
        if ($town->agency_id !== $this->resolveAgencyId($request)) {
            abort(403, 'Cross-agency access denied.');
        }
    }
}
