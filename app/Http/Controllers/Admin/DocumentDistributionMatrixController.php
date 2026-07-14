<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DocumentType;
use App\Services\DealV2\DocumentDistributionMatrix;
use Illuminate\Http\Request;

/**
 * AT-227 — Deal Document Distribution (party-first, editable). Admin → Deal Register
 * Settings → Deal Document Distribution. Per PARTY: an editable checklist of document
 * types → per-doc delivery mode → optional stage. Writes through DocumentDistributionMatrix;
 * the one matrix AT-228 send-buttons + m6 e-sign completion both consume.
 */
class DocumentDistributionMatrixController extends Controller
{
    private function agencyId(Request $request): int
    {
        return (int) $request->user()?->effectiveAgencyId();
    }

    public function index(Request $request, DocumentDistributionMatrix $matrix)
    {
        $agencyId = $this->agencyId($request);

        return view('admin.settings.document-distribution', [
            'types'        => DocumentType::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'partyRoles'   => DocumentDistributionMatrix::PARTY_ROLES,
            'partyMatrix'  => $matrix->partyMatrix($agencyId),
            'stageOptions' => $matrix->stageOptions($agencyId),
        ]);
    }

    public function save(Request $request, DocumentDistributionMatrix $matrix)
    {
        $agencyId = $this->agencyId($request);
        $submitted = (array) $request->input('party', []);   // [role => [typeId => [include,delivery_mode,stage]]]

        foreach (array_keys(DocumentDistributionMatrix::PARTY_ROLES) as $role) {
            $entries = [];
            foreach ((array) ($submitted[$role] ?? []) as $typeId => $cfg) {
                if (empty($cfg['include'])) {
                    continue;   // unchecked → not distributable to this party
                }
                $entries[(int) $typeId] = [
                    'delivery_mode'    => $cfg['delivery_mode'] ?? 'secure_link',
                    'pipeline_step_id' => $cfg['stage'] ?? null,
                ];
            }
            $matrix->setPartyDistribution($agencyId, $role, $entries, $request->user()->id);
        }

        return back()->with('success', 'Deal document distribution saved.');
    }
}
