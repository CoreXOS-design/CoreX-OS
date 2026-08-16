<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\Template;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PageImageController extends Controller
{
    use \App\Http\Controllers\Concerns\AuthorizesDocumentAccess;

    public function show(Request $request, $id, $page)
    {
        $template = Template::findOrFail($id);

        $page = (int) $page;
        if ($page < 0 || $page >= $template->page_count) {
            abort(404);
        }

        // Try png first, then jpg (some templates have jpeg images)
        $path = "docuperfect/templates/{$id}/page-{$page}.png";
        $contentType = 'image/png';

        if (!Storage::exists($path)) {
            $path = "docuperfect/templates/{$id}/page-{$page}.jpg";
            $contentType = 'image/jpeg';
        }

        if (!Storage::exists($path)) {
            abort(404);
        }

        return response(Storage::get($path), 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * Serve a document-level page image (flattened web template).
     * Path: docuperfect/documents/{doc_id}/page-{n}.png
     */
    public function showDocumentPage(Request $request, $id, $page)
    {
        $document = Document::findOrFail($id);

        // AT-267-style zero-guard IDOR fix: this streamed a rendered
        // signed-document page image (names, ID numbers, signed content)
        // with no ownership/scope check at all, gated only by the base
        // `access_docuperfect` permission. Mirrors the per-record data-scope
        // guard already used everywhere else document access is checked
        // (DocumentController, AmendmentController, SalesDocumentController,
        // SignatureController::authorizeDocument). Read-only, so forEdit: false.
        $this->guardDocument($document, forEdit: false);

        $page = (int) $page;

        $webTemplateData = $document->web_template_data ?? [];
        $maxPages = (int) ($webTemplateData['flattened_page_count'] ?? 0);

        if ($page < 0 || ($maxPages > 0 && $page >= $maxPages)) {
            abort(404);
        }

        $path = "docuperfect/documents/{$id}/page-{$page}.png";
        if (!Storage::exists($path)) {
            abort(404);
        }

        return response(Storage::get($path), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
