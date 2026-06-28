<?php

namespace App\Http\Controllers\CommandCenter;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ViewingPack;
use Illuminate\Http\Request;
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

    /** The pack workspace — skeleton for now (selection arrives in Step 3). */
    public function show(ViewingPack $viewingPack)
    {
        $viewingPack->load([
            'contact',
            'agent',
            'viewingPackProperties' => fn ($q) => $q->ordered()->with(['property', 'viewingPackDocuments']),
        ]);

        return view('command-center.viewing-packs.show', [
            'pack' => $viewingPack,
        ]);
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
