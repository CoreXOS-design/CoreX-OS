<?php

namespace App\Services\Compliance;

use App\Models\FicaDocument;
use App\Services\Security\MediaCipher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AT-173 — the single storage seam for FICA client documents (ID copies, proof of
 * address, FICA forms). Everything sensitive is written ENCRYPTED to the PRIVATE
 * disk and served back through a decrypting stream — never a direct public URL.
 *
 * Migration-safe reads: a document may still be a legacy PLAINTEXT file, and it may
 * still sit on the old PUBLIC disk (pre-AT-173 agent/wet-ink uploads went there).
 * readRaw() looks on private first, then public; decrypt() passes plaintext through.
 * The backfill moves public→private and encrypts; after it runs everything is
 * private + ciphertext.
 */
class FicaDocumentStorage
{
    public const DISK = 'local';           // private: storage/app/private
    public const LEGACY_DISK = 'public';   // where pre-AT-173 agent/wet-ink docs landed

    public function __construct(private readonly MediaCipher $cipher)
    {
    }

    /** Store an uploaded FICA file encrypted on the private disk; return its path. */
    public function putUploaded(UploadedFile $file, string $dir): string
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $path = trim($dir, '/') . '/' . Str::random(40) . '.' . $ext;

        return $this->putBytes($path, (string) file_get_contents($file->getRealPath()));
    }

    /** Store raw bytes (e.g. generated wet-ink PDF) encrypted on the private disk. */
    public function putBytes(string $path, string $bytes): string
    {
        Storage::disk(self::DISK)->put(
            $path,
            $this->cipher->enabled() ? $this->cipher->encrypt($bytes) : $bytes
        );

        return $path;
    }

    /** Decrypted plaintext bytes for a document, or null if the file is missing. */
    public function bytes(FicaDocument $doc): ?string
    {
        $raw = $this->readRaw($doc->file_path);

        return $raw === null ? null : $this->cipher->decrypt($raw);
    }

    /** Stream a document to the browser, decrypted, inline. */
    public function stream(FicaDocument $doc, string $disposition = 'inline'): StreamedResponse
    {
        $bytes = $this->bytes($doc);
        abort_if($bytes === null, 404, 'Document file not found.');

        $name = $doc->file_name ?: ('document.' . pathinfo($doc->file_path, PATHINFO_EXTENSION));

        // response()->stream() (NOT streamDownload) so the caller's $disposition is honoured —
        // streamDownload() forces Content-Disposition: attachment when given a name, which turned
        // "View"/"Open in new tab" into a download. Default 'inline' opens the doc VIEWABLE in the
        // tab and lets <img>/<iframe> previews render; callers can still pass 'attachment' for a
        // genuine download.
        return response()->stream(
            function () use ($bytes) {
                echo $bytes;
            },
            200,
            [
                'Content-Type' => $doc->mime_type ?: 'application/octet-stream',
                'Content-Disposition' => $disposition . '; filename="' . addslashes($name) . '"',
                'Content-Length' => (string) strlen($bytes),
            ]
        );
    }

    /** Raw (possibly still-encrypted) bytes — private disk first, then legacy public. */
    private function readRaw(string $path): ?string
    {
        foreach ([self::DISK, self::LEGACY_DISK] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk)->get($path);
            }
        }

        return null;
    }
}
