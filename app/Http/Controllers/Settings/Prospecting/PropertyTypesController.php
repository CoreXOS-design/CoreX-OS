<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings\Prospecting;

use App\Events\Prospecting\PropertyTypeConfigured;
use App\Http\Controllers\Controller;
use App\Models\Prospecting\PropertyTypeOption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PropertyTypesController extends Controller
{
    public function store(Request $request)
    {
        $agencyId = $this->resolveAgencyId($request);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $type = PropertyTypeOption::create([
            'agency_id'     => $agencyId,
            'name'          => $validated['name'],
            'slug'          => $this->generateUniqueSlug($validated['name'], $agencyId),
            'display_order' => $this->nextDisplayOrder($agencyId),
            'is_active'     => true,
        ]);

        event(new PropertyTypeConfigured(
            propertyType: $type,
            action:       PropertyTypeConfigured::ACTION_CREATED,
            actorUserId:  Auth::id(),
            agencyId:     $agencyId,
        ));

        return redirect()->route('settings.prospecting.index', ['tab' => 'property-types'])
            ->with('status', "Property type '{$type->name}' added.");
    }

    public function update(Request $request, PropertyTypeOption $type)
    {
        $this->authorizeAgency($request, $type);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        if ($validated['name'] !== $type->name) {
            $validated['slug'] = $this->generateUniqueSlug($validated['name'], $type->agency_id, exceptId: $type->id);
        }

        $type->update($validated);

        event(new PropertyTypeConfigured(
            propertyType: $type->fresh(),
            action:       PropertyTypeConfigured::ACTION_UPDATED,
            actorUserId:  Auth::id(),
            agencyId:     $type->agency_id,
        ));

        return redirect()->route('settings.prospecting.index', ['tab' => 'property-types'])
            ->with('status', 'Property type updated.');
    }

    public function toggleActive(Request $request, PropertyTypeOption $type)
    {
        $this->authorizeAgency($request, $type);

        $type->update(['is_active' => !$type->is_active]);

        event(new PropertyTypeConfigured(
            propertyType: $type->fresh(),
            action:       PropertyTypeConfigured::ACTION_UPDATED,
            actorUserId:  Auth::id(),
            agencyId:     $type->agency_id,
        ));

        return redirect()->route('settings.prospecting.index', ['tab' => 'property-types'])
            ->with('status', "Property type '{$type->name}' " . ($type->is_active ? 'activated' : 'deactivated') . '.');
    }

    public function archive(Request $request, PropertyTypeOption $type)
    {
        $this->authorizeAgency($request, $type);

        $type->delete();

        event(new PropertyTypeConfigured(
            propertyType: $type,
            action:       PropertyTypeConfigured::ACTION_ARCHIVED,
            actorUserId:  Auth::id(),
            agencyId:     $type->agency_id,
        ));

        return redirect()->route('settings.prospecting.index', ['tab' => 'property-types'])
            ->with('status', "Property type '{$type->name}' archived.");
    }

    public function reorder(Request $request)
    {
        $agencyId = $this->resolveAgencyId($request);

        $validated = $request->validate([
            'order'   => 'required|array',
            'order.*' => 'integer',
        ]);

        DB::transaction(function () use ($validated, $agencyId) {
            foreach ($validated['order'] as $position => $id) {
                PropertyTypeOption::withoutGlobalScopes()
                    ->where('id', $id)
                    ->where('agency_id', $agencyId)
                    ->update(['display_order' => $position]);
            }
        });

        $first = PropertyTypeOption::withoutGlobalScopes()->where('agency_id', $agencyId)->first();
        if ($first) {
            event(new PropertyTypeConfigured(
                propertyType: $first,
                action:       PropertyTypeConfigured::ACTION_UPDATED,
                actorUserId:  Auth::id(),
                agencyId:     $agencyId,
            ));
        }

        return redirect()->route('settings.prospecting.index', ['tab' => 'property-types'])
            ->with('status', 'Property types reordered.');
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

    private function generateUniqueSlug(string $name, int $agencyId, ?int $exceptId = null): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 2;

        while (PropertyTypeOption::withoutGlobalScopes()->withTrashed()
            ->where('agency_id', $agencyId)
            ->where('slug', $slug)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists()
        ) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    private function nextDisplayOrder(int $agencyId): int
    {
        return (int) (PropertyTypeOption::withoutGlobalScopes()->where('agency_id', $agencyId)->max('display_order') ?? 0) + 1;
    }

    private function authorizeAgency(Request $request, PropertyTypeOption $type): void
    {
        if ($type->agency_id !== $this->resolveAgencyId($request)) {
            abort(403, 'Cross-agency access denied.');
        }
    }
}
