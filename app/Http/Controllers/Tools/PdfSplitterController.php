<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Property;
use App\Models\SplitterDocType;
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
            return [
                'id'    => $p->id,
                'label' => trim($addr . ($p->suburb ? ' — ' . $p->suburb : '')),
                'ref'   => $p->property_number,
            ];
        }));
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
        $manifestId = session('splitter_manifest_id');
        if (!$manifestId) {
            return redirect()->route('tools.pdf_splitter.index')
                ->withErrors(['pdf' => 'No active session. Please upload a PDF first.']);
        }

        if (!preg_match('/^[a-z0-9_-]+__\d{8}_\d{6}$/', $manifestId)) {
            return redirect()->route('tools.pdf_splitter.index')
                ->withErrors(['pdf' => 'Invalid session token.']);
        }

        $manifestRel = 'private/splitter/tmp/' . $manifestId . '/manifest.json';
        if (!Storage::disk('local')->exists($manifestRel)) {
            return redirect()->route('tools.pdf_splitter.index')
                ->withErrors(['pdf' => 'Session expired or manifest not found. Please re-upload.']);
        }

        $manifest = json_decode(Storage::disk('local')->get($manifestRel), true);

        return view('tools.pdf_splitter_review', compact('manifest'));
    }

    /**
     * Accept label overrides → group ranges → extract bucket PDFs → ZIP → download.
     * OCR is NOT re-run. Only labels that have at least one page are included in the ZIP.
     */
    public function confirm(Request $request)
    {
        $manifestId = session('splitter_manifest_id');
        if (!$manifestId) {
            return redirect()->route('tools.pdf_splitter.index')
                ->withErrors(['pdf' => 'Session expired. Please re-upload.']);
        }

        if (!preg_match('/^[a-z0-9_-]+__\d{8}_\d{6}$/', $manifestId)) {
            return redirect()->route('tools.pdf_splitter.index')
                ->withErrors(['pdf' => 'Invalid session token.']);
        }

        $manifestRel = 'private/splitter/tmp/' . $manifestId . '/manifest.json';
        if (!Storage::disk('local')->exists($manifestRel)) {
            return redirect()->route('tools.pdf_splitter.index')
                ->withErrors(['pdf' => 'Session expired or manifest not found. Please re-upload.']);
        }

        $manifest    = json_decode(Storage::disk('local')->get($manifestRel), true);
        $base        = $manifest['base'];
        $ts          = $manifest['ts'];
        $origRel     = $manifest['origRel'];
        $origAbsNorm = str_replace('\\', '/', Storage::disk('local')->path($origRel));
        $outDirRel   = $manifest['outDirRel'];
        $tmpRel      = $manifest['tmpRel'];
        $pCount      = (int)$manifest['pCount'];
        $autoLabels  = $manifest['labels'];   // string-keyed (from JSON)
        $snippets    = $manifest['snippets'];
        $pageScores  = $manifest['pageScores'];

        // Apply posted overrides — whitelist against DOC_TYPES keys
        $posted       = $request->input('labels', []);
        $validBuckets = array_keys($this->docTypes());
        $finalLabels  = [];   // int-keyed for groupRanges()
        $overrides    = [];   // [page => ['from' => old, 'to' => new]]

        for ($p = 1; $p <= $pCount; $p++) {
            $auto     = $autoLabels[(string)$p] ?? 'other';
            $override = isset($posted[(string)$p]) ? trim($posted[(string)$p]) : null;

            if ($override !== null && in_array($override, $validBuckets, true) && $override !== $auto) {
                $finalLabels[$p] = $override;
                $overrides[$p]   = ['from' => $auto, 'to' => $override];
            } else {
                $finalLabels[$p] = $auto;
            }
        }

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

        // Optionally link each split PDF to a Property's drive.
        $linkedCount = 0;
        $linkedProperty = null;
        $propertyId = (int) $request->input('property_id');
        if ($propertyId > 0) {
            $linkedProperty = Property::query()
                ->visibleTo($request->user())
                ->find($propertyId);

            if ($linkedProperty) {
                $linkedCount = $this->linkOutputsToProperty($linkedProperty, $outFiles);
            }
        }

        // Cleanup tmp PNGs/TXTs; originals + output PDFs are kept
        Storage::disk('local')->deleteDirectory($tmpRel);
        session()->forget('splitter_manifest_id');

        // After generating ZIP: go back to upload screen, and trigger download there (hidden iframe).
        session([
            'splitter_last_zip'      => $zipAbsNorm,
            'splitter_last_zip_name' => basename($zipAbsNorm),
        ]);

        $redirect = redirect()
            ->route('tools.pdf_splitter.index')
            ->with('splitter_download_url', route('tools.pdf_splitter.download'));

        if ($linkedProperty && $linkedCount > 0) {
            $redirect->with('status', "ZIP generated and {$linkedCount} document(s) linked to {$linkedProperty->address}.");
        }

        return $redirect;
    }

    /**
     * Copy each split output into the property's drive as Document records,
     * tagged with a DocumentType when the splitter label slug matches.
     * Returns the number of files linked.
     */
    private function linkOutputsToProperty(Property $property, array $outFiles): int
    {
        $publicDisk = Storage::disk('public');
        $dir = "properties/{$property->id}/files";
        if (! $publicDisk->exists($dir)) {
            $publicDisk->makeDirectory($dir);
        }

        // slug → DocumentType id (cached)
        $typeMap = DocumentType::query()
            ->whereIn('slug', collect($outFiles)->map(fn ($f) => $this->labelFromFilename(basename($f)))->filter()->unique()->values())
            ->pluck('id', 'slug')
            ->toArray();

        $count = 0;
        foreach ($outFiles as $abs) {
            if (! is_file($abs)) continue;

            $labelSlug = $this->labelFromFilename(basename($abs));
            $filename  = basename($abs);
            $relPath   = $dir . '/' . Str::random(8) . '_' . $filename;

            // Copy file into public disk
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
                'uploaded_by'      => auth()->id(),
            ]);

            $doc->properties()->attach($property->id);
            $count++;
        }

        return $count;
    }

    /**
     * Extract the label slug from a splitter output filename:
     * "base__label.pdf" → "label"
     */
    private function labelFromFilename(string $filename): ?string
    {
        if (! preg_match('/__([a-z0-9_]+)\.pdf$/i', $filename, $m)) {
            return null;
        }
        return strtolower($m[1]);
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
