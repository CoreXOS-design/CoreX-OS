<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmbeddingService
{
    private const MODEL = 'text-embedding-3-small';
    private const DIMENSIONS = 1536;
    private const BATCH_SIZE = 20;

    /**
     * Generate an embedding for a single text string.
     *
     * @return float[]|null
     */
    public function embed(string $text): ?array
    {
        $results = $this->embedBatch([$text]);
        return $results[0] ?? null;
    }

    /**
     * Generate embeddings for multiple texts in one API call.
     * Splits into batches of BATCH_SIZE automatically.
     *
     * @param string[] $texts
     * @return array<int, float[]|null> Indexed same as input; null on failure for that text.
     */
    public function embedBatch(array $texts): array
    {
        $apiKey = $this->getApiKey();
        if (empty($apiKey)) {
            Log::warning('EmbeddingService: OPENAI_API_KEY not configured.');
            return array_fill(0, count($texts), null);
        }

        $allResults = array_fill(0, count($texts), null);
        $batches = array_chunk($texts, self::BATCH_SIZE, true);

        foreach ($batches as $batch) {
            $indices = array_keys($batch);
            $inputTexts = array_values($batch);

            try {
                $response = Http::withToken($apiKey)
                    ->timeout(30)
                    ->post('https://api.openai.com/v1/embeddings', [
                        'model' => self::MODEL,
                        'input' => $inputTexts,
                    ]);

                if (!$response->successful()) {
                    Log::error('EmbeddingService: API error', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    continue;
                }

                $data = $response->json('data', []);
                foreach ($data as $item) {
                    $batchIdx = $item['index'];
                    if (isset($indices[$batchIdx])) {
                        $allResults[$indices[$batchIdx]] = $item['embedding'];
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
     * Calculate cosine similarity between two embedding vectors.
     */
    public function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;
        $len = min(count($a), count($b));

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

    private function getApiKey(): string
    {
        return trim((string) (config('services.openai.key') ?? env('OPENAI_API_KEY', '')));
    }
}
