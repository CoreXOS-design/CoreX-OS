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

    private const BLOB_FILL_MIN = 0.30;

    public function __construct(private RentalInspectionFormPdfService $formPdfService)
    {
    }

    // ── Orchestration ───────────────────────────────────────────────────

    /**
     * The whole job for one uploaded scan: rasterize, decode, match against
     * the inspection's CURRENT form version, sample every box, persist one
     * RentalInspectionScanMark per item. Never applies anything to the
     * inspection itself — that only happens once a human confirms
     * (applyMark()/applyAll() below).
     */
    public function process(RentalInspectionScan $scan): void
    {
        $absolutePath = Storage::disk('local')->path($scan->storage_path);

        try {
            $pages = $this->rasterize($absolutePath, $scan->mime_type);
        } catch (\Throwable $e) {
            $scan->forceFill(['status' => RentalInspectionScan::STATUS_FAILED, 'failure_reason' => 'Could not open the scan file: ' . $e->getMessage()])->save();

            return;
        }

        if ($pages === []) {
            $scan->forceFill(['status' => RentalInspectionScan::STATUS_FAILED, 'failure_reason' => 'No pages found in the scan file.'])->save();

            return;
        }

        $scan->forceFill(['page_count' => count($pages)])->save();

        // Decode the first page's identifier to learn which inspection/
        // version this scan claims to be — the grid's position is a fixed
        // constant, read without needing any manifest yet.
        $firstPage = $pages[1];
        $transform = $this->calibratePage($firstPage, $scan->rental_inspection_id, 1);
        if ($transform === null) {
            $scan->forceFill(['status' => RentalInspectionScan::STATUS_FAILED, 'failure_reason' => 'Could not locate the four registration marks on page 1 — the scan may be too skewed, cropped, or low quality.'])->save();

            return;
        }

        $identifier = $this->decodePageIdentifier($firstPage, $transform);
        $scan->forceFill([
            'decoded_inspection_id' => $identifier['rental_inspection_id'],
            'decoded_form_version' => $identifier['form_version'],
        ])->save();

        if ($identifier['rental_inspection_id'] !== $scan->rental_inspection_id) {
            $scan->forceFill([
                'status' => RentalInspectionScan::STATUS_FAILED,
                'failure_reason' => "This scan's own page identifier says inspection #{$identifier['rental_inspection_id']}, not #{$scan->rental_inspection_id} — wrong form uploaded here.",
            ])->save();

            return;
        }

        $currentForm = RentalInspectionForm::currentFor($scan->rental_inspection_id)->first();
        if (! $currentForm) {
            $scan->forceFill(['status' => RentalInspectionScan::STATUS_FAILED, 'failure_reason' => 'No printable form has ever been generated for this inspection to match against.'])->save();

            return;
        }

        // Never apply marks against a stale layout — an agency reprinting
        // after adding an item must never have an old scan silently write
        // onto the new one (Johan's explicit instruction).
        if ($identifier['form_version'] !== $currentForm->version) {
            $scan->forceFill([
                'status' => RentalInspectionScan::STATUS_VERSION_MISMATCH,
                'failure_reason' => "This scan is of form version {$identifier['form_version']}; the inspection's current form is version {$currentForm->version}. The form has changed since this was printed — a human needs to reconcile this by hand.",
            ])->save();

            return;
        }

        $scan->forceFill(['rental_inspection_form_id' => $currentForm->id])->save();

        $manifest = $currentForm->manifest_json;
        $threshold = RentalInspectionSetting::omrMarkThresholdFor($scan->agency_id);

        $boxesByPage = [];
        foreach ($manifest['boxes'] as $box) {
            $boxesByPage[(int) $box['page']][] = $box;
        }

        DB::transaction(function () use ($scan, $pages, $boxesByPage, $threshold, $transform) {
            foreach ($pages as $pageNumber => $page) {
                $pageTransform = $pageNumber === 1 ? $transform : $this->calibratePage($page, $scan->rental_inspection_id, $pageNumber);
                if ($pageTransform === null) {
                    continue; // this page's boxes simply can't be read; left unmarked/ambiguous below for review.
                }
                $boxes = $boxesByPage[$pageNumber] ?? [];
                $this->readBoxesOnPage($scan, $page, $pageTransform, $boxes, $threshold, $pageNumber);
            }

            $scan->forceFill(['status' => RentalInspectionScan::STATUS_NEEDS_REVIEW])->save();
        });
    }

    /** One RentalInspectionScanMark per item whose boxes live on this page. */
    private function readBoxesOnPage(RentalInspectionScan $scan, Imagick $page, array $transform, array $boxes, float $threshold, int $pageNumber): void
    {
        $byItem = [];
        foreach ($boxes as $box) {
            $byItem[(int) $box['rental_inspection_item_id']][] = $box;
        }

        foreach ($byItem as $itemId => $itemBoxes) {
            $ratios = [];
            foreach ($itemBoxes as $box) {
                [$px, $py] = $this->applyAffine($transform, (float) $box['x'] + (float) $box['width'] / 2, (float) $box['y'] + (float) $box['height'] / 2);
                $scalePx = $this->affineScale($transform);
                $halfW = ((float) $box['width'] / 2) * $scalePx * 0.85; // sample slightly inside the printed box, never its border
                $halfH = ((float) $box['height'] / 2) * $scalePx * 0.85;
                $ratios[$box['condition_key']] = $this->sampleFillRatio($page, $px, $py, $halfW, $halfH);
            }

            $marked = array_filter($ratios, fn ($r) => $r >= $threshold);
            $ambiguous = count($marked) !== 1;
            $detectedKey = $ambiguous ? null : array_key_first($marked);
            $confidence = $ratios ? max($ratios) : null;

            RentalInspectionScanMark::updateOrCreate(
                ['rental_inspection_scan_id' => $scan->id, 'rental_inspection_item_id' => $itemId],
                [
                    'agency_id' => $scan->agency_id,
                    'page_number' => $pageNumber,
                    'detected_condition_key' => $detectedKey,
                    'detected_confidence' => $confidence !== null ? round($confidence, 2) : null,
                    'ambiguous' => $ambiguous,
                ]
            );
        }
    }

    // ── Applying a confirmed review ─────────────────────────────────────

    /**
     * Item 6 — creates an ORDINARY observation, identical to a screen tap.
     * No parallel storage path: RentalInspectionObservation::record() is
     * the exact same entry point onConditionTap() (rental-inspections.md
     * §14.1) uses. `applied_observation_id` on the mark is the audit trail
     * — the observation itself carries no new column.
     */
    public function applyMark(RentalInspectionScanMark $mark, string $conditionKey, User $confirmedBy, string $source): RentalInspectionObservation
    {
        $observation = RentalInspectionObservation::record([
            'agency_id' => $mark->agency_id,
            'rental_inspection_id' => $mark->scan->rental_inspection_id,
            'rental_inspection_item_id' => $mark->rental_inspection_item_id,
            'observed_by_user_id' => $confirmedBy->id,
            'condition' => $conditionKey,
            'notes' => null,
            'source' => $source,
        ]);

        $mark->forceFill([
            'confirmed_condition_key' => $conditionKey,
            'confirmed_by_user_id' => $confirmedBy->id,
            'confirmed_at' => now(),
            'applied_observation_id' => $observation->id,
        ])->save();

        return $observation;
    }

    // ── Rasterization ───────────────────────────────────────────────────

    /** @return array<int, Imagick> pages keyed 1..N */
    private function rasterize(string $absolutePath, string $mimeType): array
    {
        if ($mimeType === 'application/pdf') {
            $imagick = new Imagick();
            $imagick->setResolution(self::RASTER_DPI, self::RASTER_DPI);
            $imagick->readImage($absolutePath);
            $pages = [];
            foreach ($imagick as $index => $frame) {
                $this->flattenToOpaqueWhite($frame);
                $frame->setImageColorspace(Imagick::COLORSPACE_GRAY);
                $frame->setImageFormat('png');
                $pages[$index + 1] = $frame;
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
    }

    // ── Fiducial detection + calibration ────────────────────────────────

    /**
     * @param int|null $expectedInspectionId this scan's own known target
     *   (always available — set at upload time) — used to disambiguate a
     *   180-degree orientation ambiguity via the printed page identifier
     *   rather than blob shape (Johan, 2026-09-22).
     * @param int|null $expectedPageNumber known once page 1 has already
     *   been decoded; strengthens the same disambiguation for pages 2+.
     * @return array{a: float, b: float, c: float, d: float, e: float, f: float}|null
     *   pixelX = a*mx + b*my + c; pixelY = d*mx + e*my + f. Null if the four
     *   fiducials couldn't be confidently located on this page.
     */
    private function calibratePage(Imagick $page, ?int $expectedInspectionId = null, ?int $expectedPageNumber = null): ?array
    {
        $width = $page->getImageWidth();
        $height = $page->getImageHeight();
        // A fixed small window pinned to the exact image edge only works
        // when the page fills the frame with near-zero margin. A real
        // photographed/scanned form virtually never does, and a skewed
        // page's canvas is expanded by rotateImage() to exactly bound the
        // rotated rectangle — so a fiducial's own corner sits somewhere
        // along an EDGE of that bounding canvas, not at a CORNER of it.
        // Instead, search a generous quadrant near each corner for every
        // dark connected blob, and pick whichever qualifying blob sits
        // CLOSEST to the true page corner — this tolerates arbitrary
        // margin/skew without needing to know it in advance, while the
        // size filter (via the image's own implied scale) and the
        // corner-proximity ranking together keep it from picking up the
        // header block's printed text (found by direct measurement against
        // a real generated form: header text starts at MARGIN=36pt, well
        // outside the fiducial's own 12-22pt inset, so it always loses the
        // proximity ranking against the true fiducial blob).
        $impliedScale = (
            $width / RentalInspectionFormPdfService::PAGE_WIDTH
            + $height / RentalInspectionFormPdfService::PAGE_HEIGHT
        ) / 2;
        $expectedFiducialSize = $impliedScale * 10.0;
        // 40% of each dimension — generous enough to tolerate a realistically
        // framed phone photo (the page occupying as little as ~60% of the
        // frame on a given axis) while staying bounded.
        $quadW = max((int) round($width * 0.4), (int) round($expectedFiducialSize * 4));
        $quadH = max((int) round($height * 0.4), (int) round($expectedFiducialSize * 4));

        // A page corner is a page corner regardless of rotation — search
        // near each of the four PIXEL corners for a blob, without yet
        // assuming which manifest fiducial (top_left/top_right/bottom_left/
        // bottom_right) it corresponds to. Correspondence is resolved AFTER,
        // by which one is the circle (the fill-ratio outlier, below) plus
        // geometry relative to it.
        $corners = [
            'px_top_left' => $this->findFiducialNearCorner($page, 0, 0, $quadW, $quadH, 0, 0, $expectedFiducialSize),
            'px_top_right' => $this->findFiducialNearCorner($page, $width - $quadW, 0, $quadW, $quadH, (float) $width, 0, $expectedFiducialSize),
            'px_bottom_left' => $this->findFiducialNearCorner($page, 0, $height - $quadH, $quadW, $quadH, 0, (float) $height, $expectedFiducialSize),
            'px_bottom_right' => $this->findFiducialNearCorner($page, $width - $quadW, $height - $quadH, $quadW, $quadH, (float) $width, (float) $height, $expectedFiducialSize),
        ];

        foreach ($corners as $blob) {
            if ($blob === null) {
                return null;
            }
        }

        // Correspondence is really two separate questions, and only one of
        // them needs the circle (Johan, 2026-09-22): an A4 page is not
        // square, so the fiducial rectangle's own edge-length structure
        // answers "portrait or sideways" at ANY rotation angle, purely from
        // position — no shape/fill-ratio involved, so no rotation-angle
        // blind spot. That narrows the four rotational possibilities down
        // to exactly two, differing only in "right way up or upside down"
        // — the one binary question the circle actually exists to answer.
        //
        // Sort the four corners by angle around their own centroid: for
        // any convex quadrilateral this recovers the TRUE physical
        // perimeter order (never a shuffled one), in some consistent
        // traversal direction. Both possible directions (clockwise and
        // counter-clockwise) are tried below rather than hand-derived from
        // atan2's sign convention, since a wrong-chirality guess would
        // silently mirror the page — the determinant check in
        // isPlausibleSimilarity() rejects whichever direction is wrong,
        // regardless of which one that turns out to be.
        $centroidX = array_sum(array_column($corners, 'x')) / 4;
        $centroidY = array_sum(array_column($corners, 'y')) / 4;
        $keys = array_keys($corners);
        usort($keys, fn ($a, $b) => atan2($corners[$a]['y'] - $centroidY, $corners[$a]['x'] - $centroidX)
            <=> atan2($corners[$b]['y'] - $centroidY, $corners[$b]['x'] - $centroidX));
        [$q0, $q1, $q2, $q3] = $keys;

        $manifestPoints = [
            'top_left' => [12.0, 12.0],
            'top_right' => [RentalInspectionFormPdfService::PAGE_WIDTH - 12.0 - 10.0, 12.0],
            'bottom_left' => [12.0, RentalInspectionFormPdfService::PAGE_HEIGHT - 12.0 - 10.0],
            'bottom_right' => [RentalInspectionFormPdfService::PAGE_WIDTH - 12.0 - 10.0, RentalInspectionFormPdfService::PAGE_HEIGHT - 12.0 - 10.0],
        ];
        // Manifest fiducial coordinates are the TOP-LEFT of each 10x10pt
        // mark; the blob centroid detected above is the mark's CENTER — use
        // each fiducial's own center consistently on both sides.
        foreach ($manifestPoints as $key => [$mx, $my]) {
            $manifestPoints[$key] = [$mx + 5.0, $my + 5.0];
        }

        $validCandidates = [];
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
                $validCandidates[] = ['transform' => $transform, 'bottom_right_fill_ratio' => $corners[$bottomRightKey]['fill_ratio']];
            }
        }

        if ($validCandidates === []) {
            return null;
        }
        if (count($validCandidates) === 1) {
            return $validCandidates[0]['transform'];
        }

        // More than one geometrically-plausible orientation survives — the
        // residual right-way-up-or-upside-down ambiguity. Disambiguate with
        // the strongest available signal first: the printed page
        // identifier, decoded under each candidate. A wrong orientation
        // samples essentially random bit positions and will not decode to
        // THIS scan's own known target; the correct one decodes exactly.
        // This doesn't depend on blob shape at all, so — unlike fill ratio
        // — it has no rotation-angle blind spot.
        if ($expectedInspectionId !== null) {
            $matches = [];
            foreach ($validCandidates as $candidate) {
                $identifier = $this->decodePageIdentifier($page, $candidate['transform']);
                $idMatches = $identifier['rental_inspection_id'] === $expectedInspectionId;
                $pageMatches = $expectedPageNumber === null || $identifier['page_number'] === $expectedPageNumber;
                if ($idMatches && $pageMatches) {
                    $matches[] = $candidate;
                }
            }
            if (count($matches) === 1) {
                return $matches[0]['transform'];
            }
        }

        // No expected identifier to check against, or it didn't uniquely
        // resolve it — fall back to whichever candidate's bottom-right
        // corner is the clearer circle. Only the circle's fill ratio is
        // rotation-invariant, so the candidate where it reads furthest
        // below the other candidate's is the more likely circle — but
        // still refuse to guess if the two are too close to call.
        usort($validCandidates, fn ($a, $b) => $a['bottom_right_fill_ratio'] <=> $b['bottom_right_fill_ratio']);
        if (($validCandidates[1]['bottom_right_fill_ratio'] - $validCandidates[0]['bottom_right_fill_ratio']) < 0.03) {
            return null;
        }

        return $validCandidates[0]['transform'];
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
    private function isPlausibleSimilarity(array $transform): bool
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

        return $scaleRatio >= 0.9 && $normalizedDot <= 0.15;
    }

    /**
     * Every separate dark connected blob within a rectangular region, via
     * 4-connected flood fill on a thresholded pixel grid — NOT a single
     * whole-window centroid, because a generous corner quadrant can contain
     * both the true fiducial AND unrelated printed content (e.g. the header
     * block), and those must be told apart as distinct candidates rather
     * than blended into one wrong centroid.
     *
     * @return list<array{x: float, y: float, bbox_w: int, bbox_h: int, shape: string, fill_ratio: float}>
     */
    private function extractBlobs(Imagick $page, int $x, int $y, int $w, int $h): array
    {
        $x = max(0, $x);
        $y = max(0, $y);
        $w = min($w, $page->getImageWidth() - $x);
        $h = min($h, $page->getImageHeight() - $y);
        if ($w <= 0 || $h <= 0) {
            return [];
        }

        $pixels = $page->exportImagePixels($x, $y, $w, $h, 'I', Imagick::PIXEL_CHAR);
        $total = $w * $h;
        $dark = [];
        for ($i = 0; $i < $total; $i++) {
            // exportImagePixels() with PIXEL_CHAR returns a plain PHP array
            // of already-decoded 0-255 integers, NOT a packed byte string —
            // ord() on an already-int value silently corrupts it (PHP casts
            // the int to a string first, e.g. 255 -> "255" -> ord('2') =
            // 50), which is why every sample once read as uniformly "dark"
            // until this was found and fixed by direct measurement against
            // a real generated form, not guessed.
            $dark[$i] = ($pixels[$i] & 0xFF) < 128;
        }

        $visited = array_fill(0, $total, false);
        $blobs = [];
        for ($start = 0; $start < $total; $start++) {
            if (! $dark[$start] || $visited[$start]) {
                continue;
            }

            $stack = [$start];
            $visited[$start] = true;
            $sumCol = 0;
            $sumRow = 0;
            $count = 0;
            $minCol = $w;
            $maxCol = 0;
            $minRow = $h;
            $maxRow = 0;
            while ($stack !== []) {
                $idx = array_pop($stack);
                $row = intdiv($idx, $w);
                $col = $idx % $w;
                $sumCol += $col;
                $sumRow += $row;
                $count++;
                $minCol = min($minCol, $col);
                $maxCol = max($maxCol, $col);
                $minRow = min($minRow, $row);
                $maxRow = max($maxRow, $row);

                if ($col > 0 && $dark[$idx - 1] && ! $visited[$idx - 1]) {
                    $visited[$idx - 1] = true;
                    $stack[] = $idx - 1;
                }
                if ($col < $w - 1 && $dark[$idx + 1] && ! $visited[$idx + 1]) {
                    $visited[$idx + 1] = true;
                    $stack[] = $idx + 1;
                }
                if ($row > 0 && $dark[$idx - $w] && ! $visited[$idx - $w]) {
                    $visited[$idx - $w] = true;
                    $stack[] = $idx - $w;
                }
                if ($row < $h - 1 && $dark[$idx + $w] && ! $visited[$idx + $w]) {
                    $visited[$idx + $w] = true;
                    $stack[] = $idx + $w;
                }
            }

            $bboxW = $maxCol - $minCol + 1;
            $bboxH = $maxRow - $minRow + 1;
            $bboxArea = $bboxW * $bboxH;
            if ($bboxArea <= 0) {
                continue;
            }
            $fillRatio = $count / $bboxArea;
            if ($fillRatio < self::BLOB_FILL_MIN) {
                continue; // too sparse to be a real fiducial mark, likely noise or thin text strokes
            }

            $blobs[] = [
                'x' => $x + $sumCol / $count,
                'y' => $y + $sumRow / $count,
                'bbox_w' => $bboxW,
                'bbox_h' => $bboxH,
                'fill_ratio' => $fillRatio,
            ];
        }

        return $blobs;
    }

    /**
     * Among every dark blob in a corner quadrant, keep only the ones whose
     * bounding box is close to the fiducial's own expected size, then
     * return whichever survivor sits closest to the true page corner — the
     * fiducial itself, by construction, sits far closer to that corner than
     * any printed body content (header text starts at the page's 36pt
     * content margin; the fiducial sits at a 12-22pt inset).
     */
    private function findFiducialNearCorner(Imagick $page, int $qx, int $qy, int $qw, int $qh, float $cornerX, float $cornerY, float $expectedSize): ?array
    {
        $minSize = $expectedSize * 0.4;
        $maxSize = $expectedSize * 3.0;
        $best = null;
        $bestDistSq = null;
        foreach ($this->extractBlobs($page, $qx, $qy, $qw, $qh) as $blob) {
            if ($blob['bbox_w'] < $minSize || $blob['bbox_w'] > $maxSize) {
                continue;
            }
            if ($blob['bbox_h'] < $minSize || $blob['bbox_h'] > $maxSize) {
                continue;
            }
            $distSq = ($blob['x'] - $cornerX) ** 2 + ($blob['y'] - $cornerY) ** 2;
            if ($bestDistSq === null || $distSq < $bestDistSq) {
                $bestDistSq = $distSq;
                $best = $blob;
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'x' => $best['x'],
            'y' => $best['y'],
            'fill_ratio' => $best['fill_ratio'],
        ];
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

    /** @return array{rental_inspection_id: int, page_number: int, form_version: int} */
    private function decodePageIdentifier(Imagick $page, array $transform): array
    {
        $grid = RentalInspectionFormPdfService::pageIdentifierGridLayout();
        $threshold = 0.5; // the identifier grid is print-perfect, generated by us — a plain midpoint threshold is deliberately not the agency-configurable OMR setting, which is about wet-ink variability the identifier bits never have.

        $bits = [];
        $totalBits = $grid['bits_inspection'] + $grid['bits_page'] + $grid['bits_version'];
        for ($index = 0; $index < $totalBits; $index++) {
            $col = $index % $grid['cols'];
            $row = intdiv($index, $grid['cols']);
            $mx = $grid['x'] + $col * ($grid['bit_size'] + $grid['bit_gap']) + $grid['bit_size'] / 2;
            $my = $grid['y'] + $row * ($grid['bit_size'] + $grid['bit_gap']) + $grid['bit_size'] / 2;
            [$px, $py] = $this->applyAffine($transform, $mx, $my);
            $scalePx = $this->affineScale($transform);
            $half = ($grid['bit_size'] / 2) * $scalePx * 0.85;
            $ratio = $this->sampleFillRatio($page, $px, $py, $half, $half);
            $bits[] = $ratio >= $threshold ? 1 : 0;
        }

        $inspectionBits = array_slice($bits, 0, $grid['bits_inspection']);
        $pageBits = array_slice($bits, $grid['bits_inspection'], $grid['bits_page']);
        $versionBits = array_slice($bits, $grid['bits_inspection'] + $grid['bits_page'], $grid['bits_version']);

        return [
            'rental_inspection_id' => $this->bitsToInt($inspectionBits),
            'page_number' => $this->bitsToInt($pageBits),
            'form_version' => $this->bitsToInt($versionBits),
        ];
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
