<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AgentSocialAccount;
use App\Models\ContactSource;
use App\Models\ContactTag;
use App\Models\ContactType;
use App\Models\CommissionSetting;
use App\Models\Designation;
use App\Models\PropertySettingItem;
use App\Models\Docuperfect\NamedField;
use App\Models\PerformanceSetting;
use App\Models\Rental\RentalDocumentType;
use App\Models\Rental\RentalReminderSetting;
use App\Models\Branch;
use App\Models\User;
use App\Models\CommandCenter\AgencyDashboardSetting;
use App\Models\CommandCenter\AutomationRule;
use App\Models\CommandCenter\DocumentExpectation;
use App\Models\CommandCenter\UserDashboardSetting;
use App\Services\CommandCenter\NotificationPreferenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SettingsController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();

        // New hub: single ?s=section-key drives the right pane.
        // Legacy ?tab= / ?fsec= still supported and mapped forward.
        $section = $request->get('s');
        if (!$section) {
            $tab  = $request->get('tab', 'agency');
            $fsec = $request->get('fsec', 'documents');
            $section = $tab === 'feature' ? 'feature-' . $fsec : $tab;
        }

        $validSections = [
            'agency', 'user', 'system', 'notifications',
            'feature-documents', 'feature-rentals', 'feature-contacts',
            'feature-properties', 'feature-presentations', 'feature-matches', 'feature-dashboard',
            'leave-visibility', 'remote-access', 'commission', 'command-center', 'prospecting-setup',
            'outreach-templates',
        ];
        if (!in_array($section, $validSections, true)) {
            $section = 'agency';
        }

        $data = [
            'activeSection'  => $section,
            // Legacy variables kept for any partial that still references them.
            'activeTab'      => str_starts_with($section, 'feature-') ? 'feature' : $section,
            'featureSection' => str_starts_with($section, 'feature-') ? substr($section, 8) : 'documents',
        ];

        // User Settings tab: Designations
        $data['designations'] = $user?->hasPermission('manage_designations')
            ? Designation::orderBy('sort_order')->orderBy('name')->get()
            : collect([]);

        // User Settings tab: Social Media Accounts
        if ($user) {
            $data['agentSocialAccounts'] = AgentSocialAccount::where('user_id', $user->id)
                ->active()
                ->get();
            $fbAccount = $data['agentSocialAccounts']->firstWhere('platform', 'facebook');
            $data['socialAccountExpiringSoon'] = $fbAccount
                && $fbAccount->token_expires_at
                && $fbAccount->token_expires_at->lessThan(now()->addDays(7));
        }

        // Feature Settings tab: Docuperfect
        $data['namedFields'] = NamedField::orderBy('sort_order')->orderBy('name')->get();

        // Feature Settings tab: Rentals
        $data['rentalDocTypes']         = RentalDocumentType::orderBy('sort_order')->get();
        $data['rentalReminderSettings'] = RentalReminderSetting::current();

        // Feature Settings tab: Contacts — the 4 fixed parents, each with its
        // agency-scoped sub-tags eager-loaded (AT-79). Any legacy tag without a
        // parent is surfaced separately so it can be re-homed.
        $data['contactTypes']    = ContactType::parents()->with('subTags')->get()->unique('name')->values();
        $data['contactSources']  = ContactSource::orderBy('sort_order')->orderBy('name')->get();
        // Legacy tags awaiting a parent (pre-normalisation). BOUNDED: on an
        // un-normalised install EVERY tag is unassigned, so an unbounded render
        // would OOM the settings page — cap the rendered forms and surface the
        // true total so the admin knows to run `contacts:normalise-types`.
        $unassignedTagsQuery = ContactTag::whereNull('contact_type_id')
            ->orderBy('sort_order')->orderBy('name');
        $data['unassignedTagsCount'] = (clone $unassignedTagsQuery)->count();
        $data['unassignedTags']      = $unassignedTagsQuery->limit(50)->get();

        // Feature Settings: list page sizes (how many rows show per page)
        $data['contactsPerPage']   = (int) PerformanceSetting::get('contacts_per_page', 25);
        $data['propertiesPerPage'] = (int) PerformanceSetting::get('properties_per_page', 20);

        // Feature Settings tab: Properties
        $data['propCategories']     = PropertySettingItem::group('category')->get();
        $data['propTypes']          = PropertySettingItem::group('property_type')->get();
        $data['propStatuses']       = PropertySettingItem::group('property_status')->get();
        $data['propMandateTypes']   = PropertySettingItem::group('mandate_type')->get();
        // Build 3 — agency-configurable condition levels with adjustment_pct.
        $data['propConditionLevels'] = PropertySettingItem::group('condition_level')->get();

        // Feature Settings tab: Properties — marketing toggle
        $data['marketingEnabled'] = (bool) PerformanceSetting::get('marketing_enabled', 1);

        // Feature Settings tab: Properties — syndication portal availability.
        // The legacy single-site "HFC Premium" (web) portal was retired with the
        // Agency Public API; agency websites are now per-key portals managed in
        // Admin → Agencies → API Access. Spec: agency-public-api.md §6.5.
        $data['syndicationPpEnabled']      = (bool) PerformanceSetting::get('syndication_pp_enabled', 1);
        $data['syndicationP24Enabled']     = (bool) PerformanceSetting::get('syndication_p24_enabled', 1);

        // Feature Settings tab: Matches
        $data['matchesEnabled']            = (bool) PerformanceSetting::get('matches_enabled', 1);
        $data['matchesShowOnProperties']   = (bool) PerformanceSetting::get('matches_show_on_properties', 1);
        $data['matchesVisibilityScope']    = (string) PerformanceSetting::get('matches_visibility_scope', \App\Services\Matching\MatchingService::SCOPE_AGENCY);
        $defaultWaMsg = "Hi {name}! 👋\n\nI've put together a personalised selection of properties that match your search criteria.\n\nView your property matches here:\n{link}\n\nFeel free to reach out if you'd like to arrange viewings or have any questions!";
        $data['matchesWaMessage'] = (string) PerformanceSetting::get('matches_wa_message', $defaultWaMsg);

        // Agency Settings tab: Agency record + Performance Settings
        if ($user?->hasPermission('manage_performance_settings')) {
            $data['vatRate']         = (float)  PerformanceSetting::get('vat_rate', 15);
            $data['listingsPerSale'] = (float)  PerformanceSetting::get('listings_per_sale', 5);
        }

        // Agency Settings tab: Company details from Agency model
        $agencyId = $user?->effectiveAgencyId();
        $data['agency'] = $agencyId ? Agency::find($agencyId) : Agency::first();

        // Feature Settings tab: Properties — agency-wide list ordering.
        // The orderable list shows every configured status: saved priority order
        // first (dropping any status that was since removed), then any remaining
        // statuses appended so the list always stays complete.
        $allStatusNames = $data['propStatuses']->pluck('name')->filter()->values()->all();
        $savedStatusOrder = is_array($data['agency']?->properties_status_priority)
            ? $data['agency']->properties_status_priority : [];
        $orderedStatuses = array_values(array_filter($savedStatusOrder, fn ($n) => in_array($n, $allStatusNames, true)));
        foreach ($allStatusNames as $n) {
            if (!in_array($n, $orderedStatuses, true)) $orderedStatuses[] = $n;
        }
        $data['propertiesSortMode']    = $data['agency']?->properties_sort_mode ?? 'created';
        $data['propertiesStatusOrder'] = $orderedStatuses;

        // Operations tab: Commission & Revenue Share (singleton per agency)
        $data['commissionSettings'] = CommissionSetting::forAgency($user?->effectiveAgencyId() ?? 1);

        // Operations tab: Command Center rules + document expectations.
        // Gated to the same permission the mutation routes require.
        if ($user?->hasPermission('command_center.settings')) {
            $data['automationRules'] = AutomationRule::orderBy('sort_order')->get();
            $data['docExpectations'] = DocumentExpectation::orderBy('property_type')->orderBy('sort_order')->get();
        } else {
            $data['automationRules'] = collect();
            $data['docExpectations'] = collect();
        }

        // Operations tab: Prospecting Setup (towns/suburbs, property types,
        // bedroom segments, price bands, buyer-match tiers). Mirrors
        // TownsController::index so the _panel partial renders inline.
        $prospectingAgencyId = $user?->effectiveAgencyId();
        if ($user?->hasPermission('prospecting_setup.manage') && $prospectingAgencyId) {
            $prospectingConfig = app(\App\Services\Prospecting\ProspectingConfigurationService::class);
            $prospectingConfig->clearCache($prospectingAgencyId); // see latest writes after a redirect
            $data['prospectingActiveTab'] = $request->query('tab', 'towns');
            $data['towns'] = \App\Models\Prospecting\Town::withoutGlobalScopes()
                ->where('agency_id', $prospectingAgencyId)
                ->orderBy('display_order')
                ->orderBy('name')
                ->with(['suburbs' => fn ($q) => $q->withoutGlobalScopes()->orderBy('suburb_name')])
                ->get();
            $data['propertyTypes']     = $prospectingConfig->propertyTypes($prospectingAgencyId, activeOnly: false);
            $data['bedroomSegments']   = $prospectingConfig->bedroomSegments($prospectingAgencyId);
            $data['priceBandsSale']    = $prospectingConfig->priceBandsFor($prospectingAgencyId, 'sale');
            $data['priceBandsRental']  = $prospectingConfig->priceBandsFor($prospectingAgencyId, 'rental');
            $data['suggestionRegions'] = app(\App\Services\Prospecting\RegionSuggestionService::class)->regions();
            $data['unmappedSuburbs']   = $prospectingConfig->unmappedSuburbsFor($prospectingAgencyId);
            $data['buyerMatchTier']    = $prospectingConfig->buyerMatchTiers($prospectingAgencyId);
        }

        // Operations tab: Seller Outreach Templates (WhatsApp + email).
        // Mirrors TemplatesController::index so the channel panels render inline.
        if ($user?->hasPermission('outreach_templates.manage') && $prospectingAgencyId) {
            $outreachTemplate = \App\Models\SellerOutreach\SellerOutreachTemplate::class;
            $data['whatsappTemplates'] = $outreachTemplate::withoutGlobalScopes()
                ->where('agency_id', $prospectingAgencyId)
                ->where('channel', $outreachTemplate::CHANNEL_WHATSAPP)
                ->whereNull('deleted_at')
                ->orderByDesc('is_default_for_channel')
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get();
            $data['emailTemplates'] = $outreachTemplate::withoutGlobalScopes()
                ->where('agency_id', $prospectingAgencyId)
                ->where('channel', $outreachTemplate::CHANNEL_EMAIL)
                ->whereNull('deleted_at')
                ->orderByDesc('is_default_for_channel')
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get();
            $data['mergeFields'] = \App\Services\SellerOutreach\SellerOutreachTemplateValidator::KNOWN_MERGE_FIELDS;
            $outreachTab = $request->query('tab', 'whatsapp');
            $data['outreachActiveTab'] = in_array($outreachTab, ['whatsapp', 'email'], true) ? $outreachTab : 'whatsapp';
        }

        // P24 Suburb mappings are NOT loaded here anymore. The mapping table can
        // run to thousands of rows; rendering it inline in the settings hub (which
        // renders every section's HTML at once) exhausted PHP memory. The section
        // now lives on its dedicated page (admin.p24-suburbs.index), linked from
        // the settings rail.

        // Agents list for email signature preview selector
        $data['agents'] = User::agencyMembers()->where('is_active', true)->orderBy('name')->get(['id', 'name']);

        // Feature Settings tab: Dashboard — settings mode + agency dashboard settings
        $data['dashboardSettingsMode'] = $data['agency']->dashboard_settings_mode ?? 'user';
        $data['agencyDashboardSettings'] = $data['agency']
            ? AgencyDashboardSetting::firstOrNew(['agency_id' => $data['agency']->id], UserDashboardSetting::defaults())
            : new AgencyDashboardSetting(UserDashboardSetting::defaults());

        // Notifications tab snapshot
        if ($user) {
            $data['notificationSnapshot'] = app(NotificationPreferenceService::class)->snapshot($user);
        } else {
            $data['notificationSnapshot'] = null;
        }

        // Operations: Leave Visibility matrix (admin/owner only)
        if ($user && in_array($user->role, ['admin', 'owner', 'super_admin'])) {
            $agencyId = $user->effectiveAgencyId();
            $matrix = \App\Models\AgencyLeaveVisibilityMatrix::matrixForAgency($agencyId);
            $roles = \App\Models\Role::allRoles($agencyId)->pluck('name')
                ->reject(fn($r) => $r === 'super_admin')->values()->toArray();
            $aliases = ['branch_manager' => 'bm', 'bm' => 'branch_manager'];
            $grid = [];
            foreach ($roles as $vr) {
                foreach ($roles as $or) {
                    $vv = array_filter([$vr, $aliases[$vr] ?? null]);
                    $ov = array_filter([$or, $aliases[$or] ?? null]);
                    $sb = $matrix->first(fn($r) => in_array($r->viewing_role, $vv) && in_array($r->leave_owner_role, $ov) && $r->same_branch_only === true);
                    $cb = $matrix->first(fn($r) => in_array($r->viewing_role, $vv) && in_array($r->leave_owner_role, $ov) && $r->same_branch_only === false);
                    $grid[$vr][$or] = [
                        'same_branch'  => $sb ? $sb->can_see : false,
                        'cross_branch' => $cb ? $cb->can_see : false,
                    ];
                }
            }
            $data['leaveVisibilityRoles'] = $roles;
            $data['leaveVisibilityGrid']  = $grid;
        }

        return view('corex.settings', $data);
    }

    public function updateNotificationPreferences(Request $request, NotificationPreferenceService $service)
    {
        $user = auth()->user();
        abort_unless($user, 403);

        $payload = $request->validate([
            'master'                       => 'sometimes|array',
            'master.in_app'                => 'sometimes|boolean',
            'master.email'                 => 'sometimes|boolean',
            'master.push'                  => 'sometimes|boolean',
            'preferences'                  => 'sometimes|array',
            'preferences.*.key'            => 'required_with:preferences|string',
            'preferences.*.enabled'        => 'sometimes|boolean',
            'preferences.*.threshold'      => 'sometimes|nullable|integer|min:0',
            'preferences.*.channel_in_app' => 'sometimes|boolean',
            'preferences.*.channel_email'  => 'sometimes|boolean',
            'preferences.*.channel_push'   => 'sometimes|boolean',
        ]);

        $saved = $service->applyUpdates($user, $payload);

        return response()->json(['ok' => true, 'saved' => $saved]);
    }

    public function updatePortalPreferences(Request $request)
    {
        $user = auth()->user();
        abort_unless($user, 403);

        $data = $request->validate([
            'portal_show_api_token'        => 'sometimes|boolean',
            'portal_show_social_accounts'  => 'sometimes|boolean',
        ]);

        $user->forceFill([
            'portal_show_api_token'       => (bool) ($data['portal_show_api_token'] ?? false),
            'portal_show_social_accounts' => (bool) ($data['portal_show_social_accounts'] ?? false),
        ])->save();

        return redirect()->route('corex.settings', ['section' => 'my-portal'])
            ->with('success', 'My Portal preferences updated.');
    }

    // ── Property Setting Items CRUD ─────────────────────────────────────────

    public function storePropertySettingItem(Request $request)
    {
        $data = $request->validate([
            'group'          => 'required|in:category,property_type,property_status,mandate_type,condition_level',
            'name'           => 'required|string|max:100',
            'sort_order'     => 'nullable|integer|min:0',
            // title_type only meaningful on group='category'; for any other
            // group the value is silently dropped below.
            'title_type'     => 'nullable|in:full_title,sectional_title,vacant_land,other',
            // Build 3 — adjustment_pct only meaningful on
            // group='condition_level'. Range -100 exclusive < x <= 200.
            'adjustment_pct' => 'nullable|numeric|gt:-100|lte:200',
        ]);
        if (empty($data['sort_order'])) {
            $data['sort_order'] = (PropertySettingItem::where('group', $data['group'])->max('sort_order') ?? 0) + 1;
        }
        if ($data['group'] !== 'category') {
            unset($data['title_type']);
        }
        if ($data['group'] !== 'condition_level') {
            unset($data['adjustment_pct']);
        } elseif (!array_key_exists('adjustment_pct', $data) || $data['adjustment_pct'] === null) {
            $data['adjustment_pct'] = 0;
        }
        PropertySettingItem::create($data);
        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'properties'])->with('success', 'Item added.');
    }

    public function updatePropertySettingItem(Request $request, PropertySettingItem $item)
    {
        $data = $request->validate([
            'name'           => 'required|string|max:100',
            'sort_order'     => 'nullable|integer|min:0',
            // Build 1 — categories carry a title_type discipline; ignored
            // for the other groups.
            'title_type'     => 'nullable|in:full_title,sectional_title,vacant_land,other',
            // Build 3 — condition_level adjustment.
            'adjustment_pct' => 'nullable|numeric|gt:-100|lte:200',
        ]);
        if ($item->group !== 'category') {
            unset($data['title_type']);
        }
        if ($item->group !== 'condition_level') {
            unset($data['adjustment_pct']);
        } elseif ($item->name === PropertySettingItem::CONDITION_BASELINE_NAME) {
            // The baseline's pct is frozen at 0 by construction — the agency
            // may rename it but cannot move its multiplier off the baseline.
            $data['adjustment_pct'] = 0;
        }
        $item->update($data);
        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'properties'])->with('success', 'Item updated.');
    }

    public function reorderPropertySettingItems(Request $request)
    {
        $data = $request->validate([
            'items'              => 'required|array',
            'items.*.id'         => 'required|integer|exists:property_setting_items,id',
            'items.*.sort_order' => 'required|integer|min:0',
        ]);

        foreach ($data['items'] as $item) {
            PropertySettingItem::where('id', $item['id'])->update(['sort_order' => $item['sort_order']]);
        }

        return response()->json(['ok' => true]);
    }

    public function batchToggleDefaultItems(Request $request, string $group)
    {
        $allowed = ['category', 'property_type', 'property_status', 'mandate_type', 'condition_level'];
        if (! in_array($group, $allowed)) {
            return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'properties'])->with('error', 'Invalid group.');
        }

        $enabledIds = array_map('intval', $request->input('enabled_ids', []));

        PropertySettingItem::where('group', $group)
            ->where('is_default', true)
            ->get()
            ->each(function($item) use ($enabledIds, $group) {
                // Build 3 — the Average baseline cannot be disabled because
                // the valuation calc needs it as the "no adjustment" anchor.
                if ($group === 'condition_level'
                    && $item->name === PropertySettingItem::CONDITION_BASELINE_NAME) {
                    $item->update(['active' => true]);
                    return;
                }
                $item->update(['active' => in_array($item->id, $enabledIds)]);
            });

        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'properties'])->with('success', 'Updated successfully.');
    }

    public function togglePropertySettingItem(PropertySettingItem $item)
    {
        $item->update(['active' => ! $item->active]);
        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'properties'])->with('success', 'Item updated.');
    }

    public function destroyPropertySettingItem(PropertySettingItem $item)
    {
        if ($item->is_default) {
            return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'properties'])->with('error', 'Default items cannot be deleted — use the toggle to disable them instead.');
        }
        // Build 3 — defensive: even if someone marks Average as non-default,
        // a baseline-name row cannot be deleted (valuation depends on it).
        if ($item->group === 'condition_level'
            && $item->name === PropertySettingItem::CONDITION_BASELINE_NAME) {
            return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'properties'])
                ->with('error', 'The "Average" baseline condition cannot be deleted — it anchors the valuation calc.');
        }
        $item->delete();
        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'properties'])->with('success', 'Item deleted.');
    }

    public function updateMarketingEnabled(Request $request)
    {
        $enabled = $request->boolean('marketing_enabled');
        PerformanceSetting::updateOrCreate(['key' => 'marketing_enabled'], ['value' => $enabled ? 1 : 0]);
        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'properties'])->with('success', 'Marketing setting updated.');
    }

    public function updateSyndicationPortals(Request $request)
    {
        foreach (['syndication_pp_enabled', 'syndication_p24_enabled'] as $key) {
            PerformanceSetting::updateOrCreate(['key' => $key], ['value' => $request->boolean($key) ? 1 : 0]);
        }
        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'properties'])->with('success', 'Syndication portals updated.');
    }

    /**
     * Presentations V2 Phase 2 — CMA coverage thresholds + default period.
     */
    public function updatePresentations(Request $request)
    {
        $user = auth()->user();
        abort_unless($user, 403);

        $agencyId = $user->effectiveAgencyId();
        $agency   = $agencyId ? Agency::find($agencyId) : null;
        abort_unless($agency, 404, 'No agency in scope.');

        $data = $request->validate([
            'presentations_coverage_rich_threshold'     => ['required', 'integer', 'min:1', 'max:999'],
            'presentations_coverage_moderate_threshold' => ['required', 'integer', 'min:1', 'max:999'],
            'presentations_coverage_thin_threshold'     => ['required', 'integer', 'min:1', 'max:999'],
            'presentations_default_period_months'       => ['required', 'integer', 'min:1', 'max:60'],
            // Phase 3b
            'presentations_default_comp_scope'          => ['nullable', 'in:radius_all,suburb_only'],
            'presentations_default_radius_m'            => ['nullable', 'integer', 'min:50', 'max:5000'],
            // SS presentations — complex/sectional sales section toggle. Checkbox:
            // absent (unchecked) → false, handled via $request->boolean() below.
            'ss_show_complex_section'                   => ['sometimes', 'boolean'],
            // Build 5 — freshness window before the seller-facing CTA fires.
            'presentations_freshness_days'              => ['nullable', 'integer', 'min:7', 'max:365'],
            // Build 8b — CmaComputeService cleaning controls. Decoupled
            // from presentations_default_period_months so the compute
            // engine has its own knob without rippling into the hydrator
            // pool or the coverage-badge thresholds.
            'cma_compute_recency_months'                => ['nullable', 'integer', 'min:1', 'max:600'],
            'cma_compute_iqr_multiplier'                => ['nullable', 'numeric', 'min:0.5', 'max:5.0'],
            // AT-22 §0.1/§1/§1.5/§5 — comp-selection gate-then-rank + range.
            // Null on any → CompPoolBuilder service constant (locked defaults).
            'comp_price_band_pct'                       => ['nullable', 'numeric', 'min:5', 'max:100'],
            'comp_erf_band_pct'                         => ['nullable', 'numeric', 'min:5', 'max:200'],
            'comp_radius_m'                             => ['nullable', 'integer', 'min:50', 'max:10000'],
            'comp_radius_widen_steps'                   => ['nullable', 'string', 'max:120', 'regex:/^\s*\d{2,5}(\s*,\s*\d{2,5})*\s*$/'],
            'comp_radius_max_m'                         => ['nullable', 'integer', 'min:100', 'max:10000'],
            'comp_min_count'                            => ['nullable', 'integer', 'min:1', 'max:100'],
            'comp_max_count'                            => ['nullable', 'integer', 'min:1', 'max:100'],
            'anchor_divergence_pct'                     => ['nullable', 'numeric', 'min:5', 'max:100'],
            'range_lower_pct'                           => ['nullable', 'integer', 'min:1', 'max:49'],
            'range_upper_pct'                           => ['nullable', 'integer', 'min:51', 'max:99'],
            // PRES-CMA-REALFIX — recommended-band half-widths (± % around the
            // indicated value). Asymmetric market norm 10% below / 13% above;
            // 0–40 spans the full evidenced CMA range without rejecting legit
            // wider bands.
            'cma_band_lower_pct'                        => ['nullable', 'numeric', 'min:0', 'max:40'],
            'cma_band_upper_pct'                        => ['nullable', 'numeric', 'min:0', 'max:40'],
            // AT-22 item 3 — holding-cost Tier-2 agency defaults (were
            // migration-only; now agency-editable so the figures are
            // transparent + tunable, not opaque auto-fill).
            'presentations_default_opportunity_cost_pct'     => ['nullable', 'numeric', 'min:0', 'max:30'],
            'presentations_default_rates_per_million_zar'    => ['nullable', 'integer', 'min:0', 'max:100000'],
            'presentations_default_insurance_per_million_zar'=> ['nullable', 'integer', 'min:0', 'max:100000'],
            'presentations_default_utilities_zar'            => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'presentations_default_garden_zar'               => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'presentations_default_pool_zar'                 => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'presentations_default_security_zar'             => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        if (
            $data['presentations_coverage_rich_threshold'] < $data['presentations_coverage_moderate_threshold']
            || $data['presentations_coverage_moderate_threshold'] < $data['presentations_coverage_thin_threshold']
        ) {
            return redirect()
                ->route('corex.settings', ['s' => 'feature-presentations'])
                ->withErrors(['presentations_coverage_rich_threshold' => 'Thresholds must satisfy: rich ≥ moderate ≥ thin.']);
        }

        // AT-22 cross-field guards — comp min ≤ max, range lower < upper.
        if (($data['comp_min_count'] ?? 0) && ($data['comp_max_count'] ?? 0)
            && $data['comp_min_count'] > $data['comp_max_count']) {
            return redirect()
                ->route('corex.settings', ['s' => 'feature-presentations'])
                ->withErrors(['comp_min_count' => 'Minimum comps must be ≤ maximum comps.']);
        }
        if (($data['range_lower_pct'] ?? 0) && ($data['range_upper_pct'] ?? 0)
            && $data['range_lower_pct'] >= $data['range_upper_pct']) {
            return redirect()
                ->route('corex.settings', ['s' => 'feature-presentations'])
                ->withErrors(['range_lower_pct' => 'Range lower percentile must be below the upper percentile.']);
        }
        // Normalise the widen-steps CSV (strip spaces) so it round-trips clean
        // through CompPoolBuilder::parseSteps and re-renders without padding.
        if (!empty($data['comp_radius_widen_steps'])) {
            $data['comp_radius_widen_steps'] = implode(',', array_map(
                'trim',
                explode(',', $data['comp_radius_widen_steps'])
            ));
        }

        // An unchecked box must persist as false, but an ABSENT key means the
        // caller's form never rendered this field at all — the setup wizard's
        // presentations step posts only its six coverage controls. Coercing
        // unconditionally would let that step silently switch the section off.
        // Forms that own the checkbox post a hidden "0" companion, so "rendered
        // but unchecked" still arrives and still saves as false.
        if ($request->has('ss_show_complex_section')) {
            $data['ss_show_complex_section'] = $request->boolean('ss_show_complex_section');
        }

        $agency->update($data);

        return redirect()
            ->route('corex.settings', ['s' => 'feature-presentations'])
            ->with('success', 'Presentation coverage thresholds saved.');
    }

    /**
     * Build 4 — agency-default toggles for which sections render in the
     * full report by default. Per-presentation overrides happen on the
     * review screen via PresentationReviewController::toggleSection.
     */
    public function updatePresentationSections(Request $request)
    {
        $user = auth()->user();
        abort_unless($user, 403);

        $agencyId = $user->effectiveAgencyId();
        $agency   = $agencyId ? Agency::find($agencyId) : null;
        abort_unless($agency, 404, 'No agency in scope.');

        $update = [];
        foreach (\App\Models\PresentationVersion::SECTIONS_CATALOGUE as $key => $_label) {
            $col = 'presentations_default_show_' . $key;
            // Floor sections coerce to true regardless of POST payload —
            // they're rendered always, settings just surfaces them
            // visually so agents see the full report shape.
            if (in_array($key, \App\Models\PresentationVersion::SECTION_FLOOR, true)) {
                $update[$col] = true;
            } else {
                $update[$col] = $request->boolean($col);
            }
        }

        $agency->update($update);

        return redirect()
            ->route('corex.settings', ['s' => 'feature-presentations'])
            ->with('success', 'Default presentation sections saved.');
    }

    public function updateMatchesEnabled(Request $request)
    {
        $enabled = $request->boolean('matches_enabled');
        PerformanceSetting::updateOrCreate(['key' => 'matches_enabled'], ['value' => $enabled ? 1 : 0]);
        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'matches'])->with('success', 'Core Matches setting updated.');
    }

    public function updateMatchesShowOnProperties(Request $request)
    {
        $enabled = $request->boolean('matches_show_on_properties');
        PerformanceSetting::updateOrCreate(['key' => 'matches_show_on_properties'], ['value' => $enabled ? 1 : 0]);
        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'matches'])->with('success', 'Setting updated.');
    }

    public function updateContactsPerPage(Request $request)
    {
        $perPage = $request->validate([
            'contacts_per_page' => 'required|integer|min:1|max:200',
        ])['contacts_per_page'];
        PerformanceSetting::updateOrCreate(['key' => 'contacts_per_page'], ['value' => (int) $perPage]);
        return redirect()->route('corex.settings', ['s' => 'feature-contacts'])->with('success', 'Contacts per page updated.');
    }

    public function updatePropertiesPerPage(Request $request)
    {
        $perPage = $request->validate([
            'properties_per_page' => 'required|integer|min:1|max:200',
        ])['properties_per_page'];
        PerformanceSetting::updateOrCreate(['key' => 'properties_per_page'], ['value' => (int) $perPage]);
        return redirect()->route('corex.settings', ['s' => 'feature-properties'])->with('success', 'Properties per page updated.');
    }

    /**
     * Agency-wide default ordering for the Properties list:
     *   - 'created'         → newest first (default).
     *   - 'status_priority' → order by the admin-defined status sequence, then
     *                         newest within each status.
     */
    public function updatePropertiesSort(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('access_settings'), 403);

        $data = $request->validate([
            'properties_sort_mode'     => 'required|in:created,status_priority',
            'properties_status_order'  => 'nullable|string',   // comma-separated status names
        ]);

        $agency = Agency::findOrFail(auth()->user()->effectiveAgencyId());

        $order = [];
        if (!empty($data['properties_status_order'])) {
            // Keep only real, currently-configured statuses; preserve given order.
            $valid = PropertySettingItem::group('property_status')->pluck('name')->all();
            foreach (explode(',', $data['properties_status_order']) as $name) {
                $name = trim($name);
                if ($name !== '' && in_array($name, $valid, true) && !in_array($name, $order, true)) {
                    $order[] = $name;
                }
            }
        }

        $agency->properties_sort_mode = $data['properties_sort_mode'];
        $agency->properties_status_priority = $order;
        $agency->save();

        return redirect()->route('corex.settings', ['s' => 'feature-properties'])
            ->with('success', 'Properties ordering updated.');
    }

    public function updateMatchesVisibilityScope(Request $request)
    {
        $scope = $request->validate([
            'matches_visibility_scope' => 'required|in:agent,branch,agency',
        ])['matches_visibility_scope'];
        PerformanceSetting::updateOrCreate(['key' => 'matches_visibility_scope'], ['value' => $scope]);
        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'matches'])->with('success', 'Match visibility scope updated.');
    }

    public function updateMatchesWaMessage(Request $request)
    {
        $message = substr($request->input('matches_wa_message', ''), 0, 1000);
        PerformanceSetting::updateOrCreate(['key' => 'matches_wa_message'], ['value' => $message]);
        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'matches'])->with('success', 'WhatsApp message template saved.');
    }

    public function generateApiToken(Request $request)
    {
        $user = $request->user();

        // Revoke all existing Sanctum tokens
        $user->tokens()->delete();

        // Create new Sanctum token
        $token = $user->createToken('corex-extension');

        // Store hash in api_token column for backwards compatibility
        $user->api_token = hash('sha256', explode('|', $token->plainTextToken)[1]);
        $user->save();

        return response()->json(['token' => $token->plainTextToken]);
    }

    // ── Agency Company Settings ───────────────────────────────────────────

    public function updateAgency(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('manage_performance_settings'), 403);

        $agencyId = auth()->user()->effectiveAgencyId();
        $agency = $agencyId ? Agency::findOrFail($agencyId) : Agency::firstOrFail();

        $data = $request->validate([
            'trading_name'     => ['nullable', 'string', 'max:255'],
            'tagline'          => ['nullable', 'string', 'max:255'],
            'address'          => ['nullable', 'string', 'max:500'],
            'phone'                  => ['nullable', 'string', 'max:255'],
            'phone_label'            => ['nullable', 'string', 'max:100'],
            'phone_secondary'        => ['nullable', 'string', 'max:255'],
            'phone_secondary_label'  => ['nullable', 'string', 'max:100'],
            'fax'              => ['nullable', 'string', 'max:255'],
            'email'            => ['nullable', 'string', 'max:255'],
            'reg_no'           => ['nullable', 'string', 'max:255'],
            'vat_no'           => ['nullable', 'string', 'max:255'],
            'ffc_no'           => ['nullable', 'string', 'max:255'],
            'ppra_number'      => ['nullable', 'string', 'max:32'],
            'fic_no'           => ['nullable', 'string', 'max:255'],
            'email_disclaimer' => ['nullable', 'string', 'max:2000'],
            'popi_url'         => ['nullable', 'string', 'max:500'],
            'logo'             => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_logo'      => ['nullable', 'boolean'],
        ]);

        $removeLogo = $data['remove_logo'] ?? false;
        unset($data['logo'], $data['remove_logo']);

        if ($removeLogo) {
            if ($agency->logo_path) {
                Storage::disk('public')->delete($agency->logo_path);
            }
            $data['logo_path'] = null;
        } elseif ($request->hasFile('logo')) {
            if ($agency->logo_path) {
                Storage::disk('public')->delete($agency->logo_path);
            }
            $ext = $request->file('logo')->getClientOriginalExtension();
            $path = $request->file('logo')->storeAs(
                "agencies/{$agency->id}", "logo.{$ext}", 'public'
            );
            $data['logo_path'] = $path;
        }

        $agency->update($data);

        return redirect()->back()->with('success', 'Company settings updated.');
    }

    // ── Live Previews ─────────────────────────────────────────────────

    /**
     * Render document header preview with query param overrides.
     */
    public function previewHeader(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('manage_performance_settings'), 403);

        $agency = Agency::where('slug', 'hfc-coastal')->first();

        // Build a temporary agency-like object with query param overrides
        $overrides = $request->only([
            'trading_name', 'tagline', 'address', 'phone', 'phone_label',
            'phone_secondary', 'phone_secondary_label', 'fax', 'email',
            'reg_no', 'vat_no', 'ffc_no', 'ppra_number', 'fic_no', 'name',
        ]);

        // Create a clone with overrides applied
        $previewAgency = clone $agency;
        foreach ($overrides as $key => $value) {
            if ($value !== null && $value !== '') {
                $previewAgency->{$key} = $value;
            }
        }

        // Build a minimal branch (null — will use agency fallback)
        $branch = null;

        // Determine logo URL
        $logoUrl = $previewAgency->logo_path
            ? asset('storage/' . $previewAgency->logo_path)
            : null;

        $html = view('docuperfect.web-templates.components.company-header', [
            'previewAgency'  => $previewAgency,
            'header_display' => 'all_pages',
            'logo_url'       => $logoUrl,
        ])->render();

        // Wrap in a minimal HTML shell so the iframe renders cleanly
        $shell = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
            . 'body { font-family: Arial, sans-serif; margin: 8px; }'
            . '</style></head><body>' . $html . '</body></html>';

        return response($shell)->header('Content-Type', 'text/html');
    }

    /**
     * Render email signature preview for a given user.
     */
    public function previewSignature(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('manage_performance_settings'), 403);

        $userId = $request->input('user_id');
        $agent = $userId ? User::find($userId) : auth()->user();

        $agency = Agency::where('slug', 'hfc-coastal')->first();

        $agentFooter = [
            'name'             => $agent->name ?? 'Agent Name',
            'email'            => $agent->email ?? '',
            'phone'            => $agent->phone ?? null,
            'designation'      => $agent->designation ?? null,
            'cell'             => $agent->cell ?? null,
            'fax'              => $agent->fax ?? null,
            'ffc_number'       => $agent->ffc_number ?? null,
            'website'          => $agent->website ?? null,
            'agent_photo_url'  => $agent->agent_photo_path ? asset('storage/' . $agent->agent_photo_path) : null,
            'logo_url'         => $agency && $agency->logo_path ? asset('storage/' . $agency->logo_path) : null,
            'email_disclaimer' => $request->input('email_disclaimer', $agency->email_disclaimer ?? null),
            'popi_url'         => $request->input('popi_url', $agency->popi_url ?? null),
            'agency_name'      => $agency->name ?? 'Home Finders Coastal',
        ];

        $html = view('emails.signatures.partials.agent-footer', compact('agentFooter'))->render();

        $shell = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
            . 'body { font-family: Arial, Helvetica, sans-serif; margin: 8px; font-size: 13px; color: #333; }'
            . '</style></head><body>' . $html . '</body></html>';

        return response($shell)->header('Content-Type', 'text/html');
    }

    // ── Dashboard Settings Mode ────────────────────────────────────────

    public function updateDashboardMode(Request $request)
    {
        $request->validate([
            'dashboard_settings_mode' => 'required|in:user,agency',
        ]);

        $user     = auth()->user();
        $agencyId = $user?->effectiveAgencyId();
        $agency   = $agencyId ? Agency::find($agencyId) : Agency::first();

        if (!$agency) {
            return back()->with('error', 'No agency found.');
        }

        $agency->update(['dashboard_settings_mode' => $request->dashboard_settings_mode]);

        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'dashboard'])
            ->with('success', 'Dashboard settings mode updated to "' . ucfirst($request->dashboard_settings_mode) . '".');
    }

    // ── Split Branches toggle ──────────────────────────────────────────

    public function updateSplitBranches(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('manage_performance_settings'), 403);

        $request->validate([
            'split_branches_enabled' => ['nullable', 'boolean'],
        ]);

        $user     = auth()->user();
        $agencyId = $user?->effectiveAgencyId();
        $agency   = $agencyId ? Agency::find($agencyId) : Agency::first();

        if (!$agency) {
            return back()->with('error', 'No agency found.');
        }

        $agency->update([
            'split_branches_enabled' => $request->boolean('split_branches_enabled'),
        ]);

        $state = $agency->split_branches_enabled ? 'ON' : 'OFF';
        return redirect()->route('corex.settings', ['tab' => 'agency'])
            ->with('success', "Split Branches turned {$state}.");
    }

    public function updateAgencyDashboardSettings(Request $request)
    {
        $user     = auth()->user();
        $agencyId = $user?->effectiveAgencyId();
        $agency   = $agencyId ? Agency::find($agencyId) : Agency::first();

        if (!$agency) {
            return back()->with('error', 'No agency found.');
        }

        $boolFields = [
            'idle_alerts_enabled', 'doc_reminders_enabled', 'lease_expiry_reminders',
            'fica_reminders', 'ffc_reminders', 'task_due_reminders', 'overdue_daily_digest',
            'weekend_visible', 'notify_in_app', 'notify_email', 'notify_push',
            'open_hours_enabled',
        ];

        $data = $request->only([
            'idle_alerts_enabled', 'idle_threshold_days', 'idle_alert_day', 'idle_alert_time',
            'doc_reminders_enabled', 'lease_expiry_reminders', 'fica_reminders', 'ffc_reminders',
            'task_due_reminders', 'overdue_daily_digest', 'digest_time',
            'default_calendar_view', 'weekend_visible', 'notify_in_app', 'notify_email',
            'notify_push', 'open_hours_enabled', 'open_hours_start', 'open_hours_end',
            'min_minutes_between_same',
        ]);

        foreach (['open_hours_start', 'open_hours_end'] as $t) {
            if (!empty($data[$t])) $data[$t] = substr($data[$t], 0, 5);
        }
        if (isset($data['min_minutes_between_same'])) {
            $data['min_minutes_between_same'] = max(0, (int) $data['min_minutes_between_same']);
        }

        // Only coerce a checkbox the caller's form actually rendered. Forms that
        // own a toggle post a hidden "0" companion, so a rendered-but-unchecked
        // box still arrives and still saves as false. An ABSENT key means the
        // form never showed the field — the setup wizard's notifications step
        // renders 10 of these 12, omitting weekend_visible and
        // open_hours_enabled — so leave it alone rather than switch it off.
        foreach ($boolFields as $bf) {
            if ($request->has($bf)) {
                $data[$bf] = $request->boolean($bf);
            }
        }

        AgencyDashboardSetting::updateOrCreate(
            ['agency_id' => $agency->id],
            $data
        );

        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'dashboard'])
            ->with('success', 'Agency dashboard settings saved.');
    }

    /**
     * Toggle the agency-level "Require system owner consent for remote access" flag.
     * See .ai/specs/agency-access-authorization-spec.md.
     */
    public function updateRemoteAccess(Request $request)
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('agency.manage_access_authorization'), 403);

        $data = $request->validate([
            'require_external_access_authorization' => 'nullable|boolean',
        ]);
        $newValue = (bool) ($data['require_external_access_authorization'] ?? false);

        $agencyId = $user->effectiveAgencyId();
        $agency = Agency::findOrFail($agencyId);
        $oldValue = (bool) $agency->require_external_access_authorization;

        if ($oldValue !== $newValue) {
            $agency->update(['require_external_access_authorization' => $newValue]);
            \Illuminate\Support\Facades\Log::info('agency_access_auth_flag_toggled', [
                'agency_id' => $agency->id,
                'old'       => $oldValue,
                'new'       => $newValue,
                'by'        => $user->id,
            ]);
        }

        return redirect()->route('corex.settings', ['s' => 'remote-access'])
            ->with('success', $newValue
                ? 'Remote access authorization is now ON. System owners must request consent to switch in.'
                : 'Remote access authorization is now OFF. System owners can switch in directly.');
    }

    /**
     * Save whistleblower compliance reporting settings.
     */
    public function saveWhistleblowSettings(Request $request)
    {
        $request->validate([
            'whistleblow_approver_user_ids'        => 'nullable|array',
            'whistleblow_approver_user_ids.*'      => 'integer|exists:users,id',
            'whistleblow_compliance_officer_email'  => 'nullable|email|max:255',
            'tier_recipients'                       => 'nullable|array',
            'tier_recipients.tier_1'                => 'nullable|string',
            'tier_recipients.tier_2'                => 'nullable|string',
            'tier_recipients.tier_3'                => 'nullable|string',
        ]);

        $agency = \App\Models\Agency::withoutGlobalScopes()->find(auth()->user()->agency_id);
        if (!$agency) {
            return redirect()->back()->with('error', 'Agency not found.');
        }

        $approverIds = $request->input('whistleblow_approver_user_ids', []);

        // Parse tier recipients from textareas (one email per line)
        $tierRecipients = [];
        foreach (['tier_1', 'tier_2', 'tier_3'] as $tier) {
            $raw = $request->input("tier_recipients.{$tier}", '');
            $emails = array_filter(array_map('trim', preg_split('/[\r\n]+/', $raw)));
            $tierRecipients[$tier] = array_values($emails);
        }

        $agency->update([
            'whistleblow_approver_user_ids'        => !empty($approverIds) ? array_map('intval', $approverIds) : null,
            'whistleblow_compliance_officer_email'  => $request->input('whistleblow_compliance_officer_email'),
            'whistleblow_tier_recipients'           => !empty(array_filter($tierRecipients, fn($a) => !empty($a))) ? $tierRecipients : null,
        ]);

        \Illuminate\Support\Facades\Log::info('Whistleblow settings updated', [
            'user_id'   => auth()->id(),
            'agency_id' => $agency->id,
            'approvers' => $approverIds,
            'co_email'  => $request->input('whistleblow_compliance_officer_email'),
            'tier_recipients' => $tierRecipients,
        ]);

        return redirect()->route('corex.settings', ['s' => 'whistleblow-settings'])
            ->with('success', 'Compliance reporting settings saved.');
    }
}
