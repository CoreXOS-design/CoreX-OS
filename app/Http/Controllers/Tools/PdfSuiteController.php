<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class PdfSuiteController extends Controller
{
    private const MAX_KB = 51200;

    private static function qpdfPath(): string     { return config('splitter.qpdf_path', 'qpdf'); }
    private static function pdfunitePath(): string { return config('splitter.pdfunite_path', 'pdfunite'); }
    private static function pdftoppmPath(): string { return config('splitter.pdftoppm_path', 'pdftoppm'); }
    private static function gsPath(): string       { return config('pdf-suite.gs_path', 'gs'); }

    /** Hub landing page — 8 tool cards. */
    public function hub()
    {
        return view('tools.pdf-suite.hub');
    }

    // ── Compress ────────────────────────────────────────────────────────────
    public function compress()
    {
        return view('tools.pdf-suite.compress');
    }

    public function compressRun(Request $request)
    {
        $data = $request->validate([
            'pdf'     => 'required|file|mimes:pdf|max:' . self::MAX_KB,
            'quality' => 'required|in:screen,ebook,printer',
        ]);

        $in  = $this->stash($data['pdf']);
        $out = $this->outPath('compressed');

        $proc = new Process([
            self::gsPath(), '-sDEVICE=pdfwrite', '-dCompatibilityLevel=1.4',
            '-dPDFSETTINGS=/' . $data['quality'],
            '-dNOPAUSE', '-dQUIET', '-dBATCH',
            '-sOutputFile=' . $out, $in,
        ]);
        $proc->setTimeout(300);

        try { $proc->run(); }
        catch (\Throwable $e) { return $this->binaryError('Ghostscript', 'PDF_SUITE_GS_PATH'); }

        if (! $proc->isSuccessful() || ! is_file($out)) {
            return $this->binaryError('Ghostscript', 'PDF_SUITE_GS_PATH');
        }

        return $this->stream($out, 'compressed.pdf');
    }

    // ── Merge ───────────────────────────────────────────────────────────────
    public function merge()
    {
        return view('tools.pdf-suite.merge');
    }

    public function mergeRun(Request $request)
    {
        $request->validate([
            'pdfs'   => 'required|array|min:2',
            'pdfs.*' => 'file|mimes:pdf|max:' . self::MAX_KB,
        ]);

        $inputs = [];
        foreach ($request->file('pdfs') as $file) {
            $inputs[] = $this->stash($file);
        }

        $out = $this->outPath('merged');

        $args = array_merge([self::qpdfPath(), '--empty', '--pages'], $inputs, ['--', $out]);
        $proc = new Process($args);
        $proc->setTimeout(300);
        $proc->run();

        if (! $proc->isSuccessful() || ! is_file($out)) {
            // Fallback to pdfunite
            $args = array_merge([self::pdfunitePath()], $inputs, [$out]);
            $proc = new Process($args);
            $proc->setTimeout(300);
            $proc->run();
        }

        if (! is_file($out)) {
            return back()->withErrors(['pdfs' => 'Merge failed. Verify qpdf or pdfunite is installed.']);
        }

        return $this->stream($out, 'merged.pdf');
    }

    // ── Image → PDF ─────────────────────────────────────────────────────────
    public function imageToPdf()
    {
        return view('tools.pdf-suite.image-to-pdf');
    }

    public function imageToPdfRun(Request $request)
    {
        $request->validate([
            'images'   => 'required|array|min:1',
            'images.*' => 'file|mimes:jpg,jpeg,png,heic,heif,webp|max:' . self::MAX_KB,
        ]);

        if (! extension_loaded('imagick')) {
            return back()->withErrors(['images' => 'Imagick PHP extension is required for Image → PDF.']);
        }

        $out = $this->outPath('images');

        try {
            $imagick = new \Imagick();
            foreach ($request->file('images') as $file) {
                $page = new \Imagick($file->getRealPath());
                if (method_exists($page, 'autoOrient')) { $page->autoOrient(); }
                $page->setImageFormat('pdf');
                $imagick->addImage($page);
            }
            $imagick->setImageFormat('pdf');
            $imagick->writeImages($out, true);
        } catch (\Throwable $e) {
            return back()->withErrors(['images' => 'Conversion failed: ' . $e->getMessage()]);
        }

        return $this->stream($out, 'images.pdf');
    }

    // ── Rotate ──────────────────────────────────────────────────────────────
    public function rotate()
    {
        return view('tools.pdf-suite.rotate');
    }

    public function rotateRun(Request $request)
    {
        $data = $request->validate([
            'pdf'       => 'required|file|mimes:pdf|max:' . self::MAX_KB,
            'rotations' => 'nullable|string',
            'angle'     => 'nullable|in:90,180,270',
        ]);

        // Build per-page rotation map. Prefer JSON rotations payload; fall back to legacy single angle.
        $map = [];
        if (! empty($data['rotations'])) {
            $decoded = json_decode($data['rotations'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $page => $angle) {
                    $angle = (int) $angle;
                    if (in_array($angle, [90, 180, 270], true)) {
                        $map[(int) $page] = $angle;
                    }
                }
            }
        }

        $in  = $this->stash($data['pdf']);
        $out = $this->outPath('rotated');

        $args = [self::qpdfPath()];
        if (! empty($map)) {
            // Group pages by angle: --rotate=+90:1,3,5
            $byAngle = [];
            foreach ($map as $page => $angle) { $byAngle[$angle][] = $page; }
            foreach ($byAngle as $angle => $pages) {
                sort($pages);
                $args[] = '--rotate=+' . $angle . ':' . implode(',', $pages);
            }
        } elseif (! empty($data['angle'])) {
            $args[] = '--rotate=+' . $data['angle'];
        } else {
            return back()->withErrors(['pdf' => 'No rotation specified.']);
        }
        $args[] = $in;
        $args[] = $out;

        $proc = new Process($args);
        $proc->setTimeout(180);
        $proc->run();

        if (! is_file($out)) {
            return $this->binaryError('qpdf', 'SPLITTER_QPDF_PATH');
        }

        return $this->stream($out, 'rotated.pdf');
    }

    // ── Reorder / Delete ────────────────────────────────────────────────────
    public function reorder()
    {
        return view('tools.pdf-suite.reorder');
    }

    public function reorderRun(Request $request)
    {
        $data = $request->validate([
            'pdf'   => 'required|file|mimes:pdf|max:' . self::MAX_KB,
            'order' => 'required|string', // CSV of 1-based page numbers, e.g. "3,1,4"
        ]);

        $pages = array_filter(array_map('intval', explode(',', $data['order'])));
        if (empty($pages)) {
            return back()->withErrors(['order' => 'Provide at least one page number.']);
        }

        $in  = $this->stash($data['pdf']);
        $out = $this->outPath('reordered');

        $spec = implode(',', $pages);
        $proc = new Process([
            self::qpdfPath(), '--empty', '--pages', $in, $spec, '--', $out,
        ]);
        $proc->setTimeout(180);
        $proc->run();

        if (! is_file($out)) {
            return back()->withErrors(['pdf' => 'Reorder failed. Check the page list.']);
        }

        return $this->stream($out, 'reordered.pdf');
    }

    // ── Protect (lock / unlock) ─────────────────────────────────────────────
    public function protect()
    {
        return view('tools.pdf-suite.protect');
    }

    public function protectRun(Request $request)
    {
        $data = $request->validate([
            'pdf'              => 'required|file|mimes:pdf|max:' . self::MAX_KB,
            'mode'             => 'required|in:lock,unlock',
            'password'         => 'required|string|min:1|max:128',
            'owner_password'   => 'nullable|string|max:128',
        ]);

        $in  = $this->stash($data['pdf']);
        $out = $this->outPath('protected');

        if ($data['mode'] === 'lock') {
            $owner = $data['owner_password'] ?: $data['password'];
            $proc = new Process([
                self::qpdfPath(),
                '--encrypt', $data['password'], $owner, '256', '--',
                $in, $out,
            ]);
        } else {
            $proc = new Process([
                self::qpdfPath(),
                '--password=' . $data['password'],
                '--decrypt', $in, $out,
            ]);
        }

        $proc->setTimeout(180);
        $proc->run();

        if (! is_file($out)) {
            $err = trim($proc->getErrorOutput());
            return back()->withErrors([
                'pdf' => $data['mode'] === 'unlock'
                    ? 'Unlock failed — wrong password?'
                    : 'Lock failed: ' . Str::limit($err, 200),
            ]);
        }

        return $this->stream($out, $data['mode'] === 'lock' ? 'locked.pdf' : 'unlocked.pdf');
    }

    // ── Redact ──────────────────────────────────────────────────────────────
    public function redact()
    {
        return view('tools.pdf-suite.redact');
    }

    public function redactRun(Request $request)
    {
        // rects: JSON array of { page: 1-based, x, y, w, h }, all in PDF point units
        $data = $request->validate([
            'pdf'   => 'required|file|mimes:pdf|max:' . self::MAX_KB,
            'rects' => 'required|string',
        ]);

        $rects = json_decode($data['rects'], true);
        if (! is_array($rects) || empty($rects)) {
            return back()->withErrors(['rects' => 'No redaction rectangles supplied.']);
        }

        if (! extension_loaded('imagick')) {
            return back()->withErrors(['pdf' => 'Imagick PHP extension is required for redaction.']);
        }

        $in  = $this->stash($data['pdf']);
        $out = $this->outPath('redacted');

        // Rasterise each page with pdftoppm, draw black rects with Imagick, recombine.
        $tmpDir = $this->tmpDir('redact');
        $proc = new Process([
            self::pdftoppmPath(), '-png', '-r', '150', $in, $tmpDir . DIRECTORY_SEPARATOR . 'pg',
        ]);
        $proc->setTimeout(300);
        $proc->run();

        $files = glob($tmpDir . DIRECTORY_SEPARATOR . 'pg-*.png');
        sort($files);
        if (empty($files)) {
            return back()->withErrors(['pdf' => 'Failed to rasterise PDF for redaction.']);
        }

        // Group rects by page (1-based)
        $byPage = [];
        foreach ($rects as $r) {
            $p = (int)($r['page'] ?? 0);
            if ($p > 0) { $byPage[$p][] = $r; }
        }

        $output = new \Imagick();
        foreach ($files as $i => $file) {
            $pageNum = $i + 1;
            $img = new \Imagick($file);
            if (! empty($byPage[$pageNum])) {
                $imgW = $img->getImageWidth();
                $imgH = $img->getImageHeight();
                $draw = new \ImagickDraw();
                $draw->setFillColor('#000000');
                foreach ($byPage[$pageNum] as $r) {
                    // Coords from browser are fractional (0..1), top-left origin.
                    // Legacy callers may still send PDF points — detect by magnitude.
                    $rx = (float)$r['x']; $ry = (float)$r['y'];
                    $rw = (float)$r['w']; $rh = (float)$r['h'];
                    if ($rx <= 1 && $ry <= 1 && $rw <= 1 && $rh <= 1) {
                        $x = $rx * $imgW;
                        $y = $ry * $imgH;
                        $w = $rw * $imgW;
                        $h = $rh * $imgH;
                    } else {
                        // Legacy PDF-points path (72pt → 150dpi)
                        $scale = 150 / 72;
                        $x = $rx * $scale;
                        $y = $ry * $scale;
                        $w = $rw * $scale;
                        $h = $rh * $scale;
                    }
                    $draw->rectangle($x, $y, $x + $w, $y + $h);
                }
                $img->drawImage($draw);
            }
            $img->setImageFormat('pdf');
            $output->addImage($img);
        }
        $output->setImageFormat('pdf');
        $output->writeImages($out, true);

        return $this->stream($out, 'redacted.pdf');
    }

    // ── Enhance (make a blurry PDF / image readable) ─────────────────────────
    public function enhance()
    {
        return view('tools.pdf-suite.enhance');
    }

    public function enhanceRun(Request $request)
    {
        $data = $request->validate([
            'file'   => 'required|file|mimes:pdf,jpg,jpeg,png,heic,heif,webp,bmp,tiff,tif|max:' . self::MAX_KB,
            'preset' => 'required|in:auto,document,sharpen,photo',
        ]);

        if (! extension_loaded('imagick')) {
            return back()->withErrors(['file' => 'Imagick PHP extension is required for Enhance.']);
        }

        $file = $data['file'];
        $isPdf = strtolower($file->getClientOriginalExtension()) === 'pdf'
            || $file->getClientMimeType() === 'application/pdf';
        $in  = $this->stash($file);
        $out = $this->outPath('enhanced');

        $dpi = (int) config('pdf-suite.enhance_dpi', 200);
        if ($dpi < 72) { $dpi = 72; }

        // Build the list of page-image paths to enhance. PDFs are rasterised
        // first (one PNG per page); a single image is enhanced as one page.
        $pagePaths = [];
        if ($isPdf) {
            $tmpDir = $this->tmpDir('enhance');
            $proc = new Process([
                self::pdftoppmPath(), '-png', '-r', (string) $dpi, $in, $tmpDir . DIRECTORY_SEPARATOR . 'pg',
            ]);
            $proc->setTimeout(300);
            $proc->run();

            $pagePaths = glob($tmpDir . DIRECTORY_SEPARATOR . 'pg-*.png') ?: [];
            sort($pagePaths);
            if (empty($pagePaths)) {
                return $this->binaryError('pdftoppm', 'SPLITTER_PDFTOPPM_PATH');
            }
        } else {
            $pagePaths = [$in];
        }

        try {
            $output = new \Imagick();
            foreach ($pagePaths as $path) {
                $img = new \Imagick($path);
                $img->setImageResolution($dpi, $dpi);
                if (method_exists($img, 'setImageUnits')) {
                    $img->setImageUnits(\Imagick::RESOLUTION_PIXELSPERINCH);
                }
                $this->enhancePage($img, $data['preset']);
                $img->setImageFormat('pdf');
                $output->addImage($img);
            }
            $output->setImageFormat('pdf');
            $output->writeImages($out, true);
        } catch (\Throwable $e) {
            return back()->withErrors(['file' => 'Enhancement failed: ' . $e->getMessage()]);
        }

        if (! is_file($out)) {
            return back()->withErrors(['file' => 'Enhancement produced no output. Try a different preset or file.']);
        }

        return $this->stream($out, 'enhanced.pdf');
    }

    /**
     * Apply a preset-specific readability pipeline to one rasterised page.
     * Each Imagick op is wrapped against older builds; the whole call is
     * already inside a try/catch in enhanceRun().
     */
    private function enhancePage(\Imagick $img, string $preset): void
    {
        if (method_exists($img, 'autoOrientImage')) {
            try { $img->autoOrientImage(); } catch (\Throwable $e) {}
        }
        $img->setImageBackgroundColor('#ffffff');

        switch ($preset) {
            case 'document':
                // Push toward crisp black-on-white text.
                $img->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
                $img->normalizeImage();
                $img->unsharpMaskImage(0, 1.2, 1.4, 0.02);
                $img->brightnessContrastImage(3, 22);
                break;

            case 'sharpen':
                // Aggressive de-blur for very soft / out-of-focus scans.
                $img->normalizeImage();
                $img->unsharpMaskImage(0, 1.6, 2.0, 0.0);
                $img->sharpenImage(0, 1.0);
                $img->brightnessContrastImage(0, 12);
                break;

            case 'photo':
                // Colour-safe: denoise + gentle sharpen, keep original colours.
                if (method_exists($img, 'enhanceImage')) {
                    try { $img->enhanceImage(); } catch (\Throwable $e) {}
                }
                $img->normalizeImage();
                $img->unsharpMaskImage(0, 0.8, 0.8, 0.02);
                break;

            case 'auto':
            default:
                // Balanced general-purpose readability fix.
                $img->normalizeImage();
                $img->unsharpMaskImage(0, 1.0, 1.2, 0.02);
                $img->brightnessContrastImage(2, 12);
                break;
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────
    private function stash($file): string
    {
        $userDir = storage_path('app/private/pdf-suite/in/' . (auth()->id() ?? 'anon'));
        if (! is_dir($userDir)) { @mkdir($userDir, 0775, true); }
        $name = Str::uuid() . '.' . ($file->getClientOriginalExtension() ?: 'bin');
        $file->move($userDir, $name);
        return $userDir . DIRECTORY_SEPARATOR . $name;
    }

    private function outPath(string $tag): string
    {
        $userDir = storage_path('app/private/pdf-suite/out/' . (auth()->id() ?? 'anon'));
        if (! is_dir($userDir)) { @mkdir($userDir, 0775, true); }
        return $userDir . DIRECTORY_SEPARATOR . $tag . '_' . Str::uuid() . '.pdf';
    }

    private function tmpDir(string $tag): string
    {
        $dir = storage_path('app/private/pdf-suite/tmp/' . (auth()->id() ?? 'anon') . '/' . $tag . '_' . Str::uuid());
        if (! is_dir($dir)) { @mkdir($dir, 0775, true); }
        return $dir;
    }

    private function stream(string $path, string $downloadName)
    {
        return response()->download($path, $downloadName, [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend(true);
    }

    private function binaryError(string $name, string $envKey)
    {
        return back()->withErrors([
            'pdf' => $name . ' is not installed or not on PATH. Set ' . $envKey . ' in .env to its absolute path.',
        ]);
    }
}
