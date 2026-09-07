<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\DocumentType;
use App\Models\RentalApplication;
use App\Models\RentalApplicationChecklistConfig;
use App\Models\RentalApplicationDocumentRequirement;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * AT-392 spec §5 — agency-configurable supporting-document checklist per
 * employment type. Nothing here is ever enforced at submission time; this
 * only drives what shows as "outstanding" on a returned application.
 */
class RentalApplicationSettingsController extends Controller
{
    public function edit(Request $request): View
    {
        $agencyId = $request->user()->effectiveAgencyId();
        $documentTypes = DocumentType::where('is_active', true)->orderBy('sort_order')->get();

        $checklists = [];
        $isConfigured = [];
        foreach (RentalApplication::EMPLOYMENT_TYPES as $type) {
            $checklists[$type] = RentalApplicationDocumentRequirement::checklistFor($agencyId, $type)
                ->pluck('id')->all();
            // Surfaced in the view so the screen is honest about what it's
            // showing — "your saved (possibly empty) list" vs "the V8
            // default, not yet saved" — not just correct in the data.
            $isConfigured[$type] = RentalApplicationChecklistConfig::where('agency_id', $agencyId)
                ->where('employment_type', $type)->exists();
        }

        return view('corex.settings.rental-applications', compact('documentTypes', 'checklists', 'isConfigured'));
    }

    public function update(Request $request)
    {
        $agencyId = $request->user()->effectiveAgencyId();

        $validated = $request->validate([
            'checklists' => ['nullable', 'array'],
            'checklists.*' => ['array'],
            'checklists.*.*' => ['integer', 'exists:document_types,id'],
        ]);

        foreach (RentalApplication::EMPLOYMENT_TYPES as $type) {
            $documentTypeIds = $validated['checklists'][$type] ?? [];

            RentalApplicationDocumentRequirement::where('agency_id', $agencyId)
                ->where('employment_type', $type)
                ->delete();

            foreach (array_values($documentTypeIds) as $sortOrder => $documentTypeId) {
                RentalApplicationDocumentRequirement::create([
                    'agency_id' => $agencyId,
                    'employment_type' => $type,
                    'document_type_id' => $documentTypeId,
                    'sort_order' => $sortOrder,
                ]);
            }

            // Johan, 2026-09-07 — this form always submits all 3 employment
            // types together, so every save marks all 3 "configured," even
            // one that ends up with zero items selected. That zero-item save
            // IS the agency's deliberate choice and must never be confused
            // with "never touched this screen" (see RentalApplicationDocument
            // Requirement::checklistFor()). firstOrCreate — a type already
            // marked configured from a prior save is left as-is, not
            // duplicated.
            RentalApplicationChecklistConfig::firstOrCreate([
                'agency_id' => $agencyId,
                'employment_type' => $type,
            ]);
        }

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', 'Rental application document checklist saved.');
    }
}
