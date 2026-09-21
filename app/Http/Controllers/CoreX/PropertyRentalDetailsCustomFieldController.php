<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\PropertyRentalDetailsCustomField;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * .ai/specs/rental-property-tab.md §2/§8 Part 1 — the definition side only.
 * Full CRUD (create/update/archive/restore/reorder), same shape as
 * RentalApplicationCustomFieldController — the established pattern for a
 * small agency-owned, reorderable, archive-not-delete list in this codebase.
 */
class PropertyRentalDetailsCustomFieldController extends Controller
{
    public function store(Request $request)
    {
        $agencyId = $this->resolveAgencyId($request);

        $validated = $this->validateField($request);

        $key = PropertyRentalDetailsCustomField::generateKey($agencyId, $validated['label']);

        PropertyRentalDetailsCustomField::create([
            'agency_id' => $agencyId,
            'key' => $key,
            'label' => $validated['label'],
            'help_text' => $validated['help_text'] ?? null,
            'field_type' => $validated['field_type'],
            'options' => $validated['options'],
            'required' => $request->boolean('required'),
            'shown' => true,
            'advertise' => $request->boolean('advertise'),
            'created_by' => $request->user()->id,
            'sort_order' => $this->nextSortOrder($agencyId),
        ]);

        return redirect()->route('corex.settings.rental-details.edit')
            ->with('success', "Field '{$validated['label']}' added.");
    }

    public function update(Request $request, PropertyRentalDetailsCustomField $customField)
    {
        $this->authorizeAgency($request, $customField);

        $validated = $this->validateField($request);

        $customField->update([
            'label' => $validated['label'],
            'help_text' => $validated['help_text'] ?? null,
            'field_type' => $validated['field_type'],
            'options' => $validated['options'],
            'required' => $request->boolean('required'),
            'shown' => $request->boolean('shown'),
            'advertise' => $request->boolean('advertise'),
        ]);

        return redirect()->route('corex.settings.rental-details.edit')
            ->with('success', "Field '{$customField->label}' updated.");
    }

    public function archive(Request $request, PropertyRentalDetailsCustomField $customField)
    {
        $this->authorizeAgency($request, $customField);

        $customField->delete();

        return redirect()->route('corex.settings.rental-details.edit')
            ->with('success', "Field '{$customField->label}' retired — properties that already have a value keep it; it just can't be added to new ones.");
    }

    public function restore(Request $request, int $customField)
    {
        $row = PropertyRentalDetailsCustomField::withTrashed()->findOrFail($customField);
        $this->authorizeAgency($request, $row);

        $row->restore();

        return redirect()->route('corex.settings.rental-details.edit')
            ->with('success', "Field '{$row->label}' restored.");
    }

    public function reorder(Request $request)
    {
        $agencyId = $this->resolveAgencyId($request);

        $validated = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        DB::transaction(function () use ($validated, $agencyId) {
            foreach ($validated['order'] as $position => $id) {
                PropertyRentalDetailsCustomField::where('id', $id)
                    ->where('agency_id', $agencyId)
                    ->update(['sort_order' => $position]);
            }
        });

        return redirect()->route('corex.settings.rental-details.edit')
            ->with('success', 'Fields reordered.');
    }

    private function validateField(Request $request): array
    {
        $agencyId = $this->resolveAgencyId($request);
        $excludeId = $request->route('customField') instanceof PropertyRentalDetailsCustomField
            ? $request->route('customField')->id
            : null;

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:150'],
            'help_text' => ['nullable', 'string', 'max:1000'],
            'field_type' => ['required', Rule::in(PropertyRentalDetailsCustomField::FIELD_TYPES)],
            'required' => ['nullable'],
            'shown' => ['nullable'],
            'advertise' => ['nullable'],
        ]);

        $this->assertLabelNotDuplicated($agencyId, $validated['label'], $excludeId);

        // No choice-list type on this feature (spec §2.1) — options is
        // always null, unlike the sibling controller.
        $validated['options'] = null;

        return $validated;
    }

    private function resolveAgencyId(Request $request): int
    {
        $id = $request->user()->effectiveAgencyId();
        abort_if($id === null, 403, 'No agency context.');

        return (int) $id;
    }

    private function nextSortOrder(int $agencyId): int
    {
        return (int) (PropertyRentalDetailsCustomField::withTrashed()->where('agency_id', $agencyId)->max('sort_order') ?? -1) + 1;
    }

    /**
     * Scoped to ACTIVE rows only — a retired field must never block adding
     * a fresh one under the same label; there is no DB-level uniqueness to
     * lean on here (see the create migration's own docblock on the MySQL
     * NULL-uniqueness gotcha), so this is the only place duplication is
     * actually prevented.
     */
    private function assertLabelNotDuplicated(int $agencyId, string $label, ?int $excludeId = null): void
    {
        $exists = PropertyRentalDetailsCustomField::where('agency_id', $agencyId)
            ->where('label', $label)
            ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
            ->exists();

        if ($exists) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'label' => "A field named '{$label}' already exists. Choose a different label, or retire the existing one first.",
            ]);
        }
    }

    private function authorizeAgency(Request $request, PropertyRentalDetailsCustomField $customField): void
    {
        if ($customField->agency_id !== $this->resolveAgencyId($request)) {
            abort(403, 'Cross-agency access denied.');
        }
    }
}
