<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Events\Document\DocumentRefiled;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Property;
use App\Services\Compliance\AgencyComplianceDocTypeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AT-167 — Misfiled Documents register.
 *
 * Surfaces splitter documents of a CONTACT-ONLY type (Save-to = Contact, not
 * Property, per the agency's config) that carry NO contact link — i.e. an ID /
 * FICA / POR that fell back to the property or was left unfiled. For each row
 * the screen confirms the current filing location (which property, if any) and
 * offers a REFILE action that attaches the correct contact(s) and removes the
 * wrong property anchor per the type's Save-to rule. Audited via the
 * DocumentRefiled domain event; never a hard delete.
 *
 * Classification is entirely data-driven (AgencyComplianceDocTypeService), so a
 * new contact-only type surfaces here automatically — no hardcoded slug list.
 * Route-gated (access_misfiled_documents / misfiled_documents.refile); the
 * controller re-checks for defence in depth. Tenant isolation via the Document
 * BelongsToAgency global scope.
 */
final class MisfiledDocumentsController extends Controller
{
    public function __construct(private readonly AgencyComplianceDocTypeService $dest) {}

    /** document_type ids that resolve to contact-only for this agency. */
    private function contactOnlyTypeIds(int $agencyId): array
    {
        return collect($this->dest->destinationMapFor($agencyId))
            ->filter(fn ($d) => $d['contact'] && ! $d['property'])
            ->keys()->map(fn ($k) => (int) $k)->all();
    }

    /** The single, maintainable definition of "misfiled". */
    private function misfiledQuery(int $agencyId)
    {
        $ids = $this->contactOnlyTypeIds($agencyId);

        return Document::query()
            ->where('source_type', 'pdf_splitter')
            ->whereIn('document_type_id', $ids ?: [0]) // no contact-only types → empty set
            ->whereDoesntHave('contacts');
    }

    public function index(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('access_misfiled_documents'), 403);

        $agencyId = (int) ($request->user()->effectiveAgencyId() ?? 0);

        $docs = $this->misfiledQuery($agencyId)
            ->with(['documentType:id,slug,label', 'uploader:id,name', 'properties'])
            ->orderByDesc('created_at')
            ->paginate(50)->withQueryString();

        // Enrich: originating split property (source_id) + its contacts = the
        // refile candidates. Loaded without global scopes (same agency as the
        // already-scoped document; avoids a branch-scope miss on the picker).
        $sourceIds   = $docs->pluck('source_id')->filter()->unique()->all();
        $sourceProps = Property::withoutGlobalScopes()
            ->whereIn('id', $sourceIds)
            ->with('contacts')
            ->get()->keyBy('id');

        // Per-type counts (fica / ids / por …) for the header — verification of
        // the genuine misfile classes.
        $summary = (clone $this->misfiledQuery($agencyId))
            ->join('document_types as dt', 'dt.id', '=', 'documents.document_type_id')
            ->selectRaw('dt.label as label, COUNT(*) as n')
            ->groupBy('dt.label')->pluck('n', 'label')->toArray();

        return view('admin.misfiled-documents.index', [
            'docs'        => $docs,
            'sourceProps' => $sourceProps,
            'summary'     => $summary,
            'total'       => $docs->total(),
        ]);
    }

    public function refile(Request $request, Document $document)
    {
        abort_unless(auth()->user()?->hasPermission('misfiled_documents.refile'), 403);

        $data = $request->validate([
            'contact_ids'   => 'required|array|min:1',
            'contact_ids.*' => 'integer',
        ]);

        $agencyId = (int) ($request->user()->effectiveAgencyId() ?? $document->agency_id ?? 0);
        $slug = $document->documentType?->slug;
        $dest = $slug ? $this->dest->destinationForSlug($agencyId, $slug) : ['property' => false, 'contact' => true];

        // Refile can only route to a real party of the document's originating
        // split property — no cross-property leak.
        $sourceProp = $document->source_id
            ? Property::withoutGlobalScopes()->with('contacts')->find($document->source_id)
            : null;
        $candidates = $sourceProp ? $sourceProp->contacts->keyBy('id') : collect();
        $chosen = collect($data['contact_ids'])->map(fn ($v) => (int) $v)
            ->filter(fn ($cid) => $candidates->has($cid))->unique()->values();

        if ($chosen->isEmpty()) {
            return back()->withErrors(['refile' => "Pick at least one contact that belongs to this document's property."]);
        }

        $beforeProps  = $document->properties()->pluck('properties.id')->all();
        $keepProperty = (bool) $dest['property']; // a shared type keeps its property anchor

        DB::transaction(function () use ($document, $chosen, $candidates, $keepProperty) {
            foreach ($chosen as $cid) {
                $role = strtolower(trim((string) ($candidates->get($cid)?->pivot->role ?? ''))) ?: 'seller';
                $document->contacts()->syncWithoutDetaching([$cid => ['party_role' => $role]]);
            }
            if (! $keepProperty) {
                // Remove the misfiled property anchor. The Document itself is NOT
                // deleted — it now lives on the contact(s).
                $document->properties()->detach();
            }
        });

        event(new DocumentRefiled(
            document: $document->fresh(),
            fromPropertyIds: $keepProperty ? [] : $beforeProps,
            toContactIds: $chosen->all(),
            keptProperty: $keepProperty,
            actorUserId: auth()->id(),
        ));

        return redirect()->route('admin.misfiled-documents.index', $request->only('q'))
            ->with('success', "Refiled '{$document->original_name}' to {$chosen->count()} contact(s)"
                . ($keepProperty ? ' (property kept per Save-to).' : '.'));
    }
}
