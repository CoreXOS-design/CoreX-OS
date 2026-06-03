<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityDefinition;
use App\Models\ActivityDefinitionCalendarClass;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Module 6 (M6.2) — admin CRUD for per-agency calendar-class ↔
 * activity-definition mappings. Drives the auto-credit pipeline.
 */
final class ActivityCalendarMappingController extends Controller
{
    public function index()
    {
        $this->authorizeAccess();
        $agencyId = $this->agencyId();

        // Mappings grouped by event_class for the table view.
        $mappings = ActivityDefinitionCalendarClass::with('activityDefinition')
            ->forAgency($agencyId)
            ->orderBy('event_class')
            ->orderBy('id')
            ->get()
            ->groupBy('event_class');

        // Pickers: every event_class slug available to this agency
        // (agency-specific overrides + NULL-agency system defaults), plus
        // every activity_definition this agency may use.
        //
        // Coalesce pattern: same as CalendarEventFeedbackObserver.php:26-30.
        // MySQL evaluates `agency_id IS NULL` as 0 for agency rows, 1 for
        // system rows, so ASC puts the agency-specific row FIRST. We then
        // dedupe by event_class in PHP, keeping the first occurrence — i.e.
        // the agency override wins, falling back to the system default.
        $eventClasses = DB::table('calendar_event_class_settings')
            ->where(fn ($q) => $q->where('agency_id', $agencyId)->orWhereNull('agency_id'))
            ->orderBy('event_class')
            ->orderByRaw('agency_id IS NULL')
            ->get(['event_class', 'label', 'is_active', 'event_nature', 'agency_id'])
            ->unique('event_class')
            ->values();

        $activityDefinitions = ActivityDefinition::query()
            ->availableTo($agencyId)
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('admin.activity-mappings.index', [
            'mappings'            => $mappings,
            'eventClasses'        => $eventClasses,
            'activityDefinitions' => $activityDefinitions,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAccess();
        $agencyId = $this->agencyId();

        $validated = $request->validate([
            'event_class'              => 'required|string|max:64',
            'activity_definition_id'   => 'required|integer|exists:activity_definitions,id',
            'value_per_event'          => 'required|integer|min:1|max:1000',
            'requires_feedback'        => 'sometimes|boolean',
            'auto_revoke_after_hours'  => 'nullable|integer|min:1|max:8760',
            'daily_cap'                => 'nullable|integer|min:1|max:1000',
            'back_date_limit_hours'    => 'required|integer|min:0|max:8760',
            'is_active'                => 'sometimes|boolean',
        ]);

        // Reject cross-agency activity_definition usage — agency-scoped
        // definitions belonging to a different agency can't be mapped.
        $def = ActivityDefinition::query()
            ->availableTo($agencyId)
            ->where('id', $validated['activity_definition_id'])
            ->first();
        abort_unless($def, 422, 'Activity definition not available to this agency.');

        $exists = ActivityDefinitionCalendarClass::query()
            ->forAgency($agencyId)
            ->where('event_class', $validated['event_class'])
            ->where('activity_definition_id', $validated['activity_definition_id'])
            ->whereNull('deleted_at')
            ->exists();
        if ($exists) {
            return back()->withErrors([
                'duplicate' => 'A mapping for that event class + activity already exists for this agency.',
            ]);
        }

        ActivityDefinitionCalendarClass::create([
            'agency_id'               => $agencyId,
            'event_class'             => $validated['event_class'],
            'activity_definition_id'  => $validated['activity_definition_id'],
            'value_per_event'         => $validated['value_per_event'],
            'requires_feedback'       => (bool) ($validated['requires_feedback'] ?? true),
            'auto_revoke_after_hours' => $validated['auto_revoke_after_hours'] ?? 24,
            'daily_cap'               => $validated['daily_cap'] ?? null,
            'back_date_limit_hours'   => $validated['back_date_limit_hours'],
            'is_active'               => (bool) ($validated['is_active'] ?? true),
            'created_by'              => Auth::id(),
            'updated_by'              => Auth::id(),
        ]);

        return redirect()->route('admin.activity-mappings.index')
            ->with('success', 'Mapping created.');
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeAccess();
        $mapping = $this->findOrFail($id);

        $validated = $request->validate([
            'value_per_event'         => 'required|integer|min:1|max:1000',
            'requires_feedback'       => 'sometimes|boolean',
            'auto_revoke_after_hours' => 'nullable|integer|min:1|max:8760',
            'daily_cap'               => 'nullable|integer|min:1|max:1000',
            'back_date_limit_hours'   => 'required|integer|min:0|max:8760',
            'is_active'               => 'sometimes|boolean',
        ]);

        $mapping->fill($validated);
        $mapping->updated_by = Auth::id();
        $mapping->save();

        return redirect()->route('admin.activity-mappings.index')
            ->with('success', 'Mapping updated.');
    }

    public function toggleActive(int $id)
    {
        $this->authorizeAccess();
        $mapping = $this->findOrFail($id);
        $mapping->is_active = !$mapping->is_active;
        $mapping->updated_by = Auth::id();
        $mapping->save();

        return redirect()->route('admin.activity-mappings.index')
            ->with('success', $mapping->is_active ? 'Mapping activated.' : 'Mapping deactivated.');
    }

    public function destroy(int $id)
    {
        $this->authorizeAccess();
        $mapping = $this->findOrFail($id);
        $mapping->delete();
        return redirect()->route('admin.activity-mappings.index')
            ->with('success', 'Mapping archived.');
    }

    private function authorizeAccess(): void
    {
        $user = Auth::user();
        abort_unless($user && $user->hasPermission('manage_activity_mappings'), 403);
    }

    private function agencyId(): int
    {
        $id = Auth::user()?->effectiveAgencyId();
        abort_if($id === null, 403, 'No agency context.');
        return (int) $id;
    }

    private function findOrFail(int $id): ActivityDefinitionCalendarClass
    {
        $row = ActivityDefinitionCalendarClass::query()
            ->forAgency($this->agencyId())
            ->whereNull('deleted_at')
            ->where('id', $id)
            ->first();
        abort_if(!$row, 404);
        return $row;
    }
}
