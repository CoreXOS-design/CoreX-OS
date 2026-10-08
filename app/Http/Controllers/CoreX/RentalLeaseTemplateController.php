<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\Template;
use App\Models\RentalLeaseTemplate;
use App\Services\Rentals\LeaseAgreementTemplateGuard;
use App\Services\Rentals\LeaseAgreementValuesReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * .ai/specs/rental-renewals.md §5(b)/§9 — GATE 1 (approved 2026-10-04).
 * Rentals → Settings → Rental Lease Templates. Full list-screen floor
 * (BUILD_STANDARD §1b): search by name, sort name/category/active
 * (default: name asc), filter by category, pagination, archive/restore,
 * real empty state.
 *
 * .ai/specs/leases.md §15.12 (Build L0): this is also where an agency links ITS OWN lease agreement to
 * the lease process and maps its fields. The picker lists only the agency's own e-sign templates, every
 * save goes through LeaseAgreementTemplateGuard, and the field map is stored on the row.
 */
class RentalLeaseTemplateController extends Controller
{
    /**
     * Name fragments that suggest which of a template's own fields holds a registry key (§15.12.3).
     * A suggestion only pre-selects a dropdown; nothing is saved until the admin presses Save.
     */
    private const SUGGESTION_HINTS = [
        'rent' => ['monthly_rental', 'rental_amount', 'monthly_rent', 'rent'],
        'start_date' => ['lease_start', 'commencement', 'start_date'],
        'end_date' => ['lease_end', 'expiry', 'end_date', 'termination_date'],
        'deposit' => ['deposit'],
        'tenant_name' => ['lessee_name', 'tenant_name', 'lessee'],
        'tenant_address' => ['lessee_address', 'tenant_address'],
        'tenant_id' => ['lessee_id', 'tenant_id'],
        'landlord_name' => ['lessor_full', 'lessor_name', 'landlord_name', 'lessor'],
        'landlord_address' => ['lessor_address', 'landlord_address'],
        'landlord_id' => ['lessor_id', 'landlord_id'],
        'adults' => ['adults', 'occupants'],
        'max_other_persons' => ['other_persons', 'max_other', 'kids'],
        'pets' => ['pets'],
        'escalation_percent' => ['escalation_percent', 'escalation'],
        'escalation_month' => ['escalation_month'],
        'earliest_termination_date' => ['notice_date', 'earliest_termination', 'min_term'],
        'renewal_option_months' => ['renewal_period', 'renewal_months', 'renew'],
        'notice_period' => ['notice_period', 'notice_days', 'notice_length', 'notice_months'],
        'notice_period_unit' => ['notice_unit', 'notice_period_unit'],
        'earliest_notice_date' => ['earliest_notice', 'notice_from', 'notice_not_before'],
        'early_cancellation_allowed' => ['early_cancellation_allowed', 'early_cancel_allowed', 'early_termination_allowed'],
        'early_cancellation_notice' => ['early_cancellation_notice', 'early_cancel_notice', 'early_termination_notice'],
        'early_cancellation_notice_unit' => ['early_cancellation_unit', 'early_cancel_unit'],
        'early_cancellation_penalty' => ['penalty', 'cancellation_fee', 'early_cancellation_penalty'],
        'electricity_arrangement' => ['electricity'],
        'other_conditions' => ['other_conditions', 'additional_conditions'],
        'property_description' => ['property_full', 'property_description', 'property_address'],
        'rent_in_words' => ['price_in_words', 'rental_in_words', 'rental_amount_words'],
        'escalation_in_words' => ['escalation_alpha', 'escalation_in_words'],
        'net_to_owner' => ['owner_nett', 'net_to_owner', 'net_amount'],
    ];

    /** How many numbered members (`tenant_name_1` …) of an indexed key the panel offers. */
    private const INDEXED_LINES = 3;

    public function index(Request $request): View
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId();

        $sort = $request->get('sort', 'name');
        $direction = $request->get('direction', 'asc') === 'desc' ? 'desc' : 'asc';
        if (!in_array($sort, ['name', 'category', 'is_active'], true)) {
            $sort = 'name';
        }

        $showArchived = $request->boolean('archived');
        $query = $showArchived ? RentalLeaseTemplate::onlyTrashed() : RentalLeaseTemplate::query();
        $query->with('template')->orderBy($sort, $direction);

        if ($search = trim((string) $request->get('q', ''))) {
            $query->where('name', 'like', "%{$search}%");
        }
        if ($category = $request->get('category')) {
            $query->where('category', $category);
        }

        $hasAny = RentalLeaseTemplate::query()->exists();
        $templates = $query->paginate(25)->withQueryString();

        // The chip on each row (Ready / Needs field map / Not usable: reason) is worked out live, so a
        // template archived since the last save shows as not usable straight away.
        $guard = app(LeaseAgreementTemplateGuard::class);
        $statuses = [];
        foreach ($templates as $row) {
            $statuses[$row->id] = $row->category === RentalLeaseTemplate::CATEGORY_RESIDENTIAL && $agencyId
                ? $guard->statusFor($row, (int) $agencyId)
                : null;
        }

