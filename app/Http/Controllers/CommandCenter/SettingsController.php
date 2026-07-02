<?php

namespace App\Http\Controllers\CommandCenter;

use App\Http\Controllers\Controller;
use App\Models\CommandCenter\AutomationRule;
use App\Models\CommandCenter\CalendarEventClassSetting;
use App\Models\CommandCenter\DocumentExpectation;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    /**
     * Settings overview page.
     */
    public function index()
    {
        // Command Center settings now live embedded in the unified Settings
        // hub (Operations → Command Center Rules). Keep this legacy URL
        // working by redirecting to the hub section.
        return redirect()->route('corex.settings', ['s' => 'command-center']);
    }

    /**
     * Toggle an automation rule on/off.
     */
    public function toggleRule(AutomationRule $rule)
    {
        $rule->update(['is_active' => !$rule->is_active]);

        return back()->with('success', "Rule \"{$rule->name}\" " . ($rule->is_active ? 'activated' : 'deactivated') . '.');
    }

    /**
     * Store a document expectation.
     */
    public function storeExpectation(Request $request)
    {
        $request->validate([
            'property_type'    => 'required|string|max:50',
            'label'            => 'required|string|max:255',
            'due_offset_hours' => 'required|integer|min:1',
            'required'         => 'nullable|boolean',
        ]);

        DocumentExpectation::create($request->all());

        return back()->with('success', 'Document expectation added.');
    }

    /**
     * Delete a document expectation.
     */
    public function destroyExpectation(DocumentExpectation $expectation)
    {
        $expectation->delete();
        return back()->with('success', 'Document expectation removed.');
    }

    // ── Event Class Settings ──

    /**
     * List all 38 event classes with effective config per agency.
     */
    public function eventClasses(Request $request)
    {
        $agencyId = auth()->user()->effectiveAgencyId();

        $globals = CalendarEventClassSetting::withoutGlobalScopes()
            ->whereNull('agency_id')
            ->orderBy('label')
            ->get()
            ->keyBy('event_class');

        $agencyOverrides = $agencyId
            ? CalendarEventClassSetting::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->get()
                ->keyBy('event_class')
            : collect();

        $effective = $globals->map(function ($global) use ($agencyOverrides) {
            $override = $agencyOverrides->get($global->event_class);
            return [
                'config'        => $override ?? $global,
                'is_overridden' => $override !== null,
            ];
        })->values();

        // Dynamic roles from Role Manager (excludes owner roles — they bypass visibility)
        $availableRoles = \App\Models\Role::allRoles(auth()->user()?->effectiveAgencyId())->where('is_owner', false)->pluck('name')->toArray();
        $availableChannels = ['in_app', 'email'];

        return view('command-center.settings.event-classes', compact(
            'effective', 'availableRoles', 'availableChannels'
        ));
    }

    /**
     * Save an agency-specific override for an event class.
     */
    public function updateEventClass(Request $request, string $eventClass)
    {
        $agencyId = auth()->user()->effectiveAgencyId();
        if (!$agencyId) {
            return back()->with('error', 'Unable to save — no active agency context.');
        }

        $validated = $request->validate([
            'is_active'            => 'required|boolean',
            'green_days'           => 'required|integer|min:0|max:365',
            'amber_days'           => 'required|integer|min:0|max:365',
            'red_days'             => 'required|integer|min:0|max:365',
            'show_days'            => 'nullable|integer|min:0|max:730',
            'green_visibility'     => 'array',
            'green_visibility.*'   => 'string',
            'amber_visibility'     => 'array',
            'amber_visibility.*'   => 'string',
            'red_visibility'       => 'array',
            'red_visibility.*'     => 'string',
            'green_notifications'  => 'array',
            'amber_notifications'  => 'array',
            'red_notifications'    => 'array',
            'daily_digest_enabled' => 'required|boolean',
            'daily_digest_roles'   => 'array',
            'daily_digest_roles.*' => 'string',
            'actor_role'           => 'nullable|in:buyer_action,seller_action,both,neither',
            'completion_behaviour' => 'nullable|in:require_feedback,require_reason,freeform',
            'occupies_time'        => 'sometimes|boolean',
            'event_nature'         => 'sometimes|in:actionable,informational',
        ]);

        if ($validated['red_days'] > $validated['amber_days']
         || $validated['amber_days'] > $validated['green_days']) {
            return back()
                ->withInput()
                ->with('error', 'Threshold order invalid: red_days ≤ amber_days ≤ green_days required.');
        }

        $global = CalendarEventClassSetting::withoutGlobalScopes()
            ->whereNull('agency_id')
            ->where('event_class', $eventClass)
            ->first();

        if (!$global) {
            return back()->with('error', 'Unknown event class.');
        }

        $normaliseRouting = function (array $raw) {
            $out = [];
            foreach ($raw as $role => $channels) {
                if (is_array($channels) && !empty($channels)) {
                    $out[$role] = array_values(array_unique($channels));
                }
            }
            return $out;
        };

        CalendarEventClassSetting::withoutGlobalScopes()
            ->updateOrCreate(
                ['agency_id' => $agencyId, 'event_class' => $eventClass],
                [
                    'label'                => $global->label,
                    'description'          => $global->description,
                    'is_active'            => $validated['is_active'],
                    'green_days'           => $validated['green_days'],
                    'amber_days'           => $validated['amber_days'],
                    'red_days'             => $validated['red_days'],
                    'show_days'            => $validated['show_days'] ?? null,
                    'green_visibility'     => array_values($validated['green_visibility'] ?? []),
                    'amber_visibility'     => array_values($validated['amber_visibility'] ?? []),
                    'red_visibility'       => array_values($validated['red_visibility'] ?? []),
                    'green_notifications'  => $normaliseRouting($validated['green_notifications'] ?? []),
                    'amber_notifications'  => $normaliseRouting($validated['amber_notifications'] ?? []),
                    'red_notifications'    => $normaliseRouting($validated['red_notifications'] ?? []),
                    'daily_digest_enabled' => $validated['daily_digest_enabled'],
                    'daily_digest_roles'   => $validated['daily_digest_enabled']
                        ? array_values($validated['daily_digest_roles'] ?? [])
                        : null,
                    'actor_role'           => $validated['actor_role'] ?? $global->actor_role ?? 'neither',
                    'completion_behaviour' => $validated['completion_behaviour'] ?? $global->completion_behaviour ?? 'freeform',
                    // Carry the appointment flag from the global default (or an
                    // explicit override) so editing a class never silently resets
                    // it to the column default and breaks its conflict behaviour.
                    'occupies_time'        => $validated['occupies_time'] ?? (bool) $global->occupies_time,
                    // Agency-configurable default nature (requires-feedback vs not)
                    // per class; carried from global (or an explicit override) so
                    // editing a class never silently resets it.
                    'event_nature'         => $validated['event_nature'] ?? $global->event_nature ?? 'actionable',
                ]
            );

        return back()->with('success', "Event class '{$global->label}' saved.");
    }

    /**
     * Reset an agency-specific override back to global defaults.
     */
    public function resetEventClass(Request $request, string $eventClass)
    {
        $agencyId = auth()->user()->effectiveAgencyId();
        if (!$agencyId) {
            return back()->with('error', 'No active agency context.');
        }

        CalendarEventClassSetting::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('event_class', $eventClass)
            ->delete();

        return back()->with('success', 'Event class reset to global default.');
    }
}
