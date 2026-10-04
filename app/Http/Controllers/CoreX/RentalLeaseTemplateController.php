<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\Template;
use App\Models\RentalLeaseTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * .ai/specs/rental-renewals.md §5(b)/§9 — GATE 1 (approved 2026-10-04).
 * Rentals → Settings → Rental Lease Templates. Full list-screen floor
 * (BUILD_STANDARD §1b): search by name, sort name/category/active
 * (default: name asc), filter by category, pagination, archive/restore,
 * real empty state.
 */
class RentalLeaseTemplateController extends Controller
{
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

        return view('corex.rental-lease-templates.index', [
            'templates' => $query->paginate(25)->withQueryString(),
            'sort' => $sort,
            'direction' => $direction,
            'filters' => $request->only(['q', 'category', 'archived']),
            'hasAny' => $hasAny,
            'showArchived' => $showArchived,
            'categories' => \App\Models\RentalLeaseTemplate::CATEGORIES,
            // Every DocuPerfect template this agency can see — own + the
            // genuinely-ownerless platform globals, same visibility rule
            // every other e-sign path already uses.
            'availableTemplates' => Template::applySharedWith(Template::query(), $agencyId)
                ->orderBy('name')->get(['id', 'name', 'render_type']),
        ]);
    }

    public function create(Request $request): View
    {
        $agencyId = $request->user()->effectiveAgencyId();

        return view('corex.rental-lease-templates.create', [
            'categories' => RentalLeaseTemplate::CATEGORIES,
            'availableTemplates' => Template::applySharedWith(Template::query(), $agencyId)
                ->orderBy('name')->get(['id', 'name', 'render_type']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'docuperfect_template_id' => ['required', 'integer', 'exists:docuperfect_templates,id'],
            'category' => ['required', 'string', 'in:' . implode(',', RentalLeaseTemplate::CATEGORIES)],
        ]);

        $agencyId = $request->user()->effectiveAgencyId();
        $template = Template::findOrFail($validated['docuperfect_template_id']);
        $template->assertAccessibleBy($request->user());

        RentalLeaseTemplate::create([
            'agency_id' => $agencyId,
            'name' => $validated['name'],
            'docuperfect_template_id' => $validated['docuperfect_template_id'],
            'category' => $validated['category'],
            'is_active' => true,
        ]);

        return redirect()->route('corex.rental-lease-templates.index')->with('success', 'Template added.');
    }

    public function edit(RentalLeaseTemplate $rentalLeaseTemplate): View
    {
        return view('corex.rental-lease-templates.edit', [
            'rentalLeaseTemplate' => $rentalLeaseTemplate,
            'categories' => RentalLeaseTemplate::CATEGORIES,
        ]);
    }

    public function update(Request $request, RentalLeaseTemplate $rentalLeaseTemplate): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'category' => ['required', 'string', 'in:' . implode(',', RentalLeaseTemplate::CATEGORIES)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $rentalLeaseTemplate->update([
            'name' => $validated['name'],
            'category' => $validated['category'],
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('corex.rental-lease-templates.index')->with('success', 'Template updated.');
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
}
