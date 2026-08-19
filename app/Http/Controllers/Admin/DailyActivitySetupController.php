<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityDefinitionCalendarClass;
use App\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Merged Daily Activities "Setup" screen. Consolidates the two screens
 * that used to live at admin.targets.activity.definitions and
 * admin.activity-mappings.index into one two-tab surface:
 *
 *  - "Manual Daily Activities" — the ActivityDefinition catalogue (name,
 *    weight, scope, enabled). Same query TargetController::activityDefinitions()
 *    used, PLUS a NOT LIKE '[Auto] %' exclusion — those rows exist only as
 *    FK targets for the auto-credit engine (see ActivityInstantActionsSeeder)
 *    and were confusingly listed as editable manual activities before.
 *
 *  - "Auto Daily Activities" — the calendar/instant mapping catalogue.
 *    Logic ported verbatim from ActivityCalendarMappingController.
 *
 * Each tab is independently gated on its original permission
 * (manage_targets / manage_activity_mappings) so visibility does not
 * change for any existing role — only the two screens are now one.
 */
class DailyActivitySetupController extends Controller
{
    private const GROUP_ORDER = [
        'Calendar',
        'Contacts & Buyers',
        'Properties & Listings',
        'Deals & Mandates',
        'Presentations',
        'Seller Outreach',
        'MIC / Prospecting',
        'Compliance & FICA',
        'Marketing',
        'Other',
    ];

    private const SLUG_LABEL = [
        'contact.captured'                  => 'Contact captured',
        'property.captured'                 => 'Property captured',
        'property.published'                => 'Property published (first time)',
        'property.compliance_passed'        => 'Property compliance snapshot taken',
        'deal.created'                      => 'Deal captured (creator)',
        'deal.listing_side'                 => 'Deal captured — listing-side agent',
        'deal.selling_side'                 => 'Deal captured — selling-side agent',
        'deal.stage_advanced'               => 'Deal stage advanced',
        'deal.registered'                   => 'Deal registered (creator)',
        'deal.registered.listing_side'      => 'Deal registered — listing-side agent',
        'deal.registered.selling_side'      => 'Deal registered — selling-side agent',
        'deal.commission_finalised'         => 'Deal commission finalised',
        'mandate.signed'                    => 'Mandate signed',
        'presentation.generated'            => 'Presentation generated',
        'presentation.won'                  => 'Presentation outcome — won',
        'presentation.lost'                 => 'Presentation outcome — lost',
        'outreach.pitch_sent'               => 'Seller-outreach pitch sent',
        'outreach.outcome_logged'           => 'Seller-outreach outcome logged',
        'mic.claim_taken'                   => 'MIC claim taken',
        'mic.claim_feedback'                => 'MIC claim feedback recorded',
        'map.prospect_launched'             => 'Map prospect launched',
        'tracked_property.promoted_to_stock'=> 'Tracked property promoted to stock',
        'fica.submitted'                    => 'FICA submitted',
        'fica.approved'                     => 'FICA approved',
        'fica.reviewed'                     => 'FICA reviewed (any outcome)',
        'rcr.submitted'                     => 'RCR submission submitted',
        'marketing.published'               => 'Marketing post published',
    ];

    private const CALENDAR_LABEL = [
        'meeting'              => 'Meeting',
        'property_evaluation'  => 'Property evaluation',
        'listing_presentation' => 'Listing presentation',
        'viewing'              => 'Property viewing',
    ];

    public function index(Request $request)
    {
        $auth = Auth::user();
        $canManual = (bool) $auth?->hasPermission('manage_targets');
        $canAuto   = (bool) $auth?->hasPermission('manage_activity_mappings');
        abort_unless($canManual || $canAuto, 403);

        $requestedTab = (string) $request->query('tab', '');
        $activeTab = in_array($requestedTab, ['manual', 'auto'], true) ? $requestedTab : ($canManual ? 'manual' : 'auto');
        if ($activeTab === 'manual' && !$canManual) $activeTab = 'auto';
        if ($activeTab === 'auto' && !$canAuto) $activeTab = 'manual';

        return view('admin.daily-activities.setup', [
            'canManual'  => $canManual,
            'canAuto'    => $canAuto,
            'activeTab'  => $activeTab,
            'definitions'=> $canManual ? $this->manualDefinitions($auth) : collect(),
            'catalogue'  => $canAuto ? $this->autoCatalogue() : [],
            'totalActions' => $canAuto ? $this->autoCatalogueCount() : 0,
            'agencyName' => $canAuto ? Auth::user()?->agency?->name : null,
        ]);
    }

    // =========================
    // Manual Daily Activities (ActivityDefinition catalogue)
    // =========================

