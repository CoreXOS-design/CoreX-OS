<?php

namespace App\Services\Rentals;

use Imagick;

/**
 * AT-463 / spec §62 - reads a QR code off a STRAIGHTENED crop of a scan by sampling its module grid, for the scans an image
 * decoder cannot read.
 *
 * Some office scanners (the Ricoh MP C3004ex on its default mode, measured) edge-enhance and halftone: every black QR module
 * comes out as a hollow outline with a hatched middle, so the finder-pattern detection of a general QR decoder never locks.
 * But we know exactly where the code is on the page and how many modules it has. So: sample each module cell's darkness,
 * register the sampling grid against the parts of a QR code that are the same in every code - the three finder squares,
 * their separators and the two timing lines - then threshold and hand the bit matrix straight to the decoder's matrix stage
 * (Reed-Solomon corrects what noise is left).
 */
final class RentalInspectionQrModuleReader
{
    /**
     * @param Imagick $crop       a straightened crop containing the whole code
     * @param float   $originPx   pixel position of the code's top-left corner in the crop
     * @param float   $sizePx     pixel size of the code (the printed symbol, no quiet zone)
     * @param list<int> $moduleCounts module counts to try (21, 25, 29 ...), most likely first
     * @return string|null the decoded text, or null
     */
    public function decode(Imagick $crop, float $originPx, float $sizePx, array $moduleCounts): ?string
    {
        $W = $crop->getImageWidth();
        $H = $crop->getImageHeight();
        $px = $crop->exportImagePixels(0, 0, $W, $H, 'I', Imagick::PIXEL_CHAR);
        $dark = static fn (int $x, int $y): float => ($x < 0 || $y < 0 || $x >= $W || $y >= $H) ? 0.0 : (255 - ($px[$y * $W + $x] & 0xFF)) / 255;

        foreach ($moduleCounts as $n) {
            $known = $this->knownModules($n);
            $registrations = $this->bestRegistrations($dark, $n, $known, $originPx, $sizePx);
            foreach ($registrations as $reg) {
                $values = $this->sampleAll($dark, $n, $reg, 0.5);
                foreach ($this->thresholds($values) as $threshold) {
                    $text = $this->tryDecode($values, $n, $threshold);
                    if ($text !== null) {
                        return $text;
                    }
                }
            }
        }

        return null;
    }

    /** The modules whose colour is the same in every QR code: finders, separators, timing lines. [row][col] => 1|0 */
    private function knownModules(int $n): array
    {
        $known = [];
        foreach ([[0, 0], [0, $n - 7], [$n - 7, 0]] as [$r0, $c0]) {
            for ($r = 0; $r < 7; $r++) {
                for ($c = 0; $c < 7; $c++) {
                    $edge = $r === 0 || $r === 6 || $c === 0 || $c === 6;
                    $core = $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4;
                    $known[$r0 + $r][$c0 + $c] = ($edge || $core) ? 1 : 0;
                }
            }
        }
        // separators: the white row/column beside each finder
        for ($i = 0; $i < 8; $i++) {
            foreach ([[7, $i], [$i, 7], [7, $n - 8 + $i], [$i, $n - 8], [$n - 8, $i], [$n - 8 + $i, 7]] as [$r, $c]) {
                if ($r >= 0 && $c >= 0 && $r < $n && $c < $n && ! isset($known[$r][$c])) {
                    $known[$r][$c] = 0;
                }
            }
        }
        for ($i = 8; $i < $n - 8; $i++) {
            $known[6][$i] = ($i % 2 === 0) ? 1 : 0;
            $known[$i][6] = ($i % 2 === 0) ? 1 : 0;
        }

        return $known;
    }

    /** Mean darkness over a module's middle (`$frac` of its side), sampled on a 3 x 3 grid of points. */
    private function sampleModule(callable $dark, array $reg, int $r, int $c, float $frac): float
    {
        $mpx = $reg['pitch_x'];
        $mpy = $reg['pitch_y'];
        $cx = $reg['ox'] + ($c + 0.5) * $mpx;
        $cy = $reg['oy'] + ($r + 0.5) * $mpy;
        $sum = 0.0;
        foreach ([-1, 0, 1] as $dy) {
            foreach ([-1, 0, 1] as $dx) {
                $sum += $dark((int) round($cx + $dx * $frac * $mpx / 2), (int) round($cy + $dy * $frac * $mpy / 2));
            }
        }

        return $sum / 9;
    }

