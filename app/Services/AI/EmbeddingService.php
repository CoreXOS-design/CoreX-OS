<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Vector embeddings for the knowledge base — self-hosted.
 *
 * Previously OpenAI `text-embedding-3-small` (1536 dims). That meant a second
 * AI vendor account purely for embeddings, because Anthropic has no embeddings
 * endpoint — and when that account quietly ran out of quota, embed() started
 * returning null on every call, KnowledgeSearchService silently fell back to
 * keyword matching, and Ellie told agents she "didn't have" documents that
 * were sitting embedded in the database. Nothing alarmed on it.
 *
 * Now served by the hf-ai service on this box (BAAI/bge-small-en-v1.5, 384
 * dims, ONNX/CPU) — the same service that already runs Whisper. One vendor
 * account, no per-call cost, and nothing leaves the server (POPIA).
 *
 * Spec: .ai/specs/ellie-v2.md §5.4.
 */
class EmbeddingService
{
    /** Passage side — what gets stored. */
    public const KIND_PASSAGE = 'passage';

    /** Query side — BGE applies an instruction prefix to these, server-side. */
    public const KIND_QUERY = 'query';

    /** Kept under the service's own EMBED_MAX_BATCH (64). */
    private const BATCH_SIZE = 32;

    /**
     * Generate an embedding for a single text string.
     *
     * @return float[]|null
     */
    public function embed(string $text, string $kind = self::KIND_PASSAGE): ?array
    {
        $results = $this->embedBatch([$text], $kind);

        return $results[0] ?? null;
    }

    /**
     * Generate embeddings for multiple texts.
     * Splits into batches of BATCH_SIZE automatically.
     *
     * @param string[] $texts
     * @return array<int, float[]|null> Indexed same as input; null on failure for that text.
     */
    public function embedBatch(array $texts, string $kind = self::KIND_PASSAGE): array
    {
        $allResults = array_fill(0, count($texts), null);
        $batches = array_chunk($texts, self::BATCH_SIZE, true);

        foreach ($batches as $batch) {
            $indices = array_keys($batch);
            $inputTexts = array_values($batch);

            try {
                $response = Http::timeout(120)
                    ->acceptJson()
                    ->asJson()
                    ->post($this->baseUrl() . '/embed', [
                        'texts' => $inputTexts,
                        'kind'  => $kind === self::KIND_QUERY ? self::KIND_QUERY : self::KIND_PASSAGE,
                    ]);

                if (! $response->successful()) {
                    Log::error('EmbeddingService: embed endpoint error', [
                        'status' => $response->status(),
                        'body'   => mb_substr($response->body(), 0, 300),
                    ]);
                    continue;
                }

                $vectors = $response->json('embeddings', []);
                if (! is_array($vectors)) {
                    Log::error('EmbeddingService: malformed embeddings payload');
                    continue;
                }

                foreach (array_values($vectors) as $i => $vector) {
                    if (is_array($vector) && $vector !== [] && isset($indices[$i])) {
                        $allResults[$indices[$i]] = $vector;
                    }
                }
            } catch (\Throwable $e) {
                Log::error('EmbeddingService: request failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $allResults;
    }

    /**
     * Cosine similarity between two embedding vectors.
     *
     * REFUSES vectors of differing length. This used to compare the first
     * min(count) components, which silently produced a plausible-looking score
     * from two unrelated vector spaces — exactly the failure a model change
     * causes, since the stored 1536-dim OpenAI vectors and the new 384-dim
     * local ones are not comparable in any dimension. A mismatch means stale
     * embeddings, so it returns 0.0 (and says so once) rather than ranking on
     * noise. Re-embed instead of loosening this.
     */
    public function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b)) {
            static $warned = false;
            if (! $warned) {
                $warned = true;
                Log::warning('EmbeddingService: embedding dimension mismatch — stale vectors need re-embedding.', [
                    'a_dim' => count($a),
                    'b_dim' => count($b),
                ]);
            }

            return 0.0;
        }

        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;
        $len = count($a);

        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $magA += $a[$i] * $a[$i];
            $magB += $b[$i] * $b[$i];
        }

        $magA = sqrt($magA);
        $magB = sqrt($magB);

        if ($magA == 0.0 || $magB == 0.0) {
            return 0.0;
        }

        return $dot / ($magA * $magB);
    }

    /**
     * The embedding service's health + current model/dimension, or null when
     * unreachable. Used by `ellie:embed-training` to fail loudly up front
     * rather than writing a run's worth of unusable vectors.
     *
     * @return array{model:string, dim:int}|null
     */
    public function status(): ?array
    {
        try {
            $response = Http::timeout(20)->acceptJson()->get($this->baseUrl() . '/health');
            if (! $response->successful()) {
                return null;
            }

            $dim = (int) $response->json('embed_dim', 0);
            if ($response->json('embed') !== 'ready' || $dim <= 0) {
                return null;
            }

            return [
                'model' => (string) $response->json('embed_model', 'unknown'),
                'dim'   => $dim,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function baseUrl(): string
    {
        return rtrim(
            (string) (config('services.hf_ai.base_url') ?? env('HF_AI_BASE_URL', 'http://127.0.0.1:3100')),
            '/'
        );
    }
}
