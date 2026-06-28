<?php

namespace App\Http\Controllers\CommandCenter;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Property;
use App\Models\ViewingPack;
use App\Models\ViewingPackDocument;
use App\Models\ViewingPackProperty;
use App\Services\ViewingPack\ViewingPackAgentPdfService;
use App\Services\ViewingPack\ViewingPackBuyerPdfService;
use App\Services\ViewingPack\ViewingPackDocumentService;
use App\Services\ViewingPack\ViewingPackRedactionService;
use App\Services\ViewingPack\ViewingPackSelectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Viewing Pack CRUD (AT-XX, Step 2). Full create / read / update / archive /
 * recover. Tenancy is enforced structurally by AgencyScope on the ViewingPack
 * model — route-model binding of {viewingPack} returns 404 for another agency,
 * so a pack is never visible or mutable across agencies. "Delete" is a soft
 * delete (archive); restore recovers it (no hard deletes, ever).
 *
 * Selection / ordering / documents / redaction / PDFs are later steps — show()
 * renders the skeleton those steps fill in.
 */
class ViewingPackController extends Controller
{
    /** List packs for the current agency. ?archived=1 shows archived instead. */
    public function index(Request $request)
    {
        $showArchived = $request->boolean('archived');

        $query = ViewingPack::query()
            ->with(['contact', 'agent'])
            ->withCount('viewingPackProperties')
            ->latest();

        if ($showArchived) {
            $query->onlyTrashed();
        }

        $packs = $query->paginate(25)->withQueryString();

        return view('command-center.viewing-packs.index', [
            'packs'        => $packs,
            'showArchived' => $showArchived,
        ]);
    }

    /** The pack workspace — Core Matches + ad-hoc search + selected list + docs. */
    public function show(ViewingPack $viewingPack, ViewingPackSelectionService $selection, ViewingPackDocumentService $docs)
    {
        $viewingPack->load([
            'contact',
            'agent',
            'viewingPackProperties' => fn ($q) => $q->ordered()->with(['property', 'viewingPackDocuments']),
        ]);

        $buyer = $viewingPack->contact;
        // Canonical engine only — Core Matches via MatchingService/ClientMatchResolver.
        $coreMatches = $buyer ? $selection->coreMatchesFor($buyer) : collect();
        $selectedIds = $viewingPack->viewingPackProperties->pluck('property_id')->all();

        // Step 5a — per selected property: buyer-pack-ELIGIBLE attached documents
        // (resolver-filtered) + the ids currently ticked into the pack.
        $docPanel = $viewingPack->viewingPackProperties->map(fn (ViewingPackProperty $vpp) => [
            'vpp'         => $vpp,
            'eligible'    => $docs->eligibleDocumentsFor($vpp),
            'selectedIds' => $docs->selectedDocumentIds($vpp),
        ]);

        return view('command-center.viewing-packs.show', [
            'pack'        => $viewingPack,
            'coreMatches' => $coreMatches,
            'selectedIds' => $selectedIds,
            'docPanel'    => $docPanel,
        ]);
    }

    /**
     * Add a property to the pack. Source (core_match | ad_hoc) is computed
     * canonically by the service; a genuine non-match silently captures a
     * core_match_miss. The property is resolved through AgencyScope and double-
     * checked against the pack's agency — never cross-agency.
     */
    public function addProperty(Request $request, ViewingPack $viewingPack, ViewingPackSelectionService $selection)
    {
        $data = $request->validate([
            'property_id' => ['required', 'integer', Rule::exists('properties', 'id')],
        ]);

        $property = Property::findOrFail($data['property_id']);
        abort_unless((int) $property->agency_id === (int) $viewingPack->agency_id, 404);

        $selection->addProperty($viewingPack, $property, $request->user()->id);

        return back()->with('success', 'Property added to the pack.');
    }

