<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DocumentDistributionAuditEntry;
use App\Models\DocumentType;
use App\Services\DealV2\DocumentDistributionDefaults;
use App\Services\DealV2\DocumentDistributionMatrix;
use Illuminate\Http\Request;

/**
 * Deal Document Distribution - ONE simple matrix: document types down the side, parties across the top, a tick per cell = "this party may
 * receive / download this kind of document". The single source for the deal's email-a-party button AND the attorney portal
 * (.ai/specs/signed-document-distribution.md "The settings screen"). Writes TYPE-level rules only; stage-scoped (automatic-at-a-stage) rules
 * live on the Deal Distribution Rules page and are never touched here. Every save writes an audit row.
 */
class DocumentDistributionMatrixController extends Controller
{
    /** Column order on screen (the matrix supports exactly DocumentDistributionMatrix::PARTY_ROLES). */
    public const COLUMN_ORDER = ['seller', 'buyer', 'transfer_attorney', 'bond_attorney', 'bond_originator', 'conveyancer', 'external_agency'];

    private function agencyId(Request $request): int
    {
        return (int) $request->user()?->effectiveAgencyId();
    }

    public function index(Request $request, DocumentDistributionMatrix $matrix)
    {
        $agencyId = $this->agencyId($request);
        $types    = DocumentType::query()->where('is_active', true)->orderBy('sort_order')->orderBy('label')->get(['id', 'slug', 'label']);

        $defaults = [];
        foreach (DocumentDistributionDefaults::bySlug() as $slug => $roles) {
            $id = $types->firstWhere('slug', $slug)?->id;
            if ($id) {
                $defaults[$id] = array_values($roles);
            }
        }

        $history = DocumentDistributionAuditEntry::query()->where('agency_id', $agencyId)->with('user:id,name')->latest('id')->limit(15)->get();

        return view('admin.settings.document-distribution', [
            'types'    => $types,
            'columns'  => collect(self::COLUMN_ORDER)->mapWithKeys(fn ($r) => [$r => DocumentDistributionMatrix::PARTY_ROLES[$r]])->all(),
            'cells'    => $matrix->typeLevelCells($agencyId),
            'auto'     => $matrix->stageAutoCells($agencyId),
            'defaults' => $defaults,
            'history'  => $history,
            'last'     => $history->first(),
        ]);
    }

    public function save(Request $request, DocumentDistributionMatrix $matrix)
    {
        $request->validate(['grid' => ['required', 'in:1'], 'cell' => ['sometimes', 'array']]);
        $agencyId = $this->agencyId($request);

        $types   = DocumentType::query()->where('is_active', true)->get(['id', 'label'])->keyBy('id');
        $posted  = (array) $request->input('cell', []);   // [typeId => [role => '1']]
        $desired = [];
        foreach ($types as $id => $type) {
            $desired[$id] = array_keys(array_filter((array) ($posted[$id] ?? [])));
        }

        $changes = $matrix->applyCells($agencyId, $desired, $request->user()->id);
        if (! $changes) {
            return back()->with('success', 'Nothing was changed.');
        }

        $labels  = DocumentDistributionMatrix::PARTY_ROLES;
        $lines   = collect($changes)->map(fn ($c) => ($c['change'] === 'added' ? 'Added: ' : 'Removed: ') . ($types[$c['type_id']]->label ?? ('#' . $c['type_id'])) . ' - ' . ($labels[$c['role']] ?? $c['role']))->all();
        $added   = collect($changes)->where('change', 'added')->count();
        $removed = collect($changes)->where('change', 'removed')->count();
        $summary = trim(($added ? "{$added} added" : '') . ($added && $removed ? ', ' : '') . ($removed ? "{$removed} removed" : ''));

        DocumentDistributionAuditEntry::create([
            'agency_id' => $agencyId, 'user_id' => $request->user()->id,
            'summary'   => mb_substr($summary, 0, 500),
            'changes'   => $lines,
        ]);

        return back()->with('success', 'Saved. ' . $summary . '.');
    }
}
