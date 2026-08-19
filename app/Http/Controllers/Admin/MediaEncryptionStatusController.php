<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FicaDocument;
use App\Services\Security\MediaCipher;
use Illuminate\View\View;

/**
 * AT-173 — admin-facing status of media encryption at rest. Deliberately does NOT
 * walk the whole media tree (that would be a heavy scan on live); it reports the
 * configured state, the covered scopes, cheap DB counts, and the exact backfill
 * commands for migration. Precise migration counts come from the backfill --dry-run.
 *
 * Moved from Compliance to Admin (alongside Backups / Server Health / API) —
 * this reports server-side encryption CONFIGURATION, the same category of
 * infra/security status as its new siblings, not a compliance workflow.
 */
class MediaEncryptionStatusController extends Controller
{
    public function index(MediaCipher $cipher): View
    {
        return view('admin.media-encryption.status', [
            'enabled' => $cipher->enabled(),
            'keyPresent' => config('media-encryption.key') !== null,
            'algorithm' => strtoupper((string) config('media-encryption.cipher', 'aes-256-gcm')),
            'ficaDocCount' => FicaDocument::query()->count(),
        ]);
    }
}