    /** Remove a selected property (soft delete; children cascade per Step 2). */
    public function removeProperty(ViewingPack $viewingPack, ViewingPackProperty $viewingPackProperty, ViewingPackSelectionService $selection)
    {
        abort_unless((int) $viewingPackProperty->viewing_pack_id === (int) $viewingPack->id, 404);

        $selection->removeProperty($viewingPackProperty);

        return back()->with('success', 'Property removed from the pack.');
    }

    /**
     * Persist the agent's manual drag order (spec §4 — no auto-routing). The
     * submitted `order` is the full list of this pack's property-row ids in the
     * new sequence; we rewrite sort_order as a COMPACT 1..N over exactly the
     * rows that belong to this pack, in one transaction, so a prior removal
     * leaves no gap and a foreign id can never touch another pack/agency.
     */
    public function reorderProperties(Request $request, ViewingPack $viewingPack)
    {
        $data = $request->validate([
            'order'   => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        DB::transaction(function () use ($data, $viewingPack) {
            // Current pack rows in their existing order (scoped to this pack +
            // agency). This is the authoritative membership set — a stray/foreign
            // id in the payload is filtered out, never written.
            $currentIds = $viewingPack->viewingPackProperties()->ordered()->pluck('id')->all();

            $submitted = array_values(array_filter(
                array_map('intval', $data['order']),
                fn ($id) => in_array($id, $currentIds, true),
            ));

            // Any pack rows the payload omitted keep their relative order at the
            // end — so the write is always a COHERENT FULL sequence, never partial.
            $remaining  = array_values(array_diff($currentIds, $submitted));
            $finalOrder = array_merge($submitted, $remaining);

            // Compact 1..N. Increment per row regardless of whether the value
            // actually changed (MySQL reports 0 affected for a no-op update —
            // gating on affected-count would collide positions).
            $position = 1;
            foreach ($finalOrder as $rowId) {
                $viewingPack->viewingPackProperties()
                    ->whereKey($rowId)
                    ->update(['sort_order' => $position]);
                $position++;
            }
        });

        return response()->json(['ok' => true]);
    }

    /**
     * Tick an eligible document into the buyer pack for one pack property
     * (Step 5a). The service rejects any document that isn't in the property's
     * buyer-pack-eligible set (attachment + eligibility + agency all enforced
     * there), so a forged/foreign/ineligible id 404s.
     */
    public function addDocument(Request $request, ViewingPack $viewingPack, ViewingPackProperty $viewingPackProperty, ViewingPackDocumentService $docs)
    {
        abort_unless((int) $viewingPackProperty->viewing_pack_id === (int) $viewingPack->id, 404);

        $data = $request->validate([
            'document_id' => ['required', 'integer', Rule::exists('documents', 'id')],
        ]);

        $document = Document::findOrFail($data['document_id']);
        $docs->includeDocument($viewingPackProperty, $document);

        return back()->with('success', 'Document added to the buyer pack.');
    }

    /** Untick a document (soft-remove the row; no hard delete). */
    public function removeDocument(ViewingPack $viewingPack, ViewingPackProperty $viewingPackProperty, ViewingPackDocument $viewingPackDocument, ViewingPackDocumentService $docs)
    {
        abort_unless((int) $viewingPackProperty->viewing_pack_id === (int) $viewingPack->id, 404);
        abort_unless((int) $viewingPackDocument->viewing_pack_property_id === (int) $viewingPackProperty->id, 404);

        $docs->removeDocument($viewingPackDocument);

        return back()->with('success', 'Document removed from the buyer pack.');
    }

    /**
     * Rasterized source pages (base64 PNG + raster dims) for the on-screen
     * redaction tool (Step 5b). Authenticated + agency-scoped; nothing written
     * to disk — the unredacted preview only lives in this response.
     */
    public function redactionData(ViewingPack $viewingPack, ViewingPackProperty $viewingPackProperty, ViewingPackDocument $viewingPackDocument, ViewingPackRedactionService $redaction)
    {
        $this->guardDocumentRow($viewingPack, $viewingPackProperty, $viewingPackDocument);

        try {
            return response()->json($redaction->pagePreviews($viewingPackDocument));
        } catch (\Throwable $e) {
            Log::error('ViewingPack redaction preview failed', ['vpd' => $viewingPackDocument->id, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'This document could not be opened for redaction.'], 422);
        }
    }

    /**
     * Burn the agent's boxes and (re)generate the flattened image-only artifact.
     * Boxes are raster-pixel coords per page: { "0": [{x,y,w,h}, …], … }.
     * On failure, nothing is written and a clear message is returned.
     */
    public function redactDocument(Request $request, ViewingPack $viewingPack, ViewingPackProperty $viewingPackProperty, ViewingPackDocument $viewingPackDocument, ViewingPackRedactionService $redaction)
    {
        $this->guardDocumentRow($viewingPack, $viewingPackProperty, $viewingPackDocument);

        $request->validate([
            'boxes'   => ['nullable', 'array'],
            'boxes.*' => ['nullable', 'array'],
        ]);

        try {
            $redaction->redact($viewingPackDocument, (array) $request->input('boxes', []));
        } catch (\Throwable $e) {
            Log::error('ViewingPack redaction failed', ['vpd' => $viewingPackDocument->id, 'error' => $e->getMessage()]);

            return back()->withErrors(['redaction' => 'Could not redact this document: ' . $e->getMessage()]);
        }

        return back()->with('success', 'Document redacted — a flattened copy was added to the buyer pack.');
    }

    /** Stream the flattened redacted artifact (authenticated + agency-scoped). */
    public function redactedFile(ViewingPack $viewingPack, ViewingPackProperty $viewingPackProperty, ViewingPackDocument $viewingPackDocument)
    {
        $this->guardDocumentRow($viewingPack, $viewingPackProperty, $viewingPackDocument);

        $path = $viewingPackDocument->redacted_file_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path));
    }