        return view('corex.rental-lease-templates.index', [
            'templates' => $templates,
            'statuses' => $statuses,
            'sort' => $sort,
            'direction' => $direction,
            'filters' => $request->only(['q', 'category', 'archived']),
            'hasAny' => $hasAny,
            'showArchived' => $showArchived,
            'categories' => \App\Models\RentalLeaseTemplate::CATEGORIES,
        ]);
    }

    public function create(Request $request): View
    {
        $agencyId = $request->user()->effectiveAgencyId();

        return view('corex.rental-lease-templates.create', [
            'categories' => RentalLeaseTemplate::CATEGORIES,
            'availableTemplates' => $this->ownEsignTemplates($agencyId),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'docuperfect_template_id' => ['required', 'integer'],
            'category' => ['required', 'string', 'in:' . implode(',', RentalLeaseTemplate::CATEGORIES)],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $agencyId = (int) $request->user()->effectiveAgencyId();
        $guard = app(LeaseAgreementTemplateGuard::class);

        // A missing id and another agency's template are both the same plain 404 — a forged id reveals
        // nothing about other agencies' templates (Template is deliberately not agency-scoped, so this is
        // checked here, not by a global scope). An ownerless (shared) one, an archived one or a non-e-sign
        // one is refused by the guard with its reason.
        $template = Template::find($validated['docuperfect_template_id']);
        abort_if(! $template || ($template->agency_id !== null && (int) $template->agency_id !== $agencyId), 404);
        $refusals = $guard->refusalsFor($template, $agencyId);
        if ($refusals !== []) {
            throw ValidationException::withMessages(['docuperfect_template_id' => $refusals]);
        }

        $row = DB::transaction(function () use ($validated, $agencyId, $request) {
            $row = RentalLeaseTemplate::create([
                'agency_id' => $agencyId,
                'name' => $validated['name'],
                'docuperfect_template_id' => $validated['docuperfect_template_id'],
                'category' => $validated['category'],
                'is_active' => true,
            ]);
            if ($request->boolean('is_default')) {
                $this->makeDefault($row);
            }

            return $row;
        });

        $problems = $guard->recordCheck($row->fresh(['template']), $agencyId);

        return redirect()
            ->route('corex.rental-lease-templates.edit', $row)
            ->with('success', $problems === [] ? 'Lease agreement linked.' : 'Lease agreement added — it still needs attention before it can be used (see below).');
    }

    public function edit(Request $request, RentalLeaseTemplate $rentalLeaseTemplate): View
    {
        $agencyId = (int) $request->user()->effectiveAgencyId();
        $guard = app(LeaseAgreementTemplateGuard::class);
        $template = $rentalLeaseTemplate->template;
        $names = $template ? $guard->fieldNamesFor($template) : [];

        return view('corex.rental-lease-templates.edit', [
            'rentalLeaseTemplate' => $rentalLeaseTemplate,
            'categories' => RentalLeaseTemplate::CATEGORIES,
            'status' => $rentalLeaseTemplate->category === RentalLeaseTemplate::CATEGORY_RESIDENTIAL
                ? $guard->statusFor($rentalLeaseTemplate, $agencyId)
                : null,
            'mapLines' => $this->mapLines(),
            'templateFieldNames' => $names,
            'savedMap' => app(LeaseAgreementValuesReader::class)->normaliseMap((array) ($rentalLeaseTemplate->field_map ?? [])),
            'monthToMonth' => ! empty($rentalLeaseTemplate->field_map['_meta']['month_to_month']),
            'suggestions' => $this->suggestions($names),
        ]);
    }

    public function update(Request $request, RentalLeaseTemplate $rentalLeaseTemplate): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'category' => ['required', 'string', 'in:' . implode(',', RentalLeaseTemplate::CATEGORIES)],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $agencyId = (int) $request->user()->effectiveAgencyId();

        DB::transaction(function () use ($rentalLeaseTemplate, $validated, $request) {
            $rentalLeaseTemplate->update([
                'name' => $validated['name'],
                'category' => $validated['category'],
                'is_active' => $request->boolean('is_active'),
                'is_default' => false,
            ]);
            if ($request->boolean('is_default')) {
                $this->makeDefault($rentalLeaseTemplate);
            }
        });

        app(LeaseAgreementTemplateGuard::class)->recordCheck($rentalLeaseTemplate->fresh(['template']), $agencyId);

        return redirect()->route('corex.rental-lease-templates.index')->with('success', 'Template updated.');
    }

    /**
     * §15.12.3 — save which of the agreement's own fields holds each registry key. Only keys the panel
     * offers are read; a field must be one of the template's own (when it has any); "required for
     * signing" only counts for the agreement-term and schedule keys. Nothing is suggested into the map
     * without this save.
     */
    public function updateFieldMap(Request $request, RentalLeaseTemplate $rentalLeaseTemplate): RedirectResponse
    {
        $request->validate([
            'map' => ['nullable', 'array'],
            'map.*.field' => ['nullable', 'string', 'max:191'],
            'map.*.required' => ['nullable', 'boolean'],
            'map.*.label' => ['nullable', 'string', 'max:100'],
            'month_to_month' => ['nullable', 'boolean'],
        ]);

        $agencyId = (int) $request->user()->effectiveAgencyId();
        $guard = app(LeaseAgreementTemplateGuard::class);
        $template = $rentalLeaseTemplate->template;
        $names = $template ? $guard->fieldNamesFor($template) : [];
        $registry = (array) config('lease-agreement-fields.fields', []);
        $requirable = (array) config('lease-agreement-fields.requirable_groups', []);

        $map = [];
        $errors = [];
        foreach ($this->mapLines() as $key => $line) {
            $posted = $request->input("map.$key", []);
            $field = trim((string) ($posted['field'] ?? ''));
            if ($field === '') {
                continue;
            }
            if ($names !== [] && ! in_array($field, $names, true)) {
                $errors["map.$key.field"] = 'Pick one of this agreement\'s own fields.';
                continue;
            }

            $group = (string) ($registry[$line['base']]['group'] ?? '');
            $label = trim((string) ($posted['label'] ?? ''));
            $map[$key] = [
                'field' => $field,
                'required' => in_array($group, $requirable, true) && ! empty($posted['required']),
                'label' => $group === 'schedule' && $label !== '' ? $label : null,
            ];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        if ($request->boolean('month_to_month')) {
            $map['_meta'] = ['month_to_month' => true];
        }

        $rentalLeaseTemplate->update(['field_map' => $map === [] ? null : $map]);
        $problems = $guard->recordCheck($rentalLeaseTemplate->fresh(['template']), $agencyId);

        return redirect()
            ->route('corex.rental-lease-templates.edit', $rentalLeaseTemplate)
            ->with('success', $problems === [] ? 'Field map saved — this lease agreement is ready to use.' : 'Field map saved.');
    }

    public function destroy(RentalLeaseTemplate $rentalLeaseTemplate): RedirectResponse
    {
        $rentalLeaseTemplate->delete();

        return redirect()->route('corex.rental-lease-templates.index')->with('success', 'Template archived.');
    }

    public function restore(int $rentalLeaseTemplate): RedirectResponse
    {
        $template = RentalLeaseTemplate::onlyTrashed()->findOrFail($rentalLeaseTemplate);
        $template->restore();

        return redirect()->route('corex.rental-lease-templates.index')->with('success', 'Template restored.');
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────

    /**
     * The picker: ONLY the acting agency's own, active e-sign templates (§15.12.2). Never
     * Template::applySharedWith() — that also lists the ownerless built-in templates, which is exactly
     * how another agency's lease used to be offered to everyone.
     */
    private function ownEsignTemplates(?int $agencyId)
    {
        if (! $agencyId) {
            return collect();
        }

        return Template::query()
            ->where('agency_id', $agencyId)
            ->where('is_esign', true)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name', 'render_type']);
    }

    /** One default per agency + category, kept inside the caller's transaction. */
    private function makeDefault(RentalLeaseTemplate $row): void
    {
        // Lock the agency's rows for this category first, so two admins ticking "default" at once
        // cannot both win (an UPDATE alone does not take the lock).
        $siblingIds = RentalLeaseTemplate::query()
            ->where('agency_id', $row->agency_id)
            ->where('category', $row->category)
            ->where('id', '!=', $row->id)
            ->lockForUpdate()
            ->pluck('id');

        RentalLeaseTemplate::query()->whereIn('id', $siblingIds)->update(['is_default' => false]);
        $row->update(['is_default' => true]);
    }

    /**
     * The lines of the field-map panel: every registry key, with the per-party families
     * (`tenant_name`, `tenant_name_1` …) expanded.
     *
     * @return array<string, array{base: string, label: string, group: string}>
     */
    private function mapLines(): array
    {
        $lines = [];
        foreach ((array) config('lease-agreement-fields.fields', []) as $key => $def) {
            $lines[$key] = ['base' => $key, 'label' => (string) $def['label'], 'group' => (string) $def['group']];
            if (! empty($def['indexed'])) {
                for ($i = 1; $i <= self::INDEXED_LINES; $i++) {
                    $lines["{$key}_{$i}"] = ['base' => $key, 'label' => $def['label'] . ' ' . $i, 'group' => (string) $def['group']];
                }
            }
        }

        return $lines;
    }

    /**
     * Best-guess field per key by name similarity. Only offered, never saved.
     *
     * @param array<int,string> $names
     * @return array<string,string>
     */
    private function suggestions(array $names): array
    {
        $out = [];
        foreach (self::SUGGESTION_HINTS as $key => $hints) {
            foreach ($hints as $hint) {
                foreach ($names as $name) {
                    if (strtolower($name) === $hint) {
                        $out[$key] = $name;
                        continue 3;
                    }
                }
            }
            foreach ($hints as $hint) {
                foreach ($names as $name) {
                    if (str_contains(strtolower($name), $hint)) {
                        $out[$key] = $name;
                        continue 3;
                    }
                }
            }
        }

        return $out;
    }
}
