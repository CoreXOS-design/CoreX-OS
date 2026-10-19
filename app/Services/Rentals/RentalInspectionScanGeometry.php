<?php

namespace App\Services\Rentals;

use Imagick;
use ImagickPixel;

/**
 * AT-463 / .ai/specs/rental-inspections.md §62 - the geometry half of reading a scanned site form: finding the corner marks
 * on whatever an office scanner produces, straightening the page, and locating each row of tick boxes on it.
 *
 * Built from three real scans (Ricoh MP 402SPF at 200 dpi and MP C3004ex at 300 dpi, 1-bit CCITT):
 *  - a SOLID black square is scanned as a DITHERED patch (30-65 % dark) - a hard "pixel < 128" blob search never sees it,
 *    so marks are found with a matched filter on average darkness instead (findMarkNearCorner);
 *  - the form printed on Letter, or shrunk by the printer, loses its bottom marks and the QR, and the sheet comes out
 *    scaled and shifted - so a page is placed from whichever marks survive (the reader fits 2 to 4);
 *  - a scanner stretches one axis by up to ~1 % (5 pt over a page) - so after the page is straightened every ROW is
 *    located from its own printed box outlines (alignRows), never from the page corners alone;
 *  - a hand tick fills 5-20 % of a box, not 35 % - so ink is measured inside the box, away from its outline (inkShare).
 *
 * Everything here works in the form's own units (points, origin top-left) on a straightened "canvas" at CANVAS_PX_PER_PT.
 */
final class RentalInspectionScanGeometry
{
    /** Pixels per printed point on the straightened canvas (216 dpi - the box outline is 3 px, a box 36 px). */
    public const CANVAS_PX_PER_PT = 3.0;

    /** A printed tick box is its 10 pt content box plus a 1 pt outline on every side: 12 pt outside, edges at +0.5 and +11.5. */
    public const BOX_OUTER = 12.0;

    /** Pixel value below which a pixel counts as ink. Grey pre-ticks print at ~210, so they never count. */
    private const INK_BELOW = 140;

    // ── Corner marks ───────────────────────────────────────────────────────

