<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class ImageConverterController extends Controller
{
    private const OUTPUT_FORMATS = ['png', 'jpg', 'webp'];

    private static function magickPath(): string  { return config('image-converter.magick_path', 'magick'); }
    private static function maxKb(): int          { return (int) config('image-converter.max_upload_kb', 51200); }

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

        $format    = $request->input('format');
        $files     = $request->file('images');
        $outDir    = $this->outDir();
        $converted = [];

        foreach ($files as $file) {
            $base    = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'image';
            $outPath = $outDir . DIRECTORY_SEPARATOR . $base . '_' . Str::random(6) . '.' . $format;

            $args = [self::magickPath(), $file->getRealPath(), '-auto-orient'];

            if ($format === 'jpg') {
                $args = array_merge($args, ['-background', 'white', '-flatten', '-quality', '92']);
            } elseif ($format === 'webp') {
                $args = array_merge($args, ['-quality', '90']);
            }

            $args[] = $outPath;

            $proc = new Process($args);
            $proc->setTimeout(120);

            try { $proc->run(); }
            catch (\Throwable $e) { return $this->binaryError(); }

            if (! $proc->isSuccessful() || ! is_file($outPath)) {
                $err = trim($proc->getErrorOutput());
                if ($err === '' || stripos($err, 'not recognized') !== false || stripos($err, 'not found') !== false) {
                    return $this->binaryError();
                }
                return back()->withErrors(['images' => 'Conversion failed: ' . Str::limit($err, 240)]);
            }

            $converted[] = $outPath;
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

    private function outDir(): string
    {
        $dir = storage_path('app/private/image-converter/' . (auth()->id() ?? 'anon') . '/' . Str::uuid());
        if (! is_dir($dir)) { @mkdir($dir, 0775, true); }
        return $dir;
    }

    private function binaryError()
    {
        return back()->withErrors([
            'images' => 'ImageMagick is not installed or not on PATH. Install via `winget install ImageMagick.ImageMagick`, then set IMAGE_CONVERTER_MAGICK_PATH in .env to the full path of magick.exe.',
        ]);
    }
}
