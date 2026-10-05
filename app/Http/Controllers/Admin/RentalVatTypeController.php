<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\RentalVatType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Agency VAT set-up — the agency-maintained VAT types list (Johan, Pastel
 * style: Standard/No VAT/Custom, plus whatever an agency adds). Embedded in
 * the Company Settings "VAT" block, gated by the same `manage_performance_settings`
 * permission as the rest of that page. Full CRUD (add/rename/archive/
 * restore), no hard delete.
 */
class RentalVatTypeController extends Controller
{
    public function store(Request $request, Agency $agency): RedirectResponse
    {
        $this->authorizeAgency($agency);
        $data = $this->validated($request);

        $type = RentalVatType::create($data + [
            'agency_id' => $agency->id,
            'sort_order' => (int) (RentalVatType::where('agency_id', $agency->id)->max('sort_order') ?? 0) + 1,
            'created_by_user_id' => $request->user()->id,
        ]);

        if ($request->boolean('is_default')) {
            $type->makeDefault();
        }

        return back()->with('success', "VAT type '{$type->name}' added.");
    }

    public function update(Request $request, Agency $agency, RentalVatType $vatType): RedirectResponse
    {
        $this->authorizeAgency($agency);
        abort_unless($vatType->agency_id === $agency->id, 404);

        $vatType->update($this->validated($request));

        return back()->with('success', "VAT type '{$vatType->name}' updated.");
    }

    public function makeDefault(Request $request, Agency $agency, RentalVatType $vatType): RedirectResponse
    {
        $this->authorizeAgency($agency);
        abort_unless($vatType->agency_id === $agency->id, 404);

        $vatType->makeDefault();

        return back()->with('success', "'{$vatType->name}' is now the default VAT type.");
    }

    public function archive(Request $request, Agency $agency, RentalVatType $vatType): RedirectResponse
    {
        $this->authorizeAgency($agency);
        abort_unless($vatType->agency_id === $agency->id, 404);

        $vatType->archive();

        return back()->with('success', "VAT type '{$vatType->name}' archived.");
    }

    public function restore(Request $request, Agency $agency, int $vatType): RedirectResponse
    {
        $this->authorizeAgency($agency);

        $type = RentalVatType::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
            ->onlyTrashed()->where('agency_id', $agency->id)->findOrFail($vatType);
        $type->restoreRecord();

        return back()->with('success', "VAT type '{$type->name}' restored.");
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'rate_mode' => ['required', 'in:' . implode(',', [
                RentalVatType::RATE_MODE_AGENCY_RATE, RentalVatType::RATE_MODE_FIXED, RentalVatType::RATE_MODE_CUSTOM_PER_LINE,
            ])],
            'fixed_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'required_if:rate_mode,' . RentalVatType::RATE_MODE_FIXED],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        if ($data['rate_mode'] !== RentalVatType::RATE_MODE_FIXED) {
            $data['fixed_rate'] = null;
        }

        return $data;
    }

    private function authorizeAgency(Agency $agency): void
    {
        $user = auth()->user();
        if ($user->isOwnerRole()) {
            return;
        }
        if ((int) $user->effectiveAgencyId() !== (int) $agency->id) {
            abort(403, 'You can only edit your own agency.');
        }
    }
}
