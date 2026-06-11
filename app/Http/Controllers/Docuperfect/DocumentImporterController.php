<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\AgencySigningParty;
use App\Models\Docuperfect\CdsDraft;
use App\Models\Docuperfect\FieldCorrection;
use App\Models\Docuperfect\Template;
use App\Services\Docuperfect\CdsParserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DocumentImporterController extends Controller
{
    /**
     * Show the upload form.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        // ES-6.5 — Path A (Mammoth/AI draft import) is retired. The import
        // landing now offers only the CDS marker-aware path (Word + text PDF).
        return view('docuperfect.importer.index');
    }

    /**
     * Upload a .docx, parse via CDS engine, and redirect to the CDS template builder.
     */
    public function generateCdsTemplate(Request $request)
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        // ES-6 — the CDS import path accepts Word AND text-based PDF. Limits
        // are agency/environment-configurable (config/docuperfect.php), no
        // hardcoded thresholds.
        $allowed = config('docuperfect.import.allowed_extensions', ['docx', 'pdf']);
        $maxKb   = (int) config('docuperfect.import.max_upload_kb', 20480);
        $request->validate([
            'document' => 'required|file|mimes:' . implode(',', $allowed) . '|max:' . $maxKb,
        ], [
            'document.mimes' => 'Please upload a Word (.docx) or PDF document.',
            'document.max'   => 'That file is too large. The maximum size is ' . (int) round($maxKb / 1024) . ' MB.',
        ]);

        $file = $request->file('document');
        $ext  = strtolower((string) $file->getClientOriginalExtension());
        $parser = app(CdsParserService::class);

        // Parse BEFORE any write — a failed/rejected parse leaves nothing
        // half-created. Clear, user-facing messages; no raw 500.
        try {
            $cds = $ext === 'pdf'
                ? $parser->parsePdf($file->getPathname())
                : $parser->parse($file->getPathname());
        } catch (\App\Exceptions\Docuperfect\ScannedPdfException $e) {
            return back()->withErrors(['document' => $e->getMessage()])->withInput();
        } catch (\RuntimeException $e) {
            return back()->withErrors(['document' => $e->getMessage()])->withInput();
        }

        // ES-6.3/6.4 — aggregate detected insertable blocks (the ~~~~ markers,
        // SAME detectMarkers pipeline for docx + pdf) so the builder can
        // surface them for confirmation. Persisted on the draft settings.
        $insertableBlocks = $parser->collectInsertableBlocks($cds['sections'] ?? []);

        // Cap the derived title to the column contract — an untitled/long
        // document (common with flat PDF text) must never overflow
        // cds_drafts.template_name. Always a non-empty value.
        $title = trim((string) ($cds['title'] ?? ''));
        $templateName = $title !== '' ? mb_substr($title, 0, 150) : 'Untitled Import';

        // ES-6.7 — AI extraction-fidelity verification, PDF ONLY. Word
        // extraction is reliable and skips entirely (cost). The verifier is
        // fail-open: an AI/transport failure returns 'could_not_run' and the
        // import still proceeds (surfaced as a warning, never a silent pass).
        // The call is external (no DB writes) so it sits OUTSIDE the write txn.
        $verification = ['status' => null, 'flags' => []];
        if ($ext === 'pdf') {
            $verification = app(\App\Services\Docuperfect\CdsExtractionVerifier::class)
                ->verify($file->getPathname(), $cds);
        }

        // Create the draft + persist any fidelity flags atomically — a failure
        // mid-write rolls back cleanly, never a half-created draft.
        $draft = DB::transaction(function () use ($user, $templateName, $cds, $insertableBlocks, $verification) {
            $draft = CdsDraft::create([
                'user_id' => $user->id,
                'agency_id' => $user->agency_id ?? null,
                'template_name' => $templateName,
                'cds_json' => $cds,
                'settings' => ['insertable_blocks' => $insertableBlocks],
                'status' => 'draft',
                'extraction_verification' => $verification['status'],
            ]);

            foreach ($verification['flags'] as $flag) {
                \App\Models\Docuperfect\CdsExtractionFlag::create($flag + [
                    'cds_draft_id' => $draft->id,
                    'status'       => \App\Models\Docuperfect\CdsExtractionFlag::STATUS_PENDING,
                ]);
            }

            return $draft;
        });

        return redirect()->route('docuperfect.cds.builder', $draft);
    }

    /**
     * ES-6.7 — human ratification of an AI extraction-fidelity flag. The
     * reviewer Accepts (extraction is fine), Fixes (corrected the content), or
     * Acknowledges (low-severity only). Resolving a flag recomputes the
     * owning draft + template run state so the wizard gate updates. A 'fix'
     * with a corrected snippet feeds the existing field_corrections loop.
     */
    public function resolveFidelityFlag(Request $request, int $flag)
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        $record = \App\Models\Docuperfect\CdsExtractionFlag::findOrFail($flag);

        $validated = $request->validate([
            'action'            => 'required|in:accept,fix,acknowledge',
            'note'              => 'nullable|string|max:2000',
            'corrected_snippet' => 'nullable|string|max:65000',
        ]);
        $action = $validated['action'];

        // A high-severity flag is a content/structure problem — it cannot be
        // dismissed with a bare "acknowledge"; it must be Accepted (extraction
        // verified correct) or Fixed.
        if ($action === 'acknowledge'
            && $record->severity === \App\Models\Docuperfect\CdsExtractionFlag::SEVERITY_HIGH) {
            return response()->json([
                'ok'    => false,
                'error' => 'High-severity flags must be Accepted or Fixed, not just acknowledged.',
            ], 422);
        }

        $statusFor = [
            'accept'      => \App\Models\Docuperfect\CdsExtractionFlag::STATUS_ACCEPTED,
            'fix'         => \App\Models\Docuperfect\CdsExtractionFlag::STATUS_FIXED,
            'acknowledge' => \App\Models\Docuperfect\CdsExtractionFlag::STATUS_ACKNOWLEDGED,
        ];

        DB::transaction(function () use ($record, $action, $statusFor, $validated, $user) {
            $record->update([
                'status'          => $statusFor[$action],
                'resolution_note' => $validated['note'] ?? null,
                'resolved_by'     => $user->id,
                'resolved_at'     => now(),
            ]);

            // Feed a corrected 'fix' into the existing learning loop.
            if ($action === 'fix' && !empty($validated['corrected_snippet'])) {
                FieldCorrection::create([
                    'context'                => trim((string) ($record->divergence_type . ' @ ' . ($record->location ?? ''))),
                    'claude_suggested_key'   => $record->divergence_type,
                    'claude_suggested_label' => $record->extracted_snippet,
                    'user_corrected_key'     => $record->divergence_type,
                    'user_corrected_label'   => $validated['corrected_snippet'],
                    'correction_reason'      => 'extraction_fidelity',
                    'document_type'          => 'cds_import',
                    'user_id'                => $user->id,
                ]);
            }

            // Recompute run-level state on the owning draft AND template so the
            // builder display + the wizard gate both reflect the resolution.
            $verifier = app(\App\Services\Docuperfect\CdsExtractionVerifier::class);
            if ($record->cds_draft_id) {
                $draft = CdsDraft::find($record->cds_draft_id);
                if ($draft) {
                    $draft->update(['extraction_verification' => $verifier->statusFromLiveFlags($draft->extractionFlags()->get())]);
                }
            }
            if ($record->template_id) {
                $tpl = Template::find($record->template_id);
                if ($tpl) {
                    $tpl->update(['extraction_verification' => $verifier->statusFromLiveFlags($tpl->extractionFlags()->get())]);
                }
            }
        });

        return response()->json(['ok' => true]);
    }

    // ===== SIGNING PARTY MANAGEMENT =====

    /**
     * Return JSON list of signing parties for the current user's agency.
     * Seeds defaults if agency has none.
     */
    public function getParties(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        return response()->json($this->loadAgencyParties($user));
    }

    /**
     * Create a new signing party for the agency.
     */
    public function storeParty(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $agencyId = $user->effectiveAgencyId();
        $maxSort = AgencySigningParty::forAgency($agencyId)->max('sort_order') ?? -1;

        $party = AgencySigningParty::create([
            'agency_id'  => $agencyId,
            'name'       => $validated['name'],
            'sort_order' => $maxSort + 1,
            'is_default' => false,
        ]);

        return response()->json($party, 201);
    }

    /**
     * Update name of a signing party. Verify agency ownership.
     */
    public function updateParty(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        $party = AgencySigningParty::where('id', $id)
            ->forAgency($user->effectiveAgencyId())
            ->first();

        if (!$party) {
            return response()->json(['error' => 'Party not found'], 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $party->update(['name' => $validated['name']]);

        return response()->json($party);
    }

    /**
     * Soft-delete a signing party. Prevent if only 1 remains.
     */
    public function destroyParty(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        $agencyId = $user->effectiveAgencyId();
        $count = AgencySigningParty::forAgency($agencyId)->count();

        if ($count <= 1) {
            return response()->json(['error' => 'Cannot delete the last signing party.'], 422);
        }

        $party = AgencySigningParty::where('id', $id)
            ->forAgency($agencyId)
            ->first();

        if (!$party) {
            return response()->json(['error' => 'Party not found'], 404);
        }

        $party->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Bulk update sort_order for reordering.
     */
    public function reorderParties(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        $validated = $request->validate([
            'order' => ['required', 'array'],
            'order.*.id' => ['required', 'integer'],
            'order.*.sort_order' => ['required', 'integer'],
        ]);

        $agencyId = $user->effectiveAgencyId();

        foreach ($validated['order'] as $item) {
            AgencySigningParty::where('id', $item['id'])
                ->forAgency($agencyId)
                ->update(['sort_order' => $item['sort_order']]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Load agency signing parties, seeding defaults if empty.
     */
    protected function loadAgencyParties($user): array
    {
        $agencyId = $user->effectiveAgencyId();

        $parties = AgencySigningParty::forAgency($agencyId)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'sort_order', 'is_default']);

        // effectiveAgencyId() is nullable; seedDefaultsForAgency() requires a
        // concrete int. With no resolvable agency we cannot seed agency-scoped
        // defaults — load with whatever exists rather than 500.
        if ($parties->isEmpty() && $agencyId !== null) {
            AgencySigningParty::seedDefaultsForAgency($agencyId);
            $parties = AgencySigningParty::forAgency($agencyId)
                ->orderBy('sort_order')
                ->get(['id', 'name', 'sort_order', 'is_default']);
        }

        return $parties->toArray();
    }

    /**
     * Compare user's final field assignments against Claude's original suggestions.
     * Store corrections so future imports learn from them.
     */
}
