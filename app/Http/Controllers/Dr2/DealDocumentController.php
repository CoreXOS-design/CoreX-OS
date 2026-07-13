<?php

namespace App\Http\Controllers\Dr2;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\Document;
use App\Models\DocumentType;
use App\Services\DealV2\DealDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * DR2 docs (AT-226 lane) — upload / attach documents on the canonical DR2 deal view
 * (the DR1 twin on `deals`). Files funnel through DealDocumentService::fileDealDocumentFromDeal
 * so the deal↔document 3-pillar wiring (deal-twin + property + property-contacts) lives in
 * ONE place. Document types are the SHARED truth with the PDF splitter (document_types) — no
 * parallel type system. Optional pipeline_step_id lands the doc on its pipeline step.
 */
class DealDocumentController extends Controller
{
    public function __construct(private DealDocumentService $documents) {}

    public function store(Request $request, Deal $deal)
    {
        $data = $request->validate([
            'file'             => ['required', 'file', 'max:20480'], // 20MB
            'document_type_id' => ['nullable', 'integer', 'exists:document_types,id'],
            'pipeline_step_id' => ['nullable', 'integer'],
        ]);

        $file = $request->file('file');
        // Private disk under the deal — never a public docroot path.
        $path = $file->store("deal-documents/{$deal->agency_id}/{$deal->id}", 'local');

        $doc = $this->documents->fileDealDocumentFromDeal($deal, [
            'original_name'    => $file->getClientOriginalName(),
            'storage_path'     => $path,
            'disk'             => 'local',
            'mime_type'        => $file->getClientMimeType(),
            'size'             => $file->getSize(),
            'document_type_id' => $data['document_type_id'] ?? null,
            'source_type'      => 'deal',
            'pipeline_step_id' => $data['pipeline_step_id'] ?? null,
        ], $request->user());

        return back()->with('success', "Filed {$doc->original_name} — it's now on the deal, the property and the linked contacts.");
    }

    public function download(Deal $deal, Document $document)
    {
        // The doc must belong to this deal (via the twin) or its DR1 source.
        $ownsByTwin   = $deal->deal_v2_id && (int) $document->deal_id === (int) $deal->deal_v2_id;
        $ownsBySource = $document->source_type === 'deal' && (int) $document->source_id === (int) $deal->id;
        abort_unless($ownsByTwin || $ownsBySource, 403);

        $disk = $document->disk ?: 'local';
        abort_unless(Storage::disk($disk)->exists($document->storage_path), 404);

        return Storage::disk($disk)->download($document->storage_path, $document->original_name);
    }

    /** Document types shared with the PDF splitter — for the upload form's type picker. */
    public static function typeOptions()
    {
        return DocumentType::query()->where('is_active', true)->orderBy('sort_order')->orderBy('label')
            ->get(['id', 'slug', 'label']);
    }
}