    /**
     * The registration mark in one corner region of the picture: the isolated, solid-looking square nearest the picture's
     * corner. Works on average DARKNESS at half resolution (so a dithered patch counts), with an integral image:
     *   - the window is dark enough inside (>= 18 %) and its four quarters are all dark (a solid square, not text);
     *   - a ring of paper, set back by a guard gap, is clean (an isolated mark, not part of a table or a letter), and the
     *     guard gap makes the test tolerate a mark 15 % bigger or smaller than expected (a form printed scaled);
     *   - among qualifying windows the one nearest the picture corner wins - a registration mark always sits nearer the
     *     corner than anything printed on the page.
     *
     * @param string $corner 'tl' | 'tr' | 'bl' | 'br'
     * @return array{x: float, y: float, share: float, ring: float}|null centre in full-resolution picture pixels
     */
    public function findMarkNearCorner(Imagick $page, string $corner, float $expectedPx): ?array
    {
        $W = $page->getImageWidth();
        $H = $page->getImageHeight();
        $qw = (int) round($W * 0.40);
        $qh = (int) round($H * 0.35);
        $qx = in_array($corner, ['tr', 'br'], true) ? $W - $qw : 0;
        $qy = in_array($corner, ['bl', 'br'], true) ? $H - $qh : 0;
        $cornerX = in_array($corner, ['tr', 'br'], true) ? (float) $W : 0.0;
        $cornerY = in_array($corner, ['bl', 'br'], true) ? (float) $H : 0.0;
        // A page photographed 20-25 degrees off square sits with its nearest mark a third of the picture in from the corner
        // (the page's own corner points out along the picture's edge), so the cap is the search quadrant itself.
        $capX = 0.40 * $W;
        $capY = 0.35 * $H;

        $f = 0.5;
        $crop = clone $page;
        $crop->cropImage($qw, $qh, $qx, $qy);
        $crop->setImagePage(0, 0, 0, 0);
        $w = max(8, (int) round($qw * $f));
        $h = max(8, (int) round($qh * $f));
        $crop->resizeImage($w, $h, Imagick::FILTER_BOX, 1);
        $px = $crop->exportImagePixels(0, 0, $w, $h, 'I', Imagick::PIXEL_CHAR);
        $crop->clear();

        $s = max(4, (int) round($expectedPx * 0.95 * $f));
        $g = max(1, (int) round($s * 0.2));       // guard gap
        $m = max(2, (int) round($s * 0.3));       // clean ring width
        $pad = $g + $m;

        // The integral image is laid over the picture plus a `$pad` margin of blank paper on every side, so a mark that sits
        // within a few pixels of the picture's edge (a scanner that stretches the page past its frame) still has the clean
        // ring the isolation test asks for.
        $wp = $w + 2 * $pad;
        $hp = $h + 2 * $pad;
        $w1 = $wp + 1;
        $I = array_fill(0, $w1 * ($hp + 1), 0.0);
        for ($y = 0; $y < $hp; $y++) {
            $run = 0.0;
            $inY = $y >= $pad && $y < $pad + $h;
            $base = ($y - $pad) * $w - $pad;
            for ($x = 0; $x < $wp; $x++) {
                if ($inY && $x >= $pad && $x < $pad + $w) {
                    $run += (255 - ($px[$base + $x] & 0xFF)) / 255;
                }
                $I[($y + 1) * $w1 + $x + 1] = $I[$y * $w1 + $x + 1] + $run;
            }
        }
        $sum = static fn (int $x0, int $y0, int $x1, int $y1): float => $I[$y1 * $w1 + $x1] - $I[$y0 * $w1 + $x1] - $I[$y1 * $w1 + $x0] + $I[$y0 * $w1 + $x0];

        $found = [];
        for ($y = $pad; $y <= $pad + $h - $s; $y++) {
            for ($x = $pad; $x <= $pad + $w - $s; $x++) {
                $in = $sum($x, $y, $x + $s, $y + $s);
                $share = $in / ($s * $s);
                if ($share < 0.18) {
                    continue;
                }
                // a solid mark is evenly dark across a 3 x 3 grid of its window; a letter, a table corner or a cluster of text
                // has holes and light cells
                $third = $s / 3;
                $even = true;
                for ($gy = 0; $gy < 3 && $even; $gy++) {
                    for ($gx = 0; $gx < 3; $gx++) {
                        $cell = $sum((int) round($x + $gx * $third), (int) round($y + $gy * $third), (int) round($x + ($gx + 1) * $third), (int) round($y + ($gy + 1) * $third)) / max(1.0, $third * $third);
                        if ($cell < 0.4 * $share) {
                            $even = false;
                            break;
                        }
                    }
                }
                if (! $even) {
                    continue;
                }
                // a patch SMALLER than a mark (a filled box of the page-identifier grid, a full stop) is dark in its middle only:
                // a real mark is as dark across the whole window as in a window 60 % the size
                $c0 = (int) round($s * 0.2);
                $core = $sum($x + $c0, $y + $c0, $x + $s - $c0, $y + $s - $c0) / (($s - 2 * $c0) ** 2);
                if ($core > 0 && $share < 0.6 * $core) {
                    continue;
                }
                $guard = $sum($x - $g, $y - $g, $x + $s + $g, $y + $s + $g);
                $outer = $sum($x - $pad, $y - $pad, $x + $s + $pad, $y + $s + $pad);
                $ringArea = ($s + 2 * $pad) ** 2 - ($s + 2 * $g) ** 2;
                $ring = ($outer - $guard) / $ringArea;
                if ($ring > 0.06) {
                    continue;
                }
                $cx = ($qx + ($x - $pad + $s / 2) / $f);
                $cy = ($qy + ($y - $pad + $s / 2) / $f);
                if (abs($cx - $cornerX) > $capX || abs($cy - $cornerY) > $capY) {
                    continue;
                }
                $found[] = ['x' => $cx, 'y' => $cy, 'score' => $share - 3 * $ring, 'share' => $share, 'ring' => $ring];
            }
        }
        if ($found === []) {
            return null;
        }

        $best = max(array_column($found, 'score'));
        $good = array_values(array_filter($found, fn ($c) => $c['score'] >= 0.75 * $best));
        usort($good, fn ($a, $b) => (($a['x'] - $cornerX) ** 2 + ($a['y'] - $cornerY) ** 2) <=> (($b['x'] - $cornerX) ** 2 + ($b['y'] - $cornerY) ** 2));
        $pick = $good[0];
        // every qualifying window of the same mark votes for its centre
        $near = array_filter($good, fn ($c) => hypot($c['x'] - $pick['x'], $c['y'] - $pick['y']) <= $s / $f);
        $wsum = array_sum(array_column($near, 'score'));
        $cx = array_sum(array_map(fn ($c) => $c['x'] * $c['score'], $near)) / $wsum;
        $cy = array_sum(array_map(fn ($c) => $c['y'] * $c['score'], $near)) / $wsum;

        return ['x' => $cx, 'y' => $cy, 'share' => round($pick['share'], 3), 'ring' => round($pick['ring'], 3)];
    }

