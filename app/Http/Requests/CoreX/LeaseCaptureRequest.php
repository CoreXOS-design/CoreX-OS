<?php

namespace App\Http\Requests\CoreX;

use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Concerns\ValidatesDocumentUploads;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalApplication;
use App\Services\Rentals\LeaseAgentService;
use App\Services\Rentals\LeaseCaptureService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * .ai/specs/leases.md §15.2 / §15.3 (Build L2) — the ONE validator behind the single capture screen, for a
 * new lease (the web screen and the API) and for a renewal. The mode is read from the request itself: a
 * `{lease}` route parameter (the renewal screen) or `previous_lease_id` (the API) means a renewal.
 *
 * A new lease keeps every rule the New Lease screen already had (leases.md §7.2/§7.3): only a rental
 * property the acting user may see under their own own/branch/agency properties scope — the SAME query as
 * the picker — the plain "choose a property" message, same-agency tenants and rental application.
 * A renewal ignores any tenant field posted (R7): the service rebuilds the tenants from the previous term.
 *
 * Permission by mode lives here as well as on the routes, so the API (which has no per-mode middleware)
 * is held to the same rule: new → leases.create, renewal → leases.renew + own/branch/agency scope.
 *
 * The agreement details are validated only for the fields the agency's own linked lease carries
 * (LeaseCaptureService::agreementFields) — anything else posted is dropped.
 */
class LeaseCaptureRequest extends FormRequest
{
    use AuthorizesRentalRecordScope;
    use ValidatesDocumentUploads;

    private ?Lease $previous = null;
    private bool $previousResolved = false;

    /** The lease being renewed, or null for a new lease. */
    public function previousLease(): ?Lease
    {
        if ($this->previousResolved) {
            return $this->previous;
        }
        $this->previousResolved = true;

        $route = $this->route('lease');
        if ($route instanceof Lease) {
            return $this->previous = $route;
        }

        $id = $this->input('previous_lease_id');
        if (is_scalar($id) && (int) $id > 0) {
            return $this->previous = Lease::query()->find((int) $id);
        }

        return null;
    }

    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        $previous = $this->previousLease();
        if ($previous) {
            // Direct-URL / forged-id access to another agency's lease is a 404 (the global scope above
            // already returned null for it); own/branch scope is checked here, before anything is read.
            $this->guardRentalRecordScope($previous, 'leases', $previous->branch_id);

            return $user->hasPermission('leases.renew');
        }

