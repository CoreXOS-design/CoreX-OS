<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\RentalApplicationHighlighter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Highlighter collection expansion, 2026-09-09 — Johan: "we allow an agency
 * to set up which highlighters they want... as many as they want, each with
 * their own label and colour." Full CRUD (create/update/archive/restore),
 * same shape as PropertyTypesController — the established pattern for a
 * small agency-owned, reorderable, archive-not-delete list in this
 * codebase. No wizard entry yet (Johan: leave that out until he decides).
 */
class RentalApplicationHighlighterController extends Controller
{
    public function store(Request $request)
    {
        $agencyId = $this->resolveAgencyId($request);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'role_scope' => ['required', Rule::in(RentalApplicationHighlighter::ROLE_SCOPES)],
        ]);

        RentalApplicationHighlighter::create([
            'agency_id' => $agencyId,
            'label' => $validated['label'],
            'color' => $validated['color'],
            'role_scope' => $validated['role_scope'],
            'created_by' => $request->user()->id,
            'sort_order' => $this->nextSortOrder($agencyId),
        ]);

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', "Highlighter '{$validated['label']}' added.");
    }

    public function update(Request $request, RentalApplicationHighlighter $highlighter)
    {
        $this->authorizeAgency($request, $highlighter);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'role_scope' => ['required', Rule::in(RentalApplicationHighlighter::ROLE_SCOPES)],
        ]);

        $highlighter->update($validated);

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', "Highlighter '{$highlighter->label}' updated — every mark drawn with it now shows the new colour.");
    }

    public function archive(Request $request, RentalApplicationHighlighter $highlighter)
    {
        $this->authorizeAgency($request, $highlighter);

        $highlighter->delete();

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', "Highlighter '{$highlighter->label}' archived — existing marks made with it are unaffected; it just can't be chosen for new ones.");
    }

    public function restore(Request $request, int $highlighter)
    {
        $row = RentalApplicationHighlighter::withTrashed()->findOrFail($highlighter);
        $this->authorizeAgency($request, $row);

        $row->restore();

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', "Highlighter '{$row->label}' restored.");
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
                RentalApplicationHighlighter::where('id', $id)
                    ->where('agency_id', $agencyId)
                    ->update(['sort_order' => $position]);
            }
        });

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', 'Highlighters reordered.');
    }

    private function resolveAgencyId(Request $request): int
    {
        $id = $request->user()->effectiveAgencyId();
        abort_if($id === null, 403, 'No agency context.');

        return (int) $id;
    }

    private function nextSortOrder(int $agencyId): int
    {
        return (int) (RentalApplicationHighlighter::withTrashed()->where('agency_id', $agencyId)->max('sort_order') ?? -1) + 1;
    }

    private function authorizeAgency(Request $request, RentalApplicationHighlighter $highlighter): void
    {
        if ($highlighter->agency_id !== $this->resolveAgencyId($request)) {
            abort(403, 'Cross-agency access denied.');
        }
    }
}
