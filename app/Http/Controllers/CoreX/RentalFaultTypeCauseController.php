<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\RentalFaultType;
use App\Models\RentalFaultTypeCause;
use App\Models\RentalPayerRuling;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * .ai/specs/rentals-faults-work-orders.md section 22.13.5 - the "Who normally pays" lists on a fault type: its self-checks and its causes, each with a
 * default payer. Create, edit, archive, restore - never a hard delete. Permission `rental_fault_types.create`; the fault type binds through the agency scope
 * (another agency's type is a 404) and a row must belong to the type in the URL.
 */
class RentalFaultTypeCauseController extends Controller
{
    private function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(array_keys(RentalFaultTypeCause::KINDS))],
            'label' => ['required', 'string', 'min:2', 'max:191'],
            'default_payer' => ['nullable', Rule::in(array_keys(RentalPayerRuling::PAYERS))],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    private function row(RentalFaultType $type, int $id, bool $trashed = false): RentalFaultTypeCause
    {
        $q = RentalFaultTypeCause::query()->where('rental_fault_type_id', $type->id);

        return ($trashed ? $q->onlyTrashed() : $q)->findOrFail($id);
    }

    public function store(Request $request, RentalFaultType $rentalFaultType): RedirectResponse
    {
        $data = $request->validate($this->rules());
        $active = RentalFaultTypeCause::query()->where('rental_fault_type_id', $rentalFaultType->id)->where('kind', $data['kind'])->count();
        if ($active >= RentalFaultTypeCause::MAX_PER_KIND) {
            return $this->back($rentalFaultType)->withErrors(['label' => 'A fault type can carry at most ' . RentalFaultTypeCause::MAX_PER_KIND . ' of these. Archive one first.']);
        }
        $same = RentalFaultTypeCause::query()->where('rental_fault_type_id', $rentalFaultType->id)->where('kind', $data['kind'])->whereRaw('lower(label) = ?', [mb_strtolower(trim($data['label']))])->exists();
        if ($same) {
            return $this->back($rentalFaultType)->withErrors(['label' => 'That is already in the list.'])->withInput();
        }
        RentalFaultTypeCause::create([
            'agency_id' => $rentalFaultType->agency_id, 'rental_fault_type_id' => $rentalFaultType->id, 'kind' => $data['kind'], 'label' => trim($data['label']),
            'default_payer' => $data['default_payer'] ?: null, 'sort_order' => $data['sort_order'] ?? ($active + 1), 'is_active' => true, 'created_by_user_id' => $request->user()->id,
        ]);

        return $this->back($rentalFaultType)->with('success', 'Added.');
    }

    public function update(Request $request, RentalFaultType $rentalFaultType, int $cause): RedirectResponse
    {
        $row = $this->row($rentalFaultType, $cause);
        $data = $request->validate($this->rules());
        $row->update(['kind' => $data['kind'], 'label' => trim($data['label']), 'default_payer' => $data['default_payer'] ?: null, 'sort_order' => $data['sort_order'] ?? $row->sort_order]);

        return $this->back($rentalFaultType)->with('success', 'Saved.');
    }

    public function archive(RentalFaultType $rentalFaultType, int $cause): RedirectResponse
    {
        $this->row($rentalFaultType, $cause)->archive();

        return $this->back($rentalFaultType)->with('success', 'Archived. It no longer shows in the list; Show archived brings it back.');
    }

    public function restore(RentalFaultType $rentalFaultType, int $cause): RedirectResponse
    {
        $this->row($rentalFaultType, $cause, true)->restoreRecord();

        return $this->back($rentalFaultType)->with('success', 'Restored.');
    }

    private function back(RentalFaultType $type): RedirectResponse
    {
        return redirect()->to(route('corex.rental-fault-types.edit', $type) . '#who-normally-pays');
    }
}
