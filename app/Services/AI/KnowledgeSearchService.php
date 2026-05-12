<?php

namespace App\Services\AI;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Training\TrainingDoc;
use App\Models\Training\TrainingDocChunk;

class KnowledgeSearchService
{
    private EmbeddingService $embeddingService;

    public function __construct(EmbeddingService $embeddingService)
    {
        $this->embeddingService = $embeddingService;
    }

    /**
     * Search knowledge base for relevant chunks using vector similarity.
     *
     * @return array{context: string, sources: array}
     */
    public function search(string $query, int $limit = 5): array
    {
        try {
            $queryEmbedding = $this->embeddingService->embed($query);

            if ($queryEmbedding === null) {
                return ['context' => '', 'sources' => []];
            }

            // Load all embedded chunks from active, ready, ellie-enabled documents
            $chunks = KnowledgeChunk::whereHas('document', function ($q) {
                $q->where('is_active', true)
                  ->where('status', 'ready')
                  ->where('is_ellie_enabled', true);
            })
                ->where('has_embedding', true)
                ->with('document')
                ->get();

            // Also load training doc chunks (always included — canonical user-facing answers)
            $trainingChunks = TrainingDocChunk::where('has_embedding', true)
                ->with('doc')
                ->get();

            if ($chunks->isEmpty() && $trainingChunks->isEmpty()) {
                return ['context' => '', 'sources' => []];
            }

            // Extract structural signals from query for hybrid scoring
            $stopWords = ['what', 'is', 'the', 'of', 'a', 'an', 'in', 'for', 'to', 'how', 'does', 'do', 'whats', 'tell', 'me', 'about', 'can', 'you'];
            preg_match_all('/\b(\d+(?:\.\d+)*)\b/', $query, $numberMatches);
            $queryNumbers = $numberMatches[1] ?? [];
            $queryWords = array_values(array_filter(
                preg_split('/\s+/', mb_strtolower(preg_replace('/[^\w\s]/', '', $query))),
                fn ($w) => $w !== '' && !in_array($w, $stopWords) && !is_numeric($w)
            ));
            $totalMeaningfulWords = max(count($queryWords), 1);

            // Score each chunk: hybrid = (cosine * 0.7) + (structural * 0.3)
            $scoreChunk = function ($chunk, bool $isTraining) use ($queryEmbedding, $queryNumbers, $queryWords, $totalMeaningfulWords) {
                $cosine = $this->embeddingService->cosineSimilarity(
                    $queryEmbedding,
                    $chunk->embedding
                );

                // For KB chunks use section_title, for training chunks use heading_path
                $title = mb_strtolower($chunk->section_title ?? $chunk->heading_path ?? '');

                // Numbered reference matching
                $numberScore = 0.0;
                foreach ($queryNumbers as $num) {
                    $escaped = preg_quote($num, '/');
                    if (preg_match('/^' . $escaped . '(?:[\.\s]|$)/', $title)) {
                        $numberScore = 1.0;
                        break;
                    } elseif (str_contains($title, $num)) {
                        $numberScore = max($numberScore, 0.5);
                    }
                }

                // Keyword title matching
                $keywordScore = 0.0;
                if ($title !== '') {
                    $matchCount = 0;
                    foreach ($queryWords as $word) {
                        if (str_contains($title, $word)) {
                            $matchCount++;
                        }
                    }
                    $keywordScore = $matchCount / $totalMeaningfulWords;
                }

                $structural = max($numberScore, $keywordScore);
                $hybrid = ($cosine * 0.7) + ($structural * 0.3);

                // Boost training docs by 1.2x — canonical user-facing answers
                if ($isTraining) {
                    $hybrid *= 1.2;
                }

                return ['chunk' => $chunk, 'score' => $hybrid, 'is_training' => $isTraining];
            };

            $scored = $chunks->map(fn ($c) => $scoreChunk($c, false))
                ->merge($trainingChunks->map(fn ($c) => $scoreChunk($c, true)));

            // Sort by hybrid score descending, take top N, filter by minimum threshold
            $topChunks = $scored->sortByDesc('score')->take($limit)->filter(fn ($item) => $item['score'] >= 0.3);

            if ($topChunks->isEmpty()) {
                return ['context' => '', 'sources' => []];
            }

            $contextParts = [];
            $sources = [];

            foreach ($topChunks as $item) {
                $chunk = $item['chunk'];
                $isTraining = $item['is_training'] ?? false;

                if ($isTraining) {
                    $doc = $chunk->doc;
                    $header = "--- From training guide: {$doc->title}";
                    if ($chunk->heading_path) {
                        $header .= " ({$chunk->heading_path})";
                    }
                    $anchor = $chunk->section_anchor ? "#{$chunk->section_anchor}" : '';
                    $header .= " → /corex/training-help/{$doc->slug}{$anchor}";
                    $header .= " ---";

                    $contextParts[] = $header . "\n" . $chunk->content;

                    $sources[] = [
                        'document_id'  => $doc->id,
                        'title'        => $doc->title,
                        'section'      => $chunk->heading_path,
                        'page'         => null,
                        'category'     => 'Training',
                        'url'          => "/corex/training-help/{$doc->slug}{$anchor}",
                        'is_training'  => true,
                    ];
                } else {
                    $doc = $chunk->document;
                    $header = "--- From: {$doc->title}";
                    if ($chunk->section_title) {
                        $header .= " ({$chunk->section_title})";
                    }
                    if ($chunk->page_number) {
                        $header .= " [Page {$chunk->page_number}]";
                    }
                    $header .= " ---";

                    $contextParts[] = $header . "\n" . $chunk->content;

                    $sources[] = [
                        'document_id' => $doc->id,
                        'title'       => $doc->title,
                        'section'     => $chunk->section_title,
                        'page'        => $chunk->page_number,
                        'category'    => $doc->category->name ?? null,
                    ];
                }
            }

            return [
                'context' => implode("\n\n", $contextParts),
                'sources' => $sources,
            ];
        } catch (\Throwable $e) {
            \Log::warning('Knowledge search failed: ' . $e->getMessage());
            return ['context' => '', 'sources' => []];
        }
    }

    /**
     * Determine if the message warrants a knowledge base search.
     */
    public function shouldSearch(string $message): bool
    {
        // Always search when KB documents with embeddings are available
        if (KnowledgeDocument::where('status', 'ready')->where('is_ellie_enabled', true)->exists()) {
            return true;
        }

        // Also search when training docs have been ingested
        if (TrainingDocChunk::where('has_embedding', true)->exists()) {
            return true;
        }

        // Fallback: keyword gate for when no ready documents exist (avoids unnecessary queries)
        $lower = mb_strtolower($message);

        $patterns = [
            'what does', 'what is', 'what are',
            'clause', 'section', 'policy', 'procedure',
            'otp', 'mandate', 'fica', 'compliance',
            'commission', 'split', 'transfer', 'trust account',
            'lease', 'rental', 'tell me about', 'explain',
            'according to', 'cpd', 'ppra', 'eaab',
            'conveyancing', 'bond', 'contract', 'agreement',
            'regulation', 'rule', 'guideline', 'requirement',
            'training', 'onboarding', 'branding', 'marketing',
            'how do i', 'how does', 'what should',
            'knowledge base', 'document says',
            'evaluation', 'valuation', 'commercial', 'agricultural',
            'hospitality', 'industrial', 'crop', 'livestock',
            'comparable', 'financial', 'calculator', 'bond overpayment',
            'fee scale', 'knowledge', 'document',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