        // A previous_lease_id that points at nothing visible is a clean error (rules()), not a new lease.
        return $user->hasPermission('leases.create');
    }

    protected function prepareForValidation(): void
    {
        // Enter-key submits and older callers post no button: that is "Create lease only", exactly as before.
        if (! is_string($this->input('intent')) || $this->input('intent') === '') {
            $this->merge(['intent' => LeaseCaptureService::INTENT_LEASE_ONLY]);
        }
    }

    public function rules(): array
    {
        $user = $this->user();
        $previous = $this->previousLease();
        $isPaper = $this->input('intent') === LeaseCaptureService::INTENT_PAPER_COPY;

        $rules = [
            'intent' => ['required', Rule::in(LeaseCaptureService::INTENTS)],
            'capture_key' => ['nullable', 'string', 'max:100'],
            'rental_amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'is_month_to_month' => ['nullable', 'boolean'],
            'lease_type' => ['nullable', 'string', 'max:40'],
            'activate_immediately' => ['nullable', 'boolean'],
            'rent_above_approved_reason' => ['nullable', 'string', 'max:1000'],
            'agreement_id' => ['nullable', 'integer'],
            'signed_document' => $isPaper ? $this->documentUploadRule(20480) : ['nullable', 'file'],
        ];

        // leases.md §17 — the owner's and the tenant's agent: optional here (a blank side gets the default), but a value
        // must be an active user of the lease's own agency — the SAME rule the dropdown is built from.
        $agentAgencyId = (int) ($previous?->agency_id ?? $user->effectiveAgencyId());
        foreach (LeaseAgentService::SIDES as $side) {
            $rules[LeaseAgentService::column($side)] = ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) use ($agentAgencyId, $side) {
                if ($value !== null && $value !== '' && ! app(LeaseAgentService::class)->isSelectable((int) $value, $agentAgencyId)) {
                    $fail('Choose ' . strtolower(LeaseAgentService::sideLabel($side)) . ' from the list.');
                }
            }];
        }

        if ($this->filled('previous_lease_id') && ! $previous) {
            $rules['previous_lease_id'] = [function (string $attribute, mixed $value, \Closure $fail) {
                $fail('That lease is no longer available.');
            }];
        }

        if (! $previous) {
            $agencyId = $user->effectiveAgencyId();
            // Only a rental property this user may see (own/branch/agency) — the SAME query as the
            // picker. A sale listing, another agent's/branch's/agency's property and a made-up id all
            // fail identically (nothing to enumerate).
            $rules['property_id'] = ['bail', 'required', 'integer', function (string $attribute, mixed $value, \Closure $fail) use ($user) {
                if (! Property::query()->rentalVisibleTo($user)->whereKey($value)->exists()) {
                    $fail('Please choose a property from the list.');
                }
            }];
            // Same-agency only — a bare exists: would accept any agency's row.
            // The SAME query the New Lease screen opens an application with (visibleTo: own / branch / agency): a bare
            // same-agency check accepted any application in the agency, and linking one re-points its property and
            // links its applicant as the property's tenant.
            $rules['rental_application_id'] = ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) use ($user) {
                if ($value !== null && $value !== '' && ! RentalApplication::query()->visibleTo($user)->whereKey($value)->exists()) {
                    $fail('That rental application is no longer available.');
                }
            }];
            $rules['tenant_contact_ids'] = ['required', 'array', 'min:1'];
            // distinct: the same contact twice is a validation error, not a unique-index 500 on lease_tenants.
            $rules['tenant_contact_ids.*'] = ['distinct', Rule::exists('contacts', 'id')->where('agency_id', $agencyId)];
        }

        foreach ($this->selectedAgreementFields() as $field) {
            $rules['agreement.' . $field['key']] = $this->agreementRule($field);
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'property_id.required' => 'Please choose a property from the list.',
            'property_id.integer' => 'Please choose a property from the list.',
            'tenant_contact_ids.required' => 'At least one tenant is required.',
            'tenant_contact_ids.min' => 'At least one tenant is required.',
            'end_date.after' => 'The end date must be after the start date.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $data = $validator->getData();

            // A fixed-term lease has an end date, a month-to-month one has none — never both (§15.3).
            if (filter_var($data['is_month_to_month'] ?? false, FILTER_VALIDATE_BOOLEAN) && ! empty($data['end_date'])) {
                $validator->errors()->add('end_date', 'A month-to-month lease has no end date — clear one or the other.');
            }

            // The linked application must be for THIS property (audit M6/L2).
            if (! $this->previousLease() && ! empty($data['rental_application_id']) && is_scalar($data['property_id'] ?? null)
                && ! $validator->errors()->has('rental_application_id') && ! $validator->errors()->has('property_id')) {
                $application = RentalApplication::query()->find($data['rental_application_id']);
                // An APPROVED application's property is chosen on the lease screen itself (it is re-pointed when
                // the lease is created), so only an application still in progress is held to its own property.
                if ($application && $application->status !== 'approved' && $application->property_id !== null && (int) $application->property_id !== (int) $data['property_id']) {
                    $validator->errors()->add('rental_application_id', 'That rental application is for a different property.');
                }

                // Johan, QA1, 2026-10-07 — a lease rent above what the tenant was approved for: the agency's
                // policy decides (block, or warn-and-confirm with a reason). Never silent.
                if ($application && ! $validator->errors()->has('rental_amount') && ($gap = $application->rentAboveApproved($data['rental_amount'] ?? null))) {
                    $figures = 'approved for R' . number_format($gap['approved'], 2) . ' a month; the lease rent is R' . number_format($gap['rent'], 2);
                    if ($gap['mode'] === 'block') {
                        $validator->errors()->add('rental_amount', "This tenant was {$figures}, and your agency does not allow a lease above the approved amount.");
                    } elseif (trim((string) ($data['rent_above_approved_reason'] ?? '')) === '') {
                        $validator->errors()->add('rent_above_approved_reason', "This tenant was {$figures}. Give a reason to confirm it.");
                    }
                }
            }
        });
    }

    /**
     * The agreement details the agency's own (selected or default) lease carries, from the guard-checked
     * list of its ready agreements — an id that is not the agency's own is simply not found, so a forged
     * one validates nothing and the service refuses it for signing with the generic message.
     *
     * @return array<int, array<string, mixed>>
     */
    public function selectedAgreementFields(): array
    {
        $service = app(LeaseCaptureService::class);
        // A renewal belongs to the lease's own agency (an owner-role user may be working across agencies).
        $state = $service->agreementStateFor((int) ($this->previousLease()?->agency_id ?? $this->user()->effectiveAgencyId()));
        if ($state['state'] !== 'ready') {
            return [];
        }

        $id = $this->input('agreement_id');
        $row = is_scalar($id) && $id !== '' ? $state['agreements']->firstWhere('id', (int) $id) : null;

        return $service->agreementFields($row ?? $state['agreements']->first());
    }

    /** @param array<string,mixed> $field */
    private function agreementRule(array $field): array
    {
        return match ($field['type']) {
            'integer' => ['nullable', 'integer', 'min:0', 'max:32000'],
            'percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'date' => $field['key'] === 'earliest_termination_date'
                ? ['nullable', 'date', 'after_or_equal:start_date']
                : ['nullable', 'date'],
            'money' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            default => ['nullable', 'string', 'max:' . $field['max']],
        };
    }
}
