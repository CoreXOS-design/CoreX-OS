<?php

namespace App\Services\Rentals;

use App\Models\RentalInspectionForm;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionScan;
use App\Models\RentalInspectionScanMark;
use App\Models\RentalInspectionSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickPixel;

/**
 * .ai/specs/rental-inspection-form.md §13 — the OMR scan reader, built
 * against cc5's own manifest contract (§12.6). Johan, verbatim: "we read
 * the form back by OMR — COLUMN MARKING ONLY. We do NOT read handwriting,
 * ever." This service never runs OCR and never reads the notes columns —
 * it samples the fixed rectangular regions the manifest names, nothing
 * else.
 *
 * Pipeline: rasterize each page -> detect the four fiducials -> resolve
 * correspondence as TWO separate questions (Johan, 2026-09-22): the
 * fiducial rectangle's own edge-length structure answers "portrait or
 * sideways" from position alone, at any rotation angle, no shape needed
 * (an A4 page is never square) — narrowing to exactly two candidates
 * differing only by "right way up or upside down", the one question the
 * bottom-right circle actually exists to answer. That's resolved first by
 * decoding the printed page identifier under each candidate and keeping
 * whichever matches this scan's own known target (rectangleLabelCandidates()
 * + isPlausibleSimilarity(), calibratePage()'s disambiguation block) — a
 * deterministic, shape-independent signal with no rotation blind spot —
 * falling back to relative fill-ratio comparison only if that's
 * inconclusive. A rotated square's own fill ratio is NOT rotation-
 * invariant (unlike a circle's), which is why classifying by an absolute
 * shape threshold used to cap the working envelope at roughly +-5 degrees;
 * this approach reaches +-24 degrees around upright, sideways, and
 * upside-down alike (measured; see the reader's own test suite) -> solve
 * one affine transform per page mapping manifest pt-space
 * onto that page's actual pixels -> decode the fixed-position page-
 * identifier grid through that transform (BEFORE consulting any
 * manifest — the grid's own position is a published constant, read via
 * RentalInspectionFormPdfService::pageIdentifierGridLayout(), never
 * re-derived) -> once the inspection/page/version is known, load THAT
 * exact form version's manifest and sample every box on this page through
 * the same transform.
 *
 * A single affine transform (scale + rotation + translation, fit by least
 * squares from the four fiducial correspondences) handles an arbitrarily
 * rotated OR skewed scan uniformly — there is no separate "180 degree"
 * code path; a 180-degree rotation and a five-degree camera-angle skew are
 * both just different affine matrices to this code.
 */
class RentalInspectionScanReaderService
{
    /** Print/detectability note only, matching cc5's own RECOMMENDED_MIN_SCAN_DPI — the DPI actually used to rasterize an uploaded scan for analysis. */
    private const RASTER_DPI = 200;

    /**
     * Audit M8 - the most pages one upload may carry. A form is a handful of pages (a large property is rarely past a dozen), so 30 leaves
     * generous room for re-scans while firmly bounding the rasterize-and-sample work done inside one web request. Over the limit the scan is
     * refused with a readable reason (never silently truncated): split it and upload the parts. Stated in rental-inspection-form.md §13.
     */
    public const MAX_PAGES = 30;

    /** How much of the PHP time limit a scan may use before it stops itself with a readable reason (the rest is left for saving). */
    private const TIME_BUDGET_SHARE = 0.8;

    /** The pages rasterized for the most recent scan, kept for the rest of the SAME upload request so a re-file / QR look does not rasterize again. */
    private ?array $rasterCache = null;

    private ?float $deadlineAt = null;

    public function __construct(
        private RentalInspectionFormPdfService $formPdfService,
        private RentalInspectionScanGeometry $geometry,
        private RentalInspectionNoteReadService $noteReader,
    ) {
    }

    // ── Orchestration ───────────────────────────────────────────────────

    /**
     * The whole job for one uploaded scan: rasterize, decode, match against
     * the inspection's CURRENT form version, sample every box, persist one
     * RentalInspectionScanMark per item. Never applies anything to the
     * inspection itself — that only happens once a human confirms
     * (applyMark()/applyAll() below).
     *
     * @param list<string> $leadNotes plain-words notes shown first on the review screen (e.g. that the upload was filed here
     *                                because its own form code says so, §64)
     */
    public function process(RentalInspectionScan $scan, array $leadNotes = []): void
    {
        // Audit M8 - a scan never stays "processing". Any failure leaves it failed with a readable reason, and so does a fatal PHP time-limit,
        // which skips every catch block: the shutdown hook below runs after it and fails the scan if the read never finished.
        $finished = false;
        $scanId = $scan->id;
        register_shutdown_function(static function () use (&$finished, $scanId) {
            if ($finished) {
                return;
            }
            try {
                RentalInspectionScan::withoutGlobalScopes()->whereKey($scanId)->where('status', RentalInspectionScan::STATUS_PROCESSING)->update([
                    'status' => RentalInspectionScan::STATUS_FAILED,
                    'failure_reason' => 'Reading this scan took longer than the server allows, so it was stopped. Upload fewer pages at a time, or scan at 200-300 dpi in black and white or greyscale.',
                ]);
            } catch (\Throwable) {
                // nothing more can be done at shutdown
            }
        });

        try {
            $this->deadlineAt = microtime(true) + $this->timeBudgetSeconds();
            $this->processScan($scan, $leadNotes);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Scan read failed', ['scan' => $scanId, 'error' => $e->getMessage()]);
            RentalInspectionScan::withoutGlobalScopes()->whereKey($scanId)->update([
                'status' => RentalInspectionScan::STATUS_FAILED,
                'failure_reason' => $e instanceof ScanTimeBudgetExceeded
                    ? $e->getMessage()
                    : 'The scan could not be read: ' . $e->getMessage() . ' Try scanning it again at 200-300 dpi in black and white or greyscale.',
            ]);
        } finally {
            $finished = true;
            $this->deadlineAt = null;
        }
    }

    /** Seconds this scan may run before it stops itself: a share of PHP's own limit (generous when the limit is off). Overridable in tests. */
    protected function timeBudgetSeconds(): float
    {
        $limit = (int) ini_get('max_execution_time');

        return $limit > 0 ? $limit * self::TIME_BUDGET_SHARE : 600.0;
    }

    private function assertWithinTimeBudget(string $what): void
    {
        if ($this->deadlineAt !== null && microtime(true) > $this->deadlineAt) {
            throw new ScanTimeBudgetExceeded('Reading this scan took longer than the server allows (' . $what . '), so it was stopped. Upload fewer pages at a time, or scan at 200-300 dpi in black and white or greyscale.');
        }
    }

    /** Drops the rasterized pages kept for the current upload (the controller calls this when the request's work is done). */
    public function flushRasterCache(): void
    {
        $this->rasterCache = null;
    }