    // ── Straightening ──────────────────────────────────────────────────────

    /**
     * Warp the rectangle (x, y, w, h) - in printed-page points - out of the scan into an upright image of w*scale by h*scale
     * pixels, using the page's own transform (printed points -> scan pixels). Rotation, scale and shift of the scan are all
     * undone here, so everything read from the result is read the right way up.
     *
     * @param array{a: float, b: float, c: float, d: float, e: float, f: float} $t
     */
    public function straightenRegion(Imagick $page, array $t, float $x, float $y, float $w, float $h, float $scale): Imagick
    {
        $det = $t['a'] * $t['e'] - $t['b'] * $t['d'];
        $sx = $scale * $t['e'] / $det;
        $ry = -$scale * $t['b'] / $det;
        $rx = -$scale * $t['d'] / $det;
        $sy = $scale * $t['a'] / $det;
        $tx = $scale * ((-$t['e'] * $t['c'] + $t['b'] * $t['f']) / $det - $x);
        $ty = $scale * (($t['d'] * $t['c'] - $t['a'] * $t['f']) / $det - $y);

        $outW = (int) round($w * $scale);
        $outH = (int) round($h * $scale);

        // A small region (the QR, the page grid) is warped out of a small crop of the scan, not out of the whole page.
        $corners = [[$x, $y], [$x + $w, $y], [$x + $w, $y + $h], [$x, $y + $h]];
        $xs = [];
        $ys = [];
        foreach ($corners as [$mx, $my]) {
            $xs[] = $t['a'] * $mx + $t['b'] * $my + $t['c'];
            $ys[] = $t['d'] * $mx + $t['e'] * $my + $t['f'];
        }
        $bx = max(0, (int) floor(min($xs)) - 6);
        $by = max(0, (int) floor(min($ys)) - 6);
        $bw = min($page->getImageWidth(), (int) ceil(max($xs)) + 6) - $bx;
        $bh = min($page->getImageHeight(), (int) ceil(max($ys)) + 6) - $by;
        $img = clone $page;
        if ($bw > 0 && $bh > 0 && $bw * $bh < 0.5 * $page->getImageWidth() * $page->getImageHeight()) {
            $img->cropImage($bw, $bh, $bx, $by);
            $img->setImagePage(0, 0, 0, 0);
            $t['c'] -= $bx;
            $t['f'] -= $by;
            $det = $t['a'] * $t['e'] - $t['b'] * $t['d'];
            $sx = $scale * $t['e'] / $det;
            $ry = -$scale * $t['b'] / $det;
            $rx = -$scale * $t['d'] / $det;
            $sy = $scale * $t['a'] / $det;
            $tx = $scale * ((-$t['e'] * $t['c'] + $t['b'] * $t['f']) / $det - $x);
            $ty = $scale * (($t['d'] * $t['c'] - $t['a'] * $t['f']) / $det - $y);
        }
        $img->setImageBackgroundColor(new ImagickPixel('white'));
        // the distorted image keeps its source's size: make the canvas big enough to hold the output
        if ($img->getImageWidth() < $outW || $img->getImageHeight() < $outH) {
            $img->extentImage(max($img->getImageWidth(), $outW), max($img->getImageHeight(), $outH), 0, 0);
        }
        $img->setImageVirtualPixelMethod(Imagick::VIRTUALPIXELMETHOD_WHITE);
        $img->distortImage(Imagick::DISTORTION_AFFINEPROJECTION, [$sx, $rx, $ry, $sy, $tx, $ty], false);
        $img->cropImage($outW, $outH, 0, 0);
        $img->setImagePage(0, 0, 0, 0);

        return $img;
    }

    /** The whole printed page, upright, at CANVAS_PX_PER_PT. */
    public function straightenPage(Imagick $page, array $t, float $widthPt, float $heightPt): Imagick
    {
        return $this->straightenRegion($page, $t, 0.0, 0.0, $widthPt, $heightPt, self::CANVAS_PX_PER_PT);
    }

    // ── Rows ───────────────────────────────────────────────────────────────

