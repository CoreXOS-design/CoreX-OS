<?php

declare(strict_types=1);

namespace App\Http\Controllers\Docuperfect\Compiler;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\CompiledTemplate;
use App\Models\Docuperfect\DataDictionaryEntry;
use App\Services\Docuperfect\Compiler\Contracts\BindingSuggester;
use App\Services\Docuperfect\Compiler\Contracts\CompileDraftManager;
use App\Services\Docuperfect\Compiler\Contracts\CompilePipeline;
use App\Services\Docuperfect\Compiler\Contracts\SegmentationService;
use App\Services\Docuperfect\Compiler\Ingest\IngestorRegistry;
use App\Support\Docuperfect\Cds\Pipeline\SegmentationResult;
use App\Support\Docuperfect\Cds\Reference\ReferencePackCds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * AT-177 / WS4-S — the internal Compile Studio. The Johan-clickable surface that drives cc2's
 * WS4-E engine end to end (spec §3): ingest → segment → seed draft → bind every fill-point →
 * declare party & signature topology → run the linter (L1–L7, block-addressed) → publish gate
 * (disabled until lint-clean AND golden-certified) → immutable hashed version.
 *
 * This controller is THIN: it calls cc2's contracts ({@see CompileDraftManager} for draft edits,
 * {@see CompilePipeline} for the gate + publish, {@see IngestorRegistry}/{@see SegmentationService}
 * for file ingest, {@see BindingSuggester} for AI-suggest). It never writes the compiled_templates
 * row directly. Every mutation absorbs failures into a user-clear JSON error — never a 500
 * (BUILD_STANDARD §4). Internal-role gated by `esign.compiler.*`.
 */
class CompileStudioController extends Controller
{
    public function __construct(
        private readonly CompileDraftManager $drafts,
        private readonly CompilePipeline $pipeline,
    ) {
    }

    /** Studio home: in-progress drafts + published families. */
    public function index(): \Illuminate\View\View
    {
        $drafts = CompiledTemplate::query()
            ->where('status', CompiledTemplate::STATUS_DRAFT)
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get();

        $published = CompiledTemplate::query()
            ->where('status', CompiledTemplate::STATUS_PUBLISHED)
            ->orderBy('family')
            ->orderByDesc('version')
            ->limit(100)
            ->get();

        return view('docuperfect.compiler.index', [
            'drafts' => $drafts,
            'published' => $published,
            'references' => $this->referenceCatalogue(),
        ]);
    }