    private function processScan(RentalInspectionScan $scan, array $leadNotes = []): void
    {
        // 2026-09-22, Johan — checked ONCE, here, at the entry point, not
        // scattered through rasterize()'s internals. Without this, a missing
        // Imagick extension would still fail safely (the catch below already
        // covers it — \Error extends \Throwable), but with a raw PHP "Class
        // Imagick not found" string as the failure reason: indistinguishable
        // from "the scan itself was unreadable" to the agent looking at it.
        // Those are different facts and the agent needs to be able to tell
        // them apart — one means try a clearer photo, the other means this
        // environment cannot process scans at all and someone else needs to
        // know. extension_loaded(), not class_exists(\Imagick::class) — the
        // class can be autoload-discoverable while the extension itself is
        // absent, which would pass a class_exists() check and still fatal
        // the moment rasterize() actually instantiates it.
        if (!extension_loaded('imagick')) {
            $scan->forceFill([
                'status' => RentalInspectionScan::STATUS_FAILED,
                'failure_reason' => 'Scanning is not available in this environment — the Imagick PHP extension is not installed. This is a server configuration issue, not a problem with the uploaded scan. Contact support.',
            ])->save();

            return;
        }

        $absolutePath = Storage::disk('local')->path($scan->storage_path);

        try {
            $total = $this->pageCountOf($absolutePath, $scan->mime_type);
            if ($total > self::MAX_PAGES) {
                $scan->forceFill([
                    'status' => RentalInspectionScan::STATUS_FAILED,
                    'page_count' => $total,
                    'failure_reason' => "This scan has {$total} pages; one upload can carry at most " . self::MAX_PAGES . ' pages. Split it into smaller files (each page of the form only needs to be in once) and upload them one after the other.',
                ])->save();

                return;
            }
            $pages = $this->rasterizeCached($scan, $absolutePath);
        } catch (ScanTimeBudgetExceeded $e) {
            throw $e; // process() turns it into the scan's own readable failure
        } catch (\Throwable $e) {
            $scan->forceFill(['status' => RentalInspectionScan::STATUS_FAILED, 'failure_reason' => 'Could not open the scan file: ' . $e->getMessage()])->save();

            return;
        }

        if ($pages === []) {
            $scan->forceFill(['status' => RentalInspectionScan::STATUS_FAILED, 'failure_reason' => 'No pages found in the scan file.'])->save();

            return;
        }

        $scan->forceFill(['page_count' => count($pages)])->save();

        // The inspection's CURRENT printed form is the contract everything below is read against (its manifest gives
        // the paper size, the registration marks, the QR position and every box). Without one there is nothing to read.
        $allForms = RentalInspectionForm::currentFor($scan->rental_inspection_id)->get();
        $currentForm = $allForms->first();
        if (! $currentForm) {
            $scan->forceFill(['status' => RentalInspectionScan::STATUS_FAILED, 'failure_reason' => 'The site form has not been printed from this inspection, and no page code (QR) on this scan could be read to say which inspection it belongs to. Print the site form from this inspection and mark that one, or upload the scan on the inspection the form was printed from.'])->save();

            return;
        }

        // Every printed LAYOUT this inspection has had for the current set of rooms and items. A form printed before the safe
        // margin existed (or with another margin) is still in an agent's hands and still scans back: a page is read against
        // whichever layout it was printed with, which its own QR / page grid then confirms. A form whose rooms or items have
        // since changed is a different form and is refused below, exactly as before.
        $layouts = $this->layoutsFor($allForms, $currentForm);
        $formPages = (int) ($currentForm->manifest_json['page_count'] ?? 1);

        // Place and identify every uploaded page. The corner marks (any two to four of them) place it; the page's QR code
        // settles which way up it is and says which inspection and page it is; a page whose QR cannot be read falls back to
        // the printed OMR grid. Every page gets a plain-words result - what was found, or exactly why it could not be read -
        // which the review screen shows.
        $notes = $leadNotes;
        $results = [];   // file page => result row (spec §62)
        $read = [];      // form page number => ['canvas' => Imagick, 'file_page' => int, 'layout' => array]
        $firstIdentity = null;
        $firstLayout = null;
        foreach ($pages as $fileIndex => $page) {
            $this->assertWithinTimeBudget('page ' . $fileIndex . ' of ' . count($pages));
            $found = null;
            $firstFail = null;
            $staleIdentity = null;
            $otherInspection = null;
            foreach ($layouts as $li => $layout) {
                $qr = null;
                $why = null;
                $info = [];
                $transform = $this->calibratePage($page, $layout['geo'], $scan->rental_inspection_id, null, $qr, $why, $info);
                if ($transform === null) {
                    $firstFail ??= ['reason' => $why ?: 'The page could not be placed - no corner marks or QR code were found on it.', 'info' => $info];

                    continue;
                }
                if ($qr !== null) {
                    $identity = $qr;
                    $via = 'qr';
                } else {
                    $identity = $this->decodePageIdentifier($page, $transform, $layout['geo'], $scan->rental_inspection_id);
                    $via = 'grid';
                    if ($identity === null) {
                        $firstFail ??= ['reason' => "Page {$fileIndex}: the page was placed on the form, but neither its QR code nor its page-number grid could be read, so it is not known which page of the form this is. Scan it again at 300 dpi in black and white or greyscale (not 'photo'), with the whole sheet flat on the glass.", 'info' => $info];

                        continue;
                    }
                }
                // A page that belongs to another inspection. A QR read is proof; a bare grid read through a layout the page may
                // not have been printed with is only a hint, so with several layouts to try it is not yet a verdict.
                if ($identity['rental_inspection_id'] !== $scan->rental_inspection_id) {
                    $otherInspection = ['identity' => $identity, 'via' => $via];
                    if ($via === 'qr' || count($layouts) === 1) {
                        break;
                    }

                    continue;
                }
                if (! in_array($identity['form_version'], $layout['versions'], true)) {
                    $staleIdentity ??= $identity;

                    continue;
                }
                $found = ['layout' => $layout, 'transform' => $transform, 'identity' => $identity, 'via' => $via, 'info' => $info];

                break;
            }

            // Wrong form uploaded here: ANY page that belongs to another inspection refuses the whole upload.
            if ($found === null && $otherInspection !== null) {
                $other = $otherInspection['identity'];
                $scan->forceFill([
                    'decoded_inspection_id' => $other['rental_inspection_id'],
                    'decoded_form_version' => $other['form_version'],
                    'status' => RentalInspectionScan::STATUS_FAILED,
                    'failure_reason' => "This scan's own page code says inspection #{$other['rental_inspection_id']}, not #{$scan->rental_inspection_id} - wrong form uploaded here.",
                ])->save();

                return;
            }

            // Never apply marks against a stale layout - an agency reprinting after adding an item must never have an
            // old scan silently write onto the new one (Johan's explicit instruction).
            if ($found === null && $staleIdentity !== null) {
                $scan->forceFill([
                    'decoded_inspection_id' => $staleIdentity['rental_inspection_id'],
                    'decoded_form_version' => $staleIdentity['form_version'],
                    'status' => RentalInspectionScan::STATUS_VERSION_MISMATCH,
                    'failure_reason' => "This scan is of form version {$staleIdentity['form_version']}; the inspection's current form is version {$currentForm->version}. The form has changed since this was printed - print the current form and mark that one.",
                ])->save();

                return;
            }

            if ($found === null) {
                $results[$fileIndex] = ['page' => $fileIndex, 'form_page' => null, 'status' => 'not_read', 'reason' => $firstFail['reason'] ?? 'The page could not be placed - no corner marks or QR code were found on it.'] + ($firstFail['info'] ?? []);

                continue;
            }

            $layout = $found['layout'];
            $identity = $found['identity'];
            $info = $found['info'];
            $via = $found['via'];
            $firstIdentity ??= $identity;
            $firstLayout ??= $layout;

            $layoutPages = (int) ($layout['manifest']['page_count'] ?? 1);
            $formPage = $identity['page_number'];
            if ($formPage < 1 || $formPage > $layoutPages) {
                $results[$fileIndex] = ['page' => $fileIndex, 'form_page' => null, 'status' => 'not_read', 'reason' => "Page {$fileIndex} of the upload says it is page {$formPage}, but this form has {$layoutPages} pages - it was not read."] + $info;

                continue;
            }
            if (isset($read[$formPage])) {
                $scan->forceFill([
                    'status' => RentalInspectionScan::STATUS_FAILED,
                    'failure_reason' => "Page {$formPage} of the form is in this upload twice - upload each page once.",
                    'page_results' => array_values($results),
                ])->save();

                return;
            }

            $canvas = $this->geometry->straightenPage($page, $found['transform'], $layout['geo']['width'], $layout['geo']['height']);
            $read[$formPage] = ['canvas' => $canvas, 'file_page' => $fileIndex, 'layout' => $layout];
            $results[$fileIndex] = ['page' => $fileIndex, 'form_page' => $formPage, 'status' => 'read', 'reason' => null, 'identified_by' => $via] + $info;
            if ($via === 'grid') {
                $notes[] = "Page {$fileIndex} was identified by its printed page grid - its QR code could not be read.";
            }
        }

        if ($read === []) {
            $reasons = array_values(array_filter(array_column($results, 'reason')));
            $scan->forceFill([
                'status' => RentalInspectionScan::STATUS_FAILED,
                'failure_reason' => $reasons !== [] ? implode(' ', $reasons) : 'None of the pages could be read.',
                'read_notes' => $notes,
                'page_results' => array_values($results),
            ])->save();

            return;
        }

        $formPages = (int) ($firstLayout['manifest']['page_count'] ?? $formPages);

        $scan->forceFill([
            'decoded_inspection_id' => $firstIdentity['rental_inspection_id'],
            'decoded_form_version' => $firstIdentity['form_version'],
            'rental_inspection_form_id' => $currentForm->id,
        ])->save();

        $missing = array_values(array_diff(range(1, max(1, $formPages)), array_keys($read)));
        if ($missing !== [] && $formPages > 1) {
            $notes[] = 'Pages not read from this scan: ' . implode(', ', $missing) . ' of ' . $formPages . '. Items on those pages have nothing proposed.';
        }

        $threshold = RentalInspectionSetting::omrMarkThresholdFor($scan->agency_id);

        $recorded = $this->recordedConditions($scan->rental_inspection_id);

        $noteCrops = [];   // mark id => ['png', 'label', 'agency_id'] - the note areas with handwriting in them (AT-472), read after the transaction

        DB::transaction(function () use ($scan, $read, $threshold, $recorded, &$notes, &$results, &$noteCrops) {
            $unchanged = 0;
            ksort($read);
            foreach ($read as $formPage => $info) {
                $counts = $this->readBoxesOnPage($scan, $info['canvas'], $info['layout']['boxesByPage'][$formPage] ?? [], $threshold, $formPage, $recorded, $info['layout']['geo'], $noteCrops);
                $unchanged += $counts['unchanged'];
                $results[$info['file_page']]['rows'] = $counts['rows'];
                $results[$info['file_page']]['proposed'] = $counts['proposed'];
                $results[$info['file_page']]['flagged'] = $counts['flagged'];
                $results[$info['file_page']]['rows_located'] = $counts['located'];
                if ($counts['untrusted']) {
                    $notes[] = 'Page ' . $info['file_page'] . ': only ' . $counts['located'] . ' of ' . $counts['rows'] . ' rows could be found on the page, so none of its ticks were proposed. Scan it again with the whole sheet flat on the glass (corners not cut off).';
                } elseif ($counts['located'] < $counts['rows']) {
                    $notes[] = 'Page ' . $info['file_page'] . ': ' . ($counts['rows'] - $counts['located']) . ' of ' . $counts['rows'] . ' rows were placed from the rows around them because their printed boxes could not be seen clearly.';
                }
            }
            if ($unchanged > 0) {
                $notes[] = $unchanged . ($unchanged === 1 ? ' row matches' : ' rows match') . ' what is already recorded on screen, so nothing is proposed for ' . ($unchanged === 1 ? 'it' : 'them') . '.';
            }

            $scan->forceFill(['status' => RentalInspectionScan::STATUS_NEEDS_REVIEW, 'read_notes' => $notes, 'page_results' => array_values($results)])->save();
        });

        $this->rasterCache = null; // a read that got this far needs its pages no more
        $this->readHandwrittenNotes($noteCrops);
    }