    /**
     * Where each row of tick boxes REALLY is on the straightened canvas, found from the rows' own printed outlines.
     *
     * Registration is GLOBAL first, then local. The corner marks only get the page close: one may be cut off by the edge of
     * the scan, a letter may look like one, a scanner stretches one axis by 1-2 %. Adjacent rows look alike, so a per-row
     * search that starts off by a row or two would lock on the wrong row. Instead the whole page's row lattice (whose room
     * headings make the spacing irregular, so it has ONE best fit) is fitted at once:
     *   1. y: every row's two horizontal edges, against a line  y_seen = y + a + b*y  (offset and stretch);
     *   2. x: every row's 2 x N vertical edges, against  x_seen = x + c + d*y  (offset and shear);
     * then each row is refined within +-2.5 pt of that fit and a straight line is fitted through the rows measured well, so
     * one heavy-tick row or a row of notes cannot pull a row off its boxes.
     *
     * @param array<int|string, list<array{x: float|int, y: float|int}>> $rows row key => that row's boxes, left to right
     * @return array<int|string, array{dx: float, dy: float, measured: bool}> offsets in points, to ADD to the manifest position
     */
    public function alignRows(Imagick $canvas, array $rows): array
    {
        $S = self::CANVAS_PX_PER_PT;
        if ($rows === []) {
            return [];
        }

        // ── 1. y: global fit of the row lattice ────────────────────────────
        $minX = min(array_map(fn ($r) => (float) $r[0]['x'], $rows));
        $maxX = max(array_map(fn ($r) => (float) end($r)['x'] + self::BOX_OUTER, $rows));
        $minY = min(array_map(fn ($r) => (float) $r[0]['y'], $rows));
        $maxY = max(array_map(fn ($r) => (float) $r[0]['y'] + self::BOX_OUTER, $rows));
        $padY = 40.0;
        $bx = (int) round($minX * $S);
        $bw = (int) round(($maxX - $minX) * $S);
        $by = max(0, (int) round(($minY - $padY) * $S));
        $bh = min($canvas->getImageHeight() - $by, (int) round(($maxY - $minY + 2 * $padY) * $S));
        if ($bw <= 0 || $bh <= 0 || $bx < 0 || $bx + $bw > $canvas->getImageWidth()) {
            return $this->unmeasured($rows);
        }
        $px = $canvas->exportImagePixels($bx, $by, $bw, $bh, 'I', Imagick::PIXEL_CHAR);
        $profY = array_fill(0, $bh, 0);
        foreach ($px as $i => $v) {
            if (($v & 0xFF) < self::INK_BELOW) {
                $profY[intdiv($i, $bw)]++;
            }
        }
        unset($px);
        $yAt = fn (float $ySeenPt): int => (int) round($ySeenPt * $S) - $by;

        $bestY = [-1.0, 0.0, 0.0];
        for ($b = -0.06; $b <= 0.0601; $b += 0.004) {
            for ($a = -36.0; $a <= 36.0; $a += 1.0) {
                $score = 0;
                foreach ($rows as $boxes) {
                    $y = (float) $boxes[0]['y'];
                    foreach ([0.5, self::BOX_OUTER - 0.5] as $edge) {
                        $j = $yAt($y + $edge + $a + $b * $y);
                        if ($j >= 1 && $j < $bh - 1) {
                            $score += $profY[$j - 1] + $profY[$j] + $profY[$j + 1];
                        }
                    }
                }
                if ($score > $bestY[0]) {
                    $bestY = [$score, $a, $b];
                }
            }
        }
        [, $ay, $by_] = $bestY;
        $dyOf = fn (float $y): float => $ay + $by_ * $y;

        // ── 2. x: global fit of the box columns, row by row on its own y band ──
        $bands = [];
        foreach ($rows as $key => $boxes) {
            $y = (float) $boxes[0]['y'];
            $y0 = (int) round(($y + $dyOf($y) + 2.5) * $S);
            $y1 = (int) round(($y + $dyOf($y) + 9.5) * $S);
            $x0 = (int) round(($minX - 24) * $S);
            $w0 = (int) round(($maxX - $minX + 48) * $S);
            if ($y0 < 0 || $y1 > $canvas->getImageHeight() || $x0 < 0 || $x0 + $w0 > $canvas->getImageWidth()) {
                continue;
            }
            $bp = $canvas->exportImagePixels($x0, $y0, $w0, $y1 - $y0, 'I', Imagick::PIXEL_CHAR);
            $cols = array_fill(0, $w0, 0);
            foreach ($bp as $i => $v) {
                if (($v & 0xFF) < self::INK_BELOW) {
                    $cols[$i % $w0]++;
                }
            }
            $bands[$key] = ['cols' => $cols, 'x0' => $x0, 'w' => $w0];
        }
        $bestX = [-1.0, 0.0, 0.0];
        for ($d = -0.06; $d <= 0.0601; $d += 0.004) {
            for ($c = -26.0; $c <= 26.0; $c += 1.0) {
                $score = 0;
                foreach ($bands as $key => $bd) {
                    $y = (float) $rows[$key][0]['y'];
                    $shift = $c + $d * $y;
                    foreach ($rows[$key] as $box) {
                        foreach ([0.5, self::BOX_OUTER - 0.5] as $edge) {
                            $i = (int) round(((float) $box['x'] + $edge + $shift) * $S) - $bd['x0'];
                            if ($i >= 1 && $i < $bd['w'] - 1) {
                                $score += $bd['cols'][$i - 1] + $bd['cols'][$i] + $bd['cols'][$i + 1];
                            }
                        }
                    }
                }
                if ($score > $bestX[0]) {
                    $bestX = [$score, $c, $d];
                }
            }
        }
        [, $cx, $dx_] = $bestX;
        $dxOf = fn (float $y): float => $cx + $dx_ * $y;

        // ── 3. local refinement around the global fit, then a robust line through what was measured well ──
        $measured = [];
        foreach ($rows as $key => $boxes) {
            $y = (float) $boxes[0]['y'];
            $one = $this->measureRow($canvas, $boxes, $S, $dxOf($y), $dyOf($y), 2.5, 2.5);
            if ($one !== null) {
                $measured[$key] = $one;
            }
        }
        $fitDy = $this->robustLine(array_map(fn ($key) => [(float) $rows[$key][0]['y'], $measured[$key]['dy']], array_keys($measured)));
        $fitDx = $this->robustLine(array_map(fn ($key) => [(float) $rows[$key][0]['y'], $measured[$key]['dx']], array_keys($measured)));

        $out = [];
        foreach ($rows as $key => $boxes) {
            $y = (float) $boxes[0]['y'];
            $out[$key] = [
                'dx' => $fitDx !== null ? $fitDx[0] + $fitDx[1] * $y : $dxOf($y),
                'dy' => $fitDy !== null ? $fitDy[0] + $fitDy[1] * $y : $dyOf($y),
                'measured' => isset($measured[$key]),
            ];
        }

        return $out;
    }

