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
 * SPINE-SETTINGS — agency configuration UI for the full activity-points
 * catalogue.
 *
 * SCOPE
 *   Renders EVERY action the activity-points engine can credit (calendar
 *   classes + every SPINE-1/2/3/2.5 instant slug) grouped by domain, with
 *   an enable/disable toggle + per-action weight (points) per row, and
 *   the existing calendar-specific fields (requires_feedback, daily_cap,
 *   back_date_limit_hours, auto_revoke_after_hours) retained where they
 *   apply. Multi-actor SPINE-2.5 role slugs (deal.listing_side,
 *   deal.selling_side, etc.) appear as distinct configurable rows so the
 *   agency sets the weight of each role separately.
 *
 * AGENCY ISOLATION
 *   Calendar entries: agency override vs system default coalesce — same
 *   pattern as M6.2 (agency_id-NULL = system default, agency_id=X =
 *   agency override). Editing creates the override; system row stays.
 *   Instant entries: the SPINE-1 seeder writes per-agency rows directly
 *   (because activity_definition_calendar_classes.agency_id is NOT NULL
 *   on the M6.2 schema), so the agency's row IS the row — editing
 *   updates it in place, no override row needed.
 *
 * DOES NOT
 *   Touch InstantPointService, the listeners, the resolver, or daily-
 *   total math. This is purely the catalogue config surface.
 */
final class ActivityCalendarMappingController extends Controller
{
    /**
     * Domain group display order. Determines section ordering in the UI.
     * Keys MUST match the values returned by groupForSlug() /
     * groupForEventClass() below.
     */
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

    /**
     * Human-readable label for each instant slug. The seeder stores
     * names like "[Auto] deal.listing_side" — that's a dev artifact, not
     * a UI label. This map turns them into agent-readable copy. New
     * slugs default to a generic prettifier (Title Cased Slug).
     */
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

    /**
     * Calendar event_class → user-facing label. Same purpose as
     * SLUG_LABEL but for the M6.3 calendar side.
     */
    private const CALENDAR_LABEL = [
        'meeting'              => 'Meeting',
        'property_evaluation'  => 'Property evaluation',
        'listing_presentation' => 'Listing presentation',
        'viewing'              => 'Property viewing',
    ];

    public function index()
    {
        $this->authorizeAccess();
        $agencyId = $this->agencyId();

        // Calendar mappings — same coalesce pattern as before: agency
        // override wins over system default.
        $calendarMappings = ActivityDefinitionCalendarClass::with('activityDefinition')
            ->forAgency($agencyId)
            ->where('trigger_kind', 'calendar')
            ->orderBy('event_class')
            ->orderBy('id')
            ->get()
            ->unique(fn ($m) => $m->event_class)  // keep first (forAgency() puts agency override first)
            ->values();

        // Instant mappings — the SPINE-1 seeder already writes per-agency
        // rows directly, so "for this agency" === "the agency's actual
        // config". Older agencies created before SPINE-1 might be missing
        // rows for newer slugs — the reseed-on-deploy contract handles
        // that, and we surface anything-missing visibly here.
        $instantMappings = ActivityDefinitionCalendarClass::with('activityDefinition')
            ->where('agency_id', $agencyId)
            ->where('trigger_kind', 'instant')
            ->orderBy('slug')
            ->get();

        // Build the domain-grouped catalogue the view renders. Each
        // row is a flat array (not an Eloquent model) so the view stays
        // simple and we can compose calendar + instant uniformly.
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
                'requires_feedback'        => null,  // N/A for instant
                'auto_revoke_after_hours'  => null,  // N/A for instant
                'back_date_limit_hours'    => null,  // N/A for instant
                'subject_type'             => $m->subject_type,
                'agency_owned'             => true,  // instant rows are always per-agency
            ];
        }

        // Sort each group's rows by label for stable, scannable display.
        foreach ($catalogue as $g => $rows) {
            usort($rows, fn ($a, $b) => strcmp($a['label'], $b['label']));
            $catalogue[$g] = $rows;
        }

        // Drop empty groups so the view doesn't render empty section
        // headers (e.g. "Marketing" when no marketing slug is seeded).
        $catalogue = array_filter($catalogue, fn ($rows) => count($rows) > 0);

        $totalActions = array_sum(array_map('count', $catalogue));

        return view('admin.activity-mappings.index', [
            'catalogue'    => $catalogue,
            'totalActions' => $totalActions,
            'agencyName'   => Auth::user()?->agency?->name,
        ]);
    }

    /**
     * Update a single mapping row. Used by inline save from the SPINE-
     * SETTINGS screen.
     *
     * Field set is intentionally lax: only `value_per_event` is required
     * — for instant rows, that's the whole edit. Calendar-only fields
     * (requires_feedback, back_date_limit_hours, daily_cap,
     * auto_revoke_after_hours) are optional and only consumed when the
     * caller includes them. The pre-SPINE-SETTINGS contract REQUIRED
     * back_date_limit_hours; SPINE-SETTINGS relaxes that so updating
     * an instant row doesn't need a fake placeholder field.
     */
    public function update(Request $request, int $id)
    {
        $this->authorizeAccess();
        $mapping = $this->findOrFail($id);

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
        // Calendar-only fields applied only if explicitly sent.
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

        return redirect()->route('admin.activity-mappings.index')
            ->with('success', 'Updated.');
    }

    public function toggleActive(int $id)
    {
        $this->authorizeAccess();
        $mapping = $this->findOrFail($id);
        $mapping->is_active = ! $mapping->is_active;
        $mapping->updated_by = Auth::id();
        $mapping->save();

        if (request()->wantsJson()) {
            return response()->json([
                'ok'        => true,
                'id'        => $mapping->id,
                'is_active' => (bool) $mapping->is_active,
            ]);
        }

        return redirect()->route('admin.activity-mappings.index')
            ->with('success', $mapping->is_active ? 'Activated.' : 'Deactivated.');
    }

    /**
     * Resolve a slug to its display domain group. New slugs that don't
     * match any prefix fall into "Other" — visible but unclassified, a
     * cue to add a mapping here when introducing a new domain.
     */
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
        // All calendar event classes live in the Calendar group — that's
        // the surface the M6.3 path scores from.
        return 'Calendar';
    }

    /**
     * Fallback when no explicit label exists in SLUG_LABEL /
     * CALENDAR_LABEL. Converts "snake.dotted_keys" into "Snake dotted
     * keys" Title Case-ish.
     */
    private function prettifyKey(string $key): string
    {
        $clean = str_replace(['.', '_'], ' ', $key);
        return ucfirst(strtolower($clean));
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
        $agencyId = $this->agencyId();
        // Agency override OR the system-default row this agency is
        // currently using — both are editable from this screen (writes
        // hit the matched row; system-row writes are NOT done — see
        // below).
        $row = ActivityDefinitionCalendarClass::query()
            ->where('id', $id)
            ->where(fn ($q) => $q->where('agency_id', $agencyId)->orWhereNull('agency_id'))
            ->whereNull('deleted_at')
            ->first();
        abort_if(! $row, 404);

        // If the matched row is a system default (agency_id IS NULL),
        // a save would mutate the system default — which is wrong.
        // Forbid that: agencies edit their own override. The SPINE-1
        // seeder writes per-agency instant rows, so this only ever
        // applies to calendar (which also seeds per-agency mappings
        // separately). Defence in depth.
        if ($row->agency_id === null) {
            abort(403, 'System default rows are not editable. Use the agency override.');
        }

        return $row;
    }
}
