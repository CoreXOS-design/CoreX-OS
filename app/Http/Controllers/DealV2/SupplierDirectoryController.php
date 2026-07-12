<?php

namespace App\Http\Controllers\DealV2;

use App\Http\Controllers\Controller;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceProviderContact;
use App\Models\DealV2\DealV2;
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
        $agencyId = (int) ($request->user()->effectiveAgencyId() ?? 0);
        $providers = AgencyServiceProvider::query()->withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->with(['serviceContacts' => fn ($q) => $q->orderByDesc('is_active')->orderBy('attorney_name')])
            ->orderByDesc('is_active')->orderBy('specialty')->orderByDesc('is_preferred')->orderBy('name')
            ->get();

        return view('deals-v2.suppliers.index', [
            'providers' => $providers,
            'specialties' => self::SPECIALTIES,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $this->service->findOrCreate((int) $request->user()->effectiveAgencyId(), $data, $request->user()->id);

        return back()->with('success', 'Provider saved to the directory.');
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
        $agencyId = (int) ($request->user()->effectiveAgencyId() ?? 0);
        $results = $this->service->search($agencyId, $request->query('specialty'), $request->query('q'));

        return response()->json(['results' => $results->map(fn ($p) => $this->row($p))->values()]);
    }

    public function createInline(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $provider = $this->service->findOrCreate((int) $request->user()->effectiveAgencyId(), $data, $request->user()->id);

        return response()->json(['provider' => $this->row($provider)], 201);
    }

    /** Attach a directory provider to a deal under a provider role. */
    public function attach(Request $request, DealV2 $deal): JsonResponse
    {
        $validated = $request->validate([
            'provider_id' => 'required|integer',
            'role' => 'required|string|in:' . implode(',', self::PROVIDER_ROLES),
        ]);
        $provider = AgencyServiceProvider::query()->withoutGlobalScopes()
            ->where('agency_id', $deal->agency_id ?? $request->user()->effectiveAgencyId())
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
        abort_unless((int) $contact->agency_id === (int) $request->user()->effectiveAgencyId(), 403);
        $contact->update(['is_active' => false]);
        $contact->delete();

        return back()->with('success', 'Contact removed.');
    }

    private function authorizeAgency(Request $request, AgencyServiceProvider $provider): void
    {
        abort_unless((int) $provider->agency_id === (int) $request->user()->effectiveAgencyId(), 403);
    }

    private function row(AgencyServiceProvider $p): array
    {
        return [
            'id' => $p->id, 'name' => $p->name, 'specialty' => $p->specialty,
            'company' => $p->company, 'email' => $p->email, 'phone' => $p->phone,
            'is_preferred' => (bool) $p->is_preferred, 'is_active' => (bool) $p->is_active,
        ];
    }
}