    private function unmeasured(array $rows): array
    {
        return array_map(fn () => ['dx' => 0.0, 'dy' => 0.0, 'measured' => false], $rows);
    }

    /** @return array{dx: float, dy: float, strength: float}|null null when the row's outlines are not there to be measured */
    private function measureRow(Imagick $canvas, array $boxes, float $S, float $centreDx = 0.0, float $centreDy = 0.0, float $rangeDx = 9.0, float $rangeDy = 13.0): ?array
    {
        $x1 = (float) $boxes[0]['x'];
        $xn = (float) end($boxes)['x'] + self::BOX_OUTER;
        $y = (float) $boxes[0]['y'];
        $padX = 6.0 + abs($centreDx) + $rangeDx;
        $above = 14.0 + abs($centreDy);
        $bx = (int) round(($x1 - $padX) * $S);
        $by = (int) round(($y - $above) * $S);
        $bw = (int) round(($xn - $x1 + 2 * $padX) * $S);
        $bh = (int) round((self::BOX_OUTER + 2 * $above) * $S);
        if ($bx < 0 || $by < 0 || $bx + $bw > $canvas->getImageWidth() || $by + $bh > $canvas->getImageHeight()) {
            return null;
        }
        $px = $canvas->exportImagePixels($bx, $by, $bw, $bh, 'I', Imagick::PIXEL_CHAR);

        $prof = array_fill(0, $bh, 0);
        foreach ($px as $i => $v) {
            if (($v & 0xFF) < self::INK_BELOW) {
                $prof[intdiv($i, $bw)]++;
            }
        }
        $dy = 0.0;
        $best = -1;
        for ($d = $centreDy - $rangeDy; $d <= $centreDy + $rangeDy; $d += 0.25) {
            $score = 0;
            foreach ([0.5, self::BOX_OUTER - 0.5] as $edge) {
                for ($t = -0.5; $t <= 0.5; $t += 0.34) {
                    $j = (int) round(($above + $edge + $d + $t) * $S);
                    if ($j >= 0 && $j < $bh) {
                        $score += $prof[$j];
                    }
                }
            }
            if ($score > $best) {
                $best = $score;
                $dy = $d;
            }
        }
        // a real row has two long dark lines (7 boxes x 10 pt): demand a fair share of that, or call the row unmeasurable
        $expected = 2 * 3 * count($boxes) * self::BOX_OUTER * $S;
        $strength = $best / max(1.0, $expected);
        if ($strength < 0.35) {
            return null;
        }

        $cols = array_fill(0, $bw, 0);
        $j0 = (int) round(($above + $dy + 2.5) * $S);
        $j1 = (int) round(($above + $dy + 9.5) * $S);
        for ($j = max(0, $j0); $j < min($bh, $j1); $j++) {
            $base = $j * $bw;
            for ($i = 0; $i < $bw; $i++) {
                if (($px[$base + $i] & 0xFF) < self::INK_BELOW) {
                    $cols[$i]++;
                }
            }
        }
        $dx = 0.0;
        $bestX = -1;
        for ($d = $centreDx - $rangeDx; $d <= $centreDx + $rangeDx; $d += 0.25) {
            $score = 0;
            foreach ($boxes as $b) {
                foreach ([0.5, self::BOX_OUTER - 0.5] as $edge) {
                    $i = (int) round((((float) $b['x']) - $x1 + $padX + $edge + $d) * $S);
                    if ($i >= 0 && $i < $bw) {
                        $score += $cols[$i];
                    }
                }
            }
            if ($score > $bestX) {
                $bestX = $score;
                $dx = $d;
            }
        }

        return ['dx' => $dx, 'dy' => $dy, 'strength' => $strength];
    }