    /**
     * Generate + stream the single buyer-facing PDF (Step 6). Tenancy is
     * enforced by AgencyScope on the {viewingPack} binding. This is ONE file;
     * the agent sheet (Step 7) is a separate download.
     */
    public function downloadBuyerPack(ViewingPack $viewingPack, ViewingPackBuyerPdfService $buyerPdf)
    {
        return $buyerPdf->download($viewingPack);
    }

    /**
     * Generate + stream the SEPARATE agent sheet (Step 7). A distinct file from
     * the buyer pack (distinct service, path, route, filename) — never merged
     * (compliance spine §1). Eyes-only; carries the CONFIDENTIAL band.
     */
    public function downloadAgentSheet(ViewingPack $viewingPack, ViewingPackAgentPdfService $agentPdf)
    {
        return $agentPdf->download($viewingPack);
    }

    /** Membership + tenancy guard shared by the document endpoints. */
    private function guardDocumentRow(ViewingPack $viewingPack, ViewingPackProperty $viewingPackProperty, ViewingPackDocument $viewingPackDocument): void
    {
        abort_unless((int) $viewingPackProperty->viewing_pack_id === (int) $viewingPack->id, 404);
        abort_unless((int) $viewingPackDocument->viewing_pack_property_id === (int) $viewingPackProperty->id, 404);
    }

    /** Scoped property typeahead for ad-hoc selection (agency-bounded). */
    public function searchProperties(Request $request, ViewingPack $viewingPack)
    {
        $q = trim((string) $request->input('q', ''));
        if (strlen($q) < 2) {
            return response()->json([]);
        }

        $cols  = ['address', 'street_number', 'street_name', 'suburb', 'city', 'complex_name', 'unit_number', 'property_number'];
        $terms = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY);

