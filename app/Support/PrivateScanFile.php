<?php

namespace App\Support;

use App\Models\RentalInspectionSignature;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Audit A-H1 (2026-10-18) — one place for how a customer-supplied wet-ink
 * scan (PDF / photo) is stored and served for the rental inventory records.
 *
 * Stored on the PRIVATE disk as "private:<disk path>" under a server-generated
 * name whose extension comes from the content-sniffed MIME type through a
 * fixed allow-list — the client's filename/extension is never used, so an
 * uploaded file can never be a web-servable .html/.svg. Served only through
 * an authorised route using response(), with nosniff and (for images) a
 * sandboxing CSP. Same contract as RentalInspectionSignature's wet-ink files.
 */
final class PrivateScanFile
{
    public const PRIVATE_PREFIX = 'private:';

    /** Content-sniffed MIME -> the ONLY extension we ever write. */
    private const EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/heic' => 'heic',
        'image/heif' => 'heic',
    ];

    /**
     * @return string "private:<path>" to save on the row
     *
     * @throws \InvalidArgumentException when the file type is not allowed
     */
    public static function store(UploadedFile $file, string $directory): string
    {
        $extension = self::EXTENSIONS[strtolower((string) $file->getMimeType())] ?? null;
        if ($extension === null) {
            throw new \InvalidArgumentException('The uploaded page must be a PDF, JPG, PNG or HEIC file.');
        }

        $filename = 'wetink_' . bin2hex(random_bytes(16)) . '.' . $extension;
        $path = $file->storeAs($directory, $filename, 'local');
        if ($path === false) {
            throw new \LogicException('The uploaded page could not be stored.');
        }

        return self::PRIVATE_PREFIX . $path;
    }

    public static function isPrivate(?string $stored): bool
    {
        return $stored !== null && str_starts_with($stored, self::PRIVATE_PREFIX);
    }

    /** Remove a just-stored private file again (used when the record it was uploaded for is then refused). */
    public static function discard(?string $stored): void
    {
        if (self::isPrivate($stored)) {
            Storage::disk('local')->delete(substr($stored, strlen(self::PRIVATE_PREFIX)));
        }
    }

    /**
     * Stream a stored scan (private, or a legacy public "/storage/..." value).
     * Only image/PDF types are ever served inline.
     */
    public static function response(?string $stored): Response
    {
        $read = RentalInspectionSignature::readStored($stored);
        abort_if($read === null, 404);

        [$bytes, $mime] = $read;
        abort_unless(in_array($mime, ['image/png', 'image/jpeg', 'image/heic', 'image/heif', 'application/pdf'], true), 404);

        $headers = [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
        ];
        // A PDF must NOT get the sandbox CSP (Chrome's PDF viewer will not render inside it); an
        // application/pdf body with nosniff cannot execute as a page.
        if ($mime !== 'application/pdf') {
            $headers['Content-Security-Policy'] = "sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'";
        }

        return response($bytes, 200, $headers);
    }

    /**
     * Decode a canvas-captured base64 PNG, require it to really be a PNG
     * within the size cap, and re-encode it through GD (when available) so
     * only pixel data is ever written. Returns the raw PNG bytes.
     *
     * @throws \InvalidArgumentException when the payload is not a usable PNG
     */
    public static function validatedPng(string $base64, int $maxBytes = 1048576): string
    {
        $data = str_contains($base64, ',') ? explode(',', $base64, 2)[1] : $base64;
        $binary = base64_decode(trim($data), true);

        if ($binary === false || $binary === '') {
            throw new \InvalidArgumentException('The signature image is empty or not valid base64.');
        }
        if (strlen($binary) > $maxBytes) {
            throw new \InvalidArgumentException('The signature image is too large.');
        }

        $info = @getimagesizefromstring($binary);
        if (! str_starts_with($binary, "\x89PNG\r\n\x1a\n") || $info === false || ($info[2] ?? null) !== IMAGETYPE_PNG || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
            throw new \InvalidArgumentException('The signature must be a PNG image.');
        }

        if (function_exists('imagecreatefromstring') && function_exists('imagepng')) {
            $image = @imagecreatefromstring($binary);
            if ($image === false) {
                throw new \InvalidArgumentException('The signature image could not be read.');
            }
            imagealphablending($image, false);
            imagesavealpha($image, true);
            ob_start();
            imagepng($image);
            $reencoded = (string) ob_get_clean();
            imagedestroy($image);
            if ($reencoded === '') {
                throw new \InvalidArgumentException('The signature image could not be processed.');
            }
            $binary = $reencoded;
        }

        return $binary;
    }
}
