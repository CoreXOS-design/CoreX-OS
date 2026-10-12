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
    // Public so the DR2 pipeline's inline "＋ Add supplier" (WorkOrderController::cocConfigPanel)
    // offers the SAME supplier-type list as this directory add-form — incl. the attorney types.
    public const SPECIALTIES = [
        'electrician', 'entomologist', 'plumber', 'gas', 'electric_fence',
        'transfer_attorney', 'bond_attorney', 'conveyancer', 'bond_originator', 'external_agency', 'other',
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

        $events = \Illuminate\Support\Facades\Schema::hasTable('agency_service_provider_events')
            ? \Illuminate\Support\Facades\DB::table('agency_service_provider_events as e')->leftJoin('users as u', 'u.id', '=', 'e.user_id')
                ->where('e.agency_id', $agencyId)->whereIn('e.service_provider_id', $providers->pluck('id'))->orderByDesc('e.id')->limit(300)
                ->get(['e.service_provider_id', 'e.note', 'e.created_at', 'u.name as by'])->groupBy('service_provider_id')
            : collect();

        return view('deals-v2.suppliers.index', [
            'providerEvents' => $events,
            'providers' => $providers,
            'specialties' => self::SPECIALTIES,
            'serviceTypes' => $serviceTypes,
            'returnFault' => $this->returnFault($request, $agencyId),
        ]);
    }

    /**
     * AT-452 item 9 (rentals-faults-work-orders.md §18.6) - the fault page's "Add a contractor" / "Add <type> to an existing contractor" open this
     * screen with ?fault=<id>&mode=add|types. This only works out the way BACK (and whether the fault is covered now); nothing here changes the
     * directory's own forms. A fault the user cannot view, or one of another agency, is simply not offered.
     *
     * @return array{id:int, url:string, type:string, category:?string, mode:string, covered:bool, trade_code:?string}|null
     */
    private function returnFault(Request $request, int $agencyId): ?array
    {
        $user = $request->user();
        $mode = $request->query('mode') === 'types' ? 'types' : 'add';
        $contractors = app(\App\Services\Rentals\RentalFaultContractorService::class);

        // AT-452 round 2 - the work order's own supplier card sends the agent here to add a contractor's email / mobile (?work_order=&provider=)
        $woId = (int) $request->query('work_order', 0);
        if ($woId > 0 && $user && $user->hasPermission('rental_work_orders.view')) {
            $wo = \App\Models\RentalWorkOrder::withoutGlobalScopes()->with('reportedFaultReport.faultType')->where('agency_id', $agencyId)->whereNull('deleted_at')->find($woId);
            if ($wo) {
                $type = $wo->reportedFaultReport?->faultType;
                $class = $contractors->classifyFor($agencyId, $type?->name, $type?->category);
                $provider = (int) $request->query('provider', 0) ?: null;

                return [
                    'id' => $wo->id, 'label' => 'work order #' . $wo->id, 'url' => route('corex.rental-work-orders.show', $wo), 'type' => $type?->name ?: 'this job',
                    'category' => $type?->category, 'mode' => $provider ? 'details' : $mode, 'covered' => $class['suits']->isNotEmpty(),
                    'trade_code' => $type?->category ? ($contractors->classifyTradeCode($agencyId, $type->category)) : null, 'provider' => $provider,
                ];
            }
        }

        $id = (int) $request->query('fault', 0);
        if ($id <= 0 || ! $user || ! $user->hasPermission('rental_fault_reports.view')) {
            return null;
        }
        $fault = \App\Models\RentalFaultReport::withoutGlobalScopes()->with('faultType')->where('agency_id', $agencyId)->whereNull('deleted_at')->find($id);
        if (! $fault) {
            return null;
        }

        return [
            'id' => $fault->id,
            'label' => 'fault report #' . $fault->id,
            'url' => route('corex.rental-fault-reports.show', $fault),
            'type' => $fault->faultType?->name ?: 'this fault',
            'category' => $fault->faultType?->category,
            'mode' => $mode,
            'covered' => $contractors->hasContractorFor($fault),
            'trade_code' => $contractors->tradeCodeFor($fault),
            'provider' => null,
        ];
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $agencyId = (int) $request->user()?->effectiveAgencyId();
        $provider = $this->service->findOrCreate($agencyId, $data, $request->user()->id);
        // The directory's own match-or-create also finds contractors that were ARCHIVED earlier (same specialty and email, or name) and leaves
        // them archived. In the popup that would say "added" while nothing on the fault screen changes, so say what really happened instead.
        if ($request->wantsJson() && $provider->trashed()) {
            return response()->json([
                'ok' => false,
                'message' => $provider->name . ' is already in your contractor list but has been archived. Restore them from the contractor list, then add the field of work there.',
            ], 422);
        }
        // AT-319 — capture the supplier's type(s) alongside the legacy single specialty.
        $this->syncServiceTypes($provider, $this->postedTypeCodes($request, $agencyId));

        // AT-452 round 2 (H) - the fault / work order screens open this same form in a popup and save it over the page: JSON in, JSON out, same
        // validation and the same write as the directory's own page. The contractor's history notes where they were added from.
        [$fault, $workOrder] = $this->contextOf($request, $agencyId);
        if ($fault || $workOrder) {
            $this->recordEvent($provider, 'created_from_fault', 'Added from ' . ($fault ? 'fault report #' . $fault->id : 'work order #' . $workOrder->id) . ' by ' . $request->user()->name . '.', $request->user(), $fault?->id, $workOrder?->id);
        }
        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'provider' => ['id' => $provider->id, 'name' => $provider->name]] + $this->coverage($fault, $workOrder, $agencyId));
        }

        return back()->with('success', 'Provider saved to the directory.');
    }

    /**
     * AT-452 round 2 (H) - the "Add <type> to an existing contractor" popup: every active maintenance contractor of the agency with the fields of
     * work they have now, whether they already have this one, and whether the agency's trade list HAS a trade for the type at all.
     */
    public function tradePicker(Request $request)
    {
        $agencyId = (int) ($request->user()?->effectiveAgencyId() ?? 0);
        [$fault, $workOrder] = $this->contextOf($request, $agencyId);
        [$typeName, $category] = $this->typeOf($fault, $workOrder);
        $contractors = app(\App\Services\Rentals\RentalFaultContractorService::class);
        $code = $category ? $contractors->classifyTradeCode($agencyId, $category) : null;
        $labels = AgencyServiceType::withoutGlobalScopes()->where('agency_id', $agencyId)->pluck('label', 'code');

        $providers = AgencyServiceProvider::withoutGlobalScopes()->where('agency_id', $agencyId)->where('is_active', true)->whereNull('deleted_at')
            ->maintenanceContractors()->pickerOrder()->with('serviceTypes')->get()
            ->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->name,
                'trades' => collect($p->typeCodes())->map(fn ($t) => $labels[$t] ?? $t)->values()->all(),
                'has_trade' => $code !== null && in_array($code, $p->typeCodes(), true),
            ])->values();

        return response()->json([
            'type' => $typeName, 'category' => $category, 'code' => $code,
            'can_create_trade' => (bool) $request->user()->hasPermission('deals_v2.manage_pipeline'),
            'contractors' => $providers,
        ]);
    }

    /**
     * AT-452 round 2 (H) - the popup's Save: add the fault's type (its field of work) to the ticked contractors. If the agency's trade list has no
     * trade for it yet, `create_trade` adds one (only for someone who may manage the trade list). Written to each contractor's history: who, when,
     * from which fault.
     */
    public function addTrade(Request $request)
    {
        $agencyId = (int) ($request->user()?->effectiveAgencyId() ?? 0);
        $data = $request->validate(['provider_ids' => 'required|array|min:1', 'provider_ids.*' => 'integer', 'create_trade' => 'sometimes|boolean']);
        [$fault, $workOrder] = $this->contextOf($request, $agencyId);
        [$typeName, $category] = $this->typeOf($fault, $workOrder);
        abort_unless($category, 422, 'This job has no type of work to add.');

        $contractors = app(\App\Services\Rentals\RentalFaultContractorService::class);
        $code = $contractors->classifyTradeCode($agencyId, $category);
        if ($code === null) {
            if (empty($data['create_trade'])) {
                return response()->json(['message' => 'Your trade list has no "' . $category . '" yet. Add it first, or ask someone who manages the trade list.'], 422);
            }
            abort_unless($request->user()->hasPermission('deals_v2.manage_pipeline'), 403, 'Only someone who manages the trade list can add a new trade.');
            $type = AgencyServiceType::withoutGlobalScopes()->firstOrCreate(
                ['agency_id' => $agencyId, 'code' => $category],
                ['label' => $category, 'sort_order' => (int) AgencyServiceType::withoutGlobalScopes()->where('agency_id', $agencyId)->max('sort_order') + 1, 'is_active' => true],
            );
            $code = $type->code;
        }

        $added = [];
        foreach (AgencyServiceProvider::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->maintenanceContractors()->whereIn('id', $data['provider_ids'])->get() as $p) {
            if (in_array($code, $p->typeCodes(), true)) {
                continue;
            }
            $this->syncServiceTypes($p, array_values(array_unique(array_merge($p->typeCodes(), [$code]))));
            $this->recordEvent($p, 'trade_added', $typeName . ' (' . $category . ') added as a field of work by ' . $request->user()->name . ' from ' . ($fault ? 'fault report #' . $fault->id : 'work order #' . $workOrder->id) . '.', $request->user(), $fault?->id, $workOrder?->id);
            $added[] = $p->name;
        }

        return response()->json(['ok' => true, 'added' => $added, 'message' => $added ? ($typeName . ' added to ' . implode(', ', $added)) : 'Nothing to change - they already do this work.'] + $this->coverage($fault, $workOrder, $agencyId));
    }

    /** @return array{0:?\App\Models\RentalFaultReport, 1:?\App\Models\RentalWorkOrder} the fault / work order the popup was opened from, in THIS agency only */
    private function contextOf(Request $request, int $agencyId): array
    {
        $fault = $workOrder = null;
        if ($id = (int) $request->input('fault_id', $request->query('fault', 0))) {
            abort_unless($request->user()?->hasPermission('rental_fault_reports.view'), 403);
            $fault = \App\Models\RentalFaultReport::withoutGlobalScopes()->with('faultType')->where('agency_id', $agencyId)->whereNull('deleted_at')->find($id);
        }
        if (! $fault && ($id = (int) $request->input('work_order_id', $request->query('work_order', 0)))) {
            abort_unless($request->user()?->hasPermission('rental_work_orders.view'), 403);
            $workOrder = \App\Models\RentalWorkOrder::withoutGlobalScopes()->with('reportedFaultReport.faultType')->where('agency_id', $agencyId)->whereNull('deleted_at')->find($id);
        }

        return [$fault, $workOrder];
    }

    /** @return array{0:?string, 1:?string} the type name and category of the job */
    private function typeOf(?\App\Models\RentalFaultReport $fault, ?\App\Models\RentalWorkOrder $workOrder): array
    {
        $type = $fault?->faultType ?? $workOrder?->reportedFaultReport?->faultType;

        return [$type?->name, $type?->category];
    }

    /** Does a contractor suit the job NOW? (the screens clear their warning from this, without a reload) */
    private function coverage(?\App\Models\RentalFaultReport $fault, ?\App\Models\RentalWorkOrder $workOrder, int $agencyId): array
    {
        $svc = app(\App\Services\Rentals\RentalFaultContractorService::class);
        if ($fault) {
            return ['covered' => $svc->hasContractorFor($fault)];
        }
        $type = $workOrder?->reportedFaultReport?->faultType;

        return ['covered' => $svc->classifyFor($agencyId, $type?->name, $type?->category)['suits']->isNotEmpty()];
    }

    /** The contractor's own history line (who, when, what, from which fault). Append-only. */
    private function recordEvent(AgencyServiceProvider $provider, string $event, string $note, $user, ?int $faultId, ?int $workOrderId): void
    {
        \Illuminate\Support\Facades\DB::table('agency_service_provider_events')->insert([
            'agency_id' => (int) $provider->agency_id, 'service_provider_id' => $provider->id, 'event' => $event, 'note' => mb_substr($note, 0, 500),
            'rental_fault_report_id' => $faultId, 'rental_work_order_id' => $workOrderId, 'user_id' => $user?->id, 'created_at' => now(),
        ]);
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

    /**
     * AT-364 — persist a supplier's attorney capabilities (Transfer / Bond). A firm like BBB does
     * BOTH bonds and transfers, so both may be ticked. Dedicated route (same idiom as syncTypes /
     * preferred) so it never trips update()'s required-field rules. The row posts the FULL state of
     * both toggles each time, so an absent checkbox can never silently wipe the other capability.
     * Nothing here touches `specialty` or the DR distribution math.
     */
    public function syncAttorneyCapabilities(Request $request, AgencyServiceProvider $provider)
    {
        $this->authorizeAgency($request, $provider);

        $data = $request->validate([
            'is_transfer_attorney' => 'required|boolean',
            'is_bond_attorney'     => 'required|boolean',
        ]);

        $provider->update([
            'is_transfer_attorney' => (bool) $data['is_transfer_attorney'],
            'is_bond_attorney'     => (bool) $data['is_bond_attorney'],
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'is_transfer_attorney' => (bool) $provider->is_transfer_attorney,
                'is_bond_attorney'     => (bool) $provider->is_bond_attorney,
            ]);
        }

        return back()->with('success', "Updated attorney capabilities for {$provider->name}.");
    }

    /**
     * The firm's business address — e.g. an executor's practice address, needed on any
     * document the firm signs. Dedicated route (same idiom as syncTypes / attorney
     * capabilities) so saving it never trips update()'s required name/specialty rules
     * and can never trample another field.
     */
    public function updateAddress(Request $request, AgencyServiceProvider $provider)
    {
        $this->authorizeAgency($request, $provider);

        $data = $request->validate([
            'address' => 'nullable|string|max:500',
        ]);

        $provider->update(['address' => $data['address'] ?? null]);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'address' => $provider->fresh()->address]);
        }
        return back()->with('success', "Updated the address for {$provider->name}.");
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
            'registration_number' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:191',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
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

        // AT-452 round 2 (H) - the work order's "Add their details" popup saves a contact over the page (same validation, same write).
        if ($request->wantsJson()) {
            $this->recordEvent($provider, 'contact_added', 'Contact details added by ' . $request->user()->name . ' (' . trim(($data['email'] ?? '') . ' ' . ($data['phone'] ?? '')) . ').', $request->user(), null, $request->integer('work_order_id') ?: null);

            return response()->json(['ok' => true, 'message' => 'Contact details added to ' . $provider->name . '.']);
        }

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
            // AT-364 — the fixed attorney capabilities (a firm can be both).
            'is_transfer_attorney' => (bool) $p->is_transfer_attorney,
            'is_bond_attorney' => (bool) $p->is_bond_attorney,
            // AT-319 — the AgencyServiceType codes this supplier handles (drives the picker filter).
            'types' => $p->typeCodes(),
        ];
    }
}
