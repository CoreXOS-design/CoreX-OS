<?php

namespace App\Http\Controllers\Tools;

use App\Events\Docuperfect\SupportingBatchFiled;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\DealV2\DealV2;
use App\Models\Docuperfect\SignedDocumentVersion;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\FicaSubmission;
use App\Models\Property;
use App\Models\Scopes\ContactScope;
use App\Models\SplitterDocType;
use App\Services\Compliance\AgencyComplianceDocTypeService;
use App\Services\Compliance\FicaWetInkService;
use App\Services\DealV2\DealDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use ZipArchive;

class PdfSplitterController extends Controller
{
    /** Minimum override count before a learned phrase is activated in classifyPage(). */
    private const LEARN_THRESHOLD = 5;

    /**
     * AT-105 enh — routing fica_slot token → FicaDocument.document_type slot.
     * Replaces the former hardcoded slug→slot map; the slug→fica_slot half now
     * lives per-doc-type in settings (document_types.fica_slot + agency override).
     */
    public const FICA_SLOT_TO_DOC_TYPE = [
        'fica_form' => 'fica_form',
        'id'        => 'id_copy',
        'por'       => 'proof_of_address',
    ];

    // Executable paths — configured via .env / config/splitter.php
    private static function qpdfPath(): string     { return config('splitter.qpdf_path', 'qpdf'); }
    private static function pdftoppmPath(): string  { return config('splitter.pdftoppm_path', 'pdftoppm'); }
    private static function pdfunitePath(): string  { return config('splitter.pdfunite_path', 'pdfunite'); }
    private static function tesseractPath(): string { return config('splitter.tesseract_path', 'tesseract'); }

    /** Per-request cache of enabled learned boosts; null = not yet loaded. */
    private ?array $learnedBoosts = null;

    /** Per-request cache of doc types from DB. */
    private ?array $cachedDocTypes = null;

    /**
     * Ordered document-type registry from database.
     * Drives dropdowns, keyboard shortcuts, confirm() validation, and the summary.
     * Key = slug; Value = human label shown in UI.
     */
    private function docTypes(): array
    {
        if ($this->cachedDocTypes === null) {
            $this->cachedDocTypes = SplitterDocType::active()->pluck('label', 'slug')->toArray();
        }

        return $this->cachedDocTypes;
    }

    public function index()
    {
        return view('tools.pdf_splitter');
    }

    /**
     * Lightweight property typeahead for the review screen.
     * Scoped to the user's visible properties.
     */
    public function searchProperties(Request $request)
    {
        $q = trim((string) $request->input('q', ''));
        if (strlen($q) < 2) {
            return response()->json([]);
        }

        // Canonical property search + label (fix-the-class): unit+complex aware,
        // multi-term token AND, newest-first.
        $rows = Property::query()
            ->visibleTo($request->user())
            ->searchAddress($q)
            ->with('agent')
            ->latest()
            ->limit(12)
            ->get();

        return response()->json($rows->map(function (Property $p) {
            // AT-105 — surface the seller/owner's OWN FICA state so the FICA
            // kickoff toggle can key off the CONTACT (not the property's
            // import-set compliance snapshot). null seller = no kickoff target.
            $seller = $p->sellerOwnerContact();
            $sellerFica = $seller ? $seller->ficaStatus() : null; // complete|expiring|incomplete|null

            return $p->toSearchResult([
                'ref'         => $p->property_number,
                'seller'      => $seller ? trim(($seller->first_name ?? '') . ' ' . ($seller->last_name ?? '')) : null,
                'seller_fica' => $sellerFica,
            ]);
        }));
    }

