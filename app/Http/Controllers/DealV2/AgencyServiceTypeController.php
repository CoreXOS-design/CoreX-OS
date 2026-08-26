<?php

namespace App\Http\Controllers\DealV2;

use App\Http\Controllers\Controller;
use App\Models\DealV2\AgencyServiceType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * AT-229 — the agency's COC / service-type list settings screen.
 *
 * Agency-scoped CRUD (add / edit / soft-delete / restore). The work-order
 * "service type" dropdown in the pipeline step-config reads from this list
 * instead of a hardcoded array. AgencyScope keeps each agency to its own list.
 */
class AgencyServiceTypeController extends Controller
{
    public function index(): View
    {
        $types   = AgencyServiceType::orderBy('sort_order')->orderBy('id')->get();
        $archived = AgencyServiceType::onlyTrashed()->orderBy('sort_order')->orderBy('id')->get();

        return view('deals-v2.settings.service-types.index', compact('types', 'archived'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'label'     => ['required', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $code = Str::limit(Str::slug($data['label'], '_'), 40, '');
        if ($code === '') {
            return back()->withErrors(['label' => 'Enter a name with at least one letter or number.']);
        }

        // One LIVE code per agency (archived rows may share it — restore instead).
        if (AgencyServiceType::where('code', $code)->exists()) {
            return back()->withErrors(['label' => "A service type '{$data['label']}' already exists."]);
        }

        $maxSort = (int) (AgencyServiceType::max('sort_order') ?? 0);

        AgencyServiceType::create([
            'code'       => $code,
            'label'      => $data['label'],
            'sort_order' => $maxSort + 1,
            'is_active'  => $request->has('is_active') ? $request->boolean('is_active') : true,
        ]);

        return back()->with('success', "Service type '{$data['label']}' added.");
    }

    public function update(Request $request, AgencyServiceType $service_type): RedirectResponse
    {
        $data = $request->validate([
            'label'      => ['required', 'string', 'max:100'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active'  => ['nullable', 'boolean'],
        ]);

        // The CODE is stable — renaming the label never rewrites the value that
        // configured steps already store. Only label / order / active change.
        $service_type->update([
            'label'      => $data['label'],
            'sort_order' => $data['sort_order'],
            'is_active'  => $request->boolean('is_active'),
        ]);

        return back()->with('success', "'{$service_type->label}' updated.");
    }

    public function destroy(AgencyServiceType $service_type): RedirectResponse
    {
        $label = $service_type->label;
        $service_type->delete(); // soft delete — never a hard delete

        return back()->with('success', "'{$label}' archived. Steps already using it keep working; it just leaves the dropdown.");
    }

    public function restore(int $id): RedirectResponse
    {
        $type = AgencyServiceType::onlyTrashed()->findOrFail($id);
        $type->restore();

        return back()->with('success', "'{$type->label}' restored.");
    }
}
