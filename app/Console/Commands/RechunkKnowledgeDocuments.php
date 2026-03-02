<?php

namespace App\Console\Commands;

use App\Models\KnowledgeDocument;
use App\Services\AI\DocumentProcessingService;
use Illuminate\Console\Command;

class RechunkKnowledgeDocuments extends Command
{
    protected $signature = 'knowledge:rechunk {document_id?}';

    protected $description = 'Re-chunk knowledge documents using updated chunking algorithm';

    public function handle(DocumentProcessingService $service): int
    {
        $documentId = $this->argument('document_id');

        if ($documentId) {
            $doc = KnowledgeDocument::find($documentId);
            if (!$doc) {
                $this->error("Document #{$documentId} not found.");
                return 1;
            }
            $this->rechunkDocument($service, $doc);
        } else {
            $docs = KnowledgeDocument::where('status', 'ready')->get();
            if ($docs->isEmpty()) {
                $this->info('No ready documents to re-chunk.');
                return 0;
            }
            $this->info("Re-chunking {$docs->count()} document(s)...");
            foreach ($docs as $doc) {
                $this->rechunkDocument($service, $doc);
            }
        }

        $this->info('Done!');
        return 0;
    }

    private function rechunkDocument(DocumentProcessingService $service, KnowledgeDocument $doc): void
    {
        $oldCount = $doc->chunk_count;
        $this->info("  Re-chunking: {$doc->title} (#{$doc->id}, {$oldCount} chunks)");

        $service->reprocess($doc);
        $doc->refresh();

        $embedded = $doc->chunks()->where('has_embedding', true)->count();
        $this->info("  → {$doc->chunk_count} chunks, {$embedded} embedded [{$doc->status}]");
    }
}
