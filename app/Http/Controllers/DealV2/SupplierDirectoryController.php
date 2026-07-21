<?php

namespace App\Http\Controllers\DealV2;

use App\Http\Controllers\Controller;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceProviderContact;
use App\Models\DealV2\AgencyServiceProviderServiceType;
use App\Models\DealV2\AgencyServiceType;
use App\Models\DealV2\DealV2;
use App\Models\Scopes\AgencyScope;
use App\Services\DealV2\AgencyServiceProviderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WS2 (AT-158 / DR2, D2) — the agency preferred-supplier directory: a settings
 * CRUD screen plus the pick-or-create-inline JSON endpoints the deal form uses.
 */
class SupplierDirectoryController extends Controller
{
    private const SPECIALTIES = [
        'electrician', 'entomologist', 'plumber', 'gas', 'electric_fence',
        'transfer_attorney', 'bond_attorney', 'conveyancer', 'bond_originator', 'other',
    ];

    /** deal_v2_contacts provider roles (the roles a provider can fill on a deal). */
    private const PROVIDER_ROLES = [
        'transfer_attorney', 'bond_attorney', 'electrician_coc', 'entomologist', 'originator', 'service_provider', 'conveyancer', 'bond_originator',
    ];

    public function __construct(private AgencyServiceProviderService $service)
    {
    }

