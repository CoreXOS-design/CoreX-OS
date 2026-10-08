<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Concerns\ExportsRentalList;
use App\Http\Controllers\Concerns\HandlesLeaseCapture;
use App\Http\Controllers\Concerns\SearchesQualifyingRentalProperties;
use App\Http\Requests\CoreX\LeaseCaptureRequest;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseEscalation;
use App\Models\Property;
use App\Models\PropertySettingItem;
use App\Models\RentalApplication;
use App\Services\Rentals\LeaseActivationService;
use App\Services\Rentals\LeaseAgentService;
use App\Services\Rentals\LeaseArchiveService;
use App\Services\Rentals\LeaseAgreementTemplateGuard;
use App\Services\Rentals\LeaseAgreementValuesReader;
use App\Services\Rentals\LeaseCaptureService;
use App\Services\Rentals\LeaseHubService;
use App\Services\Rentals\LeaseNoticeTermsService;
use App\Services\Rentals\LeaseSigningLauncher;
use App\Services\Rentals\LeaseTimelineService;
use App\Services\Rentals\RentalDocumentPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * .ai/specs/leases.md — full CRUD + list screen per BUILD_STANDARD §1a-§1d.
 * A lease takes a Property, adds Tenant(s), carries terms. Kept small
 * deliberately — see the spec for what is explicitly NOT built here
 * (deposit-held tracking, one-click renewal's UI, CPA notice logic).
 */
class LeaseController extends Controller
{
    use AuthorizesRentalRecordScope;
    use ExportsRentalList;
    use HandlesLeaseCapture;
    use SearchesQualifyingRentalProperties;

    /** §39, 2026-09-28 — "Expiring soon" summary tile window; no agency-configurable setting exists for this yet (see index()'s own note). */
    private const LEASE_EXPIRING_SOON_DAYS = 60;

    private const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    /**
     * The Leases list screen. Search: property address, tenant name(s).
     * Sort: end_date (default, ascending — soonest to expire first), start_date,
     * status, property. Filter: status, type of date range, property, branch.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        // AT-439 — own/branch/all "Showing:" control, same pattern as
        // RentalApplicationController::index(). $query below still uses
        // $request->get('scope') directly (Lease::scopeVisibleTo() clamps
        // it internally against the same ceiling) — $resolvedScope/
        // $scopeOptions here exist only to drive the toggle UI.
        $maxScope = \App\Services\Rentals\RentalDataScope::ceiling($user, 'leases');
        $resolvedScope = \App\Services\PermissionService::clampScope($request->get('scope'), $maxScope);
        $scopeOptions = match ($maxScope) {
            'all' => ['own', 'branch', 'all'],
            'branch' => ['own', 'branch'],
            default => ['own'],
        };

        $sort = $request->get('sort', 'end_date');
        $direction = $request->get('direction', 'asc');
        $allowedSorts = ['end_date', 'start_date', 'status', 'property'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'end_date';
        }
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        $showArchived = $request->boolean('archived');
        $query = $this->filteredLeasesQuery($request, $showArchived);

        // The property-filter picker (searchProperties() below) — pre-fill
        // its label when a property_id already arrived in the querystring.
        $filteredProperty = ($propertyId = $request->get('property_id'))
            ? Property::find($propertyId)
            : null;

        if ($sort === 'property') {
            $query->join('properties', 'properties.id', '=', 'leases.property_id')
                ->orderBy('properties.title', $direction)
                ->select('leases.*');
        } else {
            $query->orderBy("leases.{$sort}", $direction);
        }

        $hasAnyLeases = Lease::query()->visibleTo($user, $request->get('scope'))->exists();

        $perPage = (int) $request->get('per_page', 25);
        if (!in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = 25;
        }

        $leases = $query->paginate($perPage)->withQueryString();

        // LEASE-AGREEMENT BEGIN (leases.md §15.15 — Build L3b): the safety net — a lease on this page whose agreement
        // is still in flight is re-checked against its e-sign envelope, so a missed announcement never shows a stale
        // status. One cheap read per in-flight lease on the page; a lease with no agreement costs nothing.
        foreach ($leases->items() as $listed) {
            if ($listed->signature_template_id && in_array($listed->signing_status, Lease::SIGNING_IN_FLIGHT, true)) {
                $listed->reconcileSigning();
            }
        }
        // LEASE-AGREEMENT END

        // §39, 2026-09-28 — Johan: a summary tiles row, the same reused
        // FICA/rental-applications pattern as rental-inspections (§39
        // there). Status tiles are the real enum (Lease::STATUS_*), not
        // invented. Exception tile: "Expiring soon" — an active lease
        // whose end_date falls within the next LEASE_EXPIRING_SOON_DAYS
        // days. No agency-configurable renewal-reminder-window setting
        // exists on this model today (checked — nothing to reuse); adding
        // one is a real setting (Non-negotiable #10a: Setup Wizard entry,
        // saver, the works) and out of scope for a tiles row. A fixed
        // 60-day default is used instead, same "sensible fixed default,
        // no new setting" call as any other unconfigured threshold —
        // flagged here for Johan if he wants it made configurable later.
        $leaseTileBase = fn () => Lease::query()->visibleTo($user, $request->get('scope'));
        $tileCounts = [
            'total' => $leaseTileBase()->count(),
            'draft' => $leaseTileBase()->where('leases.status', Lease::STATUS_DRAFT)->count(),
            'active' => $leaseTileBase()->where('leases.status', Lease::STATUS_ACTIVE)->count(),
            'expired' => $leaseTileBase()->where('leases.status', Lease::STATUS_EXPIRED)->count(),
            'cancelled' => $leaseTileBase()->where('leases.status', Lease::STATUS_CANCELLED)->count(),
            'expiring_soon' => $leaseTileBase()
                ->where('leases.status', Lease::STATUS_ACTIVE)
                ->whereBetween('leases.end_date', [now(), now()->addDays(self::LEASE_EXPIRING_SOON_DAYS)])
                ->count(),
        ];

        return view('corex.leases.index', [
            'leases' => $leases,
            'sort' => $sort,
            'direction' => $direction,
            'hasAnyLeases' => $hasAnyLeases,
            'showArchived' => $showArchived,
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'filters' => $request->only(['q', 'status', 'agreement', 'property_id', 'branch_id', 'date_from', 'date_to', 'expiring_soon', 'notice_terms']),
            'filteredProperty' => $filteredProperty,
            'tileCounts' => $tileCounts,
            'resolvedScope' => $resolvedScope,
            'scopeOptions' => $scopeOptions,
        ]);
    }

    /**
     * Shared scoped+filtered query, reused by index()/printList()/export()
     * so the three never drift on what "the current filtered list" means.
     * Returns an UNSORTED, UNPAGINATED builder — callers apply their own
     * ordering/pagination on top.
     */
    private function filteredLeasesQuery(Request $request, bool $onlyArchived = false)
    {
        $user = $request->user();

        $query = Lease::query()
            ->visibleTo($user, $request->get('scope'))
            ->with(['property.contacts', 'tenants.contact', 'ownerAgent', 'tenantAgent']);

        if ($onlyArchived) {
            $query->onlyTrashed();
        }

        if ($search = trim((string) $request->get('q', ''))) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('property', function ($p) use ($search) {
                    $p->searchAddress($search);
                })->orWhereHas('tenants.contact', function ($c) use ($search) {
                    $c->where(function ($cc) use ($search) {
                        $cc->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
                })->orWhereHas('property.contacts', function ($c) use ($search) {
                    // Landlord name is searchable too (task ask) — same
                    // strict role set as Lease::landlordContacts(), never
                    // a tenant-as-landlord match.
                    $c->whereIn('contact_property.role', ['landlord', 'lessor', 'seller', 'owner'])
                        ->where(function ($cc) use ($search) {
                            $cc->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                        });
                });
            });
        }

        if ($status = $request->get('status')) {
            $query->where('leases.status', $status);
        }

        // LEASE-AGREEMENT BEGIN (leases.md §15.13 — Build L2): the "Agreement" filter — where the lease's own
        // agreement is (not sent / being prepared / out for signing / needs my approval / signed / …).
        $agreement = $request->get('agreement');
        if (is_string($agreement) && array_key_exists($agreement, Lease::SIGNING_LABELS)) {
            $query->where('leases.signing_status', $agreement);
        }
        // LEASE-AGREEMENT END

        if ($propertyId = $request->get('property_id')) {
            $query->where('leases.property_id', $propertyId);
        }

        if ($branchId = $request->get('branch_id')) {
            $query->where('leases.branch_id', $branchId);
        }

        if ($dateFrom = $request->get('date_from')) {
            $query->where('leases.end_date', '>=', $dateFrom);
        }
        if ($dateTo = $request->get('date_to')) {
            $query->where('leases.end_date', '<=', $dateTo);
        }

        // leases.md §18.7 — the "to check" list: notice terms no agent has confirmed against the signed lease.
        if ($request->get('notice_terms') === 'unconfirmed') {
            $query->noticeTermsUnconfirmed();
        }

        // §39 — the summary tiles' own "Expiring soon" exception tile.
        if ($request->boolean('expiring_soon')) {
            $query->where('leases.status', Lease::STATUS_ACTIVE)
                ->whereBetween('leases.end_date', [now(), now()->addDays(self::LEASE_EXPIRING_SOON_DAYS)]);
        }

        return $query;
    }

    /**
     * The LIST screen's own property-filter picker — only properties that
     * actually have a lease visible to this user (leases.md §7's own
     * scopeVisibleTo(), same scope as the list query itself), never every
     * rental property. Distinct from the create screen's own property
     * `<select>` (now the type-to-search picker in leases/capture.blade.php), which deliberately keeps
     * offering every rental property, unchanged.
     */
    public function searchProperties(Request $request): JsonResponse
    {
        return $this->searchQualifyingRentalProperties($request, Lease::class);
    }

    /**
     * The properties the CREATE form's Property picker may offer: rental stock
     * the acting user can see under their own own/branch/agency properties
     * scope. This ONE query (Property::scopeRentalVisibleTo) backs the search
     * endpoint, store()'s property_id validation and create()'s ?property_id=
     * pre-fill, so the picker never offers a property the save would refuse and
     * a save never accepts one the picker would not have offered.
     */
    private function pickableRentalProperties(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        return Property::query()->rentalVisibleTo($request->user());
    }

    /**
     * The CREATE screen's type-to-search Property picker — same pattern as
     * RentalApplicationController::searchProperties() / RentalWorkOrderController
     * ::searchProperties(): Property::scopeSearchAddress() (address, street,
     * suburb, complex, unit, title/property name, property number, erf number,
     * P24 reference), limited to 10 rows. A blank term returns the most recent
     * rental stock, never an error. Distinct from searchProperties() above,
     * which backs the LIST screen's filter and only offers properties that
     * already have a lease.
     */
    public function searchRentalProperties(Request $request): JsonResponse
    {
        $properties = $this->pickableRentalProperties($request)
            ->searchAddress(trim((string) $request->query('q', '')))
            ->with('agent')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return response()->json($properties->map(fn (Property $p) => $p->toSearchResult([
            'ref' => $p->property_number,
            // leases.md §15.12.5 #22 — the letting commission % the capture screen starts from.
            'commission_percent' => $p->commission_percent,
            // leases.md §17 — the owner's agent on the capture screen starts as the property's agent.
            'agent_id' => $p->agent_id,
        ])));
    }

    /**
     * leases.md §15.3 / §15.4 (Build L3a) — what the capture screen's landlord panel and per-tenant hints show:
     * the property's landlord(s) and the chosen tenant(s), each with what is still missing before the agreement can
     * be prepared (an email, an ID or passport number) and a link to the contact. Names and "what is missing"
     * only — never the values. The property is the SAME scoped query as the picker; contacts are the agency's own.
     */
    public function partyCheck(Request $request): JsonResponse
    {
        $user = $request->user();
        $launcher = app(LeaseSigningLauncher::class);
        $agencyId = $user->effectiveAgencyId();

        $state = app(LeaseCaptureService::class)->agreementStateFor((int) $agencyId);
        $agreement = $state['agreements']->firstWhere('id', (int) $request->query('agreement_id')) ?? $state['agreements']->first();
        $map = $agreement ? app(LeaseAgreementValuesReader::class)->normaliseMap((array) ($agreement->field_map ?? [])) : [];

        $property = $request->integer('property_id') > 0
            ? $this->pickableRentalProperties($request)->find($request->integer('property_id'))
            : null;

        // Johan, 2026-10-08: FICA is a WARNING here, never a stop — the one shared FicaGate (the rule sales' signer gate
        // uses) says whether each party has submitted FICA; `needs` (which blocks "prepare") stays email / ID only.
        $present = fn (Contact $c, string $prefix) => [
            'id' => $c->id,
            'name' => $c->full_name ?: ('Contact #' . $c->id),
            'needs' => array_values($launcher->contactNeeds($c, $prefix, $map)),
            'url' => route('corex.contacts.show', $c),
            'fica' => \App\Services\Compliance\FicaGate::describe($c, $prefix === 'landlord' ? 'the landlord' : 'the tenant'),
        ];

        $landlords = $launcher->landlordsOf($property);
        $ids = array_values(array_unique(array_filter(array_map('intval', array_filter((array) $request->query('tenant_ids', []), 'is_scalar')))));
        $tenants = $ids === []
            ? collect()
            : Contact::query()->where('agency_id', $agencyId)->whereIn('id', $ids)->get()->sortBy(fn (Contact $c) => array_search($c->id, $ids, true));

        return response()->json([
            'property_chosen' => (bool) $property,
            'landlord_missing' => $property !== null && $landlords->isEmpty(),
            'landlord_url' => $property ? route('corex.properties.show', ['property' => $property->id, 'tab' => 'contacts']) : null,
            'landlords' => $landlords->map(fn (Contact $c) => $present($c, 'landlord'))->values(),
            'tenants' => $tenants->map(fn (Contact $c) => $present($c, 'tenant'))->values(),
        ]);
    }

    /** Human-readable active-filter summary for the print-list header/export filename — shared shape across all four rental lists. */
    private function activeLeaseFiltersSummary(Request $request): array
    {
        $out = [];
        if ($q = $request->get('q')) {
            $out['Search'] = $q;
        }
        if ($status = $request->get('status')) {
            $out['Status'] = ucfirst($status);
        }
        if (is_string($agreement = $request->get('agreement')) && isset(Lease::SIGNING_LABELS[$agreement])) {
            $out['Agreement'] = Lease::SIGNING_LABELS[$agreement];
        }
        if ($request->get('notice_terms') === 'unconfirmed') {
            $out['Notice terms'] = 'To check — not confirmed';
        }
        if ($request->boolean('expiring_soon')) {
            $out['Expiring soon'] = 'Yes';
        }
        if ($df = $request->get('date_from')) {
            $out['End date from'] = $df;
        }
        if ($dt = $request->get('date_to')) {
            $out['End date to'] = $dt;
        }
        if ($request->boolean('archived')) {
            $out['Archived'] = 'Yes';
        }
        $out['Scope'] = ucfirst(\App\Services\Rentals\RentalDataScope::resolve($request->user(), 'leases', $request->get('scope')));

        return $out;
    }

    /** req — print the current filtered list, same scoping as index(), filters shown in the header. */
    public function printList(Request $request): View
    {
        $leases = $this->filteredLeasesQuery($request, $request->boolean('archived'))
            ->orderBy('leases.end_date')
            ->get();

        return view('corex.leases.print-list', [
            'leases' => $leases,
            'printFilters' => $this->activeLeaseFiltersSummary($request),
        ]);
    }

    /** req — export the current filtered list as xlsx/csv, same scoping as index(). */
    public function export(Request $request)
    {
        $leases = $this->filteredLeasesQuery($request, $request->boolean('archived'))
            ->orderBy('leases.end_date')
            ->get();

        $headers = ['Property', 'Tenant(s)', 'Landlord', "Owner's agent", "Tenant's agent", 'Status', 'Start', 'End', 'Rent', 'Agreement status', 'Signed on'];
        $rows = $leases->map(fn (Lease $lease) => [
            $lease->property?->buildDisplayAddress() ?? 'Unknown property',
            $lease->tenantNames(),
            $lease->landlordNames(),
            $lease->ownerAgent?->name ?? '',
            $lease->tenantAgent?->name ?? '',
            ucfirst($lease->status),
            $lease->start_date?->format('Y-m-d') ?? '',
            $lease->end_date?->format('Y-m-d') ?? ($lease->is_month_to_month ? 'Month-to-month' : ''),
            number_format((float) $lease->rental_amount, 2),
            Lease::SIGNING_LABELS[$lease->signing_status ?? Lease::SIGNING_NOT_SENT] ?? 'Not sent',
            $lease->signed_at?->format('Y-m-d') ?? '',
        ]);

        $filename = 'leases-' . now()->format('Y-m-d');

        return $request->get('format') === 'csv'
            ? $this->streamRentalListCsv($filename . '.csv', $headers, $rows)
            : $this->streamRentalListXlsx($filename . '.xlsx', $headers, $rows);
    }

    public function create(Request $request): View
    {
        // ?property_id= (from a property screen) pre-fills ONLY a property this user
        // may put a lease on — same scoped query as the picker. A non-rental,
        // out-of-scope, other-agency or malformed id pre-fills nothing (no error
        // page): the form simply opens with the type-to-search picker.
        $presetPropertyId = $request->integer('property_id');
        $property = $presetPropertyId > 0
            ? $this->pickableRentalProperties($request)->find($presetPropertyId)
            : null;
        // Visible to this user under their own rental-application scope — a forged or other-branch id is a 404,
        // never a pre-fill of someone else's applicant.
        $rentalApplication = $request->get('rental_application_id')
            ? RentalApplication::query()->visibleTo($request->user())->findOrFail($request->get('rental_application_id'))
            : null;

        // Johan, QA1, 2026-10-07 — arriving from an approved application ("link the property"): the property is the
        // one the agent just linked, the tenant is the applicant, the rent is the PROPERTY's (never the approved
        // affordability amount) and the deposit follows the approved deposit terms applied to that rent. The agent
        // still enters the dates and presses the button — nothing is created until they do (leases.md §15.3).
        $applicationDefaults = null;
        if ($rentalApplication && ! $property && $rentalApplication->property_id) {
            $property = $this->pickableRentalProperties($request)->find($rentalApplication->property_id);
        }
        if ($rentalApplication) {
            $applicationDefaults = $this->applicationCaptureDefaults($rentalApplication, $property);
        }

        // A validation error bounces back here with the picked property only in
        // old('property_id'): resolve it again through the SAME scoped query as
        // the picker, so a property the user can no longer see (or that was
        // archived meanwhile) comes back empty and must be re-picked.
        $oldPropertyId = $property ? null : old('property_id');
        $oldProperty = $oldPropertyId && is_scalar($oldPropertyId)
            ? $this->pickableRentalProperties($request)->find($oldPropertyId)
            : null;

        // leases.md §15.2 (Build L2) — one capture screen for a new lease and a renewal.
        return view('corex.leases.capture', array_merge(
            $this->leaseCaptureScreen($request->user(), null, $rentalApplication, $property ?? $oldProperty),
            [
                'mode' => 'new',
                'lease' => null,
                'property' => $property,
                'oldProperty' => $oldProperty,
                'oldTenants' => $this->oldTenantSeed($request) ?: $this->applicationTenantSeed($rentalApplication),
                'rentalApplication' => $rentalApplication,
                'applicationDefaults' => $applicationDefaults,
            ]
        ));
    }

    /**
     * The applicant as the lease's first (primary) tenant — same shape as oldTenantSeed().
     *
     * @return list<array{id:int,name:string,email:string}>
     */
    private function applicationTenantSeed(?RentalApplication $application): array
    {
        $contact = $application?->contact;
        if (! $contact) {
            return [];
        }

        return [[
            'id' => (int) $contact->id,
            'name' => $contact->full_name !== '' ? $contact->full_name : 'Contact #' . $contact->id,
            'email' => (string) ($contact->email ?? ''),
        ]];
    }

    /**
     * Rent/deposit starting values for a lease captured from an approved application, plus the figures the
     * screen needs to warn when the agent goes above the approved amount (RentalApplication::rentAboveApproved()
     * is the server-side twin). Rent: the property's own rental amount, blank when it has none — never the
     * approved amount. Deposit: the approved deposit as a multiple of the approved rent (the approved
     * "terms") applied to the lease rent; when the application carries no approved pair, the property's own
     * deposit.
     *
     * @return array<string,mixed>
     */
    private function applicationCaptureDefaults(RentalApplication $application, ?Property $property): array
    {
        $rent = $property?->rental_amount !== null ? round((float) $property->rental_amount, 2) : null;
        $approvedRent = $application->approved_rental_amount !== null ? (float) $application->approved_rental_amount : null;
        $approvedDeposit = $application->approved_deposit_amount !== null ? (float) $application->approved_deposit_amount : null;

        $months = ($approvedRent !== null && $approvedRent > 0 && $approvedDeposit !== null)
            ? round($approvedDeposit / $approvedRent, 4)
            : null;
        $deposit = ($months !== null && $rent !== null)
            ? round($rent * $months, 2)
            : ($property?->deposit_amount !== null ? round((float) $property->deposit_amount, 2) : null);

        return [
            'rental_amount' => $rent,
            'deposit_amount' => $deposit,
            'deposit_months' => $months,
            'approved_rental_amount' => $approvedRent,
            'mode' => \App\Models\RentalApplicationQualifyingSetting::rentAboveApprovedModeFor((int) $application->agency_id),
        ];
    }

    /**
     * The tenants chosen before a validation error, for the form to show again
     * (leases.md §7.3). The bounce carries only contact ids in
     * old('tenant_contact_ids'); each is re-resolved here under the SAME rule
     * store() validates (same agency), so a forged or stale id comes back as
     * nothing. Order is kept — the first tenant is the primary.
     *
     * @return list<array{id:int,name:string,email:string}>
     */
    private function oldTenantSeed(Request $request): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', array_filter((array) old('tenant_contact_ids', []), 'is_scalar'))
        )));
        if ($ids === []) {
            return [];
        }

        $contacts = Contact::query()
            ->where('agency_id', $request->user()->effectiveAgencyId())
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $seed = [];
        foreach ($ids as $id) {
            if ($contact = $contacts->get($id)) {
                $seed[] = [
                    'id' => (int) $contact->id,
                    'name' => $contact->full_name !== '' ? $contact->full_name : 'Contact #' . $contact->id,
                    'email' => (string) ($contact->email ?? ''),
                ];
            }
        }

        return $seed;
    }

    /**
     * leases.md §15.2 / §15.4 (Build L2) — "Create lease only", "Create lease & prepare for signing" and the
     * signed paper copy all land here; validation is LeaseCaptureRequest (every §7.2/§7.3 rule of the old
     * store() — a rental property the user may see, same-agency tenants — plus the agreement details), the
     * write is LeaseCaptureService, one transaction.
     */
    public function store(LeaseCaptureRequest $request): RedirectResponse
    {
        return $this->runLeaseCapture($request, null);
    }

    /**
     * .ai/specs/leases.md §12 — the lease detail becomes the tenancy file.
     * Johan, 4 Oct 2026: the one screen that answers "what has happened on
     * this tenancy, what needs to happen next, and is there anything open
     * right now" without checking four other screens.
     */
    public function show(Request $request, Lease $lease): View
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        // LEASE-AGREEMENT BEGIN (leases.md §15.15 — Build L3b): the safety net — bring the lease in step with its
        // e-sign envelope before showing it, whatever the engine did or failed to announce. A no-op for any lease
        // whose agreement is not in flight.
        $lease->reconcileSigning();
        // LEASE-AGREEMENT END

        $lease->load(['property', 'tenants.contact', 'escalations.createdByUser', 'previousLease', 'renewedLease']);

        // rental-work-orders.md §14.29 — the tenancy's job cards with status + photos.
        // Only for a user who may see job cards at all, and only the cards their own
        // own/branch/agency job-card scope lets them see (the lease scope above says
        // nothing about job cards).
        $user = $request->user();
        $jobCards = $user->hasPermission('rental_job_cards.view')
            ? $lease->jobCards()->visibleTo($user)->with(['crew', 'photos', 'workOrder.photos'])->orderByDesc('id')->get()
            : collect();

        $hubService = app(LeaseHubService::class);
        $timelineService = app(LeaseTimelineService::class);

        $filters = $request->only(['q', 'type', 'date_from', 'date_to']);
        $types = array_filter((array) $request->get('type', []));
        $page = (array) $timelineService->paginatedFor(
            $lease,
            $request->get('q'),
            $types,
            $request->get('date_from'),
            $request->get('date_to'),
            50,
            (int) $request->get('page', 1)
        );

        return view('corex.leases.show', [
            'lease' => $lease,
            // .ai/specs/rental-property-tab.md §5, Part 4 — same list as create().
            'leaseTypes' => PropertySettingItem::group('lease_type')->where('active', true)->get(),
            // Johan, 2026-09-22 — agency-configurable, hidden by default.
            'showLeaseType' => \App\Models\LeaseSetting::showLeaseTypeFieldFor($lease->agency_id),
            'lifecycle' => $hubService->lifecycle($lease),
            'nextStep' => $hubService->nextStep($lease, $user),
            // leases.md §15.13 (Build L3a) — the hub's "Agreement" card; null for a lease with no agreement.
            'agreementCard' => app(LeaseSigningLauncher::class)->cardFor($lease, $user),
            'openItemCounts' => $hubService->openItemCounts($lease),
            'landlords' => $lease->landlordContacts(),
            // Johan, 2026-10-08 — FICA never stops a lease from being activated, signed or given portal access;
            // this only WARNS (with the link to request/complete it) for any party who has not submitted FICA yet.
            'ficaWarnings' => $this->ficaWarningsFor($lease),
            // .ai/specs/rental-renewals.md §19 — the notice dialogs' "Show
            // available-from date on portals" tick defaults from the
            // property's own current setting (ticked the first time, same
            // as the property's own default); the "Change notice outcome"
            // dialog pre-selects the lease's own current choice.
            'showAvailableFromOnPortals' => (bool) ($lease->property?->show_available_from_on_portals ?? true),
            'timelineEntries' => $page['entries'],
            'timelineTotal' => $page['total'],
            'timelineTypes' => LeaseTimelineService::TYPES,
            'timelineFilters' => $filters,
            'jobCards' => $jobCards,
            // leases.md §17 — the lease's two agents (shown on the Agents card) and, for someone who may edit the lease,
            // the people they can be changed to.
            'leaseAgents' => $this->leaseAgentRows($lease),
            'agentOptions' => $user->hasPermission('leases.create')
                ? app(LeaseAgentService::class)->selectableAgents((int) $lease->agency_id, $lease->branch_id)->all()
                : [],
            // leases.md §18 — the lease's notice / early-cancellation terms (the "Notice terms" card).
            'noticeTerms' => $this->noticeTermsCard($lease),
        ]);
    }

    /**
     * The "Notice terms" card: what the lease holds (never a default shown as if it were recorded), where it came from, the
     * dates worked out from it, and the agency defaults to start from when the lease holds none.
     *
     * @return array<string,mixed>
     */
    private function noticeTermsCard(Lease $lease): array
    {
        $svc = app(LeaseNoticeTermsService::class);
        $terms = $svc->termsOf($lease);
        $values = $svc->stored($terms);
        $has = $svc->anyIn($values);

        return [
            'values' => $values,
            'has' => $has,
            'source' => $terms?->notice_terms_source,
            'confirmed' => $terms?->notice_terms_confirmed_at !== null && $has,
            'confirmedAt' => $terms?->notice_terms_confirmed_at,
            'confirmedBy' => $terms?->notice_terms_confirmed_by
                ? \App\Models\User::withoutGlobalScopes()->withTrashed()->whereKey($terms->notice_terms_confirmed_by)->value('name')
                : null,
            'defaults' => $svc->defaultsFor((int) $lease->agency_id, $lease->start_date),
            'locked' => $lease->isLockedForSigning(),
            'signedDocument' => in_array($lease->signing_status, [Lease::SIGNING_SIGNED, Lease::SIGNING_SIGNED_ON_PAPER], true),
            'periodText' => $svc->periodText($values['notice_period'], $values['notice_period_unit']),
            'earlyPeriodText' => $svc->periodText($values['early_cancellation_notice'], $values['early_cancellation_notice_unit']),
            'worked' => $svc->workedDates($lease, $values),
        ];
    }

    /**
     * leases.md §18 — change a lease's notice / early-cancellation terms. Its own permission (`lease_notice_terms.edit`, Role Manager),
     * the usual own/branch/agency guard, nothing while the agreement is out for signing, every change logged (who, which terms, from, to).
     */
    public function updateNoticeTerms(Request $request, Lease $lease): RedirectResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        if ($lease->isLockedForSigning()) {
            return redirect()->route('corex.leases.show', $lease)->withErrors(['notice' => Lease::LOCKED_FOR_SIGNING_MESSAGE]);
        }

        $svc = app(LeaseNoticeTermsService::class);
        $signed = in_array($lease->signing_status, [Lease::SIGNING_SIGNED, Lease::SIGNING_SIGNED_ON_PAPER], true);
        // leases.md §18.7 — the signed document does not change when these do: no silent edit, a reason is required and logged.
        $data = $request->validate(
            $svc->rules('notice') + ['notice_reason' => [$signed ? 'required' : 'nullable', 'string', 'max:300']],
            $svc->messages('notice') + ['notice_reason.required' => 'This lease is already signed. Say why the notice terms are being changed — the signed document does not change (an addendum is needed).']
        );
        $values = $svc->normalise((array) ($data['notice'] ?? []), (int) $lease->agency_id);

        $errors = $svc->crossErrors($values, $lease->start_date?->toDateString());
        if ($errors !== []) {
            return back()->withInput()->withErrors($errors);
        }

        $changed = $svc->save($lease, $values, $request->user(), LeaseNoticeTermsService::SOURCE_EDITED, true, $signed ? ($data['notice_reason'] ?? null) : null);

        return redirect()->route('corex.leases.show', $lease)
            ->with('success', $changed === []
                ? 'The notice terms were already set that way.'
                : ($signed ? 'Notice terms updated. The signed document is unchanged — an addendum is needed to change what it says.' : 'Notice terms updated.'));
    }

    /**
     * leases.md §18.7 — "Confirm terms": an agent confirms the notice terms the lease holds against its signed copy. One click,
     * audited (who, when), same permission as editing them. Only confirmed terms are ever stated to a tenant or owner.
     */
    public function confirmNoticeTerms(Request $request, Lease $lease): RedirectResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        $confirmed = app(LeaseNoticeTermsService::class)->confirm($lease, $request->user());

        return redirect()->route('corex.leases.show', $lease)
            ->with('success', $confirmed ? 'Notice terms confirmed. The portal can now answer notice questions for this lease.' : 'There are no unconfirmed notice terms to confirm on this lease.');
    }

    /**
     * The Agents card's two rows. A lease whose columns were never filled (created before leases carried agents and not
     * yet back-filled) shows who the default rules give — flagged `derived` so the screen can say it is the default, not
     * a recorded choice.
     *
     * @return list<array{side:string,label:string,id:?int,name:?string,derived:bool}>
     */
    private function leaseAgentRows(Lease $lease): array
    {
        $service = app(LeaseAgentService::class);
        $effective = $service->effectiveIds($lease);
        $names = \App\Models\User::withoutGlobalScopes()->withTrashed()
            ->whereIn('id', array_filter($effective))
            ->pluck('name', 'id');

        $rows = [];
        foreach (LeaseAgentService::SIDES as $side) {
            $id = $effective[$side];
            $rows[] = [
                'side' => $side,
                'label' => LeaseAgentService::sideLabel($side),
                'id' => $id,
                'name' => $id ? trim((string) ($names[$id] ?? '')) ?: 'User #' . $id : null,
                'derived' => $lease->{LeaseAgentService::column($side)} === null,
            ];
        }

        return $rows;
    }

    /**
     * leases.md §17 — change the owner's agent and/or the tenant's agent. Same permission as editing the lease
     * (leases.create) and the same own/branch/agency guard as every other lease action; only an active user of the
     * lease's own agency can be chosen; each change is written to the lease history (who, from, to, when).
     */
    public function updateAgents(Request $request, Lease $lease): RedirectResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        $validated = $request->validate([
            'owner_agent_user_id' => ['required', 'integer'],
            'tenant_agent_user_id' => ['required', 'integer'],
        ], [
            'owner_agent_user_id.required' => "Choose the owner's agent from the list.",
            'owner_agent_user_id.integer' => "Choose the owner's agent from the list.",
            'tenant_agent_user_id.required' => "Choose the tenant's agent from the list.",
            'tenant_agent_user_id.integer' => "Choose the tenant's agent from the list.",
        ]);

        $changed = app(LeaseAgentService::class)->assign($lease, [
            LeaseAgentService::SIDE_OWNER => $validated['owner_agent_user_id'],
            LeaseAgentService::SIDE_TENANT => $validated['tenant_agent_user_id'],
        ], $request->user());

        return redirect()->route('corex.leases.show', $lease)
            ->with('success', $changed === [] ? 'The agents were already set that way.' : 'Agents updated.');
    }

    /**
     * The lease screen's FICA warnings — one entry per tenant / landlord whose FICA has not been submitted, from the
     * one shared FicaGate (the same rule sales' signer gate uses). Empty for a lease that is over (expired /
     * cancelled): nothing is left to carry on with.
     *
     * @return array<int, array{contact_id:int|null,name:string,state:string,open:bool,label:string,warning:?string,url:?string}>
     */
    private function ficaWarningsFor(Lease $lease): array
    {
        if (in_array($lease->status, [Lease::STATUS_EXPIRED, Lease::STATUS_CANCELLED], true)) {
            return [];
        }

        $parties = [];
        foreach ($lease->tenants as $leaseTenant) {
            if ($leaseTenant->contact) {
                $parties[$leaseTenant->contact->id] = ['contact' => $leaseTenant->contact, 'role' => 'the tenant'];
            }
        }
        foreach ($lease->landlordContacts() as $landlord) {
            $parties[$landlord->id] ??= ['contact' => $landlord, 'role' => 'the landlord'];
        }

        return collect($parties)
            ->map(fn (array $p) => \App\Services\Compliance\FicaGate::describe($p['contact'], $p['role']))
            ->reject(fn (array $d) => $d['open'])
            ->values()
            ->all();
    }

    /**
     * AT-440 — JSON tenancy-log endpoint, same scope guard and same
     * LeaseTimelineService the screen's own panel uses. Andre's mobile app
     * calls this same endpoint for a tenant/agent mobile tenancy view
     * (leases.md §12.4).
     */
    public function tenancyLog(Request $request, Lease $lease): JsonResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        $timelineService = app(LeaseTimelineService::class);
        $types = array_filter((array) $request->get('type', []));
        $page = $timelineService->paginatedFor(
            $lease,
            $request->get('q'),
            $types,
            $request->get('date_from'),
            $request->get('date_to'),
            (int) $request->get('per_page', 50),
            (int) $request->get('page', 1)
        );

        return response()->json([
            'data' => $page['entries']->values(),
            'total' => $page['total'],
        ]);
    }

    /**
     * .ai/specs/leases.md §12.2 — "Print tenancy report" action in the
     * header. Same scope guard as show().
     */
    public function tenancyReportPdf(Lease $lease, RentalDocumentPdfService $service)
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        $pdf = $service->leaseTenancyReportPdf($lease);

        return request()->boolean('dl')
            ? $pdf->download($service->leaseTenancyReportFilename($lease))
            : $pdf->stream($service->leaseTenancyReportFilename($lease));
    }

    /**
     * leases.md — full CRUD floor: deposit/end date/lease type editable
     * after creation. Rent amount and start date are deliberately NOT
     * editable here — see the view's own comment.
     *
     * Two footguns fixed here, both caught by walking the real form rather
     * than by a passing test: (1) 'after:start_date' referenced a request
     * field this form never submits (start_date isn't part of an edit) —
     * replaced with a literal comparison against the lease's own stored
     * start_date. (2) `$validated['x'] ?? $lease->x` treats an explicit
     * null/absent-checkbox the same as "field not submitted", so clearing
     * the deposit or unchecking month-to-month would have silently kept
     * the old value — replaced with array_key_exists()/has() checks so an
     * explicit clear actually clears.
     */
    public function update(Request $request, Lease $lease): RedirectResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        // LEASE-AGREEMENT BEGIN (leases.md §15.13 — Build L2): while the agreement is out for signing the
        // agreement-governed fields change in the agreement, not here — one place to change a value. The
        // edit panel shows them read-only with the same words; this refuses a forged update.
        if ($lease->isLockedForSigning()) {
            return back()->withErrors(['lease' => Lease::LOCKED_FOR_SIGNING_MESSAGE]);
        }
        // LEASE-AGREEMENT END

        $validated = $request->validate([
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'end_date' => ['nullable', 'date', 'after:' . $lease->start_date->format('Y-m-d')],
            'is_month_to_month' => ['nullable', 'boolean'],
            'lease_type' => ['nullable', 'string', 'max:40'],
        ]);

        $lease->update([
            'deposit_amount' => array_key_exists('deposit_amount', $validated) ? $validated['deposit_amount'] : $lease->deposit_amount,
            'end_date' => array_key_exists('end_date', $validated) ? $validated['end_date'] : $lease->end_date,
            'is_month_to_month' => $request->boolean('is_month_to_month'),
            'lease_type' => $validated['lease_type'] ?? $lease->lease_type,
        ]);

        return redirect()->route('corex.leases.show', $lease)->with('success', 'Lease updated.');
    }

    /**
     * leases.md §15.13 (Build L3a) — "Prepare again": after the agreement was declined, voided or expired, open a
     * fresh agreement for the SAME lease, pre-filled from what the lease already holds — nothing is typed twice.
     * The same gate and guard as the capture screen's second button; anything still missing is listed with a link.
     */
    public function prepareAgain(Request $request, Lease $lease): RedirectResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        $user = $request->user();
        abort_unless($user->hasPermission('leases.create') || $user->hasPermission('leases.renew'), 403);

        if (! $user->hasPermission('access_docuperfect') || ! $user->hasPermission('create_docuperfect_docs')) {
            return back()->withErrors(['lease' => 'You do not have access to prepare agreements.']);
        }
        if ($lease->status !== Lease::STATUS_DRAFT
            || ! in_array($lease->signing_status, [Lease::SIGNING_DECLINED, Lease::SIGNING_VOIDED, Lease::SIGNING_EXPIRED], true)) {
            return back()->withErrors(['lease' => 'This lease agreement cannot be prepared again.']);
        }

        $ready = app(LeaseAgreementTemplateGuard::class)->readyAgreementsFor((int) $lease->agency_id);
        $agreement = $ready->firstWhere('docuperfect_template_id', (int) $lease->agreement_template_id) ?? $ready->first();
        if (! $agreement) {
            return back()->withErrors(['lease' => 'Your agency has not set up a lease agreement that can be used. An administrator sets one up under Settings → Rental lease agreements.']);
        }

        try {
            $flow = app(LeaseSigningLauncher::class)->launch($lease, $agreement, $user);
        } catch (\App\Exceptions\Rentals\LeaseCaptureIncompleteException $e) {
            return back()->withErrors($e->errors())->with('capture_gaps', $e->missing);
        } catch (\App\Exceptions\Rentals\NoLeaseAgreementLinkedException $e) {
            return back()->withErrors(['lease' => $e->getMessage()]);
        }

        return redirect(app(LeaseSigningLauncher::class)->landingUrl($flow))
            ->with('success', 'The lease agreement is ready — check it, then sign. Nothing has been sent to anyone yet.');
    }

    public function activate(Request $request, Lease $lease): RedirectResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        // leases.md §15.5 - a lease whose agreement is being prepared / out for signing / waiting for the agent's approval
        // becomes active through the signing (the agreement and the lease must agree first); activating it by hand would
        // put a lease live with no signed agreement and skip the change check. The edit fields are locked in the same
        // states for the same reason.
        if (in_array($lease->signing_status, Lease::SIGNING_IN_FLIGHT, true)) {
            return back()->withErrors(['lease' => "This lease's agreement is {$this->signingStateWords($lease)}. The lease becomes active once it is signed and you have approved it."]);
        }
        // Signed with a difference nobody has confirmed: the confirm screen is the way (it records what changed).
        if ($lease->status === Lease::STATUS_DRAFT
            && $lease->signing_status === Lease::SIGNING_SIGNED
            && app(\App\Services\Rentals\LeaseHubService::class)->awaitingConfirmation($lease)) {
            return redirect()->route('corex.leases.agreement.confirm', $lease)
                ->with('warning', 'The signed agreement differs from this lease. Check the differences and confirm them - the lease is activated from there.');
        }

        try {
            // A renewal draft goes through the renewal activation (escalation row, "renewal activated" line, previous term
            // expired and chained); the plain activation does only the last of those.
            $lease->previous_lease_id
                ? app(\App\Services\Rentals\LeaseRenewalService::class)->activateRenewalTerm($lease, $request->user())
                : app(LeaseActivationService::class)->activate($lease);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('corex.leases.show', $lease)->with('success', 'Lease activated.');
    }

    /** "out for signing", "waiting for your approval" ... in the words the hub uses. */
    private function signingStateWords(Lease $lease): string
    {
        return strtolower((string) ($lease->signingStatusLabel() ?: 'in progress'));
    }

    public function cancel(Request $request, Lease $lease): RedirectResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        $validated = $request->validate([
            'cancel_reason' => ['required', 'string', 'max:500'],
        ]);

        // Only a draft or active lease can be cancelled — cancelling one
        // that is already cancelled/expired would overwrite who/when/why.
        if (!in_array($lease->status, [Lease::STATUS_DRAFT, Lease::STATUS_ACTIVE], true)) {
            return back()->withErrors(['lease' => "A {$lease->status} lease cannot be cancelled."]);
        }

        // .ai/specs/rental-renewals.md §15 (GATE 2) row 7 — only an active
        // term ever flipped the property to "leased out" in the first
        // place (LeaseActivationService::flipPropertyToLeasedOut()); a
        // draft cancelled before activation never touched the property, so
        // there is nothing to restore.
        $wasActive = $lease->status === Lease::STATUS_ACTIVE;

        // leases.md §15.13 (Build L3a) — a lease cancelled while its agreement is open takes the agreement with it:
        // the document is cancelled in e-sign in the same action (its waiting parties are told), a flow nobody
        // has sent is abandoned.
        app(LeaseSigningLauncher::class)->closeOpenAgreement($lease, $request->user(), $validated['cancel_reason']);

        $lease->update([
            'status' => Lease::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by_user_id' => $request->user()->id,
            'cancel_reason' => $validated['cancel_reason'],
        ]);

        if ($wasActive && \App\Models\LeaseSetting::autoRestoreStatusOnLeaseCancelledFor($lease->agency_id)) {
            app(\App\Services\Rentals\PropertyStatusFollowsLeaseService::class)->restorePreLetStatus(
                $lease,
                "Lease #{$lease->id} cancelled",
                now()->toDateString(),
                $request->user(),
            );
        }

        return redirect()->route('corex.leases.show', $lease)->with('success', 'Lease cancelled.');
    }

    /**
     * .ai/specs/leases.md §3.4 — escalation as a RATE, computed and stored
     * at entry time, never re-derived later.
     */
    public function escalate(Request $request, Lease $lease): RedirectResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        $validated = $request->validate([
            'effective_date' => ['required', 'date'],
            'new_rental_amount' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if (!in_array($lease->status, [Lease::STATUS_DRAFT, Lease::STATUS_ACTIVE], true)) {
            return back()->withErrors(['lease' => "A {$lease->status} lease cannot be escalated."]);
        }

        // "Previous" = the rent in force just before this escalation's own
        // effective date: the latest earlier escalation's new amount, else
        // the original rent (first escalation's previous, else the current
        // rent when nothing has been recorded yet).
        $effective = $validated['effective_date'];
        $before = LeaseEscalation::where('lease_id', $lease->id)
            ->whereDate('effective_date', '<=', $effective)
            ->orderByDesc('effective_date')->orderByDesc('id')->first();
        if ($before) {
            $previousAmount = (float) $before->new_rental_amount;
        } else {
            $first = LeaseEscalation::where('lease_id', $lease->id)->orderBy('effective_date')->orderBy('id')->first();
            $previousAmount = $first ? (float) $first->previous_rental_amount : (float) $lease->rental_amount;
        }
        $newAmount = (float) $validated['new_rental_amount'];

        LeaseEscalation::create([
            'lease_id' => $lease->id,
            'effective_date' => $effective,
            'previous_rental_amount' => $previousAmount,
            'new_rental_amount' => $newAmount,
            'escalation_rate_percent' => LeaseEscalation::computeRatePercent($previousAmount, $newAmount),
            'note' => $validated['note'] ?? null,
            'created_by_user_id' => $request->user()->id,
        ]);

        // A future effective date must not change the rent today: the row is
        // recorded now and applied once due (leases:apply-due-escalations
        // runs daily). A date that has already arrived applies immediately.
        $lease->applyDueEscalation();

        return redirect()->route('corex.leases.show', $lease)->with('success', 'Escalation recorded.');
    }

    /**
     * leases.md §3.8 — Archive: a reason is required; an active or draft lease is cancelled with it (the property is
     * released); every status can be archived. Archiving a lease that ends a tenancy needs the cancel permission.
     */
    public function archive(Request $request, Lease $lease): RedirectResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        $validated = $request->validate([
            'archive_reason' => ['required', 'string', 'max:500'],
        ]);

        $this->abortUnlessMayArchive($request, $lease->status);

        try {
            app(LeaseArchiveService::class)->archive($lease, $request->user(), $validated['archive_reason']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withInput()->withErrors($e->errors())->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('corex.leases.index')->with('success', 'Lease archived. Find it again under "Show archived".');
    }

    /**
     * Kept for existing callers (a bare DELETE): the same archive, with a default reason when none is sent — so a
     * direct DELETE can no longer hide an ACTIVE lease and leave its property let (leases.md §3.8).
     */
    public function destroy(Request $request, Lease $lease): RedirectResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        $validated = $request->validate([
            'archive_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->abortUnlessMayArchive($request, $lease->status);

        try {
            app(LeaseArchiveService::class)->archive($lease, $request->user(), $validated['archive_reason'] ?? 'Archived');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors())->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('corex.leases.index')->with('success', 'Lease archived.');
    }

    public function restore(Request $request, int $lease): RedirectResponse
    {
        $leaseModel = Lease::withTrashed()->findOrFail($lease);
        $this->guardRentalRecordScope($leaseModel, 'leases', $leaseModel->branch_id);

        // A lease that was active or a draft comes back as one — bringing a tenancy back needs the cancel permission.
        $this->abortUnlessMayArchive($request, $leaseModel->archived_from_status ?: $leaseModel->status);

        try {
            app(LeaseArchiveService::class)->restore($leaseModel, $request->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors())->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('corex.leases.show', $leaseModel)->with('success', 'Lease restored.');
    }

    private function abortUnlessMayArchive(Request $request, ?string $status): void
    {
        if (in_array($status, [Lease::STATUS_DRAFT, Lease::STATUS_ACTIVE], true)) {
            abort_unless($request->user()->hasPermission('leases.cancel'), 403);
        }
    }
}
