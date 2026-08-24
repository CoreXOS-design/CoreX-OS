<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AI\EllieReferenceChunk;
use Illuminate\Support\Facades\Log;

/**
 * Search over admin-approved external reference pages — the ONLY thing Ellie's
 * search_reference_sites tool is backed by. This class never fetches a URL; it
 * only ever reads already-indexed `ellie_reference_chunks` rows belonging to
 * active sources. Fetching is EllieReferenceSourceFetchService's job, and that
 * class is only ever invoked by an admin action or the daily refresh cron.
 *
 * Scoring mirrors KnowledgeSearchService's hybrid cosine + structural approach,
 * simplified to a single pool (no training/KB split here).
 *
 * Spec: .ai/specs/ellie-reference-sources.md §7.
 */
class EllieReferenceSourceSearchService
{
    private const SCORE_THRESHOLD = 0.3;

    public function __construct(
        private readonly EmbeddingService $embeddingService,
    ) {
    }

    /**
     * @return array{context: string, sources: array}
     */
    public function search(string $query, int $limit = 4): array
    {
        try {
            $chunks = EllieReferenceChunk::where('has_embedding', true)
                ->whereHas('source', fn ($q) => $q->where('is_active', true))
                ->with('source')
                ->get();

            if ($chunks->isEmpty()) {
                return $this->buildKeywordResults($query, $limit);
            }

            $queryEmbedding = $this->embeddingService->embed($query, EmbeddingService::KIND_QUERY);

            if ($queryEmbedding === null) {
                Log::warning('EllieReferenceSourceSearchService: no query embedding — falling back to keyword search.');

                return $this->buildKeywordResults($query, $limit);
            }

            $words = $this->meaningfulWords($query);
            $totalWords = max(count($words), 1);

            $scored = $chunks->map(function (EllieReferenceChunk $chunk) use ($queryEmbedding, $words, $totalWords) {
                $chunkEmbedding = is_array($chunk->embedding) ? $chunk->embedding : [];
                $cosine = $chunkEmbedding !== []
                    ? $this->embeddingService->cosineSimilarity($queryEmbedding, $chunkEmbedding)
                    : 0.0;

                $title = mb_strtolower((string) ($chunk->source->title ?? ''));
                $keywordHits = 0;
                foreach ($words as $word) {
                    if (str_contains($title, $word) || str_contains(mb_strtolower($chunk->content), $word)) {
                        $keywordHits++;
                    }
                }
                $structural = $keywordHits / $totalWords;

                return ['chunk' => $chunk, 'score' => ($cosine * 0.75) + ($structural * 0.25)];
            });

            $top = $scored
                ->filter(fn ($item) => $item['score'] >= self::SCORE_THRESHOLD)
                ->sortByDesc('score')
                ->take($limit);

            if ($top->isEmpty()) {
                return ['context' => '', 'sources' => []];
            }

            return $this->format($top->pluck('chunk'));
        } catch (\Throwable $e) {
            Log::warning('EllieReferenceSourceSearchService: search failed — ' . $e->getMessage());

            return ['context' => '', 'sources' => []];
        }
    }

    /**
     * Degraded-mode keyword search — used when no query embedding is available
     * (embedding service down) or no chunk has ever been embedded yet.
     *
     * @return array{context: string, sources: array}
     */
    private function buildKeywordResults(string $query, int $limit): array
    {
        $words = $this->meaningfulWords($query);
        if (empty($words)) {
            return ['context' => '', 'sources' => []];
        }

        $candidates = EllieReferenceChunk::query()
            ->whereHas('source', fn ($q) => $q->where('is_active', true))
            ->with('source')
            ->where(function ($q) use ($words) {
                foreach ($words as $word) {
                    $q->orWhere('content', 'like', "%{$word}%");
                }
            })
            ->limit(50)
            ->get();

        $scored = $candidates->map(function (EllieReferenceChunk $chunk) use ($words) {
            $body = mb_strtolower($chunk->content);
            $title = mb_strtolower((string) ($chunk->source->title ?? ''));

            $score = 0;
            foreach ($words as $word) {
                if (str_contains($title, $word)) {
                    $score += 3;
                } elseif (str_contains($body, $word)) {
                    $score += 1;
                }
            }

            return ['chunk' => $chunk, 'score' => $score];
        })->filter(fn ($item) => $item['score'] > 0);

        $top = $scored->sortByDesc('score')->take($limit)->pluck('chunk');

        if ($top->isEmpty()) {
            return ['context' => '', 'sources' => []];
        }

        return $this->format($top);
    }

    /**
     * @param \Illuminate\Support\Collection<int, EllieReferenceChunk> $chunks
     * @return array{context: string, sources: array}
     */
    private function format(\Illuminate\Support\Collection $chunks): array
    {
        $contextParts = [];
        $sources = [];

        foreach ($chunks as $chunk) {
            $source = $chunk->source;
            if (! $source) {
                continue;
            }

            $title = $source->title ?: $source->url;

            $contextParts[] = "--- From external reference page: {$title} ({$source->url}) ---\n" . $chunk->content;

            $sources[] = [
                'title' => $title,
                'url' => $source->url,
                'category' => 'External reference',
            ];
        }

        return [
            'context' => implode("\n\n", $contextParts),
            'sources' => $sources,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function meaningfulWords(string $query): array
    {
        $stopWords = ['what', 'is', 'the', 'of', 'a', 'an', 'in', 'for', 'to', 'how', 'does', 'do', 'whats', 'tell', 'me', 'about', 'can', 'you', 'where', 'when', 'why'];

        $clean = preg_replace('/[^\w\s]/u', ' ', mb_strtolower($query));

        return array_values(array_filter(
            preg_split('/\s+/', (string) $clean, -1, PREG_SPLIT_NO_EMPTY),
            fn ($w) => strlen($w) >= 3 && ! in_array($w, $stopWords, true)
        ));
    }
}