    private function manualDefinitions($auth)
    {
        $defScope = PermissionService::getDataScope($auth, 'targets');

        $branchId = null;
        if ($defScope === 'branch') {
            $branchId = (int) ($auth?->branch_id ?? 0);
            if ($branchId <= 0) $branchId = null;
        }

        return DB::table('activity_definitions')
            ->where('name', 'not like', '[Auto]%')
            ->when($branchId !== null, function ($q) use ($branchId) {
                $q->where(function ($qq) use ($branchId) {
                    $qq->where('scope', 'system')
                       ->orWhere('scope', (string) $branchId);
                });
            }, function ($q) {
                $q->where('scope', 'system');
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function storeDefinition(Request $request)
    {
        abort_unless(Auth::user()?->hasPermission('manage_targets'), 403);

        $name = trim((string) $request->input('name'));
        if ($name === '') {
            return back()->withErrors('Name is required.');
        }

        $weight = (float) $request->input('weight', 1);
        if ($weight < 0) $weight = 0;

        $order = (int) $request->input('sort_order', 100);
        if ($order < 0) $order = 0;

        $mode = strtolower(trim((string) $request->input('scoring_mode', 'count')));
        if (!in_array($mode, ['count', 'once'], true)) $mode = 'count';

        $isActive = $request->has('is_enabled') ? 1 : 0;

        DB::table('activity_definitions')->insert([
            'name'         => $name,
            'weight'       => $weight,
            'sort_order'   => $order,
            'scoring_mode' => $mode,
            'is_enabled'   => $isActive,
            'scope'        => 'system',
            'agency_id'    => null,
            'branch_id'    => null,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return redirect()->route('admin.daily-activities.setup', ['tab' => 'manual'])
            ->with('status', 'Activity added.');
    }

    public function updateDefinition(Request $request, int $id)
    {
        abort_unless(Auth::user()?->hasPermission('manage_targets'), 403);

        $name = trim((string) $request->input('name'));
        if ($name === '') {
            return back()->withErrors('Name is required.');
        }

        $weight = (float) $request->input('weight', 1);
        if ($weight < 0) $weight = 0;

        $order = (int) $request->input('sort_order', 100);
        if ($order < 0) $order = 0;

        $mode = strtolower(trim((string) $request->input('scoring_mode', 'count')));
        if (!in_array($mode, ['count', 'once'], true)) $mode = 'count';

        $isActive = $request->has('is_enabled') ? 1 : 0;

        DB::table('activity_definitions')
            ->where('id', $id)
            ->update([
                'name'         => $name,
                'weight'       => $weight,
                'sort_order'   => $order,
                'scoring_mode' => $mode,
                'is_enabled'   => $isActive,
                'updated_at'   => now(),
            ]);

        return redirect()->route('admin.daily-activities.setup', ['tab' => 'manual'])
            ->with('status', 'Activity updated.');
    }

    // =========================
    // Auto Daily Activities (calendar/instant mappings)
    // =========================

    private function autoCatalogue(): array
    {
        $this->authorizeAutoAccess();
        $agencyId = $this->agencyId();

        $calendarMappings = ActivityDefinitionCalendarClass::with('activityDefinition')
            ->forAgency($agencyId)
            ->where('trigger_kind', 'calendar')
            ->orderBy('event_class')
            ->orderBy('id')
            ->get()
            ->unique(fn ($m) => $m->event_class)
            ->values();

        $instantMappings = ActivityDefinitionCalendarClass::with('activityDefinition')
            ->where('agency_id', $agencyId)
            ->where('trigger_kind', 'instant')
            ->orderBy('slug')
            ->get();

        $catalogue = [];
        foreach (self::GROUP_ORDER as $group) {
            $catalogue[$group] = [];
        }

        foreach ($calendarMappings as $m) {
            $group = $this->groupForEventClass((string) $m->event_class);
            $catalogue[$group][] = [
                'id'                       => $m->id,
                'kind'                     => 'calendar',
                'key'                      => $m->event_class,
                'label'                    => self::CALENDAR_LABEL[$m->event_class] ?? $this->prettifyKey((string) $m->event_class),
                'definition_name'          => $m->activityDefinition?->name,
                'value_per_event'          => (int) $m->value_per_event,
                'is_active'                => (bool) $m->is_active,
                'daily_cap'                => $m->daily_cap !== null ? (int) $m->daily_cap : null,
                'requires_feedback'        => (bool) $m->requires_feedback,
                'auto_revoke_after_hours'  => $m->auto_revoke_after_hours !== null ? (int) $m->auto_revoke_after_hours : null,
                'back_date_limit_hours'    => (int) $m->back_date_limit_hours,
                'subject_type'             => null,
                'agency_owned'             => $m->agency_id === $agencyId,
            ];
        }

        foreach ($instantMappings as $m) {
            $group = $this->groupForSlug((string) $m->slug);
            $catalogue[$group][] = [
                'id'                       => $m->id,
                'kind'                     => 'instant',
                'key'                      => $m->slug,
                'label'                    => self::SLUG_LABEL[$m->slug] ?? $this->prettifyKey((string) $m->slug),
                'definition_name'          => $m->activityDefinition?->name,
                'value_per_event'          => (int) $m->value_per_event,
                'is_active'                => (bool) $m->is_active,
                'daily_cap'                => $m->daily_cap !== null ? (int) $m->daily_cap : null,
                'requires_feedback'        => null,
                'auto_revoke_after_hours'  => null,
                'back_date_limit_hours'    => null,
                'subject_type'             => $m->subject_type,
                'agency_owned'             => true,
            ];
        }

        foreach ($catalogue as $g => $rows) {
            usort($rows, fn ($a, $b) => strcmp($a['label'], $b['label']));
            $catalogue[$g] = $rows;
        }

        return array_filter($catalogue, fn ($rows) => count($rows) > 0);
    }

    private function autoCatalogueCount(): int
    {
        return array_sum(array_map('count', $this->autoCatalogue()));
    }

    public function updateMapping(Request $request, int $id)
    {
        $this->authorizeAutoAccess();
        $mapping = $this->findMappingOrFail($id);

        $validated = $request->validate([
            'value_per_event'         => 'required|integer|min:0|max:10000',
            'is_active'               => 'sometimes|boolean',
            'requires_feedback'       => 'sometimes|boolean',
            'auto_revoke_after_hours' => 'nullable|integer|min:1|max:8760',
            'daily_cap'               => 'nullable|integer|min:1|max:10000',
            'back_date_limit_hours'   => 'nullable|integer|min:0|max:8760',
        ]);

        $mapping->value_per_event = (int) $validated['value_per_event'];
        if (array_key_exists('is_active', $validated)) {
            $mapping->is_active = (bool) $validated['is_active'];
        }
        if ($request->has('requires_feedback')) {
            $mapping->requires_feedback = (bool) $request->boolean('requires_feedback');
        }
        if ($request->has('auto_revoke_after_hours')) {
            $mapping->auto_revoke_after_hours = $validated['auto_revoke_after_hours'] ?? null;
        }
        if ($request->has('daily_cap')) {
            $mapping->daily_cap = $validated['daily_cap'] ?? null;
        }
        if ($request->has('back_date_limit_hours')) {
            $mapping->back_date_limit_hours = (int) ($validated['back_date_limit_hours'] ?? 0);
        }
        $mapping->updated_by = Auth::id();
        $mapping->save();

        if ($request->wantsJson()) {
            return response()->json([
                'ok'              => true,
                'id'              => $mapping->id,
                'value_per_event' => (int) $mapping->value_per_event,
                'is_active'       => (bool) $mapping->is_active,
            ]);
        }

        return redirect()->route('admin.daily-activities.setup', ['tab' => 'auto'])
            ->with('success', 'Updated.');
    }

    public function toggleMapping(int $id)
    {
        $this->authorizeAutoAccess();
        $mapping = $this->findMappingOrFail($id);
        $mapping->is_active = !$mapping->is_active;
        $mapping->updated_by = Auth::id();
        $mapping->save();

        if (request()->wantsJson()) {
            return response()->json([
                'ok'        => true,
                'id'        => $mapping->id,
                'is_active' => (bool) $mapping->is_active,
            ]);
        }

        return redirect()->route('admin.daily-activities.setup', ['tab' => 'auto'])
            ->with('success', $mapping->is_active ? 'Activated.' : 'Deactivated.');
    }

    private function groupForSlug(string $slug): string
    {
        return match (true) {
            str_starts_with($slug, 'contact.')                                              => 'Contacts & Buyers',
            str_starts_with($slug, 'property.')                                             => 'Properties & Listings',
            str_starts_with($slug, 'deal.') || str_starts_with($slug, 'mandate.')           => 'Deals & Mandates',
            str_starts_with($slug, 'presentation.')                                         => 'Presentations',
            str_starts_with($slug, 'outreach.')                                             => 'Seller Outreach',
            str_starts_with($slug, 'mic.')
                || str_starts_with($slug, 'tracked_property.')
                || str_starts_with($slug, 'map.')                                           => 'MIC / Prospecting',
            str_starts_with($slug, 'fica.') || str_starts_with($slug, 'rcr.')               => 'Compliance & FICA',
            str_starts_with($slug, 'marketing.')                                            => 'Marketing',
            default                                                                          => 'Other',
        };
    }

    private function groupForEventClass(string $eventClass): string
    {
        return 'Calendar';
    }

    private function prettifyKey(string $key): string
    {
        $clean = str_replace(['.', '_'], ' ', $key);
        return ucfirst(strtolower($clean));
    }

    private function authorizeAutoAccess(): void
    {
        $user = Auth::user();
        abort_unless($user && $user->hasPermission('manage_activity_mappings'), 403);
    }

    private function agencyId(): int
    {
        $id = Auth::user()?->effectiveAgencyId();
        abort_if($id === null, 403, 'No agency context — select an agency before editing activity scoring.');
        return (int) $id;
    }

    private function findMappingOrFail(int $id): ActivityDefinitionCalendarClass
    {
        $agencyId = $this->agencyId();
        $row = ActivityDefinitionCalendarClass::query()
            ->where('id', $id)
            ->where(fn ($q) => $q->where('agency_id', $agencyId)->orWhereNull('agency_id'))
            ->whereNull('deleted_at')
            ->first();
        abort_if(!$row, 404);

        if ($row->agency_id === null) {
            abort(403, 'System default rows are not editable. Use the agency override.');
        }

        return $row;
    }
}
