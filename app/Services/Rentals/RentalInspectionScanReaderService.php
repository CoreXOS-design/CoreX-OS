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
 * Pipeline: rasterize each page -> detect the four fiducials (identifying
 * the bottom-right circle as the fill-ratio outlier among the four corner
 * blobs, per cc5's own design note — NOT by an absolute shape threshold,
 * since a rotated square's own fill ratio is not rotation-invariant, only
 * the three squares clustering together relative to the odd one out is)
 * -> solve one affine transform per page mapping manifest pt-space
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
        $transform = $this->calibratePage($firstPage);
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
                $pageTransform = $pageNumber === 1 ? $transform : $this->calibratePage($page);
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

        return [1 => $imagick];
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
     * @return array{a: float, b: float, c: float, d: float, e: float, f: float}|null
     *   pixelX = a*mx + b*my + c; pixelY = d*mx + e*my + f. Null if the four
     *   fiducials couldn't be confidently located on this page.
     */
    private function calibratePage(Imagick $page): ?array
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

        // A square's OWN axis-aligned fill ratio shrinks under rotation
        // (1/(cos(theta)+sin(theta))^2 — already down to ~0.83 at just 6
        // degrees, and it keeps falling well past the circle's constant
        // ~0.785 by ~8 degrees) — so no fixed fill-ratio threshold can
        // reliably tell "circle" from "rotated square" in absolute terms.
        // What DOES hold regardless of rotation: all three squares are
        // part of the same rigid page and rotate together, so their fill
        // ratios cluster near each other, while the circle's does not —
        // classify by RELATIVE outlier, not by an absolute cutoff.
        $byFillRatio = $corners;
        uasort($byFillRatio, fn ($a, $b) => $a['fill_ratio'] <=> $b['fill_ratio']);
        $bottomRightPxKey = array_key_first($byFillRatio);
        $circleFillRatio = $byFillRatio[$bottomRightPxKey]['fill_ratio'];
        $squareKeys = array_keys(array_diff_key($corners, [$bottomRightPxKey => true]));
        $squareFillRatios = array_map(fn ($k) => $corners[$k]['fill_ratio'], $squareKeys);
        // The three squares should sit close together; the circle should be
        // a clear outlier below all of them. If it isn't, detection isn't
        // trustworthy enough to guess from.
        if ($circleFillRatio >= min($squareFillRatios) - 0.03) {
            return null;
        }

        $manifestPoints = [
            'top_left' => [12.0, 12.0],
            'top_right' => [RentalInspectionFormPdfService::PAGE_WIDTH - 12.0 - 10.0, 12.0],
            'bottom_left' => [12.0, RentalInspectionFormPdfService::PAGE_HEIGHT - 12.0 - 10.0],
            'bottom_right' => [RentalInspectionFormPdfService::PAGE_WIDTH - 12.0 - 10.0, RentalInspectionFormPdfService::PAGE_HEIGHT - 12.0 - 10.0],
        ];
        // Manifest fiducial coordinates are the TOP-LEFT of each 10x10pt
        // mark; the blob centroid detected below is the mark's CENTER — use
        // each fiducial's own center consistently on both sides.
        foreach ($manifestPoints as $key => [$mx, $my]) {
            $manifestPoints[$key] = [$mx + 5.0, $my + 5.0];
        }

        // Resolve which square is which DIRECTLY from its position relative
        // to the circle, rather than brute-forcing permutations and picking
        // whichever affine fit scores lowest residual — with only 4 points,
        // a wrong (e.g. cyclically-shifted) correspondence can ALSO produce
        // a deceptively low residual (it degenerates into a valid-looking
        // but physically wrong 90°-rotation-shaped transform), so residual
        // alone is not a reliable tie-breaker. Instead: the square
        // diagonally opposite the circle (bottom_right) is top_left —
        // farthest away in pixel space, a relationship invariant under any
        // rotation/skew. Of the remaining two, the one sharing the circle's
        // X coordinate (same right-hand edge) is top_right; the one sharing
        // the circle's Y coordinate (same bottom edge) is bottom_left.
        $circlePos = $corners[$bottomRightPxKey];
        $farthestKey = null;
        $farthestDistSq = -1.0;
        foreach ($squareKeys as $k) {
            $distSq = ($corners[$k]['x'] - $circlePos['x']) ** 2 + ($corners[$k]['y'] - $circlePos['y']) ** 2;
            if ($distSq > $farthestDistSq) {
                $farthestDistSq = $distSq;
                $farthestKey = $k;
            }
        }
        $topLeftKey = $farthestKey;
        $remainingKeys = array_values(array_diff($squareKeys, [$topLeftKey]));
        [$ra, $rb] = $remainingKeys;
        $raDx = abs($corners[$ra]['x'] - $circlePos['x']);
        $rbDx = abs($corners[$rb]['x'] - $circlePos['x']);
        [$topRightKey, $bottomLeftKey] = $raDx < $rbDx ? [$ra, $rb] : [$rb, $ra];

        $pixelPoints = [
            'top_left' => $corners[$topLeftKey],
            'top_right' => $corners[$topRightKey],
            'bottom_left' => $corners[$bottomLeftKey],
            'bottom_right' => $circlePos,
        ];
        $transform = $this->solveAffine($manifestPoints, $pixelPoints);
        $residual = $this->affineResidual($transform, $manifestPoints, $pixelPoints);

        // A correct fit should reproduce every fiducial within a couple of
        // pixels; a genuine detection failure (bad crop, wrong page) produces
        // a residual orders of magnitude larger. Guard against silently
        // accepting a bad fit.
        if ($residual > 25.0) {
            return null;
        }

        return $transform;
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
