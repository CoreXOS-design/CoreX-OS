<?php

namespace App\Services\AI;

use App\Models\KnowledgeChunk;

class KnowledgeSearchService
{
    /**
     * Search knowledge base for relevant chunks.
     *
     * @return array{context: string, sources: array}
     */
    public function search(string $query, int $limit = 3): array
    {
        try {
            // Try full-text search first
            $chunks = KnowledgeChunk::search($query)
                ->with('document')
                ->limit($limit)
                ->get();

            // If no results, try keyword extraction fallback
            if ($chunks->isEmpty()) {
                $keywords = $this->extractKeywords($query);
                if (!empty($keywords)) {
                    $chunks = KnowledgeChunk::keywordSearch($keywords)
                        ->with('document')
                        ->limit($limit)
                        ->get();
                }
            }

            if ($chunks->isEmpty()) {
                return ['context' => '', 'sources' => []];
            }

            $contextParts = [];
            $sources = [];

            foreach ($chunks as $chunk) {
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
                    'title' => $doc->title,
                    'section' => $chunk->section_title,
                    'page' => $chunk->page_number,
                    'category' => $doc->category->name ?? null,
                ];
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
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract meaningful keywords from a query string.
     */
    public function extractKeywords(string $query): array
    {
        $stopWords = [
            'the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
            'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could',
            'should', 'may', 'might', 'shall', 'can', 'need', 'dare', 'ought',
            'used', 'to', 'of', 'in', 'for', 'on', 'with', 'at', 'by', 'from',
            'as', 'into', 'through', 'during', 'before', 'after', 'above', 'below',
            'between', 'out', 'off', 'over', 'under', 'again', 'further', 'then',
            'once', 'here', 'there', 'when', 'where', 'why', 'how', 'all', 'each',
            'every', 'both', 'few', 'more', 'most', 'other', 'some', 'such', 'no',
            'nor', 'not', 'only', 'own', 'same', 'so', 'than', 'too', 'very',
            'just', 'because', 'but', 'and', 'or', 'if', 'while', 'about',
            'what', 'which', 'who', 'whom', 'this', 'that', 'these', 'those',
            'am', 'it', 'its', 'my', 'your', 'his', 'her', 'our', 'their',
            'me', 'him', 'us', 'them', 'i', 'you', 'he', 'she', 'we', 'they',
            'tell', 'explain', 'say', 'says', 'said', 'please', 'thank', 'thanks',
        ];

        $words = preg_split('/[\s,.\-;:!?()]+/', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY);

        $keywords = [];
        foreach ($words as $word) {
            if (mb_strlen($word) >= 3 && !in_array($word, $stopWords, true)) {
                $keywords[] = $word;
            }
        }

        return array_values(array_unique($keywords));
    }
}
