<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\LeaseEscalation;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\PropertySettingItem;
use App\Models\RentalApplication;
use App\Services\Rentals\LeaseActivationService;
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
    /**
     * The Leases list screen. Search: property address, tenant name(s).
     * Sort: end_date (default, ascending — soonest to expire first), start_date,
     * status, property. Filter: status, type of date range, property, branch.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        $sort = $request->get('sort', 'end_date');
        $direction = $request->get('direction', 'asc');
        $allowedSorts = ['end_date', 'start_date', 'status', 'property'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'end_date';
        }
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        $query = Lease::query()
            ->visibleTo($user, $request->get('scope'))
            ->with(['property', 'tenants.contact']);

        if ($search = trim((string) $request->get('q', ''))) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('property', function ($p) use ($search) {
                    $p->searchAddress($search);
                })->orWhereHas('tenants.contact', function ($c) use ($search) {
                    $c->where(function ($cc) use ($search) {
                        $cc->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
                });
            });
        }

        if ($status = $request->get('status')) {
            $query->where('leases.status', $status);
        }

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

        if ($sort === 'property') {
            $query->join('properties', 'properties.id', '=', 'leases.property_id')
                ->orderBy('properties.title', $direction)
                ->select('leases.*');
        } else {
            $query->orderBy("leases.{$sort}", $direction);
        }

        $hasAnyLeases = Lease::query()->visibleTo($user, $request->get('scope'))->exists();

        $leases = $query->paginate(25)->withQueryString();

        return view('corex.leases.index', [
            'leases' => $leases,
            'sort' => $sort,
            'direction' => $direction,
            'hasAnyLeases' => $hasAnyLeases,
            'filters' => $request->only(['q', 'status', 'property_id', 'branch_id', 'date_from', 'date_to']),
        ]);
    }

    public function create(Request $request): View
    {
        $property = $request->get('property_id') ? Property::findOrFail($request->get('property_id')) : null;
        $rentalApplication = $request->get('rental_application_id')
            ? RentalApplication::findOrFail($request->get('rental_application_id'))
            : null;

        return view('corex.leases.create', [
            'property' => $property,
            'rentalApplication' => $rentalApplication,
            // .ai/specs/rental-property-tab.md §5, Part 4 — same agency-editable
            // list as the property screen's Lease Type select; one source of
            // truth for both, replacing this form's own hardcoded array.
            'leaseTypes' => PropertySettingItem::group('lease_type')->where('active', true)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'property_id' => ['required', 'exists:properties,id'],
            'rental_amount' => ['required', 'numeric', 'min:0'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'is_month_to_month' => ['nullable', 'boolean'],
            'lease_type' => ['nullable', 'string', 'max:40'],
            'rental_application_id' => ['nullable', 'exists:rental_applications,id'],
            'tenant_contact_ids' => ['required', 'array', 'min:1'],
            'tenant_contact_ids.*' => ['exists:contacts,id'],
            'activate_immediately' => ['nullable', 'boolean'],
        ]);

        $property = Property::findOrFail($validated['property_id']);
        $user = $request->user();

        $lease = Lease::create([
            'agency_id' => $property->agency_id,
            'branch_id' => $property->branch_id,
            'property_id' => $property->id,
            'status' => Lease::STATUS_DRAFT,
            'rental_amount' => $validated['rental_amount'],
            'deposit_amount' => $validated['deposit_amount'] ?? null,
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'] ?? null,
            'is_month_to_month' => (bool) ($validated['is_month_to_month'] ?? false),
            'lease_type' => $validated['lease_type'] ?? null,
            'source' => !empty($validated['rental_application_id']) ? 'rental_application' : 'manual',
            'rental_application_id' => $validated['rental_application_id'] ?? null,
            'created_by_user_id' => $user->id,
        ]);

        foreach ($validated['tenant_contact_ids'] as $index => $contactId) {
            LeaseTenant::create([
                'lease_id' => $lease->id,
                'contact_id' => $contactId,
                'is_primary' => $index === 0,
            ]);
        }

        if ($request->boolean('activate_immediately')) {
            app(LeaseActivationService::class)->activate($lease);
        }

        return redirect()->route('corex.leases.show', $lease)->with('success', 'Lease created.');
    }

    public function show(Request $request, Lease $lease): View
    {
        $lease->load(['property', 'tenants.contact', 'escalations.createdByUser', 'previousLease', 'renewedLease']);

        return view('corex.leases.show', [
            'lease' => $lease,
            // .ai/specs/rental-property-tab.md §5, Part 4 — same list as create().
            'leaseTypes' => PropertySettingItem::group('lease_type')->where('active', true)->get(),
            // Johan, 2026-09-22 — agency-configurable, hidden by default.
            'showLeaseType' => \App\Models\LeaseSetting::showLeaseTypeFieldFor($lease->agency_id),
        ]);
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
        $validated = $request->validate([
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'end_date' => ['nullable', 'date', 'after:' . $lease->start_date->format('Y-m-d')],
            'is_month_to_month' => ['nullable', 'boolean'],
            'lease_type' => ['nullable', 'string', 'max:40'],
            // .ai/specs/rental-work-orders.md §3.4b, Johan's ruling 2026-09-26
            // — the spend-threshold override lives here, on the lease. Null
            // (cleared) means "use the agency default."
            'rental_no_approval_spend_threshold' => ['nullable', 'numeric', 'min:0'],
        ]);

        $lease->update([
            'deposit_amount' => array_key_exists('deposit_amount', $validated) ? $validated['deposit_amount'] : $lease->deposit_amount,
            'end_date' => array_key_exists('end_date', $validated) ? $validated['end_date'] : $lease->end_date,
            'is_month_to_month' => $request->boolean('is_month_to_month'),
            'lease_type' => $validated['lease_type'] ?? $lease->lease_type,
            'rental_no_approval_spend_threshold' => array_key_exists('rental_no_approval_spend_threshold', $validated) ? $validated['rental_no_approval_spend_threshold'] : $lease->rental_no_approval_spend_threshold,
        ]);

        return redirect()->route('corex.leases.show', $lease)->with('success', 'Lease updated.');
    }

    public function activate(Request $request, Lease $lease): RedirectResponse
    {
        try {
            app(LeaseActivationService::class)->activate($lease);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('corex.leases.show', $lease)->with('success', 'Lease activated.');
    }

    public function cancel(Request $request, Lease $lease): RedirectResponse
    {
        $validated = $request->validate([
            'cancel_reason' => ['required', 'string', 'max:500'],
        ]);

        $lease->update([
            'status' => Lease::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by_user_id' => $request->user()->id,
            'cancel_reason' => $validated['cancel_reason'],
        ]);

        return redirect()->route('corex.leases.show', $lease)->with('success', 'Lease cancelled.');
    }

    /**
     * .ai/specs/leases.md §3.4 — escalation as a RATE, computed and stored
     * at entry time, never re-derived later.
     */
    public function escalate(Request $request, Lease $lease): RedirectResponse
    {
        $validated = $request->validate([
            'effective_date' => ['required', 'date'],
            'new_rental_amount' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $previousAmount = (float) $lease->rental_amount;
        $newAmount = (float) $validated['new_rental_amount'];

        LeaseEscalation::create([
            'lease_id' => $lease->id,
            'effective_date' => $validated['effective_date'],
            'previous_rental_amount' => $previousAmount,
            'new_rental_amount' => $newAmount,
            'escalation_rate_percent' => LeaseEscalation::computeRatePercent($previousAmount, $newAmount),
            'note' => $validated['note'] ?? null,
            'created_by_user_id' => $request->user()->id,
        ]);

        $lease->update(['rental_amount' => $newAmount]);

        return redirect()->route('corex.leases.show', $lease)->with('success', 'Escalation recorded.');
    }

    public function destroy(Request $request, Lease $lease): RedirectResponse
    {
        if (!$lease->isDeletable()) {
            return back()->withErrors(['lease' => 'This lease has escalation history and cannot be deleted — cancel it instead.']);
        }

        $lease->delete();

        return redirect()->route('corex.leases.index')->with('success', 'Lease archived.');
    }

    public function restore(Request $request, int $lease): RedirectResponse
    {
        $leaseModel = Lease::withTrashed()->findOrFail($lease);
        $leaseModel->restore();

        return redirect()->route('corex.leases.show', $leaseModel)->with('success', 'Lease restored.');
    }
}
