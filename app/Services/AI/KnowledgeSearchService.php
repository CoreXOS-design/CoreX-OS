<?php

namespace App\Services\AI;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;

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
            $keywords = $this->extractKeywords($query);
            if (empty($keywords)) {
                return ['context' => '', 'sources' => []];
            }

            // Detect clause/section number (e.g. "clause 3", "section 12.1")
            $clauseNumber = $this->extractClauseNumber($query);

            // Detect document name reference by matching against known titles
            $matchedDocId = $this->detectDocumentReference($query);

            // Fetch candidate chunks: any chunk matching at least one keyword
            $candidateQuery = KnowledgeChunk::whereHas('document', function ($q) {
                $q->where('is_active', true)
                  ->where('status', 'ready')
                  ->where('is_ellie_enabled', true);
            })->with('document');

            $candidateQuery->where(function ($q) use ($keywords) {
                foreach ($keywords as $keyword) {
                    $term = '%' . $keyword . '%';
                    $q->orWhere('content', 'LIKE', $term)
                      ->orWhere('section_title', 'LIKE', $term);
                }
            });

            $candidates = $candidateQuery->limit(100)->get();

            if ($candidates->isEmpty()) {
                return ['context' => '', 'sources' => []];
            }

            // Score each candidate
            $scored = $candidates->map(function ($chunk) use ($keywords, $clauseNumber, $matchedDocId) {
                $score = $this->scoreChunk($chunk, $keywords, $clauseNumber, $matchedDocId);
                return ['chunk' => $chunk, 'score' => $score];
            });

            // Sort by score descending, take top N
            $topChunks = $scored->sortByDesc('score')->take($limit);

            $contextParts = [];
            $sources = [];

            foreach ($topChunks as $item) {
                $chunk = $item['chunk'];
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
     * Score a chunk based on keyword matches, clause numbers, and document targeting.
     */
    private function scoreChunk(KnowledgeChunk $chunk, array $keywords, ?string $clauseNumber, ?int $matchedDocId): float
    {
        $score = 0.0;
        $contentLower = mb_strtolower($chunk->content);
        $sectionLower = mb_strtolower($chunk->section_title ?? '');

        // +1 per distinct keyword found in content
        foreach ($keywords as $keyword) {
            if (str_contains($contentLower, $keyword)) {
                $score += 1.0;
            }
            // +2 bonus for keyword in section_title (more specific)
            if ($sectionLower !== '' && str_contains($sectionLower, $keyword)) {
                $score += 2.0;
            }
        }

        // +5 for clause/section number match (e.g. "3." or "clause 3" or "3 " at line start)
        if ($clauseNumber !== null) {
            $patterns = [
                $clauseNumber . '.',
                $clauseNumber . ' ',
                'clause ' . $clauseNumber,
                'section ' . $clauseNumber,
            ];
            foreach ($patterns as $p) {
                if (str_contains($contentLower, $p)) {
                    $score += 5.0;
                    break;
                }
            }
            // Also check section_title for numbered sections
            if ($sectionLower !== '') {
                foreach ($patterns as $p) {
                    if (str_contains($sectionLower, $p)) {
                        $score += 3.0;
                        break;
                    }
                }
            }
        }

        // +10 for document title match (query references this specific document)
        if ($matchedDocId !== null && $chunk->document_id === $matchedDocId) {
            $score += 10.0;
        }

        return $score;
    }

    /**
     * Extract clause or section number from query (e.g. "clause 3" → "3", "section 12.1" → "12.1").
     */
    private function extractClauseNumber(string $query): ?string
    {
        $lower = mb_strtolower($query);
        if (preg_match('/(?:clause|section|paragraph|article|item)\s+([\d]+(?:\.[\d]+)*)/i', $lower, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Detect if query references a known document title. Returns document ID or null.
     */
    private function detectDocumentReference(string $query): ?int
    {
        $lower = mb_strtolower($query);

        $documents = KnowledgeDocument::where('is_active', true)
            ->where('status', 'ready')
            ->where('is_ellie_enabled', true)
            ->get(['id', 'title']);

        $bestId = null;
        $bestLen = 0;

        foreach ($documents as $doc) {
            $titleLower = mb_strtolower($doc->title);
            // Check if significant part of the document title appears in query
            $titleWords = preg_split('/\s+/', $titleLower, -1, PREG_SPLIT_NO_EMPTY);
            $matchCount = 0;
            foreach ($titleWords as $tw) {
                if (mb_strlen($tw) >= 3 && str_contains($lower, $tw)) {
                    $matchCount++;
                }
            }
            // Require at least 2 title words matched, or full title if short
            $threshold = count($titleWords) <= 2 ? count($titleWords) : 2;
            if ($matchCount >= $threshold && $matchCount > $bestLen) {
                $bestLen = $matchCount;
                $bestId = $doc->id;
            }
        }

        return $bestId;
    }

    /**
     * Determine if the message warrants a knowledge base search.
     */
    public function shouldSearch(string $message): bool
    {
        // Always search when KB documents are available
        if (KnowledgeDocument::where('status', 'ready')->where('is_ellie_enabled', true)->exists()) {
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