    /**
     * Start a new compile draft from a source (spec §3 steps 1–2):
     *   - reference : a CoreX standard template (116/117/119) — a ready, already-typed CDS.
     *   - html      : pasted/authored body HTML → cc2's HtmlIngestor + DeterministicSegmenter.
     *   - upload    : a DOCX/PDF/HTML file → cc2's IngestorRegistry->for(mime) + Segmenter.
     */
    public function start(Request $request, IngestorRegistry $registry, SegmentationService $segmenter): RedirectResponse
    {
        $source = (string) $request->input('source', 'reference');
        $family = trim((string) $request->input('family', ''));

        try {
            $segmentation = match ($source) {
                'reference' => $this->fromReference((string) $request->input('reference', '117')),
                'html' => $this->fromHtml((string) $request->input('html', ''), $family, $registry, $segmenter),
                'upload' => $this->fromUpload($request, $family, $registry, $segmenter),
                default => throw new \InvalidArgumentException('Choose a source to start a compile.'),
            };
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        $draft = $this->drafts->createFromSegmentation($segmentation, array_filter([
            'family' => $family !== '' ? $family : null,
            'compiled_by' => $request->user()?->id,
        ], static fn ($v) => $v !== null));

        return redirect()
            ->route('docuperfect.compiler.studio', $draft->id)
            ->with('success', 'Draft created — review the segmentation, bind fields, declare topology, then lint.');
    }

    /** The Studio workbench for one draft. */
    public function studio(int $id): \Illuminate\View\View
    {
        $draft = CompiledTemplate::findOrFail($id);

        return view('docuperfect.compiler.studio', [
            'draft' => $draft,
            'structure' => $draft->structure ?? [],
            'dictionary' => $this->dictionaryForBinding($draft->agency_id, (int) $draft->data_dictionary_version),
            'lintReport' => $draft->lint_report,
            'isPublished' => $draft->isPublished(),
        ]);
    }

    // ── AJAX draft mutations (via cc2's CompileDraftManager) ───────────────────

    public function bindField(Request $request, int $id): JsonResponse
    {
        return $this->mutate($id, fn (CompiledTemplate $d) => $this->drafts->bindField(
            $d,
            (string) $request->input('block_id'),
            (string) $request->input('field_id'),
            (string) $request->input('dictionary_key'),
        ));
    }

    /** Bulk structure save (segmentation retype, anchors, arbitrary edits the studio composes). */
    public function updateStructure(Request $request, int $id): JsonResponse
    {
        $structure = $request->input('structure');
        if (! is_array($structure)) {
            return response()->json(['ok' => false, 'error' => 'No structure supplied.'], 422);
        }

        return $this->mutate($id, fn (CompiledTemplate $d) => $this->drafts->updateStructure($d, $structure));
    }

    public function declareParty(Request $request, int $id): JsonResponse
    {
        $party = $request->input('party');
        if (! is_array($party)) {
            return response()->json(['ok' => false, 'error' => 'No party supplied.'], 422);
        }

        return $this->mutate($id, fn (CompiledTemplate $d) => $this->drafts->declareParty($d, $party));
    }

    public function setVisibility(Request $request, int $id): JsonResponse
    {
        return $this->mutate($id, fn (CompiledTemplate $d) => $this->drafts->setBlockVisibility(
            $d,
            (string) $request->input('block_id'),
            (array) $request->input('expr', ['mode' => 'all']),
        ));
    }

    public function setEditability(Request $request, int $id): JsonResponse
    {
        return $this->mutate($id, fn (CompiledTemplate $d) => $this->drafts->setBlockEditability(
            $d,
            (string) $request->input('block_id'),
            (array) $request->input('expr', ['mode' => 'none']),
        ));
    }

    /** AI-suggest bindings for an unbound field (operator confirms). */
    public function suggest(Request $request, int $id, BindingSuggester $suggester): JsonResponse
    {
        try {
            $draft = CompiledTemplate::findOrFail($id);
            $suggestions = $suggester->suggest(
                (string) $request->input('label', ''),
                (string) $request->input('context', ''),
                $draft->agency_id,
                (int) $draft->data_dictionary_version,
            );

            return response()->json([
                'ok' => true,
                'suggestions' => array_map(static fn ($s) => $s->toArray(), $suggestions),
            ]);
        } catch (Throwable $e) {
            return response()->json(['ok' => true, 'suggestions' => []]);
        }
    }

    // ── The gate + publish (via cc2's CompilePipeline) ─────────────────────────

    public function lint(int $id): JsonResponse
    {
        try {
            $draft = CompiledTemplate::findOrFail($id);
            $report = $this->pipeline->lint($draft);

            // Persist the auditable verdict on the DRAFT so the Studio badge + the index reflect
            // it (cc2's pipeline returns the report but does not persist status). Draft rows only.
            if ($draft->status === CompiledTemplate::STATUS_DRAFT) {
                $draft->lint_report = $report->toArray();
                $draft->lint_status = $report->publishable()
                    ? CompiledTemplate::LINT_PASSED
                    : CompiledTemplate::LINT_FAILED;
                $draft->save();
            }

            return response()->json([
                'ok' => true,
                'lint' => $report->toArray(),
                'lint_status' => $draft->lint_status,
            ]);
        } catch (Throwable $e) {
            Log::error('Compile studio lint failed', ['draft' => $id, 'error' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => 'Lint could not run: ' . $e->getMessage()], 500);
        }
    }

    public function certify(int $id): JsonResponse
    {
        try {
            $draft = CompiledTemplate::findOrFail($id);
            $report = $this->pipeline->certify($draft);

            return response()->json(['ok' => true, 'golden' => $report->toArray()]);
        } catch (Throwable $e) {
            Log::error('Compile studio certify failed', ['draft' => $id, 'error' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => 'Certification could not run: ' . $e->getMessage()], 500);
        }
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        try {
            $draft = CompiledTemplate::findOrFail($id);
            $published = $this->pipeline->publish($draft, $request->user());

            return response()->json([
                'ok' => true,
                'message' => "Published {$published->family} v{$published->version} (immutable, hash " . substr((string) $published->content_hash, 0, 12) . '…).',
                'template_id' => $published->id,
                'version' => $published->version,
            ]);
        } catch (Throwable $e) {
            // A gate rejection is a user-facing 422 (the message names the blocking rule), not a 500.
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Soft-delete a draft (NN#1 — archived, recoverable). */
    public function archive(int $id): RedirectResponse
    {
        $draft = CompiledTemplate::findOrFail($id);
        if ($draft->isPublished()) {
            return back()->with('error', 'Published versions are immutable and cannot be archived here.');
        }
        $draft->delete();

        return redirect()->route('docuperfect.compiler.index')->with('success', 'Draft archived.');
    }

    // ── internals ──────────────────────────────────────────────────────────────

    /** Run a draft mutation, returning the fresh structure or a user-clear error. */
    private function mutate(int $id, callable $fn): JsonResponse
    {
        try {
            $draft = CompiledTemplate::findOrFail($id);
            $draft = $fn($draft);

            return response()->json([
                'ok' => true,
                'structure' => $draft->structure,
                'lint_status' => $draft->lint_status,
            ]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    private function fromReference(string $key): SegmentationResult
    {
        $structure = match ($key) {
            '116' => method_exists(ReferencePackCds::class, 'template116') ? ReferencePackCds::template116() : throw new \InvalidArgumentException('Reference 116 is not available.'),
            '117' => ReferencePackCds::template117(),
            '119' => ReferencePackCds::template119(),
            default => throw new \InvalidArgumentException("Unknown reference template [{$key}]."),
        };

        return new SegmentationResult($structure, [], 1.0);
    }

    private function fromHtml(string $html, string $family, IngestorRegistry $registry, SegmentationService $segmenter): SegmentationResult
    {
        $html = trim($html);
        if ($html === '') {
            throw new \InvalidArgumentException('Paste some document HTML to compile.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'cstudio_') . '.html';
        file_put_contents($tmp, $html);
        try {
            $ingested = $registry->for('text/html')->ingest($tmp, $family !== '' ? $family : 'pasted-html', ['family' => $family]);

            return $segmenter->segment($ingested);
        } finally {
            @unlink($tmp);
        }
    }

    private function fromUpload(Request $request, string $family, IngestorRegistry $registry, SegmentationService $segmenter): SegmentationResult
    {
        $file = $request->file('document');
        if ($file === null) {
            throw new \InvalidArgumentException('Choose a DOCX, PDF or HTML file to upload.');
        }
        $mime = (string) ($file->getMimeType() ?: 'application/octet-stream');
        if (! $registry->supports($mime)) {
            throw new \InvalidArgumentException("This file type ({$mime}) is not supported yet.");
        }
        $ingested = $registry->for($mime)->ingest(
            $file->getRealPath(),
            $file->getClientOriginalName() ?: 'upload',
            ['family' => $family],
        );

        return $segmenter->segment($ingested);
    }

    /** @return array<string,array{key:string,label:string,available:bool}> */
    private function referenceCatalogue(): array
    {
        return [
            ['key' => '117', 'label' => '117 — Mandatory Disclosure (MDF) · zero-field', 'available' => true],
            ['key' => '119', 'label' => '119 — Addendum B · zero-field', 'available' => true],
            ['key' => '116', 'label' => '116 — HFC Marketing Permission · 15 fields', 'available' => method_exists(ReferencePackCds::class, 'template116')],
        ];
    }

    /** Active dictionary entries (agency override → standard) for the binding dropdown, grouped by category. */
    private function dictionaryForBinding(?int $agencyId, int $version): array
    {
        $entries = DataDictionaryEntry::query()
            ->where('is_active', true)
            ->where('version', '<=', max(1, $version))
            ->where(function ($q) use ($agencyId) {
                $q->whereNull('agency_id');
                if ($agencyId !== null) {
                    $q->orWhere('agency_id', $agencyId);
                }
            })
            ->orderBy('category')
            ->orderBy('key')
            ->get();

        $byKey = [];
        foreach ($entries as $e) {
            // agency override wins over standard; highest version within a scope wins.
            $existing = $byKey[$e->key] ?? null;
            if ($existing === null
                || ($agencyId !== null && $e->agency_id === $agencyId && $existing['agency_id'] === null)
                || ($e->agency_id === $existing['agency_id'] && $e->version > $existing['version'])) {
                $byKey[$e->key] = [
                    'key' => $e->key,
                    'label' => $e->label ?: Str::headline($e->key),
                    'category' => $e->category ?: 'other',
                    'data_type' => $e->data_type,
                    'agency_id' => $e->agency_id,
                    'version' => $e->version,
                ];
            }
        }

        $grouped = [];
        foreach ($byKey as $entry) {
            $grouped[$entry['category']][] = $entry;
        }
        ksort($grouped);

        return $grouped;
    }
}