    /**
     * AT-472 - the AI read of the handwritten note on each row that has one, stored as the mark's `detected_note` (+ confidence) so the review
     * screen can pre-fill the note box. Never part of the scan's own success: an AI that is off, paused, failing or unreadable leaves the marks
     * exactly as the tick read made them. Nothing here touches the inspection.
     *
     * @param array<int, array{png: string, label: string, agency_id: int}> $crops mark id => the row's note area
     */
    public function readHandwrittenNotes(array $crops): void
    {
        // Audit M7 - belt and braces: an agency that has switched note reading off never has a crop sent, whoever built the list.
        $crops = array_filter($crops, fn ($c) => $this->noteReader->enabled((int) $c['agency_id']));
        if ($crops === []) {
            return;
        }

        try {
            foreach ($this->noteReader->transcribe($crops) as $markId => $answer) {
                if ($answer['text'] === '') {
                    continue; // nothing legible: no detected note
                }
                RentalInspectionScanMark::query()->whereKey($markId)->whereNull('applied_observation_id')->update([
                    'detected_note' => $answer['text'],
                    'detected_note_confidence' => $answer['confidence'],
                ]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Scan note read failed - the marks carry no detected note', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Read an already-stored scan again with the current reader. Proposals not yet applied are replaced (soft-deleted, never
     * a hard delete); anything an agent already applied is left exactly as it is. Nothing is applied by this.
     */
    public function reread(RentalInspectionScan $scan): void
    {
        $scan->marks()->whereNull('applied_observation_id')->delete();
        $scan->forceFill([
            'status' => RentalInspectionScan::STATUS_PROCESSING,
            'failure_reason' => null,
            'read_notes' => null,
            'page_results' => null,
        ])->save();

        $this->process($scan->fresh());

        // Audit L5 - a scan whose every mark was already applied stays applied: reading it again cannot make it "need review" when nothing
        // is left to review.
        $scan->refresh();
        if ($scan->status === RentalInspectionScan::STATUS_NEEDS_REVIEW
            && $scan->marks()->whereNotNull('applied_observation_id')->exists()
            && $scan->marks()->whereNull('applied_observation_id')->doesntExist()) {
            $scan->forceFill(['status' => RentalInspectionScan::STATUS_APPLIED])->save();
        }
    }

    /**
     * Which inspection does this upload's own QR code say it belongs to? (spec §64 - uploads route by the form's code, not by
     * the page they were uploaded on.) Needs no printed form on the inspection it was uploaded to: the QR sits at the same
     * place on every form of a layout, so the page is placed with the geometry of this inspection's forms and, failing that,
     * the agency's most recent distinct layouts. Only a QR that actually decodes counts - the printed page grid is never a
     * reason to move a scan.
     *
     * @return array{rental_inspection_id: int, page_number: int, page_count: int, form_version: int}|null the first page's code that reads, or null
     */
    public function qrOwner(RentalInspectionScan $scan): ?array
    {
        if (! extension_loaded('imagick')) {
            return null;
        }
        try {
            // Only the first four pages are ever looked at, so only those are rasterized (and the pages process() already
            // rasterized for this upload are reused, never done a second time).
            $pages = $this->rasterizeCached($scan, Storage::disk('local')->path($scan->storage_path), 4);
        } catch (\Throwable) {
            return null;
        }
        $geos = $this->templateGeometries($scan);
        foreach (array_slice($pages, 0, 4, true) as $page) {
            foreach ($geos as $geo) {
                $qr = null;
                $why = null;
                $info = [];
                $this->calibratePage($page, $geo, null, null, $qr, $why, $info);
                if ($qr !== null && $qr['rental_inspection_id'] > 0) {
                    return $qr;
                }
            }
        }

        return null;
    }

    /**
     * Geometries to look for a QR with when the uploaded-to inspection's own form cannot be relied on: this inspection's
     * printed layouts first, then the agency's newest distinct ones (at most four in all).
     *
     * @return list<array>
     */
    private function templateGeometries(RentalInspectionScan $scan): array
    {
        $forms = RentalInspectionForm::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('agency_id', $scan->agency_id)
            ->orderByRaw('rental_inspection_id = ? desc', [$scan->rental_inspection_id])
            ->orderByDesc('id')->limit(40)->get();
        $geos = [];
        $seen = [];
        foreach ($forms as $form) {
            $m = $form->manifest_json;
            if (! is_array($m) || empty($m['qr_codes'][0])) {
                continue;
            }
            $sig = md5(json_encode([$m['page_size']['key'] ?? null, $m['layout_version'] ?? 1, $m['safe_margin_mm'] ?? null, $m['fiducials'][0] ?? null, $m['qr_codes'][0]['x'] ?? null, $m['qr_codes'][0]['y'] ?? null]));
            if (isset($seen[$sig])) {
                continue;
            }
            $seen[$sig] = true;
            $geos[] = $this->geometryFor($m);
            if (count($geos) >= 4) {
                break;
            }
        }

        return $geos;
    }

    /** @return array<int, string> item id => the condition key currently recorded on the web inspection (append-only: the last one wins) */
    private function recordedConditions(int $inspectionId): array
    {
        $out = [];
        $rows = \App\Models\RentalInspectionObservation::withoutGlobalScopes()
            ->where('rental_inspection_id', $inspectionId)->recorded()->orderBy('id')
            ->get(['rental_inspection_item_id', 'condition']);
        foreach ($rows as $row) {
            $out[(int) $row->rental_inspection_item_id] = (string) $row->condition;
        }

        return $out;
    }

    /**
     * Everything the reader needs to know about the printed page, taken from the form's own manifest (never from
     * constants, so an agency on Letter paper reads exactly like one on A4).
     *
     * @return array{width: float, height: float, fiducial_size: float, fiducial_points: array<string, array{0: float, 1: float}>, qr: ?array{x: float, y: float, size: float, modules: ?int}}
     */
    private function geometryFor(array $manifest): array
    {
        $width = (float) ($manifest['page_size']['width_pt'] ?? RentalInspectionFormPdfService::PAGE_WIDTH);
        $height = (float) ($manifest['page_size']['height_pt'] ?? RentalInspectionFormPdfService::PAGE_HEIGHT);

        $points = [];
        $size = 10.0;
        foreach ($manifest['fiducials'] ?? [] as $f) {
            if ((int) $f['page'] === 1) {
                $size = (float) $f['size'];
                // Manifest coordinates are each mark's TOP-LEFT; the detected blob centroid is its CENTER.
                $points[$f['corner']] = [(float) $f['x'] + $size / 2, (float) $f['y'] + $size / 2];
            }
        }
        if (count($points) !== 4) {
            $points = [
                'top_left' => [12.0 + 5.0, 12.0 + 5.0],
                'top_right' => [$width - 12.0 - 10.0 + 5.0, 12.0 + 5.0],
                'bottom_left' => [12.0 + 5.0, $height - 12.0 - 10.0 + 5.0],
                'bottom_right' => [$width - 12.0 - 10.0 + 5.0, $height - 12.0 - 10.0 + 5.0],
            ];
        }

        $qr = null;
        if (! empty($manifest['qr_codes'][0])) {
            $q = $manifest['qr_codes'][0];
            // The symbol's module count: recorded by newer forms; otherwise worked out from the payload's length (the QR version
            // follows from it: byte capacity at error-correction level Q is 11 / 20 / 32 / 46 characters for versions 1-4).
            $modules = isset($q['modules']) ? (int) $q['modules'] : null;
            if ($modules === null && ! empty($q['payload'])) {
                $len = strlen((string) $q['payload']);
                $modules = 17 + 4 * ($len <= 11 ? 1 : ($len <= 20 ? 2 : ($len <= 32 ? 3 : 4)));
            }
            $qr = ['x' => (float) $q['x'], 'y' => (float) $q['y'], 'size' => (float) $q['size'], 'modules' => $modules];
        }

        return ['width' => $width, 'height' => $height, 'fiducial_size' => $size, 'fiducial_points' => $points, 'qr' => $qr, 'page_count' => (int) ($manifest['page_count'] ?? 1), 'grid' => $this->gridFrom($manifest, $width)];
    }

    /**
     * Where this form's page-identifier grid sits, taken from ITS manifest (the layout moves with the safe margin, §63); a
     * manifest without it falls back to the original fixed layout.
     */
    private function gridFrom(array $manifest, float $width): array
    {
        $fallback = RentalInspectionFormPdfService::pageIdentifierGridLayout($width);
        $pid = $manifest['page_identifiers'][0] ?? null;
        if (! $pid || empty($pid['grid']['x']) || empty($pid['bits'][0]['width'])) {
            return $fallback;
        }
        $bits = $pid['bits'];
        $size = (float) $bits[0]['width'];
        $gap = isset($bits[1]) && (float) $bits[1]['y'] === (float) $bits[0]['y'] ? (float) $bits[1]['x'] - (float) $bits[0]['x'] - $size : $fallback['bit_gap'];
        $lengths = [];
        foreach ($pid['fields'] ?? [] as $f) {
            $lengths[$f['name']] = (int) $f['bit_length'];
        }

        return [
            'x' => (float) $pid['grid']['x'], 'y' => (float) $pid['grid']['y'],
            'cols' => (int) $pid['grid']['cols'], 'rows' => (int) $pid['grid']['rows'],
            'bit_size' => $size, 'bit_gap' => $gap,
            'bits_inspection' => $lengths['rental_inspection_id'] ?? $fallback['bits_inspection'],
            'bits_page' => $lengths['page_number'] ?? $fallback['bits_page'],
            'bits_version' => $lengths['form_version'] ?? $fallback['bits_version'],
        ];
    }

    /**
     * The form's "shape": the paper, the condition columns and which items are on it - everything an answer depends on,
     * and nothing about where it is printed. Two forms of one inspection with the same shape differ only in layout.
     */
    private function shapeOf(array $manifest): string
    {
        $items = [];
        foreach ($manifest['boxes'] ?? [] as $b) {
            $items[(int) $b['rental_inspection_item_id']] = true;
        }
        ksort($items);

        return md5(json_encode([
            $manifest['page_size']['key'] ?? null,
            array_column($manifest['condition_states'] ?? [], 'key'),
            array_keys($items),
        ]));
    }

    /**
     * The printed layouts this scan may have been made from, newest first: one per distinct geometry among the inspection's
     * forms that have the current form's shape. Each carries the form versions that share it.
     *
     * @return list<array{manifest: array, geo: array, versions: list<int>, boxesByPage: array<int, list<array>>}>
     */
    private function layoutsFor(\Illuminate\Support\Collection $forms, RentalInspectionForm $current): array
    {
        $shape = $this->shapeOf($current->manifest_json);
        $layouts = [];
        $bySignature = [];
        foreach ($forms as $form) {
            $m = $form->manifest_json;
            if ($this->shapeOf($m) !== $shape) {
                continue;
            }
            $sig = md5(json_encode([$m['layout_version'] ?? 1, $m['safe_margin_mm'] ?? null, $m['page_count'] ?? 1, $m['fiducials'][0] ?? null, $m['qr_codes'][0]['y'] ?? null, $m['boxes'][0]['x'] ?? null, $m['boxes'][0]['y'] ?? null]));
            if (isset($bySignature[$sig])) {
                $layouts[$bySignature[$sig]]['versions'][] = (int) $form->version;

                continue;
            }
            $boxesByPage = [];
            foreach ($m['boxes'] ?? [] as $box) {
                $boxesByPage[(int) $box['page']][] = $box;
            }
            $bySignature[$sig] = count($layouts);
            $layouts[] = ['manifest' => $m, 'geo' => $this->geometryFor($m), 'versions' => [(int) $form->version], 'boxesByPage' => $boxesByPage];
        }

        return $layouts;
    }

    /**
     * One RentalInspectionScanMark per item whose boxes live on this page, read on the page's straightened canvas.
     *
     * Each row is first LOCATED from its own printed box outlines (RentalInspectionScanGeometry::alignRows), then the ink
     * inside each of its boxes is measured away from the outline. A box is ticked when its ink reaches the agency's
     * sensitivity (`omr_mark_threshold`, the share of the box's inside that must carry ink - default 4 %, because a hand
     * tick covers 5-20 % of a box). Exactly one ticked box per row is a proposal; none, or more than one, is flagged for the
     * agent (never guessed). Confidence is how far above the sensitivity the tick is (1.0 = a clear tick).
     *
     * @return array{unchanged: int, rows: int, proposed: int, flagged: int, located: int}
     */
    private function readBoxesOnPage(RentalInspectionScan $scan, Imagick $canvas, array $boxes, float $threshold, int $pageNumber, array $recorded = [], ?array $geo = null, array &$noteCrops = []): array
    {
        $byItem = [];
        foreach ($boxes as $box) {
            $byItem[(int) $box['rental_inspection_item_id']][] = $box;
        }
        foreach ($byItem as $itemId => $itemBoxes) {
            usort($itemBoxes, fn ($a, $b) => (float) $a['x'] <=> (float) $b['x']);
            $byItem[$itemId] = $itemBoxes;
        }

        $offsets = $this->geometry->alignRows($canvas, $byItem);
        $rowHeight = $this->rowHeightOf($byItem);
        $readNotes = $geo !== null && $this->noteReader->enabled($scan->agency_id);
        $unsureBelow = RentalInspectionSetting::omrUnsureBelowFor($scan->agency_id);
        $counts = ['unchanged' => 0, 'rows' => count($byItem), 'proposed' => 0, 'flagged' => 0, 'located' => 0, 'untrusted' => false];

        // A page on which fewer than half the rows could be found from their own printed boxes was not placed reliably (marks
        // clipped or misjudged): a tick read at a guessed position can land on the neighbouring row or column and look
        // perfectly plausible. Nothing on such a page is proposed - every row is flagged for the agent instead.
        $locatedRows = count(array_filter($offsets, fn ($o) => $o['measured']));
        $untrusted = count($byItem) >= 4 && $locatedRows * 2 < count($byItem);
        $counts['untrusted'] = $untrusted;

        foreach ($byItem as $itemId => $itemBoxes) {
            $off = $offsets[$itemId] ?? ['dx' => 0.0, 'dy' => 0.0, 'measured' => false];
            if ($off['measured']) {
                $counts['located']++;
            }

            $ink = [];
            foreach ($itemBoxes as $box) {
                $ink[$box['condition_key']] = $this->geometry->inkShare($canvas, (float) $box['x'] + $off['dx'], (float) $box['y'] + $off['dy']);
            }

            // Every box of a row sits on the same scan noise, so each is judged against the ROW's own baseline (its median
            // box - a row has one tick at most), not against zero: a noisy page does not turn every box into a "mark". A
            // ticked box must stand out from that baseline by the sensitivity AND be comparable with the row's strongest box
            // (two real ticks are about as dark as each other; a speck beside a heavy tick is not a second tick).
            $sorted = array_values($ink);
            sort($sorted);
            $baseline = $sorted ? $sorted[intdiv(count($sorted), 2)] : 0.0;
            $net = array_map(fn ($v) => max(0.0, $v - $baseline), $ink);
            $strongest = $net ? max($net) : 0.0;
            $marked = array_filter($net, fn ($v) => $v >= $threshold && $v >= 0.4 * $strongest);
            $ambiguous = $untrusted || count($marked) !== 1;
            $detectedKey = $ambiguous ? null : array_key_first($marked);
            $best = $strongest;
            $confidence = $threshold > 0 ? min(1.0, $best / (2.5 * $threshold)) : null;

            // An item already recorded on screen (it printed greyed): a row left alone, or ticked the same as the screen,
            // proposes nothing. Only a different tick - or a doubtful one - reaches the agent.
            $onScreen = $recorded[$itemId] ?? null;
            // Audit M1 - a handwritten NOTE beside such a row is still new information: a row with note ink is never skipped here. It
            // goes through as a proposal that keeps the on-screen condition and carries the note for the agent to confirm.
            $noteCrop = null;
            if ($readNotes && ! $untrusted) {
                $crop = $this->noteReader->cropNoteArea($canvas, $itemBoxes, $off, $geo, $rowHeight);
                $noteCrop = $crop !== null && $crop['ink'] >= RentalInspectionNoteReadService::INK_MIN ? $crop : null;
            }
            $leftAlone = $onScreen !== null && (count($marked) === 0 || (! $ambiguous && $detectedKey === $onScreen));
            if ($leftAlone && $noteCrop === null) {
                $counts['unchanged']++;

                continue;
            }
            if ($leftAlone) {
                $detectedKey = $onScreen;
                $ambiguous = false;
                $confidence = 1.0;
            }

            // One row per (scan, item) - the table's unique key counts archived rows too, so a re-read brings the archived
            // proposal back and rewrites it. A mark the agent already applied is never touched.
            $mark = RentalInspectionScanMark::withTrashed()->firstOrNew(['rental_inspection_scan_id' => $scan->id, 'rental_inspection_item_id' => $itemId]);
            if ($mark->applied_observation_id !== null) {
                continue;
            }
            if ($mark->exists && $mark->trashed()) {
                $mark->restore();
            }
            // AT-472 - the row's handwritten note ($noteCrop, cropped above) is sent only when there is ink in its note area.
            $mark->fill([
                'agency_id' => $scan->agency_id,
                'page_number' => $pageNumber,
                'detected_condition_key' => $detectedKey,
                'detected_confidence' => $confidence !== null ? round($confidence, 2) : null,
                'ambiguous' => $ambiguous,
                'tick_boxes' => $leftAlone ? 0 : count($marked),
            ] + ($readNotes ? ['note_ink' => $noteCrop !== null, 'detected_note' => null, 'detected_note_confidence' => null] : []))->save();
            $counts[$ambiguous || ($confidence !== null && $confidence < $unsureBelow) ? 'flagged' : 'proposed']++;

            if ($noteCrop !== null) {
                $noteCrops[$mark->id] = [
                    'png' => $noteCrop['png'],
                    'label' => trim(($itemBoxes[0]['room_label'] ?? '') . ' - ' . ($itemBoxes[0]['item_label'] ?? ''), ' -'),
                    'agency_id' => (int) $scan->agency_id,
                ];
            }
        }

        return $counts;
    }

    /** The height of one printed row, in points: the smallest real step between consecutive rows' tick boxes on the page. */
    private function rowHeightOf(array $byItem): float
    {
        $ys = array_values(array_unique(array_map(fn ($boxes) => round((float) $boxes[0]['y'], 2), $byItem)));
        sort($ys);
        $steps = [];
        for ($i = 1; $i < count($ys); $i++) {
            $step = round($ys[$i] - $ys[$i - 1], 2);
            if ($step > 5) {
                $steps[] = $step;
            }
        }

        return $steps !== [] ? min($steps) : 20.0;
    }

    // ── Applying a confirmed review ─────────────────────────────────────

    /**
     * Item 6 — creates an ORDINARY observation, identical to a screen tap.
     * No parallel storage path: RentalInspectionObservation::record() is
     * the exact same entry point onConditionTap() (rental-inspections.md
     * §14.1) uses. `applied_observation_id` on the mark is the audit trail
     * — the observation itself carries no new column.
     *
     * AT-472 (Johan, 20 Oct 2026): the CONFIRMED note travels with the condition (a note the agent never touched but the AI pre-filled is saved
     * here, because the agent saw it and pressed Apply), and the row is idempotent:
     *   - a retry carrying the same `$replayKey` returns the observation that key already wrote;
     *   - when the item's latest recorded observation already says the same condition and note (the note box saves as it is typed, through the
     *     capture screen's own /observations endpoint), nothing new is recorded - the mark is simply linked to that observation.
     * "Damaged needs a note" is enforced by the caller (RentalInspectionScanController::apply) with the same rule the capture endpoint uses.
     */
    public function applyMark(RentalInspectionScanMark $mark, string $conditionKey, User $confirmedBy, string $source, ?string $notes = null, ?string $replayKey = null): RentalInspectionObservation
    {
        $inspectionId = (int) $mark->scan->rental_inspection_id;
        $notes = ($notes !== null && trim($notes) !== '') ? trim($notes) : null;

        $observation = null;
        if ($replayKey) {
            $observation = RentalInspectionObservation::withoutGlobalScopes()
                ->where('client_idempotency_key', $replayKey)->where('rental_inspection_id', $inspectionId)->first();
        }
        if (! $observation) {
            $latest = RentalInspectionObservation::query()->recorded()
                ->where('rental_inspection_id', $inspectionId)
                ->where('rental_inspection_item_id', $mark->rental_inspection_item_id)
                ->orderByDesc('id')->first();
            if ($latest && $latest->condition === $conditionKey && (($latest->notes !== null && trim($latest->notes) !== '') ? trim($latest->notes) : null) === $notes) {
                $observation = $latest;
            }
        }
        $observation ??= RentalInspectionObservation::record(array_filter([
            'agency_id' => $mark->agency_id,
            'rental_inspection_id' => $inspectionId,
            'rental_inspection_item_id' => $mark->rental_inspection_item_id,
            'observed_by_user_id' => $confirmedBy->id,
            'condition' => $conditionKey,
            'notes' => $notes,
            'source' => $source,
            'client_idempotency_key' => $replayKey,
        ], fn ($v) => $v !== null));

        $mark->forceFill([
            'confirmed_condition_key' => $conditionKey,
            'confirmed_by_user_id' => $confirmedBy->id,
            'confirmed_at' => now(),
            'applied_observation_id' => $observation->id,
        ])->save();

        return $observation;
    }

    // ── Rasterization ───────────────────────────────────────────────────

    /** How many pages the file has, without rasterizing any (a PDF is only pinged; a picture is one page). */
    private function pageCountOf(string $absolutePath, string $mimeType): int
    {
        if ($mimeType !== 'application/pdf') {
            return 1;
        }
        $probe = new Imagick();
        $probe->pingImage($absolutePath);
        $n = $probe->getNumberImages();
        $probe->clear();

        return $n;
    }

    /**
     * The pages of this scan's file, rasterized at most once per upload: a later look at the same file (the QR owner check, a re-file onto
     * the inspection the form belongs to) takes them from here. Keyed by the scan row and its file's size, so a different file never matches.
     *
     * @return array<int, Imagick> pages keyed 1..N (only the first $limit when one is given)
     */
    private function rasterizeCached(RentalInspectionScan $scan, string $absolutePath, ?int $limit = null): array
    {
        $key = $scan->id . '|' . $scan->mime_type . '|' . (@filesize($absolutePath) ?: 0);
        $c = $this->rasterCache;
        if ($c !== null && $c['key'] === $key && ($c['complete'] || ($limit !== null && count($c['pages']) >= $limit))) {
            return $limit !== null ? array_slice($c['pages'], 0, $limit, true) : $c['pages'];
        }

        $pages = $this->rasterize($absolutePath, $scan->mime_type, $limit);
        $total = $limit === null ? true : ($this->pageCountOf($absolutePath, $scan->mime_type) <= $limit);
        $this->rasterCache = ['key' => $key, 'pages' => $pages, 'complete' => $total];

        return $pages;
    }

    /** @return array<int, Imagick> pages keyed 1..N (at most $limit when given) */
    private function rasterize(string $absolutePath, string $mimeType, ?int $limit = null): array
    {
        if ($mimeType === 'application/pdf') {
            $frames = $this->pageCountOf($absolutePath, $mimeType);
            if ($limit !== null) {
                $frames = min($frames, $limit);
            }
            $pages = [];
            // Each page is read on its OWN (`file.pdf[N]`): one page in memory at a time while reading, and every page is its own
            // Imagick (iterating one multi-frame object hands back the SAME object repositioned - a 3-page scan was once read as
            // page 3 three times).
            for ($i = 0; $i < $frames; $i++) {
                $this->assertWithinTimeBudget('page ' . ($i + 1));
                $frame = new Imagick();
                $frame->setResolution(self::RASTER_DPI, self::RASTER_DPI);
                $frame->readImage($absolutePath . '[' . $i . ']');
                $this->flattenToOpaqueWhite($frame);
                $frame->setImageColorspace(Imagick::COLORSPACE_GRAY);
                $frame->setImageFormat('png');
                $pages[$i + 1] = $frame;
            }

            return $pages;
        }

        $imagick = new Imagick($absolutePath);
        $this->flattenToOpaqueWhite($imagick);
        $imagick->setImageColorspace(Imagick::COLORSPACE_GRAY);
        $this->capResolution($imagick);

        return [1 => $imagick];
    }

    /**
     * A photographed upload carries whatever native resolution the phone's
     * camera produced — unlike the PDF path, which is rasterized at a
     * controlled RASTER_DPI, nothing here bounds that. Measured directly:
     * calibratePage() cost scales roughly linearly with pixel count
     * (~0.07s per megapixel), so an uncapped high-megapixel photo across a
     * multi-page upload can approach QA's 30-second max_execution_time once
     * decode/sampling/storage overhead is added. cc5's own manifest already
     * publishes RECOMMENDED_MIN_SCAN_DPI=150 (roughly 1240-1750px for an A4
     * page) as the floor for reliable reading — 3000px on the long edge
     * keeps generous headroom above that floor while firmly bounding the
     * worst case regardless of camera resolution.
     */
    private function capResolution(Imagick $image, int $maxLongEdge = 3000): void
    {
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        $longEdge = max($width, $height);
        if ($longEdge <= $maxLongEdge) {
            return;
        }

        $scale = $maxLongEdge / $longEdge;
        // A high-quality filter (LANCZOS) costs as much as the calibration
        // step it exists to bound — measured at ~9.5s to downsample a
        // 90-megapixel source, versus ~1s for scaleImage() with identical
        // calibration/box-reading accuracy on the same test. This only
        // feeds fill-ratio sampling, never a human's eyes, so resample
        // quality below LANCZOS is not a real tradeoff here.
        $image->scaleImage((int) round($width * $scale), (int) round($height * $scale));
    }

    /**
     * A rasterized PDF page's "blank" background is fully TRANSPARENT
     * (alpha=0) with undefined RGB underneath — not opaque white — found by
     * direct measurement: getImagePixelColor()/exportImagePixels() read the
     * raw RGB channels regardless of alpha, so a blank page reads as solid
     * black everywhere the alpha channel says "transparent". This is
     * invisible on a straight/180°-rotated page (nothing there depends on
     * alpha), but a skewed rotation's interpolation exposes it everywhere,
     * since the whole page interior is nominally "transparent" over
     * undefined color. Baking the transparency against a real white
     * background once, up front, makes every later raw-RGB sample correct.
     */
    private function flattenToOpaqueWhite(Imagick $image): void
    {
        $image->setImageBackgroundColor(new ImagickPixel('white'));
        $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        // A rotated or cropped picture can carry a "virtual canvas" offset (PNG oFFs, TIFF position). cropImage() works on
        // that canvas, not on the pixels, so every corner/QR/row crop below would otherwise look in the wrong place.
        $image->setImagePage(0, 0, 0, 0);
    }

    // ── Fiducial detection + calibration ────────────────────────────────

    /**
     * Place one scanned page on the printed form: find the transform from the form's own points (printed positions in
     * points) to this picture's pixels.
     *
     * The corner marks are found with a dither-tolerant matched filter (RentalInspectionScanGeometry::findMarkNearCorner) -
     * a solid black square comes out of an office scanner as a halftone patch. ANY two to four marks place the page: four
     * give an affine fit; two or three give a similarity (scale + rotation + shift) from the pair that fits the page. A form
     * printed on the wrong paper, or scaled by the printer, loses its bottom marks and QR - the page is still placed from the
     * top ones. If fewer than two marks survive, the QR code alone places it (its three finder squares give position, scale
     * and rotation); if that too fails the page is reported with the reason, never skipped silently.
     *
     * Which way up the page is comes from the QR (decoded on a straightened crop under each orientation the marks allow),
     * else the printed page grid, else the one circular mark.
     *
     * @param int|null $expectedInspectionId this scan's own known target
     * @param array|null $qr  out: the decoded QR identity when it was read
     * @param string|null $why out: plain words for why the page could not be placed (null when it was)
     * @param array $info    out: what was found (marks, printed scale, how it was anchored)
     * @return array{a: float, b: float, c: float, d: float, e: float, f: float}|null
     *   pixelX = a*mx + b*my + c; pixelY = d*mx + e*my + f.
     */
    private function calibratePage(Imagick $page, array $geo, ?int $expectedInspectionId = null, ?int $expectedPageNumber = null, ?array &$qr = null, ?string &$why = null, array &$info = []): ?array
    {
        $qr = null;
        $why = null;
        $info = [];
        $width = $page->getImageWidth();
        $height = $page->getImageHeight();
        $impliedScale = (($width / $geo['width']) + ($height / $geo['height'])) / 2;
        $expectedPx = $impliedScale * $geo['fiducial_size'];

        $marks = [];
        foreach (['tl' => 'top left', 'tr' => 'top right', 'bl' => 'bottom left', 'br' => 'bottom right'] as $key => $label) {
            $mark = $this->geometry->findMarkNearCorner($page, $key, $expectedPx);
            if ($mark !== null) {
                $marks[$key] = $mark;
            }
        }
        $info['marks_found'] = array_keys($marks);

        $candidates = [];
        if (count($marks) === 4) {
            $candidates = $this->candidatesFromFourMarks($marks, $geo);
        }
        if ($candidates === [] && count($marks) >= 2) {
            $candidates = $this->candidatesFromFewMarks($marks, $geo, $width, $height, $impliedScale);
        }
        if ($candidates !== []) {
            $picked = $this->pickCandidate($page, $geo, $candidates, $expectedInspectionId, $expectedPageNumber, $qr);
            if ($picked !== null) {
                $info['printed_scale'] = round($this->affineScale($picked) / $impliedScale, 3);
                $info['anchored_by'] = 'marks';

                return $picked;
            }
        }

        // Not enough marks to place the page: the QR code alone can.
        $viaQr = $this->calibrateFromQrOnly($page, $geo, $qr);
        if ($viaQr !== null) {
            $info['printed_scale'] = round($this->affineScale($viaQr) / $impliedScale, 3);
            $info['anchored_by'] = 'qr';

            return $viaQr;
        }

        $why = $this->explainUnplaceable(array_keys($marks), $geo, $marks, $impliedScale, $candidates !== []);

        return null;
    }

    /** @return list<array{transform: array, bottom_right_fill_ratio: ?float}> */
    private function candidatesFromFourMarks(array $marks, array $geo): array
    {
        $corners = [
            'px_top_left' => ['x' => $marks['tl']['x'], 'y' => $marks['tl']['y'], 'fill_ratio' => $marks['tl']['share']],
            'px_top_right' => ['x' => $marks['tr']['x'], 'y' => $marks['tr']['y'], 'fill_ratio' => $marks['tr']['share']],
            'px_bottom_left' => ['x' => $marks['bl']['x'], 'y' => $marks['bl']['y'], 'fill_ratio' => $marks['bl']['share']],
            'px_bottom_right' => ['x' => $marks['br']['x'], 'y' => $marks['br']['y'], 'fill_ratio' => $marks['br']['share']],
        ];

        // Correspondence is really two separate questions, and only one of them needs the circle: an A4 page is not square,
        // so the marks' own edge-length structure answers "portrait or sideways" at ANY rotation angle, from position alone.
        // That narrows four rotational possibilities to two, differing only in "right way up or upside down".
        $centroidX = array_sum(array_column($corners, 'x')) / 4;
        $centroidY = array_sum(array_column($corners, 'y')) / 4;
        $keys = array_keys($corners);
        usort($keys, fn ($a, $b) => atan2($corners[$a]['y'] - $centroidY, $corners[$a]['x'] - $centroidX)
            <=> atan2($corners[$b]['y'] - $centroidY, $corners[$b]['x'] - $centroidX));
        [$q0, $q1, $q2, $q3] = $keys;

        $manifestPoints = $geo['fiducial_points'];

        $valid = [];
        foreach ([[$q0, $q1, $q2, $q3], [$q0, $q3, $q2, $q1]] as $qOrder) {
            foreach ($this->rectangleLabelCandidates($corners, $qOrder) as $labels) {
                $pixelPoints = array_combine($labels, array_map(fn ($k) => $corners[$k], $qOrder));
                $transform = $this->solveAffine($manifestPoints, $pixelPoints);
                if (! $this->isPlausibleSimilarity($transform)) {
                    continue;
                }
                if ($this->affineResidual($transform, $manifestPoints, $pixelPoints) > 25.0) {
                    continue;
                }
                $bottomRightKey = $qOrder[array_search('bottom_right', $labels, true)];
                $valid[] = ['transform' => $transform, 'bottom_right_fill_ratio' => $corners[$bottomRightKey]['fill_ratio']];
            }
        }

        return $valid;
    }

    /**
     * Two or three marks found (the others clipped or never printed): every similarity (scale + rotation + shift) that puts a
     * pair of the marks on a pair of the form's corner marks, kept when the page then sits inside the picture and any
     * further mark lands on a form corner.
     *
     * @param array<string, array{x: float, y: float}> $marks
     * @return list<array{transform: array, bottom_right_fill_ratio: ?float}>
     */
    private function candidatesFromFewMarks(array $marks, array $geo, int $width, int $height, float $prior): array
    {
        $manifest = $geo['fiducial_points'];
        $out = [];
        $seen = [];
        foreach ($marks as $ki => $A) {
            foreach ($marks as $kj => $B) {
                if ($ki === $kj) {
                    continue;
                }
                foreach ($manifest as $u => [$ux, $uy]) {
                    foreach ($manifest as $v => [$vx, $vy]) {
                        if ($u === $v) {
                            continue;
                        }
                        $dm = hypot($vx - $ux, $vy - $uy);
                        $dp = hypot($B['x'] - $A['x'], $B['y'] - $A['y']);
                        if ($dm <= 0 || $dp <= 0) {
                            continue;
                        }
                        $scale = $dp / $dm;
                        // a page the printer shrank to fit (Letter paper: ~94 %) or a scan a little over/under size - not
                        // a placement that would need the page far bigger than the picture it came out of. The floor is low on
                        // purpose: the picture's size is only a rough guide to the scale when the page was photographed at
                        // an angle (the picture then holds the page's bounding box, 25 % bigger at 20 degrees, 45 % at 45).
                        if ($scale < 0.62 * $prior || $scale > 1.2 * $prior) {
                            continue;
                        }
                        $theta = atan2($B['y'] - $A['y'], $B['x'] - $A['x']) - atan2($vy - $uy, $vx - $ux);
                        $a = $scale * cos($theta);
                        $b = -$scale * sin($theta);
                        $d = $scale * sin($theta);
                        $t = ['a' => $a, 'b' => $b, 'c' => $A['x'] - ($a * $ux + $b * $uy), 'd' => $d, 'e' => $a, 'f' => $A['y'] - ($d * $ux + $a * $uy)];

                        if (! $this->pageSitsInPicture($t, $geo, $width, $height)) {
                            continue;
                        }
                        // a further mark that lands on one of the form's corner marks under this placement SUPPORTS it. (A false
                        // mark - a table corner, a stray speck - supports nothing, so it costs a placement no marks, only its rank.)
                        $support = 2;
                        $manifestPts = [$u => [$ux, $uy], $v => [$vx, $vy]];
                        $pixelPts = [$u => ['x' => $A['x'], 'y' => $A['y']], $v => ['x' => $B['x'], 'y' => $B['y']]];
                        foreach ($marks as $kk => $C) {
                            if ($kk === $ki || $kk === $kj) {
                                continue;
                            }
                            [$mx, $my] = $this->invertAffine($t, $C['x'], $C['y']);
                            $dist = [];
                            foreach ($manifest as $name => $p) {
                                $dist[$name] = hypot($p[0] - $mx, $p[1] - $my);
                            }
                            asort($dist);
                            $nearest = array_key_first($dist);
                            if ($dist[$nearest] <= 2.5 * $geo['fiducial_size'] && ! isset($manifestPts[$nearest])) {
                                $support++;
                                $manifestPts[$nearest] = $manifest[$nearest];
                                $pixelPts[$nearest] = ['x' => $C['x'], 'y' => $C['y']];
                            }
                        }
                        // Three marks or more pin down a full AFFINE placement - the scale of each axis separately. Real scanners
                        // stretch one axis by 1-2 % (10 pt across a page), which no two-mark similarity can represent.
                        $similarity = $t;
                        $affine = null;
                        if (count($manifestPts) >= 3) {
                            $fit = $this->solveAffine($manifestPts, $pixelPts);
                            if ($this->isPlausibleSimilarity($fit, 0.965, 0.03) && $this->affineResidual($fit, $manifestPts, $pixelPts) <= 12.0 && $this->pageSitsInPicture($fit, $geo, $width, $height)) {
                                $affine = $fit;
                            }
                        }
                        // the affine (three or more marks agree) is tried first; the plain two-mark placement stays beside it
                        // because a third "mark" can be a letter that merely sits near a corner
                        foreach ([$affine, $similarity] as $variant => $t) {
                            if ($t === null) {
                                continue;
                            }
                            $key = round($t['a'], 3) . '|' . round($t['b'], 3) . '|' . round($t['c']) . '|' . round($t['f']);
                            if (isset($seen[$key])) {
                                continue;
                            }
                            $seen[$key] = true;
                            $out[] = ['transform' => $t, 'bottom_right_fill_ratio' => null, 'support' => $support * 2 - $variant];
                        }
                    }
                }
            }
        }

        // Most-supported placements first, but ALL plausible ones go on to the orientation choice: a stray letter that
        // happens to sit near a corner can lift a wrong placement's support above the right one's. The QR (or the page grid)
        // decides - it only reads through the right placement.
        usort($out, fn ($a, $b) => $b['support'] <=> $a['support']);
        $out = array_slice($out, 0, 16);

        return $out;
    }

    /** The printed page's four corners, placed by `$t`, must lie (within 15 %) inside the picture. */
    private function pageSitsInPicture(array $t, array $geo, int $width, int $height): bool
    {
        foreach ([[0.0, 0.0], [$geo['width'], 0.0], [$geo['width'], $geo['height']], [0.0, $geo['height']]] as [$mx, $my]) {
            [$px, $py] = $this->applyAffine($t, $mx, $my);
            if ($px < -0.15 * $width || $px > 1.15 * $width || $py < -0.15 * $height || $py > 1.15 * $height) {
                return false;
            }
        }

        return true;
    }

    /** @return array{0: float, 1: float} the printed position (points) that lands on picture pixel (px, py) */
    private function invertAffine(array $t, float $px, float $py): array
    {
        $det = $t['a'] * $t['e'] - $t['b'] * $t['d'];

        return [
            ($t['e'] * ($px - $t['c']) - $t['b'] * ($py - $t['f'])) / $det,
            (-$t['d'] * ($px - $t['c']) + $t['a'] * ($py - $t['f'])) / $det,
        ];
    }

    /**
     * Which of the surviving orientations is right. The QR first (read through each candidate; exactly one decodes - and
     * that read also tells us the inspection and page), then the printed page grid decoded under each, then the one circular
     * mark's fill. A lone candidate is returned as is.
     *
     * @param list<array{transform: array, bottom_right_fill_ratio: ?float}> $validCandidates
     */
    private function pickCandidate(Imagick $page, array $geo, array $validCandidates, ?int $expectedInspectionId, ?int $expectedPageNumber, ?array &$qr): ?array
    {
        if ($geo['qr'] !== null) {
            // A QR only decodes through the right placement (wrong ones are the wrong part of the page, or upside down), so
            // the first that decodes is the answer - best-supported placements first.
            usort($validCandidates, fn ($a, $b) => ($b['support'] ?? 0) <=> ($a['support'] ?? 0));
            foreach ($validCandidates as $candidate) {
                $found = $this->readQr($page, $candidate['transform'], $geo);
                if ($found !== null) {
                    $qr = $found;

                    return $candidate['transform'];
                }
            }
        }

        if (count($validCandidates) === 1) {
            return $validCandidates[0]['transform'];
        }

        // More than one geometrically plausible orientation survives. The printed page grid, decoded under each, settles it
        // when it can.
        if ($expectedInspectionId !== null) {
            $matches = [];
            foreach ($validCandidates as $candidate) {
                $identifier = $this->decodePageIdentifier($page, $candidate['transform'], $geo, $expectedInspectionId);
                if ($identifier === null) {
                    continue;
                }
                $idMatches = $identifier['rental_inspection_id'] === $expectedInspectionId;
                // a real page of THIS form - page 0, or past the last page, is the grid read off the wrong place
                $pageMatches = $identifier['page_number'] >= 1 && $identifier['page_number'] <= $geo['page_count']
                    && ($expectedPageNumber === null || $identifier['page_number'] === $expectedPageNumber)
                    && $identifier['form_version'] >= 1;
                if ($idMatches && $pageMatches) {
                    $matches[] = $candidate;
                }
            }
            if (count($matches) === 1) {
                return $matches[0]['transform'];
            }
        }

        // Last resort: whichever candidate's bottom-right corner is the clearer circle - only when those were measured.
        $withRatio = array_values(array_filter($validCandidates, fn ($c) => $c['bottom_right_fill_ratio'] !== null));
        if (count($withRatio) < 2) {
            return null;
        }
        usort($withRatio, fn ($a, $b) => $a['bottom_right_fill_ratio'] <=> $b['bottom_right_fill_ratio']);
        if (($withRatio[1]['bottom_right_fill_ratio'] - $withRatio[0]['bottom_right_fill_ratio']) < 0.03) {
            return null;
        }

        return $withRatio[0]['transform'];
    }

    /**
     * Place a page from its QR code alone (fewer than two corner marks survived). The code is searched for in overlapping
     * windows of the picture; its three finder squares - read by the decoder in the symbol's own bottom-left, top-left,
     * top-right order - give the page's position, scale AND rotation in one go, so there is no orientation to guess.
     */
    private function calibrateFromQrOnly(Imagick $page, array $geo, ?array &$identity): ?array
    {
        if ($geo['qr'] === null) {
            return null;
        }
        $W = $page->getImageWidth();
        $H = $page->getImageHeight();
        $tw = (int) round($W * 0.5);
        $th = (int) round($H * 0.4);
        foreach ([[0.25, 0.60], [0.0, 0.60], [0.5, 0.60], [0.25, 0.0], [0.0, 0.0], [0.5, 0.0], [0.25, 0.30], [0.0, 0.30], [0.5, 0.30]] as [$fx, $fy]) {
            $x = (int) round($fx * $W);
            $y = (int) round($fy * $H);
            try {
                $win = clone $page;
                $win->cropImage($tw, $th, $x, $y);
                $win->setImagePage(0, 0, 0, 0);
                $win->setImageFormat('png');
                $reader = new \Zxing\QrReader($win->getImageBlob(), \Zxing\QrReader::SOURCE_TYPE_BLOB);
                $text = $reader->text();
                $result = $reader->getResult();
                $win->clear();
            } catch (\Throwable) {
                continue;
            }
            if (! is_string($text) || $text === '' || ! $result || ! method_exists($result, 'getResultPoints')) {
                continue;
            }
            $parsed = RentalInspectionFormPdfService::parseQrPayload($text);
            $pts = $result->getResultPoints();
            if ($parsed === null || count($pts) < 3) {
                continue;
            }

            $q = $geo['qr'];
            $modules = $q['modules'] ?? null;
            if (! $modules) {
                $len = strlen($text);
                $modules = 17 + 4 * ($len <= 11 ? 1 : ($len <= 20 ? 2 : 3)); // byte capacity at level Q: 11 / 20 / 32
            }
            $m = $q['size'] / $modules;
            $manifestPoints = [
                'bl' => [$q['x'] + 3.5 * $m, $q['y'] + $q['size'] - 3.5 * $m],
                'tl' => [$q['x'] + 3.5 * $m, $q['y'] + 3.5 * $m],
                'tr' => [$q['x'] + $q['size'] - 3.5 * $m, $q['y'] + 3.5 * $m],
            ];
            $pixelPoints = [];
            foreach (['bl', 'tl', 'tr'] as $i => $name) {
                $pixelPoints[$name] = ['x' => $x + $pts[$i]->getX(), 'y' => $y + $pts[$i]->getY()];
            }
            $t = $this->solveAffine($manifestPoints, $pixelPoints);
            if (! $this->isPlausibleSimilarity($t) || ! $this->pageSitsInPicture($t, $geo, $W, $H)) {
                continue;
            }
            $identity = $parsed;

            return $t;
        }

        return null;
    }

    /**
     * Plain words for why a page could not be placed - what was found, what that usually means, and what to do.
     *
     * @param list<string> $found image-corner keys of the marks that were found (tl/tr/bl/br)
     * @param array<string, array{x: float, y: float}> $marks
     */
    private function explainUnplaceable(array $found, array $geo, array $marks, float $impliedScale, bool $hadCandidates): string
    {
        $top = array_intersect(['tl', 'tr'], $found);
        $bottom = array_intersect(['bl', 'br'], $found);
        $paper = $geo['width'] > 600 ? 'Letter' : 'A4';
        $other = $paper === 'A4' ? 'Letter' : 'A4';

        if (count($found) === 0) {
            $what = 'none of the corner marks were found and the QR code could not be read';
        } elseif (count($top) < 2 && count($bottom) < 2) {
            $what = 'only ' . count($found) . ' of the 4 corner marks were found and the QR code could not be read';
        } elseif (count($top) === 2 && count($bottom) === 0) {
            $what = 'the top corner marks were found but the bottom ones and the QR code were not';
        } elseif (count($top) === 0 && count($bottom) === 2) {
            $what = 'the top corner marks were not found (the bottom ones were) and the QR code could not be read';
        } elseif ($hadCandidates) {
            $what = 'the corner marks found could not be told apart from page content, and the QR code could not be read';
        } else {
            $what = 'the corner marks that were found do not fit this form, and the QR code could not be read';
        }

        return ucfirst($what) . '. That usually means the form was printed on ' . $other . ' paper, or scaled or cropped by the printer or the scanner - '
            . 'print it on ' . $paper . ' at 100% (actual size, not "fit to page") and scan the whole sheet flat, '
            . 'or set the form paper size under Settings -> Rental Inspections to the paper you print on. '
            . 'A very light or blurred scan, or a page that was cut off, can do the same.';
    }

    /**
     * Given one specific traversal order of the four corners, the two
     * label-assignments consistent with it: an A4 page's fiducial
     * rectangle is never square, so whichever opposite-edge pair averages
     * SHORTER is always the manifest's width-direction pair (top/bottom) —
     * true at any rotation angle, purely from position. That fixes which
     * of the four rotational label-assignments are consistent with THIS
     * traversal down to exactly two, differing by a 180-degree relabel
     * (shift-by-two = swap each corner for its diagonal opposite).
     *
     * @param array<string, array{x: float, y: float}> $corners
     * @param array{0: string, 1: string, 2: string, 3: string} $qOrder
     * @return array<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    private function rectangleLabelCandidates(array $corners, array $qOrder): array
    {
        [$q0, $q1, $q2, $q3] = $qOrder;
        $edgeLen = fn ($i, $j) => sqrt(($corners[$i]['x'] - $corners[$j]['x']) ** 2 + ($corners[$i]['y'] - $corners[$j]['y']) ** 2);
        $e01 = $edgeLen($q0, $q1);
        $e12 = $edgeLen($q1, $q2);
        $e23 = $edgeLen($q2, $q3);
        $e30 = $edgeLen($q3, $q0);
        $e01IsWidth = ($e01 + $e23) < ($e12 + $e30);

        $base = $e01IsWidth
            ? ['top_left', 'top_right', 'bottom_right', 'bottom_left']
            : ['top_right', 'bottom_right', 'bottom_left', 'top_left'];

        return [$base, [$base[2], $base[3], $base[0], $base[1]]];
    }

    /**
     * A genuinely correct fit is always a similarity transform (uniform
     * scale + rotation only — a rigid printed page can never appear on a
     * scan as a true shear, non-uniform stretch, OR mirror image), so its
     * linear part's two columns must be near-equal in length, near-
     * perpendicular, AND right-handed (positive determinant — a reflection
     * also has equal-length orthogonal columns, so length/orthogonality
     * alone would accept a mirrored fit a camera can never actually
     * produce). Measured directly: a 90-degree-rotated page resolved a
     * wrong-but-plausible-looking correspondence and was accepted rather
     * than rejected before this existed — the failure mode that must never
     * reach a human as a trustworthy read.
     */
    private function isPlausibleSimilarity(array $transform, float $minRatio = 0.9, float $maxDot = 0.15): bool
    {
        $col1Len = sqrt($transform['a'] ** 2 + $transform['d'] ** 2);
        $col2Len = sqrt($transform['b'] ** 2 + $transform['e'] ** 2);
        if ($col1Len <= 0.0 || $col2Len <= 0.0) {
            return false;
        }
        $determinant = $transform['a'] * $transform['e'] - $transform['b'] * $transform['d'];
        if ($determinant <= 0.0) {
            return false;
        }
        $scaleRatio = min($col1Len, $col2Len) / max($col1Len, $col2Len);
        $normalizedDot = abs($transform['a'] * $transform['b'] + $transform['d'] * $transform['e']) / ($col1Len * $col2Len);

        return $scaleRatio >= $minRatio && $normalizedDot <= $maxDot;
    }

    /**
     * Fraction of dark pixels within a small rectangular region — the same
     * mechanism reads every condition tick-box AND every page-identifier
     * bit. Never OCR, never anything but "how dark is this exact printed
     * rectangle."
     */
    private function sampleFillRatio(Imagick $page, float $px, float $py, float $halfW, float $halfH): float
    {
        $x = (int) round($px - $halfW);
        $y = (int) round($py - $halfH);
        $w = max(1, (int) round($halfW * 2));
        $h = max(1, (int) round($halfH * 2));

        $x = max(0, min($x, $page->getImageWidth() - 1));
        $y = max(0, min($y, $page->getImageHeight() - 1));
        $w = min($w, $page->getImageWidth() - $x);
        $h = min($h, $page->getImageHeight() - $y);
        if ($w <= 0 || $h <= 0) {
            return 0.0;
        }

        $pixels = $page->exportImagePixels($x, $y, $w, $h, 'I', Imagick::PIXEL_CHAR);
        $dark = 0;
        $total = $w * $h;
        for ($i = 0; $i < $total; $i++) {
            // See findBlob()'s own comment — exportImagePixels() already
            // returns plain integers, never ord() them.
            if (($pixels[$i] & 0xFF) < 128) {
                $dark++;
            }
        }

        return $total > 0 ? $dark / $total : 0.0;
    }

    // ── Page identifier decoding ─────────────────────────────────────────

    /**
     * The printed page-identifier grid (8 x 5 small boxes, filled = 1), read through a candidate placement.
     *
     * A filled box comes out of an office scanner as a halftone patch only 0.2-0.4 dark against 0.0-0.2 for an empty one, so
     * a blind threshold misreads bits. The reader KNOWS which inspection it expects, so the grid is scored against that
     * inspection's own id pattern: when the id bits match (the filled ones clearly darker than the empty ones) the grid is
     * this inspection's, and the levels the id bits just showed - filled and empty - calibrate the page-number and version
     * bits. Wrong orientation or a page that is not this form's gives no such separation and returns null.
     *
     * With no expected inspection (or no match) a crisp grid is still decoded blind, so a form of ANOTHER inspection can be
     * recognised and refused; a grid too faint to trust returns null - "could not tell", never a made-up id.
     *
     * @return array{rental_inspection_id: int, page_number: int, page_count: int, form_version: int}|null
     */
    private function decodePageIdentifier(Imagick $page, array $transform, array $geo, ?int $expectedInspectionId = null): ?array
    {
        $grid = $geo['grid'] ?? RentalInspectionFormPdfService::pageIdentifierGridLayout($geo['width']);
        $S = 6.0;
        $pad = 10.0; // room to slide the grid about: it sits far from the corner marks that place the page
        $gw = $grid['cols'] * $grid['bit_size'] + ($grid['cols'] - 1) * $grid['bit_gap'];
        $gh = $grid['rows'] * $grid['bit_size'] + ($grid['rows'] - 1) * $grid['bit_gap'];
        $crop = $this->straightenRegion($page, $transform, $grid['x'] - $pad, $grid['y'] - $pad, $gw + 2 * $pad, $gh + 2 * $pad, $S);
        $cw = $crop->getImageWidth();
        $ch = $crop->getImageHeight();
        $px = $crop->exportImagePixels(0, 0, $cw, $ch, 'I', Imagick::PIXEL_CHAR);
        $crop->clear();

        $totalBits = $grid['bits_inspection'] + $grid['bits_page'] + $grid['bits_version'];
        $measure = function (float $offX, float $offY) use ($grid, $S, $pad, $px, $cw, $ch, $totalBits): array {
            $means = [];
            for ($index = 0; $index < $totalBits; $index++) {
                $col = $index % $grid['cols'];
                $row = intdiv($index, $grid['cols']);
                // the middle of each box only: its printed outline and the scan's edge noise stay out of the measurement
                $x0 = (int) round(($pad + $offX + $col * ($grid['bit_size'] + $grid['bit_gap']) + $grid['bit_size'] * 0.3) * $S);
                $y0 = (int) round(($pad + $offY + $row * ($grid['bit_size'] + $grid['bit_gap']) + $grid['bit_size'] * 0.3) * $S);
                $side = max(2, (int) round($grid['bit_size'] * 0.4 * $S));
                $sum = 0;
                $n = 0;
                for ($y = max(0, $y0); $y < min($ch, $y0 + $side); $y++) {
                    for ($x = max(0, $x0); $x < min($cw, $x0 + $side); $x++) {
                        $sum += 255 - ($px[$y * $cw + $x] & 0xFF);
                        $n++;
                    }
                }
                $means[$index] = $n > 0 ? $sum / ($n * 255) : 0.0;
            }

            return $means;
        };

        $nI = $grid['bits_inspection'];
        $nP = $grid['bits_page'];
        $means = $measure(0.0, 0.0);
        $decode = function (float $threshold) use ($means, $nI, $nP, $totalBits): array {
            $bits = [];
            for ($i = 0; $i < $totalBits; $i++) {
                $bits[$i] = $means[$i] >= $threshold ? 1 : 0;
            }

            return [
                'rental_inspection_id' => $this->bitsToInt(array_slice($bits, 0, $nI)),
                'page_number' => $this->bitsToInt(array_slice($bits, $nI, $nP)),
                'page_count' => 0, // the OMR grid does not carry it
                'form_version' => $this->bitsToInt(array_slice($bits, $nI + $nP, $totalBits - $nI - $nP)),
            ];
        };

        if ($expectedInspectionId !== null) {
            // How well does a set of box measurements show THIS inspection's id? (filled boxes clearly darker than empty ones)
            $fit = function (array $m) use ($expectedInspectionId, $nI): ?array {
                $on = [];
                $off = [];
                for ($i = 0; $i < $nI; $i++) {
                    if (($expectedInspectionId >> ($nI - 1 - $i)) & 1) {
                        $on[] = $m[$i];
                    } else {
                        $off[] = $m[$i];
                    }
                }
                if ($on === [] || $off === []) {
                    return null;
                }
                $onLevel = array_sum($on) / count($on);
                $offLevel = array_sum($off) / count($off);
                // EVERY filled box must be above the empty level, and plainly so on average
                if ($onLevel - $offLevel < 0.10 || min($on) <= $offLevel + 0.03) {
                    return null;
                }

                return ['sep' => $onLevel - $offLevel, 'threshold' => ($onLevel + $offLevel) / 2];
            };

            // Try the grid where the placement says it is, then slid about (the far corner of a stretched or sheared page is
            // several points off): take the position that shows the expected id best.
            $best = null;
            $bestMeans = null;
            $first = $fit($means);
            if ($first !== null) {
                $best = $first;
                $bestMeans = $means;
            } else {
                foreach ([-6, -4, -2, 0, 2, 4, 6] as $oy) {
                    foreach ([-6, -4, -2, 0, 2, 4, 6] as $ox) {
                        if ($ox === 0 && $oy === 0) {
                            continue;
                        }
                        $m = $measure((float) $ox, (float) $oy);
                        $f = $fit($m);
                        if ($f !== null && ($best === null || $f['sep'] > $best['sep'])) {
                            $best = $f;
                            $bestMeans = $m;
                        }
                    }
                }
            }
            if ($best !== null) {
                $means = $bestMeans;
                $identity = $decode($best['threshold']);
                $identity['rental_inspection_id'] = $expectedInspectionId;

                return $identity;
            }
        }

        // blind: only a crisp grid is trusted (a printed grid read straight off a good scan)
        $sorted = $means;
        sort($sorted);
        $low = $sorted[intdiv(count($sorted), 2)];
        $high = array_sum(array_slice($sorted, -3)) / 3;
        if ($high - $low >= 0.35) {
            return $decode($low + 0.5 * ($high - $low));
        }

        return null;
    }

    // ── QR code ───────────────────────────────────────────────────────────

    /** Pixels per printed point in the straightened QR crop. */
    private const QR_CROP_PX_PER_PT = 6.0;
    /** Quiet zone (points) kept around the symbol in the crop. */
    private const QR_CROP_MARGIN_PT = 8.0;

    /**
     * Quick structural test that a QR is where this placement says: at the three finder positions the middle of the finder
     * is dark and the white ring around it is light (searched +-1 module, whatever the scanner did to the modules). A wrong
     * placement - blank paper, text, the QR upside down - fails this in milliseconds instead of costing a full decode.
     */
    private function qrFindersPlausible(Imagick $crop, array $qr): bool
    {
        $S = self::QR_CROP_PX_PER_PT;
        $origin = self::QR_CROP_MARGIN_PT * $S;
        $size = $qr['size'] * $S;
        $n = ! empty($qr['modules']) ? (int) $qr['modules'] : 25;
        $mp = $size / $n;
        $w = $crop->getImageWidth();
        $h = $crop->getImageHeight();
        $px = $crop->exportImagePixels(0, 0, $w, $h, 'I', Imagick::PIXEL_CHAR);
        $mean = function (float $cx, float $cy, float $r0, float $r1) use ($px, $w, $h): float {
            $sum = 0.0;
            $k = 0;
            for ($y = (int) floor($cy - $r1); $y <= (int) ceil($cy + $r1); $y += 2) {
                for ($x = (int) floor($cx - $r1); $x <= (int) ceil($cx + $r1); $x += 2) {
                    $d = max(abs($x - $cx), abs($y - $cy));
                    if ($d < $r0 || $d > $r1 || $x < 0 || $y < 0 || $x >= $w || $y >= $h) {
                        continue;
                    }
                    $sum += (255 - ($px[$y * $w + $x] & 0xFF)) / 255;
                    $k++;
                }
            }

            return $k ? $sum / $k : 0.0;
        };
        $centres = [[$origin + 3.5 * $mp, $origin + 3.5 * $mp], [$origin + $size - 3.5 * $mp, $origin + 3.5 * $mp], [$origin + 3.5 * $mp, $origin + $size - 3.5 * $mp]];
        $best = -1.0;
        foreach ([-12, -8, -4, 0, 4, 8, 12] as $dy) {
            foreach ([-12, -8, -4, 0, 4, 8, 12] as $dx) {
                $score = 0.0;
                foreach ($centres as [$cx, $cy]) {
                    $score += $mean($cx + $dx, $cy + $dy, 0.0, 1.1 * $mp) - $mean($cx + $dx, $cy + $dy, 1.7 * $mp, 2.2 * $mp);
                }
                $best = max($best, $score / 3);
            }
        }

        return $best >= 0.12;
    }

    /**
     * Read the page's QR code through a candidate orientation: the region the manifest says the QR occupies is warped
     * out of the scan into an upright, fixed-scale crop and decoded there. Wrong orientation = the crop is the wrong
     * part of the page and nothing decodes - which is exactly what lets the caller tell the orientations apart. Pure PHP
     * (khanamiryan/qrcode-detector-decoder); no extension beyond Imagick.
     *
     * @return array{rental_inspection_id: int, page_number: int, page_count: int, form_version: int}|null
     */
    private function readQr(Imagick $page, array $transform, array $geo): ?array
    {
        $qr = $geo['qr'];
        if ($qr === null) {
            return null;
        }

        try {
            $crop = $this->straightenRegion(
                $page,
                $transform,
                $qr['x'] - self::QR_CROP_MARGIN_PT,
                $qr['y'] - self::QR_CROP_MARGIN_PT,
                $qr['size'] + 2 * self::QR_CROP_MARGIN_PT,
                $qr['size'] + 2 * self::QR_CROP_MARGIN_PT,
                self::QR_CROP_PX_PER_PT
            );

            // Cheap prefilter: a QR is a dense mix of black and white (roughly 20-75 % dark in its square). Blank paper, a
            // line of text or the wrong end of the page is not - skip the slow decoding for those placements.
            $probe = clone $crop;
            $probe->resizeImage(40, 40, Imagick::FILTER_BOX, 1);
            $p = $probe->exportImagePixels(0, 0, 40, 40, 'I', Imagick::PIXEL_CHAR);
            $probe->clear();
            $mean = 0.0;
            foreach ($p as $v) {
                $mean += (255 - ($v & 0xFF)) / 255;
            }
            $mean /= max(1, count($p));
            if ($mean < 0.08 || $mean > 0.8 || ! $this->qrFindersPlausible($crop, $qr)) {
                $crop->clear();

                return null;
            }

            foreach ([false, true] as $enhance) {
                $img = clone $crop;
                if ($enhance) {
                    $img->normalizeImage();
                    $img->sharpenImage(0, 1.2);
                    $img->thresholdImage(0.55 * Imagick::getQuantum());
                }
                $img->setImageFormat('png');
                $reader = new \Zxing\QrReader($img->getImageBlob(), \Zxing\QrReader::SOURCE_TYPE_BLOB);
                $text = $reader->text();
                $img->clear();
                if (is_string($text) && $text !== '') {
                    $parsed = RentalInspectionFormPdfService::parseQrPayload($text);
                    if ($parsed !== null) {
                        $crop->clear();

                        return $parsed;
                    }
                }
            }

            // The ordinary decode could not lock on (an edge-enhancing scanner hollows every black module): sample the module
            // grid directly. The code's place and size on the page are known, so this needs no finder detection.
            $counts = ! empty($qr['modules']) ? [(int) $qr['modules']] : [25, 29, 21];
            $text = (new RentalInspectionQrModuleReader())->decode(
                $crop,
                self::QR_CROP_MARGIN_PT * self::QR_CROP_PX_PER_PT,
                $qr['size'] * self::QR_CROP_PX_PER_PT,
                $counts
            );
            $crop->clear();
            if ($text !== null) {
                $parsed = RentalInspectionFormPdfService::parseQrPayload($text);
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        } catch (\Throwable $e) {
            // A crop that cannot be built or decoded is "no QR here", never an error to the agent.
        }

        return null;
    }

    private function straightenRegion(Imagick $page, array $t, float $x, float $y, float $w, float $h, float $scale): Imagick
    {
        return $this->geometry->straightenRegion($page, $t, $x, $y, $w, $h, $scale);
    }

    /** MSB-first — the exact inverse of RentalInspectionFormPdfService::toBits(). */
    private function bitsToInt(array $bits): int
    {
        $value = 0;
        foreach ($bits as $bit) {
            $value = ($value << 1) | $bit;
        }

        return $value;
    }

    // ── Affine geometry ──────────────────────────────────────────────────

    /** @param array<string, array{0: float, 1: float}> $manifestPoints @param array<string, array{x: float, y: float}> $pixelPoints */
    private function solveAffine(array $manifestPoints, array $pixelPoints): array
    {
        $keys = array_keys($manifestPoints);
        $rows = [];
        $targetX = [];
        $targetY = [];
        foreach ($keys as $key) {
            [$mx, $my] = $manifestPoints[$key];
            $rows[] = [$mx, $my, 1.0];
            $targetX[] = $pixelPoints[$key]['x'];
            $targetY[] = $pixelPoints[$key]['y'];
        }

        [$a, $b, $c] = $this->leastSquares3($rows, $targetX);
        [$d, $e, $f] = $this->leastSquares3($rows, $targetY);

        return ['a' => $a, 'b' => $b, 'c' => $c, 'd' => $d, 'e' => $e, 'f' => $f];
    }

    /** Solve for [a,b,c] minimizing sum((a*x_i + b*y_i + c - t_i)^2) via the 3x3 normal-equations system, Cramer's rule. */
    private function leastSquares3(array $rows, array $targets): array
    {
        $n = count($rows);
        $sXX = $sXY = $sX = $sYY = $sY = $sN = 0.0;
        $sXT = $sYT = $sT = 0.0;
        for ($i = 0; $i < $n; $i++) {
            [$x, $y] = $rows[$i];
            $t = $targets[$i];
            $sXX += $x * $x;
            $sXY += $x * $y;
            $sX += $x;
            $sYY += $y * $y;
            $sY += $y;
            $sN += 1.0;
            $sXT += $x * $t;
            $sYT += $y * $t;
            $sT += $t;
        }

        // [ sXX sXY sX ] [a]   [sXT]
        // [ sXY sYY sY ] [b] = [sYT]
        // [ sX  sY  sN ] [c]   [sT ]
        $m = [[$sXX, $sXY, $sX], [$sXY, $sYY, $sY], [$sX, $sY, $sN]];
        $rhs = [$sXT, $sYT, $sT];

        return $this->solve3x3($m, $rhs);
    }

    private function solve3x3(array $m, array $rhs): array
    {
        $det = $this->det3($m);
        if (abs($det) < 1e-9) {
            return [0.0, 0.0, 0.0];
        }

        $mx = $m;
        $mx[0][0] = $rhs[0];
        $mx[1][0] = $rhs[1];
        $mx[2][0] = $rhs[2];
        $a = $this->det3($mx) / $det;

        $my = $m;
        $my[0][1] = $rhs[0];
        $my[1][1] = $rhs[1];
        $my[2][1] = $rhs[2];
        $b = $this->det3($my) / $det;

        $mc = $m;
        $mc[0][2] = $rhs[0];
        $mc[1][2] = $rhs[1];
        $mc[2][2] = $rhs[2];
        $c = $this->det3($mc) / $det;

        return [$a, $b, $c];
    }

    private function det3(array $m): float
    {
        return $m[0][0] * ($m[1][1] * $m[2][2] - $m[1][2] * $m[2][1])
             - $m[0][1] * ($m[1][0] * $m[2][2] - $m[1][2] * $m[2][0])
             + $m[0][2] * ($m[1][0] * $m[2][1] - $m[1][1] * $m[2][0]);
    }

    private function applyAffine(array $t, float $mx, float $my): array
    {
        return [$t['a'] * $mx + $t['b'] * $my + $t['c'], $t['d'] * $mx + $t['e'] * $my + $t['f']];
    }

    /** Uniform scale factor implied by the transform's linear part — used to size a sampling window in pixels from a manifest size in points. */
    private function affineScale(array $t): float
    {
        return (sqrt($t['a'] ** 2 + $t['d'] ** 2) + sqrt($t['b'] ** 2 + $t['e'] ** 2)) / 2;
    }

    /** @param array<string, array{0: float, 1: float}> $manifestPoints @param array<string, array{x: float, y: float}> $pixelPoints */
    private function affineResidual(array $transform, array $manifestPoints, array $pixelPoints): float
    {
        $sum = 0.0;
        foreach ($manifestPoints as $key => [$mx, $my]) {
            [$px, $py] = $this->applyAffine($transform, $mx, $my);
            $sum += ($px - $pixelPoints[$key]['x']) ** 2 + ($py - $pixelPoints[$key]['y']) ** 2;
        }

        return $sum;
    }
}
