<?php

namespace App\Services\ViewingPack;

use App\Models\Document;
use App\Models\ViewingPackDocument;
use App\Models\ViewingPackProperty;
use App\Services\Compliance\AgencyComplianceDocTypeService;
use Illuminate\Support\Collection;

/**
 * Viewing Pack document selection (AT-107, Step 5a).
 *
 * Surfaces, per pack property, ONLY the buyer-pack-eligible documents attached
 * to that property or to the pack's buyer (spec §5), and persists the agent's
 * tick choices as viewing_pack_documents rows. Eligibility is decided SOLELY by
 * AgencyComplianceDocTypeService::isBuyerPackEligible (Step 1 resolver: catalogue
 * default + per-agency override) — there is no document-type list in this code.
 *
 * No redaction here (Step 5b fills redacted_file_path); no PDFs.
 */
class ViewingPackDocumentService
{
    /**
     * The buyer-pack-ELIGIBLE documents available to a pack property: the union
     * of documents attached to the property AND to the pack's buyer contact,
     * deduped by document id, filtered by the eligibility resolver for the pack's
     * agency. An unknown/missing type slug resolves false (never surfaced).
     *
     * @return Collection<int, Document>
     */
    public function eligibleDocumentsFor(ViewingPackProperty $vpp): Collection
    {
        $agencyId = (int) $vpp->agency_id;
        $property = $vpp->property;
        $buyer    = $vpp->viewingPack?->contact;

        $docs = collect();
        if ($property) {
            $docs = $docs->merge($property->documents()->with('documentType')->get());
        }
        if ($buyer) {
            $docs = $docs->merge($buyer->documents()->with('documentType')->get());
        }

        // Same doc attached to both property AND contact → surfaced once.
        $docs = $docs->unique('id')->values();

        $resolver = app(AgencyComplianceDocTypeService::class);

        return $docs->filter(fn (Document $d) =>
            $resolver->isBuyerPackEligible($agencyId, (string) ($d->documentType?->slug ?? ''))
        )->values();
    }

    /**
     * Document ids currently INCLUDED (active rows) for a pack property.
     *
     * @return int[]
     */
    public function selectedDocumentIds(ViewingPackProperty $vpp): array
    {
        return $vpp->viewingPackDocuments()
            ->pluck('document_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Tick a document into the buyer pack. The document MUST be in the property's
     * eligible set (which already enforces attachment-to-this-pack + eligibility +
     * agency) — a forged/foreign/ineligible id is rejected 404, so this is the
     * security boundary. Idempotent per (pack-property, document); restores a
     * previously-unticked (soft-deleted) row instead of duplicating.
     */
    public function includeDocument(ViewingPackProperty $vpp, Document $document): ViewingPackDocument
    {
        $eligible = $this->eligibleDocumentsFor($vpp);
        $match    = $eligible->firstWhere('id', $document->id);
        abort_unless($match !== null, 404);

        $slug = (string) ($match->documentType?->slug ?? '');

        $existing = $vpp->viewingPackDocuments()
            ->withTrashed()
            ->where('document_id', $document->id)
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            if (! $existing->included) {
                $existing->update(['included' => true]);
            }

            return $existing;
        }

        return $vpp->viewingPackDocuments()->create([
            'agency_id'          => $vpp->agency_id,
            'document_id'        => $document->id,
            'document_type_slug' => $slug,
            'redacted_file_path' => null, // Step 5b
            'included'           => true,
        ]);
    }

    /** Untick a document — soft-remove the row (no hard delete; matches Step 3). */
    public function removeDocument(ViewingPackDocument $vpd): void
    {
        $vpd->delete();
    }
}
