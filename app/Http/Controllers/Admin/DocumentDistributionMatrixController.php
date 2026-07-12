<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DocumentType;
use App\Services\DealV2\DocumentDistributionMatrix;
use Illuminate\Http\Request;

/**
 * AT-227 settings — the Document-type Distribution Matrix UI. Extends the document-types
 * settings area: per type, "distributable?" (= any party role ticked) + which party roles.
 * Agency-scoped; writes type-level (null-stage) rules through DocumentDistributionMatrix.
 */
class DocumentDistributionMatrixController extends Controller
{
    private function agencyId(Request $request): int
    {
        return (int) $request->user()->effectiveAgencyId();
    }

    public function index(Request $request, DocumentDistributionMatrix $matrix)
    {
        $agencyId = $this->agencyId($request);

        return view('admin.settings.document-distribution', [
            'types'      => DocumentType::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'partyRoles' => DocumentDistributionMatrix::PARTY_ROLES,
            'matrix'     => $matrix->matrix($agencyId),
        ]);
    }

    public function save(Request $request, DocumentDistributionMatrix $matrix)
    {
        $agencyId = $this->agencyId($request);
        $submitted = (array) $request->input('dist', []);   // [typeId => [role, ...]]

        // Every active type is present in the form; an unchecked type submits no roles
        // (so it is cleared). Iterate the authoritative type list, not just submitted keys.
        foreach (DocumentType::query()->where('is_active', true)->pluck('id') as $typeId) {
            $roles = array_values((array) ($submitted[$typeId] ?? []));
            $matrix->setTypeDistribution($agencyId, (int) $typeId, $roles, $request->user()->id);
        }

        return back()->with('success', 'Distribution matrix saved.');
    }
}
