<?php

namespace App\Console\Commands;

use App\Models\KnowledgeDocument;
use App\Services\AI\DocumentProcessingService;
use Illuminate\Console\Command;

class EmbedKnowledgeChunks extends Command
{
    protected $signature = 'knowledge:embed {document_id?}';

    protected $description = 'Generate embeddings for knowledge chunks that don\'t have them yet';

    public function handle(DocumentProcessingService $service): int
    {
        $documentId = $this->argument('document_id');

        if ($documentId) {
            $doc = KnowledgeDocument::find($documentId);
            if (!$doc) {
                $this->error("Document #{$documentId} not found.");
                return 1;
            }
            $this->embedDocument($service, $doc);
        } else {
            $docs = KnowledgeDocument::where('status', 'ready')->get();
            if ($docs->isEmpty()) {
                $this->info('No ready documents to embed.');
                return 0;
            }
            $this->info("Embedding chunks for {$docs->count()} document(s)...");
            foreach ($docs as $doc) {
                $this->embedDocument($service, $doc);
            }
        }

        $this->info('Done!');
        return 0;
    }

    private function embedDocument(DocumentProcessingService $service, KnowledgeDocument $doc): void
    {
        $pending = $doc->chunks()->where('has_embedding', false)->count();
        if ($pending === 0) {
            $this->info("  {$doc->title} (#{$doc->id}): all chunks already embedded");
            return;
        }

        $this->info("  {$doc->title} (#{$doc->id}): embedding {$pending} chunk(s)...");

        $embedded = $service->generateEmbeddings($doc);

        $this->info("  → {$embedded}/{$pending} chunks embedded");
    }
}
