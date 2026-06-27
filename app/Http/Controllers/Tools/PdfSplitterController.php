<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\FicaSubmission;
use App\Models\Property;
use App\Models\SplitterDocType;
use App\Services\Compliance\AgencyComplianceDocTypeService;
use App\Services\Compliance\FicaWetInkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $cols = ['address', 'street_number', 'street_name', 'suburb', 'city', 'complex_name', 'unit_number', 'property_number'];
        $terms = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY);

        $rows = Property::query()
            ->visibleTo($request->user())
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
            ->get(['id', 'address', 'street_number', 'street_name', 'suburb', 'city', 'complex_name', 'unit_number', 'property_number']);

        return response()->json($rows->map(function ($p) {
            $addr = trim((string) $p->address);
            if ($addr === '') {
                $addr = trim(implode(' ', array_filter([
                    $p->unit_number ? 'Unit ' . $p->unit_number : null,
                    $p->complex_name,
                    $p->street_number,
                    $p->street_name,
                ])));
            }
            if ($addr === '') { $addr = '(no address)'; }

            // AT-105 — surface the seller/owner's OWN FICA state so the FICA
            // kickoff toggle can key off the CONTACT (not the property's
            // import-set compliance snapshot). null seller = no kickoff target.
            $seller = $p->sellerOwnerContact();
            $sellerFica = $seller ? $seller->ficaStatus() : null; // complete|expiring|incomplete|null

            return [
                'id'          => $p->id,
                'label'       => trim($addr . ($p->suburb ? ' — ' . $p->suburb : '')),
                'ref'         => $p->property_number,
                'seller'      => $seller ? trim(($seller->first_name ?? '') . ' ' . ($seller->last_name ?? '')) : null,
                'seller_fica' => $sellerFica,
            ];
        }));
    }

    /**
     * AT-105 enh — the contacts attached to a property, with their pivot role
     * and own FICA state. Drives the per-page contact selector + sticky
     * auto-resolution on the review screen. Scoped to the user's visible
     * properties; soft-deleted / cross-agency contacts never appear (the
     * contacts() relation respects SoftDeletes + ContactScope).
     */
    public function propertyContacts(Request $request, int $property)
    {
        $prop = Property::query()->visibleTo($request->user())->find($property);
        if (! $prop) {
            return response()->json(['contacts' => []], 404);
        }

        $contacts = $prop->contacts()->get()->map(function ($c) {
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

    public function run(Request $request)
    {
        set_time_limit(300);
        @ini_set('max_execution_time', '300');
        $validated = $request->validate([
            'base_name' => 'required|string|min:2|max:120',
            'pdf'       => 'required|file|mimes:pdf|max:51200', // 50MB
        ]);

        $baseRaw = trim($validated['base_name']);
        $base = Str::of($baseRaw)
            ->lower()
            ->replaceMatches('/[^a-z0-9\s\-_]+/', '')
            ->replaceMatches('/\s+/', '_')
            ->replaceMatches('/_+/', '_')
            ->trim('_')
            ->toString();

        $ts = now()->format('Ymd_His');

        // All splitter paths under private/
        $fileName = $base . '__' . $ts . '.pdf';
        $origRel  = 'private/splitter/originals/' . $fileName;
        Storage::disk('local')->putFileAs('private/splitter/originals', $request->file('pdf'), $fileName);
        $origAbs     = Storage::disk('local')->path($origRel);
        $origAbsNorm = str_replace('\\', '/', $origAbs);

        if (!file_exists($origAbs) || filesize($origAbs) === 0) {
            return redirect()->route('tools.pdf_splitter.index')
                ->withErrors(['pdf' => 'Stored PDF not found or empty: ' . $origAbsNorm]);
        }

        // Output folder (created now so confirm() can write into it)
        $outDirRel     = 'private/splitter/output/' . $base . '__' . $ts;
        Storage::disk('local')->makeDirectory($outDirRel);

        // Temp OCR + thumbnail folder
        $tmpRel     = 'private/splitter/tmp/' . $base . '__' . $ts;
        Storage::disk('local')->makeDirectory($tmpRel);
        $tmpAbs     = Storage::disk('local')->path($tmpRel);
        $tmpAbsNorm = str_replace('\\', '/', $tmpAbs);

        // Page count
        [$pCount, $pErr] = $this->qpdfPageCount($origAbsNorm);
        if ($pCount < 1) {
            return redirect()->route('tools.pdf_splitter.index')->withErrors([
                'pdf' => 'Could not read page count. qpdf: ' . ($pErr ?: '(none)'),
            ]);
        }

        // Classify each page (thumbnails generated lazily in serveThumb)
        $labels     = [];
        $snippets   = [];
        $pageScores = [];
        for ($page = 1; $page <= $pCount; $page++) {
            [$label, $snippet, $scores] = $this->classifyPage($origAbsNorm, $tmpAbsNorm, $page);
            $labels[$page]     = $label;
            $snippets[$page]   = $snippet;
            $pageScores[$page] = $scores;
        }

        // Save manifest for review step — no ZIP yet
        // Store relative storage path (not absolute) to avoid path disclosure
        $manifest = [
            'base'        => $base,
            'ts'          => $ts,
            'origRel'     => $origRel,
            'outDirRel'   => $outDirRel,
            'tmpRel'      => $tmpRel,
            'pCount'      => $pCount,
            'labels'      => $labels,
            'snippets'    => $snippets,
            'pageScores'  => $pageScores,
            'docTypes'    => $this->docTypes(),
        ];

        $manifestId = $base . '__' . $ts;
        Storage::disk('local')->put(
            $tmpRel . '/manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // Manifest ID in session — review/confirm read it; path is never user-controlled
        session(['splitter_manifest_id' => $manifestId]);

        return redirect()->route('tools.pdf_splitter.review');
    }

    // =========================================================================
    // Review + Confirm (two-step flow)
    // =========================================================================

    /**
     * Serve a page thumbnail from private storage.
     * Generated on first request (lazy) — never during run().
     * Manifest ID is validated against the session value; URL param is only the page number.
     */
    public function serveThumb(int $page)
    {
        $manifestId = session('splitter_manifest_id');
        if (!$manifestId || !preg_match('/^[a-z0-9_-]+__\d{8}_\d{6}$/', $manifestId)) {
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
     * Show the review table.
     * Manifest path is derived from the session — never from user input.
     */
    public function review()
    {
        $manifest = $this->loadManifestArrayOrNull(session('splitter_manifest_id'));
        if (! $manifest) {
            return redirect()->route('tools.pdf_splitter.index')
                ->withErrors(['pdf' => 'No active session, or it expired. Please upload a PDF first.']);
        }

        // AT-105 — the FICA auto-kickoff toggle is only offered to users who
        // can act on compliance. Server-enforced again in link().
        $canFica = (bool) (auth()->user()?->hasPermission('access_compliance'));

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

        return view('tools.pdf_splitter_review', compact('manifest', 'canFica', 'routing', 'roleSets', 'roleLabels'));
    }

    /**
     * Accept label overrides → group ranges → extract bucket PDFs → ZIP → download.
     * OCR is NOT re-run. Only labels that have at least one page are included in the ZIP.
     */
    public function confirm(Request $request)
    {
        $manifest = $this->loadManifestArrayOrNull(session('splitter_manifest_id'));
        if (! $manifest) {
            return redirect()->route('tools.pdf_splitter.index')
                ->withErrors(['pdf' => 'Session expired or manifest not found. Please re-upload.']);
        }

        $base        = $manifest['base'];
        $ts          = $manifest['ts'];
        $origRel     = $manifest['origRel'];
        $origAbsNorm = str_replace('\\', '/', Storage::disk('local')->path($origRel));
        $outDirRel   = $manifest['outDirRel'];
        $tmpRel      = $manifest['tmpRel'];
        $pCount      = (int)$manifest['pCount'];
        $snippets    = $manifest['snippets'];
        $pageScores  = $manifest['pageScores'];

        // Apply posted overrides — whitelist against active doc types.
        [$finalLabels, $overrides] = $this->resolveFinalLabels($request, $manifest['labels'], $pCount);

        // Log overrides as feedback and incrementally update learned phrases
        if (!empty($overrides)) {
            $this->logFeedback($base, $overrides, $snippets, $pageScores);
        }

        // Ensure output directory exists
        Storage::disk('local')->makeDirectory($outDirRel);
        $outDirAbs     = Storage::disk('local')->path($outDirRel);
        $outDirAbsNorm = str_replace('\\', '/', $outDirAbs);

        $tmpAbs     = Storage::disk('local')->path($tmpRel);
        $tmpAbsNorm = str_replace('\\', '/', $tmpAbs);

        $ranges       = $this->groupRanges($finalLabels);
        $bucketOrder  = array_keys($this->docTypes());
        $bucketRanges = array_fill_keys($bucketOrder, []);
        foreach ($ranges as $r) {
            $bucketRanges[$r['label']][] = $r;
        }

        // Only produce PDFs for labels that appear at least once — no placeholders
        $outFiles = [];
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
            $outFiles[] = $outAbs;
        }

        if (empty($outFiles)) {
            return redirect()->route('tools.pdf_splitter.review')
                ->withErrors(['pdf' => 'No pages were assigned to any label.']);
        }

        // ZIP
        $zipRel     = 'private/splitter/zips/' . $base . '__' . $ts . '__split_pack.zip';
        Storage::disk('local')->makeDirectory('private/splitter/zips');
        $zipAbs     = Storage::disk('local')->path($zipRel);
        $zipAbsNorm = str_replace('\\', '/', $zipAbs);

        $zip = new ZipArchive();
        if ($zip->open($zipAbsNorm, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return redirect()->route('tools.pdf_splitter.review')
                ->withErrors(['pdf' => 'Could not create ZIP file.']);
        }

        foreach ($outFiles as $abs) {
            $zip->addFile($abs, basename($abs));
        }

        $summary = $this->buildSummary($finalLabels, $snippets, $pageScores, $ranges, $pCount, $overrides);
        $zip->addFromString($base . '__summary.txt', $summary);
        $zip->close();

        // ZIP-ONLY action ("Download ZIP"). Filing to the property/contacts and
        // any FICA kickoff are the SEPARATE "Link to CoreX" action (link()) —
        // the two intents are never conflated. The manifest is retained in the
        // session so the agent can still run Link afterwards from the same split.
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
     * AT-105 enhancement — "Link to CoreX" action. Files each page to its
     * configured destination(s) keyed to its PER-PAGE assigned contact, and
     * (toggle) kicks off ONE wet-ink FICA per distinct contact that has FICA
     * pages assigned. Produces NO ZIP. The agent's per-page contact choices
     * arrive in contacts[page]; doc-type overrides in labels[page].
     */
    public function link(Request $request)
    {
        $manifest = $this->loadManifestArrayOrNull(session('splitter_manifest_id'));
        if (! $manifest) {
            return redirect()->route('tools.pdf_splitter.index')
                ->withErrors(['pdf' => 'Session expired or manifest not found. Please re-upload.']);
        }

        $base        = $manifest['base'];
        $origRel     = $manifest['origRel'];
        $origAbsNorm = str_replace('\\', '/', Storage::disk('local')->path($origRel));
        $outDirRel   = $manifest['outDirRel'];
        $pCount      = (int) $manifest['pCount'];
        $autoLabels  = $manifest['labels'];
        $snippets    = $manifest['snippets'];
        $pageScores  = $manifest['pageScores'];

        // A property is mandatory for filing — Download ZIP is the no-property path.
        $propertyId = (int) $request->input('property_id');
        $property   = $propertyId > 0
            ? Property::query()->visibleTo($request->user())->find($propertyId)
            : null;

        if (! $property) {
            return redirect()->route('tools.pdf_splitter.review')
                ->withErrors(['pdf' => 'Select a property above before linking — "Link to CoreX" files the documents to that property. Use "Download ZIP" if you only want the files.']);
        }

        $agencyId = (int) ($request->user()->effectiveAgencyId() ?? $property->agency_id ?? 0);

        // Resolve final labels (apply whitelisted overrides) — same as confirm().
        [$finalLabels, $overrides] = $this->resolveFinalLabels($request, $autoLabels, $pCount);
        if (! empty($overrides)) {
            $this->logFeedback($base, $overrides, $snippets, $pageScores);
        }

        // Per-page assigned contacts — MANY-TO-MANY. Each page carries a SET of
        // contacts across any/all of the doc-type's allowed roles (the OTP links
        // to all sellers AND all buyers at once). Only contacts actually attached
        // to THIS property are honoured (the page selector + link/create flow keep
        // the pivot current); anything else is dropped (no orphan, no cross-
        // property leak).
        $attached     = $property->contacts()->get()->keyBy('id');
        $postedC      = (array) $request->input('contacts', []);
        $pageContacts = [];                       // page => int[] (attached ids)
        for ($p = 1; $p <= $pCount; $p++) {
            $raw = $postedC[(string) $p] ?? [];
            $ids = collect(is_array($raw) ? $raw : [$raw])
                ->map(fn ($v) => (int) $v)
                ->filter(fn ($cid) => $cid > 0 && $attached->has($cid))
                ->unique()->sort()->values()->all();
            $pageContacts[$p] = $ids;
        }

        // Group pages by (label, exact-contact-SET). Pages sharing a label and the
        // same set of ticked contacts merge into one output. Non-contiguous pages
        // are fine — extractPageSet handles arbitrary page lists.
        $groups = [];   // key => ['label'=>, 'contact_ids'=>int[], 'pages'=>int[]]
        for ($p = 1; $p <= $pCount; $p++) {
            $label = $finalLabels[$p];
            $ids   = $pageContacts[$p];
            $key   = $label . '|' . (empty($ids) ? 'none' : implode(',', $ids));
            if (! isset($groups[$key])) {
                $groups[$key] = ['label' => $label, 'contact_ids' => $ids, 'pages' => []];
            }
            $groups[$key]['pages'][] = $p;
        }

        if (empty($groups)) {
            return redirect()->route('tools.pdf_splitter.review')
                ->withErrors(['pdf' => 'No pages were assigned to any label.']);
        }

        Storage::disk('local')->makeDirectory($outDirRel);
        $outDirAbsNorm = str_replace('\\', '/', Storage::disk('local')->path($outDirRel));

        // Extract one PDF per group from the original.
        $gi = 0;
        foreach ($groups as $key => &$g) {
            $gi++;
            $idsPart = empty($g['contact_ids']) ? 'unassigned' : ('c' . implode('-', $g['contact_ids']));
            $outAbs  = $outDirAbsNorm . '/' . $base . '__' . $g['label'] . '__' . $idsPart . '__g' . $gi . '.pdf';
            $this->extractPageSet($origAbsNorm, $g['pages'], $outAbs);
            $g['file'] = $outAbs;
        }
        unset($g);

        $routing = app(AgencyComplianceDocTypeService::class)->routingMapBySlugFor($agencyId);

        // File every group to its destination(s) + assigned contact.
        $filed = $this->fileGroupsToDestinations($property, array_values($groups), $agencyId, $attached);

        // FICA — group the FICA-relevant pages by assigned contact; one wet-ink
        // verification per distinct contact. Agent TOGGLE, never silent.
        $ficaResults = [];
        $ficaNote    = null;
        if ($request->boolean('trigger_fica')) {
            $ficaResults = $this->kickoffMultiFica(
                array_values($groups), $routing, $agencyId, $attached, $request->user(), $ficaNote
            );
        }

        $redirect = redirect()->route('tools.pdf_splitter.index');

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
     * the OCR auto-labels. Returns [finalLabels (int-keyed), overrides].
     *
     * @return array{0: array<int,string>, 1: array<int,array{from:string,to:string}>}
     */
    private function resolveFinalLabels(Request $request, array $autoLabels, int $pCount): array
    {
        $posted       = $request->input('labels', []);
        $validBuckets = array_keys($this->docTypes());
        $finalLabels  = [];
        $overrides    = [];

        for ($p = 1; $p <= $pCount; $p++) {
            $auto     = $autoLabels[(string) $p] ?? 'other';
            $override = isset($posted[(string) $p]) ? trim($posted[(string) $p]) : null;

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
     * @param array<int,array{label:string,contact_ids:int[],pages:int[],file:string}> $groups
     * @return array{property:int, contact:int, fallback:int}
     */
    private function fileGroupsToDestinations(Property $property, array $groups, int $agencyId, \Illuminate\Support\Collection $attached): array
    {
        $publicDisk = Storage::disk('public');
        $dir = "properties/{$property->id}/files";
        if (! $publicDisk->exists($dir)) {
            $publicDisk->makeDirectory($dir);
        }

        $destinations = app(AgencyComplianceDocTypeService::class);

        $slugs   = collect($groups)->pluck('label')->filter()->unique()->values();
        $typeMap = DocumentType::query()->whereIn('slug', $slugs)->pluck('id', 'slug')->toArray();

        $result = ['property' => 0, 'contact' => 0, 'fallback' => 0];
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

            $doc = Document::create([
                'original_name'    => $filename,
                'storage_path'     => $relPath,
                'disk'             => 'public',
                'mime_type'        => 'application/pdf',
                'size'             => @filesize($abs) ?: null,
                'document_type_id' => $typeMap[$labelSlug] ?? null,
                'source_type'      => 'pdf_splitter',
                // Provenance: the property this pack was split against — recorded on
                // every split doc (incl. contact-only ones) so a contact's
                // "Not Property-Linked" doc is still traceable to its split.
                'source_id'        => $property->id,
                'uploaded_by'      => auth()->id(),
            ]);

            $dest = $labelSlug
                ? $destinations->destinationForSlug($agencyId, $labelSlug)
                : ['property' => true, 'contact' => false];

            $didAttach = false;
            if ($dest['property']) {
                $doc->properties()->attach($property->id);
                $result['property']++;
                $didAttach = true;
            }
            if ($dest['contact'] && ! empty($g['contact_ids'])) {
                foreach ($g['contact_ids'] as $cid) {
                    $contact = $attached->get($cid);
                    if (! $contact) continue;
                    $partyRole = strtolower(trim((string) ($contact->pivot->role ?? ''))) ?: 'seller';
                    $doc->contacts()->attach($cid, ['party_role' => $partyRole]);
                    $result['contact']++;
                    $didAttach = true;
                }
            }

            if (! $didAttach) {
                $doc->properties()->attach($property->id);
                $result['fallback']++;
            }
        }

        return $result;
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
            'offer_to_purchase' => $this->scoreKeywords($t, [
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

        // Priority: mandate > offer_to_purchase > fica > ids > por >
        //           rates_taxes > body_corporate > house_rules >
        //           condition_report > listing_form > disclosure > other
        $priority = [
            'mandate', 'offer_to_purchase', 'fica', 'ids', 'por',
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

        return [$label, $snippet, $scores];
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
