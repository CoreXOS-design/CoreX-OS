<?php

namespace App\Http\Controllers;

use App\Services\Images\PrivateImageStore;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a private image to whoever holds a valid signed, unexpired link (audit 2026-10-19 B21). No login: the signature IS the credential,
 * and it expires (`signed` middleware on the route). The reference inside the link is encrypted with the application key, so the storage
 * path is neither guessable nor forgeable, and only the folders private evidence is kept in are ever served.
 */
class PrivateImageController extends Controller
{
    /** Folders on the private disk this endpoint may serve from. */
    private const SERVED_PREFIXES = ['rental-inspections/'];

    public function show(Request $request): Response
    {
        try {
            $reference = Crypt::decryptString((string) $request->query('p', ''));
        } catch (DecryptException) {
            abort(404);
        }

        abort_unless(PrivateImageStore::isReference($reference), 404);
        $path = PrivateImageStore::pathOf($reference);
        abort_if(str_contains($path, '..'), 404);
        abort_unless(collect(self::SERVED_PREFIXES)->contains(fn ($prefix) => str_starts_with($path, $prefix)), 404);

        $disk = Storage::disk(PrivateImageStore::DISK);
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
