<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Training\TrainingDocChunk;
use App\Services\AI\EmbeddingService;
use Illuminate\Console\Command;

/**
 * Backfill embeddings for the training-guide chunks.
 *
 * The 12 hand-written CoreX training guides are the best user-facing answers in
 * the system — and every one of their chunks shipped with has_embedding = 0.
 * KnowledgeSearchService therefore fell back to keyword matching for exactly the
 * content most likely to answer an agent's question, needing 2+ literal word
 * hits to surface anything. Knowledge-document chunks had `knowledge:embed`;
 * training chunks had no equivalent command at all.
 *
 * Idempotent — only touches chunks that still lack an embedding, so it is safe
 * to re-run after every `training:ingest`.
 *
 * Spec: .ai/specs/ellie-v2.md §5.4.
 */
class EmbedTrainingChunks extends Command
{
    protected $signature = 'ellie:embed-training {--force : Re-embed every chunk, including ones already embedded}';

    protected $description = 'Generate embeddings for training-guide chunks so Ellie can retrieve them semantically';

    public function handle(EmbeddingService $embeddings): int
    {
        $query = TrainingDocChunk::query()->with('doc');

        if (! $this->option('force')) {
            $query->where(function ($q) {
                $q->where('has_embedding', false)->orWhereNull('has_embedding');
            });
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('All training chunks are already embedded. Nothing to do.');

            return self::SUCCESS;
        }

        $this->info("Embedding {$total} training chunk(s)...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $embedded = 0;
        $failed   = 0;

        // Batched: EmbeddingService already chunks into groups of 20 per API
        // call, so feed it a slice at a time rather than one row per request.
        $query->chunkById(20, function ($chunks) use ($embeddings, &$embedded, &$failed, $bar) {
            $texts = $chunks->map(function ($c) {
                // Prefix the heading path — it carries the section vocabulary an
                // agent actually types ("buyer pipeline", "FICA request"), which
                // the chunk body alone often omits.
                $heading = trim((string) ($c->heading_path ?? ''));

                return ($heading !== '' ? $heading . "\n\n" : '') . (string) $c->content;
            })->values()->all();

            $vectors = $embeddings->embedBatch($texts);

            foreach ($chunks->values() as $i => $chunk) {
                $vector = $vectors[$i] ?? null;

                if (! is_array($vector) || $vector === []) {
                    $failed++;
                    $bar->advance();
                    continue;
                }

                $chunk->embedding     = $vector;
                $chunk->has_embedding = true;
                $chunk->save();

                $embedded++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("Embedded: {$embedded}");

        if ($failed > 0) {
            $this->warn("Failed:   {$failed} (check OPENAI_API_KEY and the log, then re-run — this command is idempotent)");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