        // Property carries AgencyScope, so this is already bounded to the user's
        // agency — the same agency-wide stock a buyer pack draws from.
        $rows = Property::query()
            ->where(function ($outer) use ($terms, $cols) {
                foreach ($terms as $term) {
                    $outer->where(function ($w) use ($term, $cols) {
                        foreach ($cols as $c) {
                            $w->orWhere($c, 'like', "%{$term}%");
                        }
                    });
                }
            })
            ->limit(12)
            ->get(['id', 'address', 'street_number', 'street_name', 'suburb', 'city', 'property_number', 'price']);

        return response()->json($rows->map(function (Property $p) {
            $addr = trim((string) $p->address);
            if ($addr === '') {
                $addr = trim(implode(' ', array_filter([$p->street_number, $p->street_name])));
            }
            if ($addr === '') {
                $addr = '(no address)';
            }

            return [
                'id'    => $p->id,
                'label' => trim($addr . ($p->suburb ? ' — ' . $p->suburb : '')),
                'ref'   => $p->property_number,
                'price' => $p->price,
            ];
        }));
    }

    /**
     * Create a draft pack for a buyer from the buyer pipeline entry point.
     * The buyer is resolved through AgencyScope (findOrFail), so an agent can
     * only ever start a pack for a buyer in their own agency.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'contact_id' => ['required', 'integer', Rule::exists('contacts', 'id')],
        ]);

        // Scoped resolve — cross-agency contact_id 404s here, never creates.
        $buyer = Contact::findOrFail($data['contact_id']);

        $pack = ViewingPack::create([
            // agency_id is force-stamped by BelongsToAgency::creating from the
            // authenticated agent; we still pass the buyer's for non-auth paths.
            'agency_id'  => $buyer->agency_id,
            'contact_id' => $buyer->id,
            'agent_id'   => $request->user()->id,
            'status'     => ViewingPack::STATUS_DRAFT,
            'title'      => $this->defaultTitle($buyer),
        ]);

        return redirect()
            ->route('corex.viewing-packs.show', $pack)
            ->with('success', 'Viewing Pack started. Add properties to begin.');
    }

    // AT-XX — the pack's separate scheduler (schedule() + ViewingPackCalendarService)
    // was removed. Scheduling now reuses the SAME calendar prefill handoff as the
    // Schedule Viewing modal: the pack view links straight to
    // command-center.calendar?prefill_class=viewing&prefill_contact_id&prefill_attendees&prefill_properties
    // (built server-side in show.blade from the pack's ordered properties + buyer).
    // No parallel scheduling logic remains.

    /** Edit pack metadata (title / status). Selection edits come in later steps. */
    public function update(Request $request, ViewingPack $viewingPack)
    {
        $data = $request->validate([
            'title'  => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(ViewingPack::STATUSES)],
        ]);

        $viewingPack->update([
            'title'  => $data['title'] ?? $viewingPack->title,
            'status' => $data['status'],
        ]);

        return back()->with('success', 'Viewing Pack updated.');
    }

    /** Archive (soft delete). Children cascade out via the model layer. */
    public function destroy(ViewingPack $viewingPack)
    {
        $viewingPack->delete();

        return redirect()
            ->route('corex.viewing-packs.index')
            ->with('success', 'Viewing Pack archived. You can recover it from the archived list.');
    }

    /** Recover an archived pack (and the children it took down). */
    public function restore(ViewingPack $viewingPack)
    {
        $viewingPack->restore();

        return redirect()
            ->route('corex.viewing-packs.show', $viewingPack)
            ->with('success', 'Viewing Pack recovered.');
    }

    /** Auto title: "Viewing Pack — {Buyer} — {d M Y}". */
    private function defaultTitle(Contact $buyer): string
    {
        $name = trim((string) ($buyer->full_name ?? trim(($buyer->first_name ?? '') . ' ' . ($buyer->last_name ?? ''))));
        if ($name === '') {
            $name = 'Buyer #' . $buyer->id;
        }

        return 'Viewing Pack — ' . $name . ' — ' . now()->format('d M Y');
    }
}