    /**
     * AT-105 enh — the contacts attached to a property, with their pivot role
     * and own FICA state. Drives the per-page contact selector + sticky
     * auto-resolution on the review screen. Scoped to the user's visible
     * properties; soft-deleted / cross-agency contacts never appear (the
     * contacts() relation still respects SoftDeletes + AgencyScope).
     *
     * ContactScope ('own'/'branch' visibility) is deliberately bypassed —
     * same fix and same rationale as PropertyContactController::search():
     * a contact legitimately attached to THIS property but captured by a
     * different agent/branch must still show up here, or the splitter's
     * AT-167 guard blocks Link for a page that actually has a valid contact,
     * just one the acting agent's personal/branch scope can't see.
     */
    public function propertyContacts(Request $request, int $property)
    {
        $prop = Property::query()->visibleTo($request->user())->find($property);
        if (! $prop) {
            return response()->json(['contacts' => []], 404);
        }

        $contacts = $prop->contacts()->withoutGlobalScope(ContactScope::class)->get()->map(function ($c) {
            $name = trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''));
            return [
                'id'          => $c->id,
                'name'        => $name !== '' ? $name : '(no name)',
                'role'        => strtolower(trim((string) ($c->pivot->role ?? ''))),
                'fica_status' => $c->ficaStatus(), // complete|expiring|incomplete
            ];
        })->values();

        return response()->json(['contacts' => $contacts]);
    }

    /**
     * Accepts one or many PDFs and splits/reviews/files them TOGETHER as one
     * batch — every file is OCR'd here, in order, and the whole batch lands on
     * ONE review screen where each source file is its own clearly-labelled
     * section. Property, deal, and the FICA toggle are picked ONCE for the
     * whole batch; Download ZIP / Link act on every file's pages in a single
     * combined submission. (An earlier "queue, review one file at a time"
     * design was replaced after live QA — this is simpler and matches how
     * agents actually work through a batch of packs for the same property.)
     */
    public function run(Request $request)
    {
        $validated = $request->validate([
            'base_name' => 'nullable|string|max:120',
            'pdf'       => 'required|array|min:1|max:20',
            'pdf.*'     => 'file|mimes:pdf|max:51200', // 50MB per file
        ]);

        $files   = $request->file('pdf');
        $baseRaw = trim((string) ($validated['base_name'] ?? ''));
        $batchTs = now()->format('Ymd_His');
        // Namespaced by uploader so two agents uploading identically-named
        // files (e.g. both leave Base Name blank on a file their scanner
        // named "OTP.pdf") in the same second can never collide onto the same
        // storage path / manifest ID and cross-file each other's documents.
        $userId = (int) (auth()->id() ?? 0);
        // Random per-REQUEST token on top of the user namespace — a double-
        // submit (double-click, browser retry) from the SAME user in the SAME
        // second would otherwise still collide on identical paths. This token
        // is also what confirm() keys the combined ZIP filename off, closing a
        // real cross-tenant leak the batch rework introduced: the ZIP path
        // used to be built from the shared per-second timestamp alone, so two
        // different agents finishing a split within the same second could
        // silently overwrite each other's compliance documents.
        $batchToken = Str::lower(Str::random(6));

        $manifestIds = [];
        $skipped     = [];
        $usedBases   = [];

        foreach ($files as $i => $uploaded) {
            $base = $this->deriveBase($baseRaw, $uploaded, $i, count($files) > 1) . '_u' . $userId . '_' . $batchToken;

            // Guarantee uniqueness within this batch (e.g. two same-named files).
            $unique = $base;
            $n      = 1;
            while (isset($usedBases[$unique])) {
                $n++;
                $unique = $base . '_' . $n;
            }
            $usedBases[$unique] = true;
            $base = $unique;

            $fileName = $base . '__' . $batchTs . '.pdf';
            $origRel  = 'private/splitter/originals/' . $fileName;
            Storage::disk('local')->putFileAs('private/splitter/originals', $uploaded, $fileName);
            $origAbs = Storage::disk('local')->path($origRel);

            if (!file_exists($origAbs) || filesize($origAbs) === 0) {
                $skipped[] = $uploaded->getClientOriginalName() . ' (empty upload)';
                continue;
            }

            // Wrapped: a genuinely corrupt PAGE inside an otherwise-fine PDF
            // can make pdftoppm/qpdf throw mid-classification (not just
            // return "0 pages", which buildManifestForFile() already handles
            // as a clean skip). Uncaught, that exception would abort the
            // WHOLE request — losing every already-OCR'd file in this loop,
            // since nothing reaches session until the loop finishes. Treat it
            // exactly like a null return: skip this one file, keep the batch.
            try {
                $manifestId = $this->buildManifestForFile([
                    'base'          => $base,
                    'ts'            => $batchTs,
                    'origRel'       => $origRel,
                    'original_name' => $uploaded->getClientOriginalName(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('PDF Splitter: file OCR pipeline threw, skipping', [
                    'file' => $uploaded->getClientOriginalName(),
                    'base' => $base,
                    'error' => $e->getMessage(),
                ]);
                $manifestId = null;
            }

            if ($manifestId === null) {
                $skipped[] = $uploaded->getClientOriginalName();
                continue;
            }

            $manifestIds[] = $manifestId;
        }

        if (empty($manifestIds)) {
            return redirect()->route('tools.pdf_splitter.index')->withErrors([
                'pdf' => 'None of the uploaded file(s) could be split: ' . implode(', ', $skipped ?: ['(unknown)']),
            ]);
        }

        session([
            'splitter_batch'   => $manifestIds,
            'splitter_skipped' => $skipped,
        ]);

        return redirect()->route('tools.pdf_splitter.review');
    }

    /**
     * ADDITIVE — batch intake BY REFERENCE, parallel to run()'s direct-upload
     * path. Accepts a set of already-stored SignedDocumentVersion rows
     * (kind=supporting, e-sign recipient uploads) by id, copies each into the
     * splitter's own storage, and OCR/classifies them via the SAME
     * buildManifestForFile() run() uses — so review()/confirm()/link() need
     * no changes to handle a batch that arrived this way. Does not touch or
     * alter run()'s own request/validation/session shape in any way.
     *
     * Correlation for the completion signal (see link()) travels in a new,
     * separate session key — 'splitter_context' — so a normal run() upload
     * (which never sets it) behaves exactly as before.
     *
     * Every version must genuinely belong to the stated signature_request_id
     * and be kind=supporting — a version id alone is not sufficient to pull a
     * file in, closing an IDOR where a caller fishes for an unrelated upload
     * by guessing ids.
     */
    public function intakeSupporting(Request $request)
    {
        $validated = $request->validate([
            'signature_request_id' => 'required|integer|min:1',
            'version_ids'          => 'required|array|min:1',
            'version_ids.*'        => 'integer|min:1',
            'property_id'          => 'nullable|integer|min:1',
        ]);

        $signatureRequestId = (int) $validated['signature_request_id'];

        $versions = SignedDocumentVersion::query()
            ->supporting()
            ->where('signature_request_id', $signatureRequestId)
            ->whereIn('id', $validated['version_ids'])
            ->get();

        if ($versions->isEmpty()) {
            return redirect()->route('tools.pdf_splitter.index')->withErrors([
                'pdf' => 'None of the requested supporting documents could be found for this signing request.',
            ]);
        }

        $batchTs    = now()->format('Ymd_His');
        $userId     = (int) (auth()->id() ?? 0);
        $batchToken = Str::lower(Str::random(6));

        $manifestIds = [];
        $skipped     = [];
        $usedVersionIds = [];

        foreach ($versions as $version) {
            if (! $version->file_path || ! Storage::disk('local')->exists($version->file_path)) {
                $skipped[] = 'Supporting document #' . $version->id . ' (file missing)';
                continue;
            }

            // Same naming shape run() uses (base + user + batch token), but keyed
            // off the version id — there's no user-supplied original filename on
            // a SignedDocumentVersion to fall back to.
            $base = 'supporting_' . $version->id . '_u' . $userId . '_' . $batchToken;
            $fileName = $base . '__' . $batchTs . '.pdf';
            $origRel  = 'private/splitter/originals/' . $fileName;

            Storage::disk('local')->copy($version->file_path, $origRel);
            $origAbs = Storage::disk('local')->path($origRel);

            if (! file_exists($origAbs) || filesize($origAbs) === 0) {
                $skipped[] = 'Supporting document #' . $version->id . ' (empty file)';
                continue;
            }

            try {
                $manifestId = $this->buildManifestForFile([
                    'base'          => $base,
                    'ts'            => $batchTs,
                    'origRel'       => $origRel,
                    'original_name' => 'Supporting document #' . $version->id . '.pdf',
                ]);
            } catch (\Throwable $e) {
                Log::warning('PDF Splitter: intake-supporting OCR pipeline threw, skipping', [
                    'version_id' => $version->id,
                    'error' => $e->getMessage(),
                ]);
                $manifestId = null;
            }

            if ($manifestId === null) {
                $skipped[] = 'Supporting document #' . $version->id;
                continue;
            }

            $manifestIds[] = $manifestId;
            $usedVersionIds[] = $version->id;
        }

        if (empty($manifestIds)) {
            return redirect()->route('tools.pdf_splitter.index')->withErrors([
                'pdf' => 'None of the requested supporting document(s) could be split: ' . implode(', ', $skipped ?: ['(unknown)']),
            ]);
        }

        // Property prefill — explicit ?property_id wins; otherwise best-effort
        // fall back to the parent Document's own property_id (the real, current
        // e-sign data model links docuperfect_documents.property_id straight to
        // the sales-stock `properties` table id — verified against live data,
        // NOT via Document::property(), which targets a different model/table
        // and cannot be trusted here). Either way this is only ever a DEFAULT:
        // review() still requires the property to resolve via the normal
        // visibleTo() scope, and the agent can always search/clear/repick.
        $propertyId = isset($validated['property_id']) ? (int) $validated['property_id'] : null;
        if ($propertyId === null) {
            $propertyId = $versions->first()?->document?->property_id;
            $propertyId = $propertyId ? (int) $propertyId : null;
        }

        session([
            'splitter_batch'   => $manifestIds,
            'splitter_skipped' => $skipped,
            'splitter_context' => [
                'signature_request_id' => $signatureRequestId,
                'version_ids'          => $usedVersionIds,
                'property_id'          => $propertyId,
            ],
        ]);

        return redirect()->route('tools.pdf_splitter.review');
    }

    /**
     * Slugify a base name for one file in the upload batch. A single file keeps
     * the historical behaviour (explicit base_name required client-side, though
     * server-side it's optional so a stray blank never 500s). Multiple files
     * default to each file's own name so a batch upload doesn't collide/blend
     * distinct packs under one shared label; an explicit base_name becomes a
     * shared prefix (base_name_1, base_name_2, …) when supplied for a batch.
     */
    private function deriveBase(string $baseRaw, \Illuminate\Http\UploadedFile $uploaded, int $index, bool $multiple): string
    {
        if ($baseRaw !== '') {
            $raw = $multiple ? ($baseRaw . '_' . ($index + 1)) : $baseRaw;
        } else {
            $raw = pathinfo($uploaded->getClientOriginalName(), PATHINFO_FILENAME);
        }

        $slug = Str::of($raw)
            ->lower()
            ->replaceMatches('/[^a-z0-9\s\-_]+/', '')
            ->replaceMatches('/\s+/', '_')
            ->replaceMatches('/_+/', '_')
            ->trim('_')
            ->toString();

        return $slug !== '' ? $slug : 'pack_' . ($index + 1);
    }

    /**
     * OCR/classify one uploaded file into a manifest.json. Called once per
     * file in run(), in upload order. set_time_limit resets PHP's execution
     * timer on every call, so each file gets its own fresh window rather than
     * the whole batch sharing one countdown.
     */
    private function buildManifestForFile(array $item): ?string
    {
        set_time_limit(300);
        @ini_set('max_execution_time', '300');

        $base    = $item['base'];
        $ts      = $item['ts'];
        $origRel = $item['origRel'];

        $origAbsNorm = str_replace('\\', '/', Storage::disk('local')->path($origRel));
        if (!Storage::disk('local')->exists($origRel)) {
            return null;
        }

        $outDirRel = 'private/splitter/output/' . $base . '__' . $ts;
        Storage::disk('local')->makeDirectory($outDirRel);

        $tmpRel     = 'private/splitter/tmp/' . $base . '__' . $ts;
        Storage::disk('local')->makeDirectory($tmpRel);
        $tmpAbsNorm = str_replace('\\', '/', Storage::disk('local')->path($tmpRel));

        [$pCount, $pErr] = $this->qpdfPageCount($origAbsNorm);
        if ($pCount < 1) {
            // Surfaced to the log (not to the agent — they just see the file
            // skipped) so production support can tell "this one file is
            // corrupt" from "qpdf is misconfigured/broken for every upload"
            // without having to reproduce manually.
            Log::warning('PDF Splitter: could not read page count', [
                'base' => $base, 'error' => $pErr ?: '(no qpdf output)',
            ]);

            return null;
        }

        $labels     = [];
        $snippets   = [];
        $pageScores = [];
        for ($page = 1; $page <= $pCount; $page++) {
            [$label, $snippet, $scores] = $this->classifyPage($origAbsNorm, $tmpAbsNorm, $page);
            $labels[$page]     = $label;
            $snippets[$page]   = $snippet;
            $pageScores[$page] = $scores;
        }

        $manifest = [
            'base'          => $base,
            'ts'            => $ts,
            'origRel'       => $origRel,
            'outDirRel'     => $outDirRel,
            'tmpRel'        => $tmpRel,
            'pCount'        => $pCount,
            'labels'        => $labels,
            'snippets'      => $snippets,
            'pageScores'    => $pageScores,
            'docTypes'      => $this->docTypes(),
            'original_name' => $item['original_name'] ?? null,
        ];

        $manifestId = $base . '__' . $ts;
        Storage::disk('local')->put(
            $tmpRel . '/manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return $manifestId;
    }

    // =========================================================================
    // Review + Confirm (two-step flow)
    // =========================================================================

    /**
     * Serve a page thumbnail from private storage.
     * Generated on first request (lazy) — never during run().
     *
     * A batch has multiple manifests open on the SAME review page at once, so
     * there is no single "current" manifest — the request must name which one
     * it wants (?manifest=), and that ID is checked against the batch actually
     * in session (not just a shape regex) so a caller can never fish a
     * thumbnail out of a manifest that isn't part of the agent's own batch.
     */
    public function serveThumb(Request $request, int $page)
    {
        $manifestId = $request->query('manifest');
        if (!$manifestId || !preg_match('/^[a-z0-9_-]+__\d{8}_\d{6}$/', $manifestId)) {
            abort(403);
        }

        if (!in_array($manifestId, session('splitter_batch', []), true)) {
            abort(403);
        }

        $padded   = str_pad((string)$page, 3, '0', STR_PAD_LEFT);
        $thumbRel = 'private/splitter/tmp/' . $manifestId . '/thumb_' . $padded . '.jpg';

        if (!Storage::disk('local')->exists($thumbRel)) {
            // Load manifest — path constructed server-side; never from user input
            $manifestRel = 'private/splitter/tmp/' . $manifestId . '/manifest.json';
            if (!Storage::disk('local')->exists($manifestRel)) {
                abort(404);
            }

            $manifest = json_decode(Storage::disk('local')->get($manifestRel), true);
            $origRel  = $manifest['origRel'] ?? null;
            $tmpRel   = $manifest['tmpRel'] ?? null;

            if (!$origRel || !$tmpRel || !Storage::disk('local')->exists($origRel)) {
                abort(404);
            }

            $origAbsNorm = str_replace('\\', '/', Storage::disk('local')->path($origRel));

            $tmpAbs     = Storage::disk('local')->path($tmpRel);
            $tmpAbsNorm = str_replace('\\', '/', $tmpAbs);

            // Render just this page at low DPI — fast, single-page only
            $prefix = $tmpAbsNorm . '/thumbsrc_' . $padded;
            $before = time() - 1;

            $proc = new Process([
                self::pdftoppmPath(),
                '-f', (string)$page,
                '-l', (string)$page,
                '-png',
                '-r', '90',
                $origAbsNorm,
                $prefix,
            ]);
            $proc->setTimeout(30);
            $proc->run();

            // Glob for the PNG — Poppler padding varies by version
            $files = glob($prefix . '-*.png');
            if (empty($files)) {
                abort(404);
            }

            $newer = array_filter($files, fn($f) => filemtime($f) >= $before);
            if (!empty($newer)) {
                $files = array_values($newer);
            }
            usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
            $pngPath = str_replace('\\', '/', $files[0]);

            // Build thumbnail at 800px wide and save to thumb path
            $thumbAbs = Storage::disk('local')->path($thumbRel);
            $this->makeThumbnail($pngPath, $thumbAbs, 800);

            // Remove the temporary PNG used only for thumbnail generation
            @unlink($pngPath);

            if (!Storage::disk('local')->exists($thumbRel)) {
                abort(404);
            }
        }

        return response()->file(
            Storage::disk('local')->path($thumbRel),
            ['Content-Type' => 'image/jpeg']
        );
    }

    /**
     * Load every manifest in the current batch, in upload order. A manifest
     * whose manifest.json no longer exists on disk (tmp cleanup, deploy, disk
     * hiccup during a long review session) is reported back in $missingIds
     * rather than silently dropped — confirm()/link() refuse to process a
     * batch that's missing any file rather than silently filing/zipping a
     * SUBSET and showing a normal-looking success banner, which would hide
     * that a file's documents (possibly including FICA pages) were never
     * split, filed, or zipped at all.
     *
     * @return array{0: array<int,array>, 1: array<int,string>} [manifests, missingIds]
     */
    private function loadBatchManifests(): array
    {
        $manifests = [];
        $missing   = [];
        foreach (session('splitter_batch', []) as $id) {
            $m = $this->loadManifestArrayOrNull($id);
            if ($m) {
                $m['manifestId'] = $id;
                $manifests[]     = $m;
            } else {
                $missing[] = $id;
            }
        }

        return [$manifests, $missing];
    }

    /**
     * confirm()/link() must never silently file/zip a SUBSET of the batch —
     * either every manifest loads, or nothing gets processed. Returns
     * [manifests, errorRedirectOrNull]; check the second value first.
     *
     * @return array{0: array<int,array>, 1: ?\Illuminate\Http\RedirectResponse}
     */
    private function loadCompleteBatchOrFail(): array
    {
        [$manifests, $missingIds] = $this->loadBatchManifests();

        if (empty($manifests)) {
            return [[], redirect()->route('tools.pdf_splitter.index')
                ->withErrors(['pdf' => 'Session expired or manifest not found. Please re-upload.'])];
        }

        if (!empty($missingIds)) {
            return [[], redirect()->route('tools.pdf_splitter.index')->withErrors([
                'pdf' => 'One or more files in this batch could not be loaded (they may have expired) — nothing was processed. Please re-upload the whole batch.',
            ])];
        }

        return [$manifests, null];
    }

    /**
     * Show the review table — every file in the batch, stacked, each its own
     * clearly-divided section. Batch is derived from the session — never from
     * user input.
     */
    public function review()
    {
        [$manifests, $missingIds] = $this->loadBatchManifests();
        if (empty($manifests)) {
            return redirect()->route('tools.pdf_splitter.index')
                ->withErrors(['pdf' => 'No active session, or it expired. Please upload PDF(s) first.']);
        }

        // AT-105 — the FICA auto-kickoff toggle is only offered to users who
        // can act on compliance. Server-enforced again in link().
        $canFica = (bool) (auth()->user()?->hasPermission('access_compliance'));

        // WS3 (D4) — the optional "also file to a deal" picker is only offered to
        // users who can reach the Deal Register. Server-enforced again in link().
        $canLinkDeal = (bool) (auth()->user()?->hasPermission('access_deal_register_v2'));

        // AT-105 enh — per-agency routing (contact_roles SET + fica_slot) by slug,
        // plus the pivot-role SET each routing role resolves across, so the review
        // screen can auto-resolve + sticky-assign per-page contacts client-side.
        $agencyId = (int) (auth()->user()?->effectiveAgencyId() ?? 0);
        $routing  = $agencyId > 0
            ? app(AgencyComplianceDocTypeService::class)->routingMapBySlugFor($agencyId)
            : [];

        $roleSets = [];
        foreach (SplitterDocType::CONTACT_ROLES as $r) {
            $roleSets[$r] = Property::pivotRolesForContactRole($r);
        }
        $roleLabels = [
            'seller_owner' => 'Seller / Owner',
            'buyer'        => 'Buyer',
            'tenant'       => 'Tenant',
            'landlord'     => 'Landlord',
            'lessor'       => 'Lessor',
        ];

        $skipped = session('splitter_skipped', []);

        // Surfaced so the review screen can warn BEFORE the agent clicks
        // Download ZIP / Link — those two actions refuse to run at all while
        // any manifest is missing, rather than silently filing a subset.
        $missingCount = count($missingIds);

        // ADDITIVE — property prefill. Only present when this batch arrived via
        // intakeSupporting()'s 'splitter_context' session key; a normal run()
        // upload never sets it, so $prefillProperty is null and the property
        // picker behaves exactly as before (client-side search only). Shaped
        // identically to searchProperties()'s own JSON (Property::toSearchResult())
        // so the SAME Alpine 'property' state — and every consumer of it — sees
        // one consistent object regardless of how it was picked.
        $prefillProperty = null;
        $splitterContext = session('splitter_context');
        if (is_array($splitterContext) && !empty($splitterContext['property_id'])) {
            $prop = Property::query()->visibleTo(auth()->user())->find($splitterContext['property_id']);
            if ($prop) {
                $seller = $prop->sellerOwnerContact();
                $prefillProperty = $prop->toSearchResult([
                    'ref'         => $prop->property_number,
                    'seller'      => $seller ? trim(($seller->first_name ?? '') . ' ' . ($seller->last_name ?? '')) : null,
                    'seller_fica' => $seller ? $seller->ficaStatus() : null,
                ]);
            }
        }

        return view('tools.pdf_splitter_review', compact(
            'manifests', 'canFica', 'canLinkDeal', 'routing', 'roleSets', 'roleLabels', 'skipped', 'missingCount',
            'prefillProperty'
        ));
    }

    /**
     * Defense against a stale review tab. The review form carries the exact
     * set of manifest IDs it was rendered for; if any of them are no longer
     * part of the session's current batch, a NEW upload has since replaced it
     * in another tab. Reject rather than silently applying this page's
     * posted labels/contacts to whatever batch happens to be active now.
     *
     * Deliberately a SUBSET check (every posted ID must be IN the session
     * batch), not exact-array equality. review() only ever seeds the form
     * from the manifests that actually loaded (loadBatchManifests()) — if one
     * went missing mid-review, the posted set is legitimately SMALLER than
     * the (unpruned) session batch. Exact equality would misdiagnose that as
     * "a new upload replaced the batch" and, since the mismatch recurs on
     * every reload, trap the agent in a loop with no way forward except
     * abandoning the batch — loadCompleteBatchOrFail() gives the accurate
     * "re-upload the whole batch" message for that case; this check must let
     * the request through to reach it.
     */
    private function rejectIfStaleBatch(Request $request): ?\Illuminate\Http\RedirectResponse
    {
        $posted = $request->input('manifest_ids');
        if ($posted === null) {
            return null; // nothing posted to check against — skip (keeps direct/test posts working)
        }

        $posted  = (array) $posted;
        $current = session('splitter_batch', []);

        if (!empty(array_diff($posted, $current))) {
            return redirect()->route('tools.pdf_splitter.review')->withErrors([
                'pdf' => 'This review page was for a different batch than the one currently active — you likely started a new upload in a different tab. Reload this page and try again.',
            ]);
        }

        return null;
    }

    /**
     * Accept label overrides for EVERY file in the batch → group ranges per
     * file → extract bucket PDFs per file → ONE combined ZIP → download. OCR
     * is NOT re-run. Only labels that have at least one page are included.
     * Filing to the property/contacts and any FICA kickoff are the SEPARATE
     * "Link" action (link()) — the two intents are never conflated. The batch
     * is retained in the session so the agent can still run Link afterwards
     * from the same split.
     */
    public function confirm(Request $request)
    {
        if ($stale = $this->rejectIfStaleBatch($request)) {
            return $stale;
        }

        [$manifests, $fail] = $this->loadCompleteBatchOrFail();
        if ($fail) {
            return $fail;
        }

        $postedLabels = (array) $request->input('labels', []);
        $allOutFiles  = [];
        $summaryParts = [];

        foreach ($manifests as $manifest) {
            $manifestId  = $manifest['manifestId'];
            $base        = $manifest['base'];
            $origRel     = $manifest['origRel'];
            $origAbsNorm = str_replace('\\', '/', Storage::disk('local')->path($origRel));
            $outDirRel   = $manifest['outDirRel'];
            $tmpRel      = $manifest['tmpRel'];
            $pCount      = (int) $manifest['pCount'];
            $snippets    = $manifest['snippets'];
            $pageScores  = $manifest['pageScores'];

            // Apply posted overrides — whitelist against active doc types.
            [$finalLabels, $overrides] = $this->resolveManifestLabels($postedLabels, $manifest, $pCount);

            Storage::disk('local')->makeDirectory($outDirRel);
            $outDirAbsNorm = str_replace('\\', '/', Storage::disk('local')->path($outDirRel));
            $tmpAbsNorm    = str_replace('\\', '/', Storage::disk('local')->path($tmpRel));

            $ranges       = $this->groupRanges($finalLabels);
            $bucketOrder  = array_keys($this->docTypes());
            $bucketRanges = array_fill_keys($bucketOrder, []);
            foreach ($ranges as $r) {
                $bucketRanges[$r['label']][] = $r;
            }

            // Only produce PDFs for labels that appear at least once — no placeholders
            $fileOutFiles = [];
            foreach ($bucketOrder as $label) {
                if (count($bucketRanges[$label]) === 0) continue;

                $outName = $base . '__' . $label . '.pdf';
                $outAbs  = $outDirAbsNorm . '/' . $outName;

                $parts = [];
                $idx   = 0;
                foreach ($bucketRanges[$label] as $r) {
                    $idx++;
                    $part = $tmpAbsNorm . '/' . $base . '__' . $label
                        . '__part' . str_pad((string)$idx, 2, '0', STR_PAD_LEFT) . '.pdf';
                    $this->qpdfExtractRange($origAbsNorm, $r['from'], $r['to'], $part);
                    $parts[] = $part;
                }

                count($parts) === 1 ? @copy($parts[0], $outAbs) : $this->pdfUnite($parts, $outAbs);

                // @copy()'s failure return was previously ignored — a silently
                // failed copy (disk pressure, permissions) would still push
                // $outAbs into the ZIP list, and ZipArchive::addFile() also
                // no-ops on a missing source, so the agent would see a normal
                // "ZIP generated" success with a document quietly missing.
                if (!is_file($outAbs) || filesize($outAbs) === 0) {
                    Log::warning('PDF Splitter: extracted output missing or empty, skipping', [
                        'base' => $base, 'label' => $label, 'out' => $outAbs,
                    ]);
                    continue;
                }

                $fileOutFiles[] = $outAbs;
            }

            if (!empty($fileOutFiles)) {
                $allOutFiles    = array_merge($allOutFiles, $fileOutFiles);
                $summaryParts[] = ($manifest['original_name'] ?? $base) . ':' . "\r\n"
                    . $this->buildSummary($finalLabels, $snippets, $pageScores, $ranges, $pCount, $overrides);
            }
        }

        if (empty($allOutFiles)) {
            return redirect()->route('tools.pdf_splitter.review')
                ->withErrors(['pdf' => 'No pages were assigned to any label.']);
        }

        // ONE ZIP for the whole batch — output filenames are already unique
        // across files (each source file's storage base is unique), so they
        // never collide inside the shared archive. The ZIP's own path is keyed
        // off the first manifest's storage BASE (unique per uploader + random
        // per-request token, not just the shared per-second timestamp) so two
        // different agents' batches can never collide onto the same ZIP path.
        $zipRel = 'private/splitter/zips/' . $manifests[0]['base'] . '__' . $manifests[0]['ts'] . '__split_pack.zip';
        Storage::disk('local')->makeDirectory('private/splitter/zips');
        $zipAbs     = Storage::disk('local')->path($zipRel);
        $zipAbsNorm = str_replace('\\', '/', $zipAbs);

        $zip = new ZipArchive();
        if ($zip->open($zipAbsNorm, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return redirect()->route('tools.pdf_splitter.review')
                ->withErrors(['pdf' => 'Could not create ZIP file.']);
        }

        // addFile() returning false was previously ignored — a failed add
        // would still produce a "ZIP generated" success banner with that
        // document silently absent from the archive. Fail loudly instead:
        // no partial ZIP, no false confidence.
        $zipFailures = [];
        foreach ($allOutFiles as $abs) {
            if (!$zip->addFile($abs, basename($abs))) {
                $zipFailures[] = basename($abs);
            }
        }
        if (!empty($zipFailures)) {
            $zip->close();
            @unlink($zipAbsNorm);

            return redirect()->route('tools.pdf_splitter.review')->withErrors([
                'pdf' => 'Could not build the ZIP — failed to add: ' . implode(', ', $zipFailures) . '. Nothing was downloaded.',
            ]);
        }

        $zip->addFromString(
            'summary.txt',
            implode("\r\n" . str_repeat('=', 40) . "\r\n\r\n", $summaryParts)
        );
        $zip->close();

        session([
            'splitter_last_zip'      => $zipAbsNorm,
            'splitter_last_zip_name' => basename($zipAbsNorm),
        ]);

        return redirect()
            ->route('tools.pdf_splitter.index')
            ->with('splitter_download_url', route('tools.pdf_splitter.download'))
            ->with('status', 'ZIP generated — your download will start automatically.');
    }

    /**
     * AT-105 enhancement — "Link" action, batch-aware. Files EVERY file's
     * pages to their configured destination(s) keyed to each page's assigned
     * contact, all against the ONE property/deal picked for the whole batch,
     * and (toggle) kicks off ONE wet-ink FICA per distinct contact that has a
     * FICA page assigned ANYWHERE in the batch. Produces NO ZIP. Per-file
     * posted contact choices arrive in contacts[manifestId][page]; doc-type
     * overrides in labels[manifestId][page].
     */
    public function link(Request $request)
    {
        if ($stale = $this->rejectIfStaleBatch($request)) {
            return $stale;
        }

        [$manifests, $fail] = $this->loadCompleteBatchOrFail();
        if ($fail) {
            return $fail;
        }

        // A property is mandatory for filing — Download ZIP is the no-property path.
        $propertyId = (int) $request->input('property_id');
        $property   = $propertyId > 0
            ? Property::query()->visibleTo($request->user())->find($propertyId)
            : null;

        if (! $property) {
            return redirect()->route('tools.pdf_splitter.review')
                ->withErrors(['pdf' => 'Select a property above before linking — "Link" files the documents to that property. Use "Download ZIP" if you only want the files.']);
        }

        $agencyId = (int) ($request->user()?->effectiveAgencyId() ?? $property->agency_id ?? 0);

        // WS3 (D4) — optional DR2 deal target. When the agent picks a deal in the
        // review screen, every split page-group (across the whole batch) is also
        // anchored to that deal (in addition to the property/contacts), and a
        // filed doc-type that matches an active document step auto-completes it.
        // Guarded: only an ACTIVE deal on THIS property, only for users who can
        // reach the register. No deal → the splitter behaves exactly as before.
        $deal = null;
        $dealId = (int) $request->input('deal_id');
        if ($dealId > 0 && $request->user()?->hasPermission('access_deal_register_v2')) {
            $deal = DealV2::whereKey($dealId)
                ->where('property_id', $property->id)
                ->where('status', 'active')
                ->first();
        }

        // Contacts are resolved once against THIS property and shared by every
        // file's per-page assignments (only contacts actually attached to this
        // property are honoured — no orphan, no cross-property leak). Bypasses
        // ContactScope for the same reason as propertyContacts() above — a
        // legitimately attached contact captured by a different agent/branch
        // must still resolve here, or every page ticked for them gets dropped
        // by the `$attached->has($cid)` filter below and blocked by AT-167.
        $attached       = $property->contacts()->withoutGlobalScope(ContactScope::class)->get()->keyBy('id');
        $postedLabels   = (array) $request->input('labels', []);
        $postedContacts = (array) $request->input('contacts', []);

        // Groups are built PER FILE (extraction always operates on one source
        // PDF at a time) then flattened into one list for filing/FICA — so two
        // files that coincidentally produce the same (label, contact-set) stay
        // as two distinct filed Documents, never pdfunite'd together.
        $allGroups = [];
        foreach ($manifests as $manifest) {
            $manifestId  = $manifest['manifestId'];
            $base        = $manifest['base'];
            $origRel     = $manifest['origRel'];
            $origAbsNorm = str_replace('\\', '/', Storage::disk('local')->path($origRel));
            $outDirRel   = $manifest['outDirRel'];
            $pCount      = (int) $manifest['pCount'];

            $manifestContactsPosted = (array) ($postedContacts[$manifestId] ?? []);

            [$finalLabels, $overrides] = $this->resolveManifestLabels($postedLabels, $manifest, $pCount);

            // Per-page assigned contacts — MANY-TO-MANY. Each page carries a SET
            // of contacts across any/all of the doc-type's allowed roles (the OTP
            // links to all sellers AND all buyers at once).
            $pageContacts = [];
            for ($p = 1; $p <= $pCount; $p++) {
                $raw = $manifestContactsPosted[(string) $p] ?? [];
                $ids = collect(is_array($raw) ? $raw : [$raw])
                    ->map(fn ($v) => (int) $v)
                    ->filter(fn ($cid) => $cid > 0 && $attached->has($cid))
                    ->unique()->sort()->values()->all();
                $pageContacts[$p] = $ids;
            }

            // Group THIS file's pages by (label, exact-contact-SET). Pages
            // sharing a label and the same set of ticked contacts merge into one
            // output. Non-contiguous pages are fine — extractPageSet handles
            // arbitrary page lists.
            $fileGroups = [];
            for ($p = 1; $p <= $pCount; $p++) {
                $label = $finalLabels[$p];
                $ids   = $pageContacts[$p];
                $key   = $label . '|' . (empty($ids) ? 'none' : implode(',', $ids));
                if (! isset($fileGroups[$key])) {
                    $fileGroups[$key] = ['label' => $label, 'contact_ids' => $ids, 'pages' => []];
                }
                $fileGroups[$key]['pages'][] = $p;
            }

            if (empty($fileGroups)) continue;

            Storage::disk('local')->makeDirectory($outDirRel);
            $outDirAbsNorm = str_replace('\\', '/', Storage::disk('local')->path($outDirRel));

            $gi = 0;
            foreach ($fileGroups as $g) {
                $gi++;
                $idsPart = empty($g['contact_ids']) ? 'unassigned' : ('c' . implode('-', $g['contact_ids']));
                $outAbs  = $outDirAbsNorm . '/' . $base . '__' . $g['label'] . '__' . $idsPart . '__g' . $gi . '.pdf';
                $this->extractPageSet($origAbsNorm, $g['pages'], $outAbs);
                $g['file'] = $outAbs;
                $allGroups[] = $g;
            }
        }

        if (empty($allGroups)) {
            return redirect()->route('tools.pdf_splitter.review')
                ->withErrors(['pdf' => 'No pages were assigned to any label.']);
        }

        // AT-167 — PREVENT AT SOURCE, across the WHOLE batch. A contact-only
        // document type (Save-to = Contact, not Property) filed with NO contact
        // assigned would otherwise fall back to the property — a misfile (an ID
        // under the property's folder instead of the person's). Block the whole
        // link and name exactly which pages need a contact, by document-type
        // label. Data-driven: the contact-only decision comes from each
        // agency's Save-to config, never a hardcoded slug list.
        $destSvc    = app(AgencyComplianceDocTypeService::class);
        $typeLabels = DocumentType::query()
            ->whereIn('slug', collect($allGroups)->pluck('label')->filter()->unique()->all())
            ->pluck('label', 'slug')->toArray();
        $needsContact = [];
        foreach ($allGroups as $g) {
            $slug = $g['label'] ?? null;
            if (! $slug) continue;
            $dest = $destSvc->destinationForSlug($agencyId, $slug);
            if ($dest['contact'] && ! $dest['property'] && empty($g['contact_ids'])) {
                $name  = $typeLabels[$slug] ?? Str::headline($slug);
                $pages = implode(', ', $g['pages']);
                $needsContact[] = $name . ' (page' . (count($g['pages']) === 1 ? '' : 's') . ' ' . $pages . ')';
            }
        }
        if (! empty($needsContact)) {
            return redirect()->route('tools.pdf_splitter.review')->withErrors([
                'pdf' => 'These are contact-only document types and can only be filed to a person — assign a contact to each before linking: '
                    . implode('; ', $needsContact) . '. (Nothing was filed.)',
            ]);
        }

        $routing = $destSvc->routingMapBySlugFor($agencyId);

        // File every group (from every file) to its destination(s) + assigned contact (+ deal).
        $filed = $this->fileGroupsToDestinations($property, $allGroups, $agencyId, $attached, $deal);

        // FICA — group the FICA-relevant pages (from every file) by assigned
        // contact; one wet-ink verification per distinct contact. Agent TOGGLE,
        // never silent.
        $ficaResults = [];
        $ficaNote    = null;
        if ($request->boolean('trigger_fica')) {
            $ficaResults = $this->kickoffMultiFica(
                $allGroups, $routing, $agencyId, $attached, $request->user(), $ficaNote
            );
        }

        // Post-link state: the agent has FINISHED this batch — the index hides
        // the uploader and shows a Finish panel that returns to the property.
        // (The ZIP path does NOT set this, so it keeps the uploader.)
        $propLabel = trim((string) ($property->address ?: $property->title ?: ''));
        $redirect = redirect()->route('tools.pdf_splitter.index')
            ->with('splitter_linked', true)
            ->with('splitter_property_url', route('corex.properties.show', $property))
            ->with('splitter_property_label', $propLabel !== '' ? $propLabel : null);

        // Filing summary banner.
        $totalFiled = $filed['property'] + $filed['contact'] + $filed['fallback'];
        if ($totalFiled > 0) {
            $parts = [];
            if ($filed['property'] > 0) { $parts[] = "{$filed['property']} to the property"; }
            if ($filed['contact'] > 0)  { $parts[] = "{$filed['contact']} to the assigned contact" . ($filed['contact'] === 1 ? '' : 's'); }
            if ($filed['fallback'] > 0) { $parts[] = "{$filed['fallback']} to the property (no contact assigned)"; }
            $redirect->with('status', 'Documents linked — ' . implode(', ', $parts) . '.');
        } else {
            $redirect->with('status', 'No documents were filed — check the Save-To settings for these document types.');
        }

        // FICA banner(s) — one line per contact (started or reused).
        if (! empty($ficaResults)) {
            $redirect->with('splitter_fica_results', $ficaResults);
        } elseif ($ficaNote) {
            $redirect->with('splitter_fica_note', $ficaNote);
        }

        // ADDITIVE — completion correlation. Only fires for a batch that arrived
        // via intakeSupporting() (session carries 'splitter_context'); a normal
        // run() upload never sets that key, so this is a pure no-op for the
        // existing flow. Fires even when $filed totals are 0 (e.g. every page's
        // Save-To settings route nowhere) — the recipient-docs side gets to
        // decide what "batch processed but nothing filed" means for its own
        // filed_at bookkeeping, not the splitter.
        $splitterContext = session('splitter_context');
        if (is_array($splitterContext) && !empty($splitterContext['signature_request_id'])) {
            SupportingBatchFiled::dispatch(
                (int) $splitterContext['signature_request_id'],
                array_map('intval', $splitterContext['version_ids'] ?? []),
                array_map('intval', $filed['document_ids']),
                $property->id,
                auth()->id(),
                $agencyId,
            );
            // One-shot correlation — never leak into whatever batch the agent
            // starts next (run() doesn't know this key exists and never clears it).
            session()->forget('splitter_context');
        }

        return $redirect;
    }

    /**
     * Manifest loader shared by review()/confirm()/link() — validates the
     * session token shape, then returns the decoded manifest array or null.
     */
    private function loadManifestArrayOrNull(?string $manifestId): ?array
    {
        if (! $manifestId || ! preg_match('/^[a-z0-9_-]+__\d{8}_\d{6}$/', $manifestId)) {
            return null;
        }
        $rel = 'private/splitter/tmp/' . $manifestId . '/manifest.json';
        if (! Storage::disk('local')->exists($rel)) {
            return null;
        }
        $decoded = json_decode(Storage::disk('local')->get($rel), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Apply posted label overrides (whitelisted against active doc types) over
     * the OCR auto-labels for ONE file's pages. $posted is that file's own
     * slice of the request's labels[manifestId] array. Returns [finalLabels
     * (int-keyed), overrides].
     *
     * @return array{0: array<int,string>, 1: array<int,array{from:string,to:string}>}
     */
    private function resolveFinalLabels(array $posted, array $autoLabels, int $pCount): array
    {
        $validBuckets = array_keys($this->docTypes());
        $finalLabels  = [];
        $overrides    = [];

        for ($p = 1; $p <= $pCount; $p++) {
            $auto = $autoLabels[(string) $p] ?? 'other';

            // A doc type can be deactivated between upload and confirm/link —
            // a batch can sit in review for minutes. An auto-label that's no
            // longer active must not silently vanish from the output;
            // confirm()'s bucket loop only visits currently-active slugs, so
            // an unrecognised $auto here would drop the page from the ZIP
            // with zero error. Fall back to 'other' so it still lands
            // somewhere the agent can see and re-triage.
            if (!in_array($auto, $validBuckets, true) && in_array('other', $validBuckets, true)) {
                $auto = 'other';
            }

            $override = isset($posted[(string) $p]) ? trim((string) $posted[(string) $p]) : null;

            if ($override !== null && in_array($override, $validBuckets, true) && $override !== $auto) {
                $finalLabels[$p] = $override;
                $overrides[$p]   = ['from' => $auto, 'to' => $override];
            } else {
                $finalLabels[$p] = $auto;
            }
        }

        return [$finalLabels, $overrides];
    }

    /**
     * Resolve ONE manifest's final labels from the batch-wide posted `labels`
     * array (keyed by manifest ID) and log any overrides as feedback. Shared
     * by confirm() and link() so the two never drift on how a manifest's own
     * slice of the request is read — they did, briefly, before this was
     * extracted (one copy read `$manifestPosted`, the other
     * `$manifestLabelsPosted`, for the identical operation).
     *
     * @return array{0: array<int,string>, 1: array<int,array{from:string,to:string}>}
     */
    private function resolveManifestLabels(array $postedLabels, array $manifest, int $pCount): array
    {
        $manifestPosted = (array) ($postedLabels[$manifest['manifestId']] ?? []);
        [$finalLabels, $overrides] = $this->resolveFinalLabels($manifestPosted, $manifest['labels'], $pCount);

        if (!empty($overrides)) {
            $this->logFeedback($manifest['base'], $overrides, $manifest['snippets'], $manifest['pageScores']);
        }

        return [$finalLabels, $overrides];
    }

    /**
     * Build a qpdf page-selection spec from an arbitrary page-number list,
     * collapsing consecutive runs into ranges (e.g. [1,2,3,5] → "1-3,5").
     */
    private function pageSpec(array $pages): string
    {
        $pages = array_values(array_unique(array_map('intval', $pages)));
        sort($pages);
        if (empty($pages)) {
            return '';
        }

        $spec = [];
        $start = $prev = $pages[0];
        foreach (array_slice($pages, 1) as $p) {
            if ($p === $prev + 1) { $prev = $p; continue; }
            $spec[] = $start === $prev ? (string) $start : "{$start}-{$prev}";
            $start = $prev = $p;
        }
        $spec[] = $start === $prev ? (string) $start : "{$start}-{$prev}";

        return implode(',', $spec);
    }

    /**
     * Extract an arbitrary ordered set of pages from the original into one PDF.
     * Replaces the range-extract + pdfunite dance for the per-group filing path.
     */
    private function extractPageSet(string $origAbsNorm, array $pages, string $outAbsNorm): void
    {
        $spec = $this->pageSpec($pages);
        if ($spec === '') {
            throw new \RuntimeException('extractPageSet called with no pages.');
        }

        $proc = new Process([self::qpdfPath(), $origAbsNorm, '--pages', $origAbsNorm, $spec, '--', $outAbsNorm]);
        $proc->setTimeout(120);
        $proc->run();

        if (! $proc->isSuccessful()) {
            throw new \RuntimeException("qpdf page-set extract failed for {$spec}: " . trim((string) $proc->getErrorOutput()));
        }
    }

    /**
     * AT-105 enh — file each (label, contact-SET) group as ONE Document, attached
     * to the property and/or to EACH ticked contact per the agency Save-To config.
     * Many-to-many: a group ticked for N contacts links its Document to all N.
     *
     * No-orphan guarantee: the property is always the split target, so any group
     * whose configured destination cannot be honoured (contact destination with
     * no ticked contact, or neither flag set) is anchored to the property.
     *
     * AT-254 (decision B) — the create + attach + AT-167 fallback + deal-step
     * auto-complete now live in DealDocumentService::fileClassifiedDocument (the
     * document spine). This method stays the splitter's PER-GROUP orchestrator:
     * extract-to-disk, resolve the Save-To destination + the explicit ticked
     * contacts, then funnel each group through the ONE canonical filing path so a
     * split OTP files by the same rules as a DR2 / e-sign OTP. contact_roles
     * remains the party-attach source (decision B); the destination decision
     * remains the agency Save-To config.
     *
     * @param array<int,array{label:string,contact_ids:int[],pages:int[],file:string}> $groups
     * @return array{property:int, contact:int, fallback:int, unfiled:int}
     */
    private function fileGroupsToDestinations(Property $property, array $groups, int $agencyId, \Illuminate\Support\Collection $attached, ?DealV2 $deal = null): array
    {
        $publicDisk = Storage::disk('public');
        $dir = "properties/{$property->id}/files";
        if (! $publicDisk->exists($dir)) {
            $publicDisk->makeDirectory($dir);
        }

        $destinations = app(AgencyComplianceDocTypeService::class);
        $docSpine     = app(DealDocumentService::class);

        $slugs    = collect($groups)->pluck('label')->filter()->unique()->values();
        $typeRows = DocumentType::query()->whereIn('slug', $slugs)->get(['id', 'slug', 'label', 'grouping']);
        $typeMap       = $typeRows->pluck('id', 'slug')->toArray();
        $typeLabels    = $typeRows->pluck('label', 'slug')->toArray();
        $typeGroupings = $typeRows->pluck('grouping', 'slug')->toArray();

        // Meaningful, collision-safe saved-document names for CLASSIFIED docs
        // ("{property} · {type label} · {date}"). Cached per document_type_id
        // so two same-type groups in this same batch (e.g. two mandate pages
        // split into separate groups) see each other's just-assigned name and
        // never collide, not just docs already in the DB.
        $existingNamesByType = [];

        // 'document_ids' is additive — collected purely so link() can correlate
        // this batch's created Documents back to a caller (e.g. the intake-by-
        // reference hook's completion event). Nothing else reads it; existing
        // 'property'/'contact'/'fallback'/'unfiled' counters are untouched.
        $result = ['property' => 0, 'contact' => 0, 'fallback' => 0, 'unfiled' => 0, 'document_ids' => []];
        foreach ($groups as $g) {
            $abs = $g['file'] ?? null;
            if (! $abs || ! is_file($abs)) continue;

            $labelSlug = $g['label'];
            $filename  = basename($abs);
            $relPath   = $dir . '/' . Str::random(8) . '_' . $filename;

            $stream = @fopen($abs, 'rb');
            if (! $stream) continue;
            $publicDisk->put($relPath, $stream);
            if (is_resource($stream)) { @fclose($stream); }

            $dest  = $labelSlug
                ? $destinations->destinationForSlug($agencyId, $labelSlug)
                : ['property' => true, 'contact' => false];
            $typeId = $labelSlug ? ($typeMap[$labelSlug] ?? null) : null;

            // Resolve the EXPLICIT per-page ticked contacts to [id => party_role].
            // Only contacts actually attached to THIS property are honoured; the
            // pivot role is the party truth (decision B). Anything else is dropped.
            $contacts = [];
            foreach ($g['contact_ids'] ?? [] as $cid) {
                $contact = $attached->get($cid);
                if (! $contact) continue;
                $contacts[(int) $cid] = strtolower(trim((string) ($contact->pivot->role ?? ''))) ?: 'seller';
            }

            // Classified doc (real type, not the 'other' catch-all) gets a
            // meaningful, collision-safe display name. Unclassified/'other'
            // pages keep the raw extracted filename — the name doesn't matter
            // there, agents re-triage them anyway.
            if ($typeId !== null && $labelSlug !== 'other') {
                if (! array_key_exists($typeId, $existingNamesByType)) {
                    $existingNamesByType[$typeId] = Document::query()
                        ->where('source_type', 'pdf_splitter')
                        ->where('source_id', $property->id)
                        ->where('document_type_id', $typeId)
                        ->pluck('original_name')
                        ->all();
                }

                // A CONTACT-type doc (document_types.grouping === 'contact':
                // fica, ids, por, bank_statement, tax_clearance, company_
                // registration, trust_deed) is named by the person, not the
                // property — a contact who holds >1 property shouldn't get
                // contact docs named by whichever property they were uploaded
                // against. Deliberately keyed on grouping, NOT $dest['contact']
                // — mandate/levy/rates are 'shared'/'property' grouping (still
                // address-named) even though an agency may ALSO route a copy
                // to the contact's file (save_to_contact=1); that routing
                // choice doesn't make them person-documents. Falls back to
                // property naming when there's genuinely no ticked contact.
                $primaryContact = null;
                if (! empty($contacts)) {
                    $primaryContact = $attached->get(array_key_first($contacts));
                }

                $isContactType = ($typeGroupings[$labelSlug] ?? null) === 'contact';

                $displayName = $isContactType && $primaryContact
                    ? $this->buildContactDocumentName(
                        $primaryContact,
                        $typeLabels[$labelSlug] ?? $labelSlug,
                        $existingNamesByType[$typeId]
                    )
                    : $this->buildClassifiedDocumentName(
                        $property,
                        $typeLabels[$labelSlug] ?? $labelSlug,
                        $existingNamesByType[$typeId]
                    );
                $existingNamesByType[$typeId][] = $displayName;
            } else {
                $displayName = $filename;
            }

            // ONE canonical create + attach + deal-step auto-complete.
            $filed = $docSpine->fileClassifiedDocument(
                $property,
                [
                    'original_name'    => $displayName,
                    'storage_path'     => $relPath,
                    'disk'             => 'public',
                    'mime_type'        => 'application/pdf',
                    // ?: would collapse a genuinely truncated/empty (0-byte)
                    // extracted PDF to null ("size unknown") instead of 0
                    // ("file is empty") — only a real stat() failure (false)
                    // should become null.
                    'size'             => (($__sz = @filesize($abs)) !== false ? $__sz : null),
                    'document_type_id' => $typeId,
                    'source_type'      => 'pdf_splitter',
                    'source_id'        => $property->id,
                    'agency_id'        => $agencyId,
                ],
                $dest,
                $contacts,
                auth()->user(),
                $deal
            );

            $result['property'] += $filed['property'];
            $result['contact']  += $filed['contact'];
            $result['fallback'] += $filed['fallback'];
            $result['unfiled']  += $filed['unfiled'];
            if (! empty($filed['document'])) {
                $result['document_ids'][] = $filed['document']->id;
            }
        }

        return $result;
    }

    /**
     * Meaningful saved-document name for a CLASSIFIED split page:
     * "{property address} · {document type label} · {YYYY-MM-DD}.pdf".
     * Falls back to the property title, then a "Property #{id}" placeholder,
     * if the address is blank.
     *
     * Collision-safe: $existingNames is every original_name already used for
     * this property+type (DB rows plus any assigned earlier in this same
     * batch) — appends " (2)", " (3)" … so two docs of the same type on one
     * property (e.g. two mandates) never overwrite each other.
     */
    private function buildClassifiedDocumentName(Property $property, string $typeLabel, array $existingNames): string
    {
        $address = trim((string) ($property->address ?: $property->title)) ?: "Property #{$property->id}";
        $base    = "{$address} · {$typeLabel} · " . now()->format('Y-m-d');

        $taken = array_flip($existingNames);
        $name  = "{$base}.pdf";
        for ($n = 2; isset($taken[$name]); $n++) {
            $name = "{$base} ({$n}).pdf";
        }

        return $name;
    }

    /**
     * Meaningful saved-document name for a CONTACT-filed classified split
     * page (fica, id, por, ...): "{first name} {last name} · {document type
     * label} · {YYYY-MM-DD}.pdf". Falls back to a "Contact #{id}" placeholder
     * if both name fields are blank.
     *
     * Collision-safe the same way as buildClassifiedDocumentName(): because
     * the contact's name is baked into $base, two contacts with the same doc
     * type on the same day never collide with each other — only repeats for
     * the SAME contact append " (2)", " (3)" ...
     */
    private function buildContactDocumentName(Contact $contact, string $typeLabel, array $existingNames): string
    {
        $name = trim((string) $contact->full_name) ?: "Contact #{$contact->id}";
        $base = "{$name} · {$typeLabel} · " . now()->format('Y-m-d');

        $taken = array_flip($existingNames);
        $out   = "{$base}.pdf";
        for ($n = 2; isset($taken[$out]); $n++) {
            $out = "{$base} ({$n}).pdf";
        }

        return $out;
    }

    /**
     * AT-105 enh — multi-FICA kickoff. Groups the FICA-relevant pages (doc-types
     * whose fica_slot != none) by EACH assigned contact and creates ONE wet-ink
     * FICA verification per distinct contact. A FICA page ticked for two contacts
     * yields two independent verifications (each party FICAs individually).
     * Contact-keyed; dedupes against an in-flight verification per contact. No
     * fica_submissions schema change.
     *
     * @param array<int,array{label:string,contact_ids:int[],pages:int[],file:string}> $groups
     * @param array<string,array{label:string,contact_roles:string[],fica_slot:string}> $routing
     * @return array<int,array{contact:string,url:string,reused:bool,slots:int}>
     */
    private function kickoffMultiFica(array $groups, array $routing, int $agencyId, \Illuminate\Support\Collection $attached, $user, ?string &$ficaNote): array
    {
        if (! $user || ! $user->hasPermission('access_compliance')) {
            $ficaNote = 'FICA verification was not started — you do not have compliance access.';
            return [];
        }
        if ($agencyId <= 0) {
            $ficaNote = 'FICA verification was not started — could not determine the agency. Pick an active agency and try again.';
            return [];
        }

        // contactId => [ ['slot'=>ficaDocType, 'file'=>abs], ... ]
        $perContact = [];
        foreach ($groups as $g) {
            $ficaSlot = $routing[$g['label']]['fica_slot'] ?? 'none';
            $docSlot  = self::FICA_SLOT_TO_DOC_TYPE[$ficaSlot] ?? null;
            if (! $docSlot) continue;                       // not a FICA-tagged type
            $abs = $g['file'] ?? null;
            if (! $abs || ! is_file($abs)) continue;
            foreach ($g['contact_ids'] as $cid) {
                $perContact[$cid][] = ['slot' => $docSlot, 'file' => $abs];
            }
        }

        if (empty($perContact)) {
            $ficaNote = 'FICA verification was not started — no FICA-tagged page in this pack was assigned to a contact.';
            return [];
        }

        $service = app(FicaWetInkService::class);
        $results = [];
        foreach ($perContact as $cid => $slots) {
            $contact = $attached->get($cid);
            if (! $contact) continue;

            $existing = $this->existingActiveFica($contact);
            if ($existing) {
                $submission = $existing;
                $reused     = true;
            } else {
                $submission = null;
                DB::transaction(function () use ($service, $contact, $agencyId, $slots, &$submission) {
                    $submission = $service->create($contact, $agencyId, ['source' => 'pdf_splitter']);
                    foreach ($slots as $s) {
                        $service->addStoredDocument($submission, $s['file'], basename($s['file']), $s['slot']);
                    }
                });
                $reused = false;
                if ($submission) {
                    $service->fireSubmitted($submission, $contact, auth()->id());
                }
            }

            if ($submission) {
                $results[] = [
                    'contact' => trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: 'the contact',
                    'url'     => route('compliance.fica.show', $submission),
                    'reused'  => $reused,
                    'slots'   => count($slots),
                ];
            }
        }

        return $results;
    }

    /**
     * AT-105 — the seller's most recent IN-FLIGHT FICA verification, if any.
     * Used to dedupe: splitting another pack for a seller who already has an
     * open verification opens that one instead of spawning a duplicate.
     *
     * "In-flight" = not a terminal outcome. Terminal (rejected / cancelled) and
     * fully-approved submissions are ignored, so a deliberate re-verify after a
     * rejection or an expiry still starts a fresh wet-ink.
     */
    private function existingActiveFica(Contact $contact): ?FicaSubmission
    {
        return FicaSubmission::query()
            ->where('contact_id', $contact->id)
            ->whereIn('status', ['draft', 'submitted', 'under_review', 'agent_approved', 'corrections_requested'])
            ->latest('id')
            ->first();
    }

    // =========================================================================
    // qpdf + pdfunite helpers
    // =========================================================================

    private function qpdfPageCount(string $pdfAbsNorm): array
    {
        $proc = new Process([self::qpdfPath(), '--show-npages', $pdfAbsNorm]);
        $proc->setTimeout(120);
        $proc->run();

        if (!$proc->isSuccessful()) {
            return [0, trim((string)$proc->getErrorOutput())];
        }

        $out = trim((string)$proc->getOutput());
        if (!preg_match('/^\d+$/', $out)) {
            return [0, 'Unexpected qpdf output: ' . $out];
        }

        return [(int)$out, ''];
    }

    private function qpdfExtractRange(string $pdfAbsNorm, int $from, int $to, string $outAbsNorm): void
    {
        $range = $from === $to ? (string)$from : ($from . '-' . $to);
        $proc  = new Process([self::qpdfPath(), $pdfAbsNorm, '--pages', $pdfAbsNorm, $range, '--', $outAbsNorm]);
        $proc->setTimeout(120);
        $proc->run();

        if (!$proc->isSuccessful()) {
            $err = trim((string)$proc->getErrorOutput());
            throw new \RuntimeException("qpdf extract failed for range {$range}: {$err}");
        }
    }

    private function pdfUnite(array $partsAbsNorm, string $outAbsNorm): void
    {
        $cmd  = array_merge([self::pdfunitePath()], $partsAbsNorm, [$outAbsNorm]);
        $proc = new Process($cmd);
        $proc->setTimeout(120);
        $proc->run();

        if (!$proc->isSuccessful()) {
            $err = trim((string)$proc->getErrorOutput());
            throw new \RuntimeException("pdfunite failed: {$err}");
        }
    }

    // =========================================================================
    // OCR pipeline
    // =========================================================================

    /**
     * Render one PDF page to PNG at 200 DPI via pdftoppm (absolute path).
     * Globs for the output file rather than guessing zero-padding.
     *
     * @return string  Absolute normalised path to the produced PNG
     */
    private function pdfToPpmPng(string $pdfAbsNorm, string $outPrefixAbsNorm, int $page): string
    {
        $before = time() - 1;

        $proc = new Process([
            self::pdftoppmPath(),
            '-f', (string)$page,
            '-l', (string)$page,
            '-png',
            '-r', '200',
            $pdfAbsNorm,
            $outPrefixAbsNorm,
        ]);
        $proc->setTimeout(120);
        $proc->run();

        if (!$proc->isSuccessful()) {
            $err = trim((string)$proc->getErrorOutput());
            throw new \RuntimeException("pdftoppm failed on page {$page}: {$err}");
        }

        $files = glob($outPrefixAbsNorm . '-*.png');
        if (empty($files)) {
            throw new \RuntimeException(
                "pdftoppm produced no PNG for page {$page} (prefix: {$outPrefixAbsNorm})"
            );
        }

        $newer = array_filter($files, fn($f) => filemtime($f) >= $before);
        if (!empty($newer)) {
            $files = array_values($newer);
        }

        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

        return str_replace('\\', '/', $files[0]);
    }

    /**
     * Crop a PNG to its top 30% to speed up OCR.
     * Uses GD (preferred) → Imagick → original unchanged.
     */
    private function cropTopPortion(string $pngAbsNorm): string
    {
        $outPath = $pngAbsNorm . '__crop.png';

        if (function_exists('imagecreatefrompng')) {
            $src = @imagecreatefrompng($pngAbsNorm);
            if ($src !== false) {
                $w    = imagesx($src);
                $h    = imagesy($src);
                $crop = max(1, (int)floor($h * 0.30));
                $dst  = imagecreatetruecolor($w, $crop);
                imagecopy($dst, $src, 0, 0, 0, 0, $w, $crop);
                imagepng($dst, $outPath);
                imagedestroy($src);
                imagedestroy($dst);
                return str_replace('\\', '/', $outPath);
            }
        }

        if (extension_loaded('imagick')) {
            try {
                $im   = new \Imagick($pngAbsNorm);
                $w    = $im->getImageWidth();
                $h    = $im->getImageHeight();
                $crop = max(1, (int)floor($h * 0.30));
                $im->cropImage($w, $crop, 0, 0);
                $im->writeImage($outPath);
                $im->destroy();
                return str_replace('\\', '/', $outPath);
            } catch (\Exception $e) {
                // fall through
            }
        }

        return $pngAbsNorm;
    }

    /**
     * Resize a PNG to a thumbnail JPEG for the review table.
     * Soft-fails silently (GD required; skip if not available).
     */
    private function makeThumbnail(string $srcPng, string $dstJpg, int $maxW = 720): void
    {
        if (!function_exists('imagecreatefrompng')) return;

        $src = @imagecreatefrompng($srcPng);
        if ($src === false) return;

        $w    = imagesx($src);
        $h    = imagesy($src);
        $newW = $maxW;
        $newH = max(1, (int)round($h * $maxW / $w));

        $dst = imagecreatetruecolor($newW, $newH);
        // White background (handles any PNG transparency)
        imagefilledrectangle($dst, 0, 0, $newW, $newH, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
        imagejpeg($dst, $dstJpg, 50);
        imagedestroy($src);
        imagedestroy($dst);
    }

    /**
     * Run Tesseract (absolute path) on an image.
     * Soft-fails — unrecognised pages become 'other'.
     */
    private function ocrImage(string $pngAbsNorm, string $txtOutBaseAbsNorm): string
    {
        $proc = new Process([
            self::tesseractPath(),
            $pngAbsNorm,
            $txtOutBaseAbsNorm,
            '-l', 'eng',
            '--dpi', '200',
        ]);
        $proc->setTimeout(120);
        $proc->run();

        if (!$proc->isSuccessful()) return '';

        $txt = $txtOutBaseAbsNorm . '.txt';
        if (!file_exists($txt)) return '';
        $s = @file_get_contents($txt);
        return $s !== false ? (string)$s : '';
    }

    /**
     * Classify one PDF page: render → crop → OCR → score all doc types.
     *
     * @return array{0: string, 1: string, 2: array<string,int>}  [label, snippet, scores]
     */
    private function classifyPage(string $pdfAbsNorm, string $tmpAbsNorm, int $page): array
    {
        $padded  = str_pad((string)$page, 3, '0', STR_PAD_LEFT);
        $prefix  = $tmpAbsNorm . '/page_' . $padded;

        $png     = $this->pdfToPpmPng($pdfAbsNorm, $prefix, $page);
        $cropped = $this->cropTopPortion($png);

        $txtBase = $tmpAbsNorm . '/ocr_' . $padded;
        $text    = $this->ocrImage($cropped, $txtBase);

        $t       = mb_strtolower($text ?? '');
        $snippet = mb_substr(preg_replace('/\s+/', ' ', trim($text ?? '')), 0, 120);

        $scores = [
            'mandate' => $this->scoreKeywords($t, [
                'exclusive authority to sell', 'exclusive mandate',
                'the mandate company', 'home finders',
                'authority to sell', 'sole mandate',
            ]),
            'fica' => $this->scoreKeywords($t, [
                'fica', 'f.i.c.a', 'kyc', 'know your client',
                'client due diligence', 'cdd', 'source of funds',
            ]),
            'ids' => $this->scoreKeywords($t, [
                'republic of south africa', 'identity document', 'id number',
                'passport', 'date of birth',
            ]),
            'por' => $this->scoreKeywords($t, [
                'proof of residence', 'utility bill',
                'water and electricity', 'proof of address',
            ]),
            'condition_report' => $this->scoreKeywords($t, [
                'condition report', 'property condition',
                'inspection report', 'defects list',
            ]),
            'listing_form' => $this->scoreKeywords($t, [
                'listing form', 'listing agreement', 'property listing',
                'listing information',
            ]),
            'rates_taxes' => $this->scoreKeywords($t, [
                'rates and taxes', 'municipal rates', 'rates clearance',
                'clearance certificate', 'municipality account',
            ]),
            'body_corporate' => $this->scoreKeywords($t, [
                'body corporate', 'sectional title', 'trustees',
                'managing agent', 'levy account',
            ]),
            'house_rules' => $this->scoreKeywords($t, [
                'house rules', 'conduct rules', 'rules of the scheme',
                'homeowners association', 'scheme rules',
            ]),
            'otp' => $this->scoreKeywords($t, [ // AT-254 A — one OTP slug (was offer_to_purchase)
                'offer to purchase', 'agreement of sale',
                'purchase price', 'purchaser', 'offer and acceptance',
            ]),
            'disclosure' => $this->scoreKeywords($t, [
                'disclosure', 'latent defects', 'patent defects',
                'seller disclosure', 'voetstoets',
            ]),
        ];


        // Apply learned phrase boosts from DB (cached per request, soft-fails if table absent)
        foreach ($this->getLearnedBoosts() as $bucket => $phrases) {
            if (!isset($scores[$bucket])) continue;
            foreach ($phrases as $phrase => $weight) {
                if ($phrase !== '' && str_contains($t, $phrase)) {
                    $scores[$bucket] += (int)$weight;
                }
            }
        }

        $label = $this->resolveLabel($scores, $t);

        return [$label, $snippet, $scores];
    }

    /**
     * Resolve the winning doc-type label from the keyword scores.
     *
     * Highest score wins; ties break by priority order (earlier = stronger):
     *   mandate > offer_to_purchase > fica > ids > por > rates_taxes >
     *   body_corporate > house_rules > condition_report > listing_form >
     *   disclosure > other.
     *
     * AT-105 — strong Proof-of-Residence override. A SA proof of residence is
     * commonly an AFFIDAVIT headed "Republic of South Africa" that quotes the
     * deponent's ID number and date of birth — so it out-scores the 'por'
     * bucket on the 'ids' bucket and would auto-label 'ids', filing into the
     * FICA ID slot (id_copy) instead of Proof of Residence (proof_of_address).
     * That is the live "both pages → id_copy" bug: the slot mapping is correct,
     * but the POR page was mis-classified 'ids' upstream. An explicit
     * "proof of residence" / "proof of address" phrase is unambiguous, so
     * honour it over 'ids'. A pure ID page carries no such phrase → unaffected.
     */
    private function resolveLabel(array $scores, string $t): string
    {
        $priority = [
            'mandate', 'otp', 'fica', 'ids', 'por',
            'rates_taxes', 'body_corporate', 'house_rules',
            'condition_report', 'listing_form', 'disclosure',
        ];

        $label = 'other';
        $best  = 0;
        foreach ($priority as $bucket) {
            if (($scores[$bucket] ?? 0) > $best) {
                $best  = $scores[$bucket];
                $label = $bucket;
            }
        }

        if ($label === 'ids'
            && ($scores['por'] ?? 0) > 0
            && (str_contains($t, 'proof of residence') || str_contains($t, 'proof of address'))) {
            $label = 'por';
        }

        return $label;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function scoreKeywords(string $haystack, array $keywords): int
    {
        $score = 0;
        foreach ($keywords as $kw) {
            if ($kw !== '' && str_contains($haystack, $kw)) {
                $score++;
            }
        }
        return $score;
    }

    private function groupRanges(array $labels): array
    {
        $out       = [];
        $prevLabel = null;
        $start     = null;
        $prevPage  = null;

        foreach ($labels as $page => $label) {
            if ($prevLabel === null) {
                $prevLabel = $label;
                $start     = $page;
                $prevPage  = $page;
                continue;
            }

            if ($label === $prevLabel && $page === $prevPage + 1) {
                $prevPage = $page;
                continue;
            }

            $out[]     = ['label' => $prevLabel, 'from' => $start, 'to' => $prevPage];
            $prevLabel = $label;
            $start     = $page;
            $prevPage  = $page;
        }

        if ($prevLabel !== null) {
            $out[] = ['label' => $prevLabel, 'from' => $start, 'to' => $prevPage];
        }

        return $out;
    }

    /**
     * Build the summary text included in the ZIP.
     * Per-page lines show only non-zero scores to keep the file readable.
     * $overrides: [page => ['from' => autoLabel, 'to' => finalLabel]]
     */
    private function buildSummary(
        array $labels,
        array $snippets,
        array $pageScores,
        array $ranges,
        int   $pCount,
        array $overrides = []
    ): string {
        $lines   = [];
        $lines[] = 'PDF Pack Split Summary';
        $lines[] = "Total pages: {$pCount}";

        if (!empty($overrides)) {
            $lines[] = 'Final labels reflect user overrides.';
            $lines[] = '';
            $lines[] = 'Overrides applied:';
            foreach ($overrides as $pg => $chg) {
                $lines[] = "  p{$pg}: {$chg['from']} -> {$chg['to']}";
            }
        }

        $lines[] = '';
        $lines[] = 'Per-page classification:';
        foreach ($labels as $pg => $lbl) {
            $snip = $snippets[(string)$pg] ?? ($snippets[$pg] ?? '');
            $sc   = $pageScores[(string)$pg] ?? ($pageScores[$pg] ?? []);

            // Only show non-zero scores
            $nonZero = array_filter($sc, fn($v) => $v > 0);
            $scoreStr = !empty($nonZero)
                ? implode(' ', array_map(fn($k, $v) => "{$k}={$v}", array_keys($nonZero), $nonZero))
                : 'no hits';

            $flag    = isset($overrides[$pg]) ? ' [OVERRIDE]' : '';
            $lines[] = "  p{$pg}: [{$lbl}]{$flag} ({$scoreStr}) " . ($snip !== '' ? $snip : '(no OCR text)');
        }
        $lines[] = '';

        $lines[] = 'Ranges:';
        foreach ($ranges as $r) {
            $lines[] = "- {$r['label']}: pages {$r['from']}"
                . ($r['to'] !== $r['from'] ? "-{$r['to']}" : '');
        }
        $lines[] = '';

        // Counts for all registered doc types
        $counts = array_fill_keys(array_keys($this->docTypes()), 0);
        foreach ($labels as $label) {
            if (isset($counts[$label])) $counts[$label]++;
            else $counts[$label] = 1;
        }
        $lines[] = 'Page counts by bucket:';
        foreach ($counts as $k => $v) {
            if ($v > 0) $lines[] = "- {$k}: {$v}";
        }
        $lines[] = '';

        return implode("\r\n", $lines);
    }

    // =========================================================================
    // Learning helpers
    // =========================================================================

    /**
     * Persist overrides to pdf_splitter_feedback and incrementally update
     * pdf_splitter_learned_phrases.  A phrase is enabled only after it reaches
     * LEARN_THRESHOLD hits across distinct override events.
     *
     * Wrapped in try/catch so a missing table never breaks confirm().
     */
    private function logFeedback(
        string $base,
        array  $overrides,
        array  $snippets,
        array  $pageScores
    ): void {
        $now = now();

        foreach ($overrides as $page => $change) {
            $snippet = mb_substr(
                trim((string)($snippets[(string)$page] ?? $snippets[$page] ?? '')),
                0, 200
            );
            $scores = $pageScores[(string)$page] ?? $pageScores[$page] ?? [];

            try {
                // Record the override for audit / rebuild command
                DB::table('pdf_splitter_feedback')->insert([
                    'base_name'   => $base,
                    'page_number' => $page,
                    'auto_label'  => $change['from'],
                    'final_label' => $change['to'],
                    'snippet'     => $snippet,
                    'scores'      => json_encode($scores),
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);

                // Learn from this override if the snippet has enough text
                if (mb_strlen($snippet) >= 8) {
                    $bucket  = $change['to'];
                    $bigrams = $this->extractBigrams($snippet);

                    foreach ($bigrams as $phrase) {
                        // Ensure row exists (ignore if already there)
                        DB::table('pdf_splitter_learned_phrases')->insertOrIgnore([
                            'bucket'     => $bucket,
                            'phrase'     => $phrase,
                            'weight'     => 1,
                            'hits'       => 0,
                            'enabled'    => false,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);

                        // Atomically increment hits; enable when threshold is reached
                        DB::table('pdf_splitter_learned_phrases')
                            ->where('bucket', $bucket)
                            ->where('phrase', $phrase)
                            ->update([
                                'hits'       => DB::raw('hits + 1'),
                                'enabled'    => DB::raw(
                                    'CASE WHEN hits + 1 >= ' . self::LEARN_THRESHOLD . ' THEN 1 ELSE 0 END'
                                ),
                                'updated_at' => $now,
                            ]);
                    }
                }
            } catch (\Throwable) {
                // Non-fatal: tables may not exist yet, or DB may be locked.
                // Never interrupt confirm() for a logging failure.
            }
        }

        // Flush per-request boost cache so subsequent classifyPage() calls (if any)
        // in the same process see the new phrases immediately.
        $this->learnedBoosts = null;
    }

    /**
     * Load enabled learned phrases from the DB once per request.
     * Returns [bucket => [phrase => weight]].
     * Soft-fails to [] if the table does not exist yet.
     */
    private function getLearnedBoosts(): array
    {
        if ($this->learnedBoosts !== null) {
            return $this->learnedBoosts;
        }

        try {
            $rows = DB::table('pdf_splitter_learned_phrases')
                ->where('enabled', true)
                ->select('bucket', 'phrase', 'weight')
                ->get();

            $boosts = [];
            foreach ($rows as $row) {
                $boosts[$row->bucket][$row->phrase] = (int)$row->weight;
            }
            $this->learnedBoosts = $boosts;
        } catch (\Throwable) {
            // Table not yet migrated — gracefully degrade
            $this->learnedBoosts = [];
        }

        return $this->learnedBoosts;
    }

    /**
     * Extract 2-word phrases (bigrams) from an OCR snippet.
     * Filters out short tokens and pure numbers; caps at 20 phrases.
     */
    private function extractBigrams(string $text): array
    {
        $words = preg_split('/\s+/', mb_strtolower(trim($text)));
        $words = array_values(array_filter(
            $words,
            fn($w) => mb_strlen($w) >= 3 && !is_numeric($w)
        ));

        $bigrams = [];
        for ($i = 0, $n = count($words) - 1; $i < $n; $i++) {
            $bigrams[] = $words[$i] . ' ' . $words[$i + 1];
        }

        return array_slice(array_unique($bigrams), 0, 20);
    }

    /**
     * Download the last generated ZIP (stored in session) without navigating away.
     * Called from the upload page via hidden iframe.
     */
    public function downloadLastZip()
    {
        $zipAbs = session('splitter_last_zip');
        $zipName = session('splitter_last_zip_name');

        if (!$zipAbs || !is_string($zipAbs) || !file_exists($zipAbs)) {
            abort(404);
        }

        // One-shot download
        session()->forget(['splitter_last_zip', 'splitter_last_zip_name']);

        return response()->download($zipAbs, $zipName ?: basename($zipAbs));
    }


}
