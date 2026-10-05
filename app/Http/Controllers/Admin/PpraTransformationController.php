<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Compliance\AgencyTransformationNote;
use App\Services\AI\Ellie\EllieAgentService;
use App\Services\Compliance\AgencyTransformationNoteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * PPRA Inspection Pack Phase D — item (i), transformation initiatives.
 * .ai/specs/ppra-inspection-pack.md §6.5 (v3). Pure Admin feature — gated
 * ppra_inspection_pack.view (read) / .configure (write), same as the rest
 * of this module. Agency-only scoping (no own/branch tier) is deliberate
 * — one statement per agency, see §6.5.
 */
class PpraTransformationController extends Controller
{
    public function __construct(
        private AgencyTransformationNoteService $notes = new AgencyTransformationNoteService(),
    ) {
    }

    /**
     * Version history — full CRUD-list floor (§1a): search, sort, filter,
     * pagination. Also carries the create form (structured + document paths)
     * for the current version.
     */
    public function index(Request $request)
    {
        $agency = $this->resolveAgency($request);

        // withTrashed() — this list IS the version history; every prior
        // version is soft-deleted the moment a new one is saved (§4.3), so
        // without withTrashed() this would only ever show the current row.
        $query = AgencyTransformationNote::withTrashed()
            ->with('createdBy')
            ->where('agency_id', $agency->id);

        if ($search = $request->input('search')) {
            $query->where('summary', 'like', "%{$search}%");
        }

        if ($type = $request->input('entry_type')) {
            $query->where('entry_type', $type);
        }

        if ($from = $request->input('date_from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->input('date_to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $query->orderByDesc('created_at'); // stated default (§1b)

        $versions = $query->paginate(25)->withQueryString();

        $current = AgencyTransformationNote::currentFor($agency->id);

        return view('admin.ppra-inspection-pack.transformation', compact('agency', 'versions', 'current'));
    }

    /** Create/Edit (structured path) — creates a new current version (§4.3). */
    public function storeStructured(Request $request)
    {
        $agency = $this->resolveAgency($request);

        $validated = $request->validate([
            'initiatives'                        => 'required|array|min:1',
            'initiatives.*.description'          => 'required|string|max:2000',
            'initiatives.*.start_date'            => 'nullable|date',
            'initiatives.*.end_date'              => 'nullable|date|after_or_equal:initiatives.*.start_date',
            'initiatives.*.people_involved'       => 'nullable|string|max:500',
            'initiatives.*.spend_amount'          => 'nullable|numeric|min:0',
        ]);

        $this->notes->createStructuredVersion($agency->id, $validated['initiatives'], $request->user());

        return redirect()->route('admin.ppra-inspection-pack.transformation.index')
            ->with('success', 'Transformation initiatives statement saved.');
    }

    /** Create/Edit (document path) — creates a new current version (§4.3). */
    public function storeDocument(Request $request)
    {
        $agency = $this->resolveAgency($request);

        $validated = $request->validate([
            'document' => 'required|file|mimes:pdf,doc,docx|max:10240',
            'caption'  => 'nullable|string|max:255',
        ]);

        $this->notes->createDocumentVersion($agency->id, $validated['document'], $validated['caption'] ?? null, $request->user());

        return redirect()->route('admin.ppra-inspection-pack.transformation.index')
            ->with('success', 'Transformation initiatives statement uploaded.');
    }

    /** Archive the current version (soft-delete). */
    public function destroy(Request $request, AgencyTransformationNote $note)
    {
        $agency = $this->resolveAgency($request);
        abort_unless($note->agency_id === $agency->id, 403);

        $note->delete();

        return redirect()->route('admin.ppra-inspection-pack.transformation.index')
            ->with('success', 'Version archived.');
    }

    /**
     * Restore a historical version — copies it forward as a new current
     * row. $noteId resolved manually with withTrashed(): the target of a
     * restore is, by definition, a soft-deleted row, which Laravel's
     * default implicit route-model-binding would 404 on.
     */
    public function restore(Request $request, int $noteId)
    {
        $agency = $this->resolveAgency($request);

        $note = AgencyTransformationNote::withTrashed()->findOrFail($noteId);
        abort_unless($note->agency_id === $agency->id, 403);

        $this->notes->restore($note, $request->user());

        return redirect()->route('admin.ppra-inspection-pack.transformation.index')
            ->with('success', 'Version restored as current.');
    }

    /**
     * Download a document-path version's uploaded file (anti-tamper, private
     * disk). $noteId resolved manually with withTrashed() — a version being
     * downloaded from the history list may well be an archived (soft-deleted)
     * one, which implicit route-model-binding would 404 on (same reasoning
     * as restore()).
     */
    public function download(Request $request, int $noteId)
    {
        $agency = $this->resolveAgency($request);

        $note = AgencyTransformationNote::withTrashed()->findOrFail($noteId);
        abort_unless($note->agency_id === $agency->id, 403);
        abort_unless($note->document_path, 404, 'This version has no uploaded document.');
        abort_unless(Storage::disk('local')->exists($note->document_path), 404, 'Document file is missing from storage.');

        return Storage::disk('local')->download($note->document_path, $note->document_original_name);
    }

    /**
     * "Ask Ellie to help me draft" — one new suggested initiative,
     * description only; dates/people/spend are left for the principal to
     * fill in, and nothing is saved until they submit the real form (§6.5).
     */
    public function draft(Request $request, EllieAgentService $ellie)
    {
        $agency = $this->resolveAgency($request);
        $user = $request->user();

        $existing = AgencyTransformationNote::currentFor($agency->id);
        $existingContext = '';
        if ($existing?->entry_type === 'structured' && is_array($existing->structured_data)) {
            $descriptions = array_filter(array_column($existing->structured_data, 'description'));
            if ($descriptions) {
                $existingContext = " The agency has already recorded: " . implode('; ', $descriptions) . '. Suggest something different.';
            }
        }

        $prompt = 'I run a small South African real estate agency, ' . ($agency->trading_name ?? $agency->name)
            . ($agency->address ? (' in ' . $agency->address) : '')
            . '. I need to record a B-BBEE / transformation initiative for a PPRA s25 inspection file. '
            . 'Suggest ONE realistic, specific initiative this size of agency could commit to (e.g. skills '
            . 'development, enterprise supplier development, employment equity, socio-economic development). '
            . 'Reply with ONLY a one-to-two sentence description of the initiative — no heading, no list, no '
            . 'preamble, nothing else.' . $existingContext;

        $answer = $ellie->answer(message: $prompt, user: $user, history: [], pageContext: [
            'path'  => '/admin/ppra-inspection-pack/transformation',
            'title' => 'PPRA Transformation Initiatives — draft assist',
        ]);

        return response()->json([
            'ok'    => (bool) $answer['ok'],
            'draft' => trim((string) $answer['reply']),
        ]);
    }

    private function resolveAgency(Request $request): Agency
    {
        $user = $request->user() ?? Auth::user();
        $agency = Agency::find($user->effectiveAgencyId());
        abort_unless($agency, 403, 'No agency context.');

        return $agency;
    }
}
