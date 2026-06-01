<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class ImageConverterController extends Controller
{
    private const OUTPUT_FORMATS = ['png', 'jpg', 'webp'];

    private static function magickPath(): string      { return config('image-converter.magick_path', 'magick'); }
    private static function heifConvertPath(): string { return config('image-converter.heif_convert_path', 'heif-convert'); }
    private static function maxKb(): int              { return (int) config('image-converter.max_upload_kb', 51200); }

    public function index()
    {
        return view('tools.image-converter.index');
    }

    public function run(Request $request)
    {
        $request->validate([
            'images'   => 'required|array|min:1|max:50',
            'images.*' => 'file|mimes:jpg,jpeg,png,heic,heif,webp,bmp,tiff,gif|max:' . self::maxKb(),
            'format'   => 'required|in:' . implode(',', self::OUTPUT_FORMATS),
        ]);

        @set_time_limit(0);
        ignore_user_abort(true);

        $format = $request->input('format');
        $files  = $request->file('images');
        $outDir = $this->outDir();

        // Stage 1: stash uploaded files to persistent paths (so they survive into
        // parallel Process workers without relying on PHP's tmp lifecycle).
        $jobs = []; // each: ['stash' => path, 'out' => path, 'heif' => bool, 'magickStage' => bool]
        foreach ($files as $file) {
            $ext       = strtolower($file->getClientOriginalExtension());
            $mime      = strtolower((string) $file->getMimeType());
            $stashPath = $outDir . DIRECTORY_SEPARATOR . 'in_' . Str::random(8) . '.' . ($ext ?: 'bin');
            move_uploaded_file($file->getRealPath(), $stashPath);

            $isHeif = in_array($ext, ['heic', 'heif'], true)
                || str_contains($mime, 'heic')
                || str_contains($mime, 'heif')
                || $this->sniffHeif($stashPath);

            $base    = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'image';
            $outPath = $outDir . DIRECTORY_SEPARATOR . $base . '_' . Str::random(6) . '.' . $format;

            // For HEIC → PNG/JPG: heif-convert writes the final file directly. No magick stage.
            // For HEIC → WEBP: heif-convert produces interim PNG, then magick repacks to WEBP.
            // For non-HEIC: magick handles everything.
            $magickStage = (! $isHeif) || $format === 'webp';

            $jobs[] = [
                'stash'       => $stashPath,
                'out'         => $outPath,
                'heif'        => $isHeif,
                'magickStage' => $magickStage,
                'interim'     => null,
            ];
        }

        $concurrency = max(2, min(4, (int) (shell_exec('nproc 2>/dev/null') ?: 4)));

        // Stage 2: parallel heif-convert pass for all HEIC files.
        $heifJobs = array_filter($jobs, fn($j) => $j['heif']);
        if (! empty($heifJobs)) {
            $procs = [];
            foreach ($heifJobs as $idx => $j) {
                if ($j['magickStage']) {
                    $target = $outDir . DIRECTORY_SEPARATOR . 'decoded_' . Str::random(6) . '.png';
                    $jobs[$idx]['interim'] = $target;
                } else {
                    $target = $j['out']; // heif-convert writes the final file directly
                }

                $args = [self::heifConvertPath()];
                if ($format === 'jpg' && ! $j['magickStage']) { $args[] = '-q'; $args[] = '92'; }
                $args[] = $j['stash'];
                $args[] = $target;

                $procs[$idx] = ['args' => $args, 'target' => $target];
            }

            $results = $this->runParallel($procs, $concurrency);
            foreach ($results as $idx => $r) {
                if (! $r['ok']) {
                    $err = $r['err'];
                    if ($err === '' || stripos($err, 'not found') !== false || stripos($err, 'not recognized') !== false) {
                        return back()->withErrors([
                            'images' => 'HEIC decoding requires libheif-examples. Install with `apt install -y libheif-examples`.',
                        ]);
                    }
                    return back()->withErrors(['images' => 'HEIC decode failed: ' . Str::limit($err, 240)]);
                }
            }
        }

        // Stage 3: parallel ImageMagick pass for everything still needing it.
        $magickJobs = array_filter($jobs, fn($j) => $j['magickStage']);
        if (! empty($magickJobs)) {
            $procs = [];
            foreach ($magickJobs as $idx => $j) {
                $input = $j['heif'] ? $j['interim'] : $j['stash'];
                $args  = [self::magickPath(), $input, '-auto-orient'];
                if ($format === 'jpg') {
                    $args = array_merge($args, ['-background', 'white', '-flatten', '-quality', '92']);
                } elseif ($format === 'webp') {
                    $args = array_merge($args, ['-quality', '90']);
                }
                $args[] = $j['out'];
                $procs[$idx] = ['args' => $args, 'target' => $j['out']];
            }

            $results = $this->runParallel($procs, $concurrency);
            foreach ($results as $idx => $r) {
                if (! $r['ok']) {
                    $err = $r['err'];
                    if ($err === '' || stripos($err, 'not recognized') !== false || stripos($err, 'not found') !== false) {
                        return $this->binaryError();
                    }
                    return back()->withErrors(['images' => 'Conversion failed: ' . Str::limit($err, 240)]);
                }
            }
        }

        $converted = [];
        foreach ($jobs as $j) {
            if (is_file($j['out'])) { $converted[] = $j['out']; }
            if ($j['interim'] && is_file($j['interim'])) { @unlink($j['interim']); }
            if (is_file($j['stash'])) { @unlink($j['stash']); }
        }

        if (count($converted) === 1) {
            return response()->download($converted[0], basename($converted[0]))->deleteFileAfterSend(true);
        }

        $zipPath = $outDir . DIRECTORY_SEPARATOR . 'converted_' . Str::uuid() . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            return back()->withErrors(['images' => 'Failed to create archive.']);
        }
        foreach ($converted as $f) { $zip->addFile($f, basename($f)); }
        $zip->close();
        foreach ($converted as $f) { @unlink($f); }

        return response()->download($zipPath, 'converted-images.zip')->deleteFileAfterSend(true);
    }

    /**
     * Run a set of Symfony Processes in parallel with a concurrency cap.
     * $procs is keyed by job index; returns the same keys with ['ok'=>bool,'err'=>string].
     */
    private function runParallel(array $procs, int $concurrency): array
    {
        $pending = $procs;
        $running = []; // idx => Process
        $results = [];

        while (! empty($pending) || ! empty($running)) {
            while (count($running) < $concurrency && ! empty($pending)) {
                $idx = array_key_first($pending);
                $spec = $pending[$idx];
                unset($pending[$idx]);

                $p = new Process($spec['args']);
                $p->setTimeout(180);
                try { $p->start(); }
                catch (\Throwable $e) {
                    $results[$idx] = ['ok' => false, 'err' => $e->getMessage()];
                    continue;
                }
                $running[$idx] = ['proc' => $p, 'target' => $spec['target']];
            }

            foreach ($running as $idx => $r) {
                if (! $r['proc']->isRunning()) {
                    $ok  = $r['proc']->isSuccessful() && is_file($r['target']);
                    $err = trim($r['proc']->getErrorOutput());
                    $results[$idx] = ['ok' => $ok, 'err' => $err];
                    unset($running[$idx]);
                }
            }

            if (! empty($running)) { usleep(50000); } // 50ms
        }

        return $results;
    }

    private function sniffHeif(string $path): bool
    {
        $fh = @fopen($path, 'rb');
        if (! $fh) { return false; }
        $head = fread($fh, 32);
        fclose($fh);
        if (strlen($head) < 12) { return false; }
        // ISO base media: bytes 4..8 are "ftyp", then a brand. HEIF brands: heic, heix, mif1, msf1, hevc, heim, heis, hevm, hevs.
        if (substr($head, 4, 4) !== 'ftyp') { return false; }
        $brand = substr($head, 8, 4);
        return in_array($brand, ['heic', 'heix', 'mif1', 'msf1', 'hevc', 'heim', 'heis', 'hevm', 'hevs'], true);
    }

    private function outDir(): string
    {
        $dir = storage_path('app/private/image-converter/' . (auth()->id() ?? 'anon') . '/' . Str::uuid());
        if (! is_dir($dir)) { @mkdir($dir, 0775, true); }
        return $dir;
    }

    private function binaryError()
    {
        $hint = PHP_OS_FAMILY === 'Windows'
            ? 'Install via `winget install ImageMagick.ImageMagick` then set IMAGE_CONVERTER_MAGICK_PATH in .env to the full path of magick.exe (e.g. C:\\Program Files\\ImageMagick-7.x.x\\magick.exe) and run `php artisan config:clear`.'
            : 'Install via `apt install -y imagemagick libmagickcore-6.q16-6-extra`. On IM6 systems set IMAGE_CONVERTER_MAGICK_PATH=/usr/bin/convert in .env and run `php artisan config:clear`.';

        return back()->withErrors(['images' => 'ImageMagick is not installed or not on PATH. ' . $hint]);
    }
}