    public function index(Request $request)
    {
        $agencyId = (int) ($request->user()?->effectiveAgencyId() ?? 0);
        $providers = AgencyServiceProvider::query()->withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->with([
                'serviceContacts' => fn ($q) => $q->orderByDesc('is_active')->orderBy('attorney_name'),
                'serviceTypes',
            ])
            ->orderByDesc('is_active')->orderBy('specialty')->orderByDesc('is_preferred')->orderBy('name')
            ->get();

        // AT-319 — the agency's active, configurable service types drive the per-supplier tick boxes.
        // Resolve the SAME way Settings → COC/Service Types does (AgencyScope / acting-agency session
        // switcher) so the tick list is guaranteed identical to what the agency configured — for every
        // user, including an un-switched owner. (The old `effectiveAgencyId() ?? 0` resolved to agency 0
        // for owner/no-agency users → empty list → the ticks looked hard-coded AND a save validated
        // against an empty set and wiped them.)
        $serviceTypes = AgencyServiceType::active()
            ->orderBy('sort_order')->orderBy('id')->get(['code', 'label']);

        return view('deals-v2.suppliers.index', [
            'providers' => $providers,
            'specialties' => self::SPECIALTIES,
            'serviceTypes' => $serviceTypes,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $agencyId = (int) $request->user()?->effectiveAgencyId();
        $provider = $this->service->findOrCreate($agencyId, $data, $request->user()->id);
        // AT-319 — capture the supplier's type(s) alongside the legacy single specialty.
        $this->syncServiceTypes($provider, $this->postedTypeCodes($request, $agencyId));

        return back()->with('success', 'Provider saved to the directory.');
    }

    /**
     * AT-319 — the "edit" for a supplier's types: re-sync the multi-select. Dedicated route
     * (same idiom as preferred/deactivate) so it never trips update()'s required-field rules.
     * Un-ticked types are soft-deleted (restore-or-create on re-add); an empty set is valid.
     */
    public function syncTypes(Request $request, AgencyServiceProvider $provider)
    {
        $this->authorizeAgency($request, $provider);

        $codes = $this->postedTypeCodes($request, (int) $provider->agency_id);
        // "No silent drop": preserve any code this supplier already holds that is no longer an
        // ACTIVE type (shown "(archived)"). The tick UI only manages active codes; archived-tagged
        // codes ride through every save untouched until the type is restored/managed in Settings.
        $activeCodes = AgencyServiceType::withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', (int) $provider->agency_id)->where('is_active', true)->pluck('code')->all();
        $archivedTagged = array_values(array_diff($provider->typeCodes(), $activeCodes));
        $codes = array_values(array_unique(array_merge($codes, $archivedTagged)));

        $this->syncServiceTypes($provider, $codes);

        // 3b — persist-on-toggle: the tick boxes auto-save each change via AJAX (no manual button).
        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'codes' => $provider->fresh()->typeCodes()]);
        }
        return back()->with('success', "Updated the service types for {$provider->name}.");
    }

    public function update(Request $request, AgencyServiceProvider $provider)
    {
        $this->authorizeAgency($request, $provider);
        $provider->update($this->validated($request));

        return back()->with('success', 'Provider updated.');
    }

    public function markPreferred(Request $request, AgencyServiceProvider $provider)
    {
        $this->authorizeAgency($request, $provider);
        $this->service->markPreferred($provider);

        return back()->with('success', "Marked {$provider->name} as the preferred {$provider->specialty}.");
    }

    public function deactivate(Request $request, AgencyServiceProvider $provider)
    {
        $this->authorizeAgency($request, $provider);
        $this->service->deactivate($provider);

        return back()->with('success', "{$provider->name} deactivated — hidden from new pickers, historic deals unaffected.");
    }

    // ── inline (JSON) — used by the deal form ────────────────────────────

    public function search(Request $request): JsonResponse
    {
        $agencyId = (int) ($request->user()?->effectiveAgencyId() ?? 0);
        $results = $this->service->search($agencyId, $request->query('specialty'), $request->query('q'));

        return response()->json(['results' => $results->map(fn ($p) => $this->row($p))->values()]);
    }

    public function createInline(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $provider = $this->service->findOrCreate((int) $request->user()?->effectiveAgencyId(), $data, $request->user()->id);

        // Item 2 — tag the new supplier with the posted AgencyServiceType code(s) so it appears in
        // the type-filtered work-order picker straight away. (`specialty` — which attorney matching
        // and dedup key off — is set by validated() and untouched here.)
        $codes = $this->postedTypeCodes($request, (int) $provider->agency_id);
        if (! empty($codes)) {
            $this->syncServiceTypes($provider, $codes);
        }

        return response()->json(['provider' => $this->row($provider->fresh())], 201);
    }

    /** Attach a directory provider to a deal under a provider role. */
    public function attach(Request $request, DealV2 $deal): JsonResponse
    {
        $validated = $request->validate([
            'provider_id' => 'required|integer',
            'role' => 'required|string|in:' . implode(',', self::PROVIDER_ROLES),
        ]);
        $provider = AgencyServiceProvider::query()->withoutGlobalScopes()
            ->where('agency_id', $deal->agency_id ?? $request->user()?->effectiveAgencyId())
            ->findOrFail($validated['provider_id']);

        $this->service->attachToDeal($deal, $provider, $validated['role']);

        return response()->json(['attached' => true, 'provider' => $this->row($provider), 'role' => $validated['role']]);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:191',
            'specialty' => 'required|string|in:' . implode(',', self::SPECIALTIES),
            'company' => 'nullable|string|max:191',
            'email' => 'nullable|email|max:191',
            'phone' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
            'is_preferred' => 'sometimes|boolean',
            'contact_id' => 'nullable|integer',
        ]);
    }

    /**
     * AT-319 — the posted service_types, kept only where they are a REAL active AgencyServiceType
     * code for this agency (the checkboxes only offer valid codes; anything else is absorbed/dropped,
     * never a 500). Optional: an empty/absent set is a legitimate types-less supplier.
     */
    private function postedTypeCodes(Request $request, int $agencyId): array
    {
        $posted = array_filter(array_map('strval', (array) $request->input('service_types', [])), fn ($c) => $c !== '');
        if (empty($posted)) {
            return [];
        }
        // Validate against the agency's real ACTIVE, non-archived types — via the provider's
        // authoritative agency_id, bypassing ONLY AgencyScope (SoftDeletes kept, so an archived
        // type is never treated as valid).
        $valid = AgencyServiceType::withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)->where('is_active', true)->pluck('code')->all();

        return array_values(array_unique(array_intersect($posted, $valid)));
    }

    /**
     * AT-319 — reconcile a supplier's type rows to $codes: soft-delete de-selected ones, restore or
     * create the selected ones (no hard deletes, no duplicates). Agency-scoped, idempotent.
     */
    private function syncServiceTypes(AgencyServiceProvider $provider, array $codes): void
    {
        $existing = AgencyServiceProviderServiceType::query()->withTrashed()->withoutGlobalScopes()
            ->where('service_provider_id', $provider->id)->get();

        foreach ($existing as $row) {
            if (! in_array($row->service_type, $codes, true) && ! $row->trashed()) {
                $row->delete(); // de-selected → soft-delete (history preserved)
            }
        }
        foreach ($codes as $code) {
            $row = $existing->firstWhere('service_type', $code);
            if ($row) {
                if ($row->trashed()) {
                    $row->restore();
                }
            } else {
                AgencyServiceProviderServiceType::query()->withoutGlobalScopes()->create([
                    'agency_id'           => (int) $provider->agency_id,
                    'service_provider_id' => $provider->id,
                    'service_type'        => $code,
                ]);
            }
        }
    }

    /**
     * (DR2 respec) Add a CONTACT PERSON under a firm — attorney + working contact
     * (assistant/paralegal) + email/phone. Agency-scoped; soft-deleted on remove so
     * historic deal references keep resolving.
     */
    public function storeContact(Request $request, AgencyServiceProvider $provider)
    {
        $this->authorizeAgency($request, $provider);

        $data = $request->validate([
            'attorney_name'  => 'nullable|string|max:191',
            'contact_person' => 'nullable|string|max:191',
            'role'           => 'nullable|string|max:100',
            'email'          => 'nullable|email|max:191',
            'phone'          => 'nullable|string|max:50',
        ]);

        if (empty($data['attorney_name']) && empty($data['contact_person'])) {
            return back()->withErrors('A contact needs at least an attorney or a contact person.');
        }

        AgencyServiceProviderContact::create(array_merge($data, [
            'agency_id'           => (int) $provider->agency_id,
            'service_provider_id' => $provider->id,
            'is_active'           => true,
            'created_by_id'       => $request->user()->id,
        ]));

        return back()->with('success', 'Contact added to ' . $provider->name . '.');
    }

    /** Soft-delete a firm contact (deactivate). Historic deals keep resolving. */
    public function deactivateContact(Request $request, AgencyServiceProviderContact $contact)
    {
        abort_unless((int) $contact->agency_id === (int) $request->user()?->effectiveAgencyId(), 403);
        $contact->update(['is_active' => false]);
        $contact->delete();

        return back()->with('success', 'Contact removed.');
    }

    private function authorizeAgency(Request $request, AgencyServiceProvider $provider): void
    {
        abort_unless((int) $provider->agency_id === (int) $request->user()?->effectiveAgencyId(), 403);
    }

    private function row(AgencyServiceProvider $p): array
    {
        return [
            'id' => $p->id, 'name' => $p->name, 'specialty' => $p->specialty,
            'company' => $p->company, 'email' => $p->email, 'phone' => $p->phone,
            'is_preferred' => (bool) $p->is_preferred, 'is_active' => (bool) $p->is_active,
            // AT-319 — the AgencyServiceType codes this supplier handles (drives the picker filter).
            'types' => $p->typeCodes(),
        ];
    }
}
