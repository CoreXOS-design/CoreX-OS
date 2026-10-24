<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\Documents\DocumentAccessService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The single authenticated route every document file is opened through.
 * Sensitive files live on the private disk only; nothing links to /storage.
 * Spec: .ai/specs/private-document-storage.md §3.
 */
class DocumentFileController extends Controller
{
    public function __construct(private readonly DocumentAccessService $access)
    {
    }

    public function show(Request $request, Document $document): StreamedResponse
    {
        $user = $request->user();

        $this->access->authorise($user, $document);

        $bytes = $document->decryptedContents();
        abort_if($bytes === null, 404, 'Document file is missing from storage.');

        $inline = ! $request->boolean('download') && $document->isViewableInline();
        if (! $inline) {
            // Viewing is allowed for an assistant; pulling the file down needs the toggle (AT-267).
            abort_unless($user->canDownloadDocuments(), 403);
        }

        $name = $document->original_name ?: ('document-' . $document->id);

        return response()->stream(
            function () use ($bytes) {
                echo $bytes;
            },
            200,
            [
                'Content-Type'           => $inline ? $document->inlineMimeType() : ($document->mime_type ?: 'application/octet-stream'),
                'Content-Disposition'    => ($inline ? 'inline' : 'attachment') . '; filename="' . addslashes($name) . '"',
                'Content-Length'         => (string) strlen($bytes),
                'Cache-Control'          => 'no-store, no-cache, must-revalidate, private',
                'Pragma'                 => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
