<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Johan, 2026-09-28 — "the PDF is the signed record that gets auto-emailed
 * to tenant and landlord... it must carry the actual signature images." A
 * signed PDF emailed to someone with no CoreX login must be self-contained
 * evidence — it cannot depend on the recipient later clicking through to a
 * public page to see what was actually signed. Shared by
 * RentalInspectionReportPdfService and RentalInventoryReportPdfService (the
 * bug — no signature image in either PDF — was one class, not two
 * instances) so a third PDF-with-signatures consumer never re-derives this.
 *
 * Reads the file straight off the 'public' disk and returns it as a
 * `data:` URI, so DomPDF never makes an HTTP round trip to its own app for
 * an asset it can read straight off disk (`isRemoteEnabled` stays off,
 * which is also what keeps DomPDF from being handed a live SSRF vector).
 * `party_signature_path`/`wet_ink_upload_path` are stored as the PUBLIC
 * URL form (`Storage::disk('public')->url($path)`, e.g.
 * "/storage/properties/{id}/.../sig_....png") — never the disk-relative
 * path — so the "/storage/" prefix (and any host/scheme in front of it) is
 * stripped back off here to resolve the real disk key.
 */
class StorageDataUri
{
    /**
     * @return string|null null when $storedPath is empty or the file is
     *                      missing/unreadable — a signature image is never
     *                      load-bearing for whether the PDF itself renders;
     *                      a caller that gets null simply omits the image.
     */
    public static function fromPublicStoragePath(?string $storedPath): ?string
    {
        if (! $storedPath) {
            return null;
        }

        $relative = preg_replace('#^.*/storage/#', '', $storedPath);
        if (! $relative || ! Storage::disk('public')->exists($relative)) {
            return null;
        }

        $bytes = Storage::disk('public')->get($relative);
        if ($bytes === null) {
            return null;
        }

        $mime = Storage::disk('public')->mimeType($relative) ?: 'image/png';

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }
}