    /** @return list<array{ox: float, oy: float, pitch_x: float, pitch_y: float}> best few registrations, best first */
    private function bestRegistrations(callable $dark, int $n, array $known, float $originPx, float $sizePx): array
    {
        $score = function (array $reg) use ($dark, $known): float {
            $on = 0.0;
            $non = 0;
            $off = 0.0;
            $noff = 0;
            foreach ($known as $r => $cols) {
                foreach ($cols as $c => $v) {
                    $d = $this->sampleModule($dark, $reg, $r, $c, 0.5);
                    if ($v) {
                        $on += $d;
                        $non++;
                    } else {
                        $off += $d;
                        $noff++;
                    }
                }
            }

            return ($non && $noff) ? $on / $non - $off / $noff : 0.0;
        };

        $base = $sizePx / $n;
        $scored = [];
        // coarse: one pitch for both axes (a 52 pt code has no room for a visible anisotropy), +-8 %, offset +-9 px
        foreach ([0.92, 0.95, 0.98, 1.0, 1.02, 1.05, 1.08] as $sx) {
            for ($dx = -9; $dx <= 9; $dx += 3) {
                for ($dy = -9; $dy <= 9; $dy += 3) {
                    $reg = ['ox' => $originPx + $dx, 'oy' => $originPx + $dy, 'pitch_x' => $base * $sx, 'pitch_y' => $base * $sx];
                    $scored[] = [$score($reg), $reg];
                }
            }
        }
        usort($scored, fn ($a, $b) => $b[0] <=> $a[0]);
        $coarse = array_slice($scored, 0, 3);

        // fine: around each of the best coarse ones
        $out = [];
        foreach ($coarse as [$s0, $reg0]) {
            $best = [$s0, $reg0];
            foreach ([-1.5, -0.75, 0.0, 0.75, 1.5] as $dx) {
                foreach ([-1.5, -0.75, 0.0, 0.75, 1.5] as $dy) {
                    foreach ([0.99, 1.0, 1.01] as $sx) {
                        foreach ([0.99, 1.0, 1.01] as $sy) {
                            $reg = ['ox' => $reg0['ox'] + $dx, 'oy' => $reg0['oy'] + $dy, 'pitch_x' => $reg0['pitch_x'] * $sx, 'pitch_y' => $reg0['pitch_y'] * $sy];
                            $s = $score($reg);
                            if ($s > $best[0]) {
                                $best = [$s, $reg];
                            }
                        }
                    }
                }
            }
            if ($best[0] >= 0.15) { // finders and timing must be plainly there
                $out[] = $best[1];
            }
        }

        return $out;
    }

    /** @return array<int, array<int, float>> */
    private function sampleAll(callable $dark, int $n, array $reg, float $frac): array
    {
        $values = [];
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                $values[$r][$c] = $this->sampleModule($dark, $reg, $r, $c, $frac);
            }
        }

        return $values;
    }

    /** Otsu's threshold over the module values, then a few neighbours of it. @return list<float> */
    private function thresholds(array $values): array
    {
        $flat = array_merge(...$values);
        sort($flat);
        $total = count($flat);
        $best = 0.0;
        $thr = 0.2;
        for ($i = 10; $i < $total - 10; $i += 3) {
            $lo = array_slice($flat, 0, $i + 1);
            $hi = array_slice($flat, $i + 1);
            $w0 = count($lo) / $total;
            $m0 = array_sum($lo) / count($lo);
            $m1 = array_sum($hi) / count($hi);
            $var = $w0 * (1 - $w0) * ($m0 - $m1) ** 2;
            if ($var > $best) {
                $best = $var;
                $thr = $flat[$i];
            }
        }

        return [$thr, $thr * 0.85, $thr * 1.15, $thr * 0.7, $thr * 1.3];
    }

    private function tryDecode(array $values, int $n, float $threshold): ?string
    {
        $bits = new \Zxing\Common\BitMatrix($n, $n);
        foreach ($values as $r => $row) {
            foreach ($row as $c => $v) {
                if ($v > $threshold) {
                    $bits->set($c, $r);
                }
            }
        }
        try {
            $result = (new \Zxing\Qrcode\Decoder\Decoder())->decode($bits);
            $text = is_object($result) ? $result->getText() : $result;

            return is_string($text) && $text !== '' ? $text : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
