<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\RentalApplicationCustomField;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * .ai/specs/rental-application-field-config.md §7, piece (c)(1) — the
 * definition side only. Full CRUD (create/update/archive/restore/reorder),
 * same shape as RentalApplicationHighlighterController — the established
 * pattern for a small agency-owned, reorderable, archive-not-delete list
 * in this module.
 */
class RentalApplicationCustomFieldController extends Controller
{
    public function store(Request $request)
    {
        $agencyId = $this->resolveAgencyId($request);

        $validated = $this->validateField($request);

        $key = RentalApplicationCustomField::generateKey($agencyId, $validated['label']);

        RentalApplicationCustomField::create([
            'agency_id' => $agencyId,
            'key' => $key,
            'label' => $validated['label'],
            'help_text' => $validated['help_text'] ?? null,
            'field_type' => $validated['field_type'],
            'options' => $validated['options'],
            'required' => $request->boolean('required'),
            'shown' => true,
            'created_by' => $request->user()->id,
            'sort_order' => $this->nextSortOrder($agencyId),
        ]);

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', "Custom field '{$validated['label']}' added.");
    }

    public function update(Request $request, RentalApplicationCustomField $customField)
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
        ]);

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', "Custom field '{$customField->label}' updated.");
    }

    public function archive(Request $request, RentalApplicationCustomField $customField)
    {
        $this->authorizeAgency($request, $customField);

        $customField->delete();

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', "Custom field '{$customField->label}' retired — applications that already answered it keep that answer; it just can't be added to new ones.");
    }

    public function restore(Request $request, int $customField)
    {
        $row = RentalApplicationCustomField::withTrashed()->findOrFail($customField);
        $this->authorizeAgency($request, $row);

        $row->restore();

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', "Custom field '{$row->label}' restored.");
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
                RentalApplicationCustomField::where('id', $id)
                    ->where('agency_id', $agencyId)
                    ->update(['sort_order' => $position]);
            }
        });

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', 'Custom fields reordered.');
    }

    /**
     * `options_text` — one comma-separated line ("Small, Medium, Large"),
     * not a repeating array of inputs: a choice list is typically a handful
     * of short words, and a single line stays inside this screen's own
     * one-row-per-field layout instead of needing a nested add/remove
     * sub-form per custom field.
     */
    private function validateField(Request $request): array
    {
        $agencyId = $this->resolveAgencyId($request);
        $excludeId = $request->route('customField') instanceof RentalApplicationCustomField
            ? $request->route('customField')->id
            : null;

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:150'],
            'help_text' => ['nullable', 'string', 'max:1000'],
            'field_type' => ['required', Rule::in(RentalApplicationCustomField::FIELD_TYPES)],
            'options_text' => ['required_if:field_type,' . RentalApplicationCustomField::TYPE_CHOICE_LIST, 'nullable', 'string', 'max:1000'],
            'required' => ['nullable'],
            'shown' => ['nullable'],
        ]);

        $this->assertLabelNotDuplicated($agencyId, $validated['label'], $excludeId);

        if ($validated['field_type'] === RentalApplicationCustomField::TYPE_CHOICE_LIST) {
            $options = array_values(array_filter(array_map('trim', explode(',', $validated['options_text'] ?? ''))));
            if (empty($options)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'options_text' => 'A choice list needs at least one option.',
                ]);
            }
            $validated['options'] = $options;
        } else {
            $validated['options'] = null;
        }

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
        return (int) (RentalApplicationCustomField::withTrashed()->where('agency_id', $agencyId)->max('sort_order') ?? -1) + 1;
    }

    /**
     * Scoped to ACTIVE rows only, same reasoning as
     * RentalApplicationHighlighterController::assertLabelNotDuplicated() —
     * a retired field must never block adding a fresh one under the same
     * label; the DB has no unique constraint to lean on here (see the
     * create migration's own docblock on the MySQL NULL-uniqueness gotcha),
     * so this is the only place duplication is actually prevented.
     */
    private function assertLabelNotDuplicated(int $agencyId, string $label, ?int $excludeId = null): void
    {
        $exists = RentalApplicationCustomField::where('agency_id', $agencyId)
            ->where('label', $label)
            ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
            ->exists();

        if ($exists) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'label' => "A custom field named '{$label}' already exists. Choose a different label, or retire the existing one first.",
            ]);
        }
    }

    private function authorizeAgency(Request $request, RentalApplicationCustomField $customField): void
    {
        if ($customField->agency_id !== $this->resolveAgencyId($request)) {
            abort(403, 'Cross-agency access denied.');
        }
    }
}
