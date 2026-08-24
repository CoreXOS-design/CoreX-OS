<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KnowledgeChunk;
use App\Models\Training\TrainingDocChunk;
use App\Services\AI\EmbeddingService;
use Illuminate\Console\Command;

/**
 * (Re)build the vector embeddings Ellie's knowledge search runs on.
 *
 * Covers BOTH pools — knowledge-base document chunks and the hand-written
 * training guides — because they are searched together and must therefore
 * share one vector space. A run where only half is re-embedded is worse than
 * no run at all: the halves become incomparable and ranking turns to noise.
 *
 * Embedding dimensions are model-specific, so CHANGING THE MODEL INVALIDATES
 * EVERY STORED VECTOR. This command detects that (it asks the service what
 * dimension it is producing, and compares against what is stored) and clears
 * stale rows before re-embedding, rather than leaving a mixed-dimension table
 * that scores garbage. Idempotent — safe to re-run.
 *
 * Spec: .ai/specs/ellie-v2.md §5.4.
 */
class EmbedAll extends Command
{
    protected $signature = 'ellie:embed
                            {--pool=all : all|kb|training}
                            {--force : Re-embed everything, not just missing/stale rows}';

    protected $description = 'Build the knowledge-base and training-guide embeddings Ellie searches';

    public function handle(EmbeddingService $embeddings): int
    {
        // Fail loudly up front. Without this the command happily writes a
        // whole run of nulls and reports "0 embedded" with no reason why.
        $status = $embeddings->status();
        if ($status === null) {
            $this->error('The embedding service is not available.');
            $this->line('  Check: systemctl status hf-ai   /   curl -s localhost:3100/health');

            return self::FAILURE;
        }

        $dim = $status['dim'];
        $this->info("Embedder: {$status['model']} ({$dim} dims)");

        $pool = (string) $this->option('pool');
        $force = (bool) $this->option('force');
        $failed = 0;

        if (in_array($pool, ['all', 'kb'], true)) {
            $failed += $this->embedPool(
                'knowledge base',
                KnowledgeChunk::query()->whereHas('document', fn ($q) => $q
                    ->where('is_active', true)
                    ->where('status', 'ready')
                    ->where('is_ellie_enabled', true)),
                fn ($c) => trim((string) ($c->section_title ?? '')) !== ''
                    ? $c->section_title . "\n\n" . $c->content
                    : (string) $c->content,
                $dim,
                $force,
                $embeddings,
            );
        }

        if (in_array($pool, ['all', 'training'], true)) {
            $failed += $this->embedPool(
                'training guides',
                TrainingDocChunk::query(),
                fn ($c) => trim((string) ($c->heading_path ?? '')) !== ''
                    ? $c->heading_path . "\n\n" . $c->content
                    : (string) $c->content,
                $dim,
                $force,
                $embeddings,
            );
        }

        if ($failed > 0) {
            $this->warn("{$failed} chunk(s) failed. Re-run — this command is idempotent.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Done. Ellie‘s semantic search is current.');

        return self::SUCCESS;
    }

    /**
     * Clear stale-dimension rows, then embed whatever still lacks a vector.
     *
     * @return int failures
     */
    private function embedPool(string $label, $query, callable $toText, int $dim, bool $force, EmbeddingService $embeddings): int
    {
        $this->newLine();
        $this->line("── {$label} ──");

        // Stale-dimension sweep. A vector built by a different model cannot be
        // compared with a current one, so it is not "already embedded" — it is
        // dead weight that must be regenerated.
        $stale = 0;
        (clone $query)->where('has_embedding', true)->chunkById(200, function ($chunks) use (&$stale, $dim) {
            foreach ($chunks as $chunk) {
                $vector = is_array($chunk->embedding) ? $chunk->embedding : json_decode((string) $chunk->embedding, true);
                if (! is_array($vector) || count($vector) !== $dim) {
                    $chunk->has_embedding = false;
                    $chunk->embedding = null;
                    $chunk->save();
                    $stale++;
                }
            }
        });

        if ($stale > 0) {
            $this->warn("  cleared {$stale} vector(s) built by a different model");
        }

        $pending = clone $query;
        if (! $force) {
            $pending->where(fn ($q) => $q->where('has_embedding', false)->orWhereNull('has_embedding'));
        }

        $total = (clone $pending)->count();
        if ($total === 0) {
            $this->line('  nothing to do — all current');

            return 0;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $failed = 0;
        $pending->chunkById(32, function ($chunks) use ($embeddings, $toText, $dim, &$failed, $bar) {
            $texts = $chunks->map($toText)->values()->all();
            $vectors = $embeddings->embedBatch($texts, EmbeddingService::KIND_PASSAGE);

            foreach ($chunks->values() as $i => $chunk) {
                $vector = $vectors[$i] ?? null;

                // Never store a vector of the wrong size — that is the exact
                // corruption this command exists to clean up.
                if (! is_array($vector) || count($vector) !== $dim) {
                    $failed++;
                    $bar->advance();
                    continue;
                }

                $chunk->embedding = $vector;
                $chunk->has_embedding = true;
                $chunk->save();
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->line('  embedded: ' . ($total - $failed) . " / {$total}");

        return $failed;
    }
}