    /**
     * Least-squares line v = a + b*y through (y, v) points, dropping points more than 1 pt off the line and refitting.
     *
     * @param list<array{0: float, 1: float}> $points
     * @return array{0: float, 1: float}|null [a, b]; null when there are too few points to trust a line
     */
    private function robustLine(array $points): ?array
    {
        if (count($points) < 4) {
            return null;
        }
        $fit = null;
        for ($pass = 0; $pass < 3; $pass++) {
            $n = count($points);
            $sy = array_sum(array_column($points, 0));
            $sv = array_sum(array_column($points, 1));
            $syy = array_sum(array_map(fn ($p) => $p[0] * $p[0], $points));
            $syv = array_sum(array_map(fn ($p) => $p[0] * $p[1], $points));
            $den = $n * $syy - $sy * $sy;
            $b = abs($den) < 1e-9 ? 0.0 : ($n * $syv - $sy * $sv) / $den;
            $a = ($sv - $b * $sy) / $n;
            $fit = [$a, $b];
            $kept = array_values(array_filter($points, fn ($p) => abs($p[1] - ($a + $b * $p[0])) <= 1.0));
            if (count($kept) === count($points) || count($kept) < 4) {
                break;
            }
            $points = $kept;
        }

        return $fit;
    }

    // ── Ink ────────────────────────────────────────────────────────────────

    /**
     * Share of the INSIDE of a tick box that carries ink, on the straightened canvas. The inside is the 10 pt content box
     * less 1.5 pt each side (the printed outline and any alignment error stay out of it); `$x`/`$y` are the box's printed
     * position PLUS its row's offset.
     */
    public function inkShare(Imagick $canvas, float $x, float $y): float
    {
        $S = self::CANVAS_PX_PER_PT;
        $inset = 2.5;
        $side = 7.0;
        $px0 = (int) round(($x + $inset) * $S);
        $py0 = (int) round(($y + $inset) * $S);
        $w = (int) round($side * $S);
        $h = $w;
        if ($px0 < 0 || $py0 < 0 || $px0 + $w > $canvas->getImageWidth() || $py0 + $h > $canvas->getImageHeight()) {
            return 0.0;
        }
        $px = $canvas->exportImagePixels($px0, $py0, $w, $h, 'I', Imagick::PIXEL_CHAR);
        $dark = 0;
        foreach ($px as $v) {
            if (($v & 0xFF) < self::INK_BELOW) {
                $dark++;
            }
        }

        return $dark / max(1, $w * $h);
    }
}
